<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/file.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/id.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/config.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/thumbnails.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/spool.php';

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\LazyOpenStream;
use GuzzleHttp\Psr7\LimitStream;

interface FileStorage
{
    public function has_file(string $name): bool;
    public function get_file(string $name): BaseFile|null;
    public function save_file(string $name, string $input_path, FileOptions|null $metadata): BaseFile|null;
    public function delete_file(string $name): bool;
    public function ensure_file(BaseFile &$file): bool;
    public function is_available(): bool;

    public function transfer_file(string $name, string $input_path, FileOptions|null $options, array &$state, callable|null $checkpoint = null, callable|null $should_continue = null): void;
    public function abort_transfer(string $name, array $state): void;
}

class TransferCancelledException extends RuntimeException
{
}

class LocalFileStorage implements FileStorage
{
    protected string $directory, $prefix;

    public function __construct(string $directory, string $prefix)
    {
        $this->directory = $directory;
        $this->prefix = $prefix;
    }

    public function has_file(string $name): bool
    {
        return is_file("{$this->directory}/$name");
    }

    public function get_file(string $name): BaseFile|null
    {
        if (!$this->has_file($name)) {
            return null;
        }

        $splitname = new SplitFilename($name);
        $path = "{$this->directory}/$name";

        $file = new BaseFile();
        $file->name = $splitname->name;
        $file->extension = $splitname->extension;
        $file->path = "local://$path";
        $file->size = filesize($path);
        $file->mime = CONFIG['upload']['acceptedmimetypes'][$file->extension];

        return $file;
    }

    public function save_file(string $name, string $input_path, FileOptions|null $options = null): BaseFile|null
    {
        if ($this->check_directory()) {
            throw new RuntimeException("Failed to create a directory for files: {$this->directory}");
        }

        if (!move_uploaded_file($input_path, "{$this->directory}/$name")) {
            return null;
        }
        return $this->get_file($name);
    }

    public function transfer_file(string $name, string $input_path, FileOptions|null $options, array &$state, callable|null $checkpoint = null, callable|null $should_continue = null): void
    {
        if ($this->check_directory()) {
            throw new RuntimeException("Failed to create a directory for files: {$this->directory}");
        }

        $dst = "{$this->directory}/$name";
        $tmp = "$dst.part";
        @unlink($tmp);
        if (!@link($input_path, $tmp) && !copy($input_path, $tmp)) {
            throw new RuntimeException("Failed to copy $input_path to $tmp");
        }
        chmod($tmp, 0644);
        if (filesize($tmp) !== filesize($input_path) || !rename($tmp, $dst)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to store $dst");
        }
    }

    public function abort_transfer(string $name, array $state): void
    {
        @unlink("{$this->directory}/$name.part");
    }

    public function delete_file(string $name): bool
    {
        $previous = SPOOL->discard_file($name);
        $path = "{$this->directory}/$name";
        if ($previous !== null && $previous !== Spool::READY && !is_file($path)) {
            return true;
        }
        return is_file($path) && unlink($path);
    }

    public function ensure_file(BaseFile &$file): bool
    {
        $name = "{$file->name}.{$file->extension}";
        if ($record = SPOOL->lookup_file($name)) {
            $file->path = "spool://{$record['id']}";
            return true;
        }

        $f = $this->get_file($name);
        if (!$f) {
            return false;
        }

        $file->path = $f->path;
        return true;
    }

    public function is_available(): bool
    {
        return is_dir($this->directory) ? is_writable($this->directory) : !$this->check_directory();
    }

    private function check_directory(): bool
    {
        return !is_dir($this->directory) && !mkdir($this->directory, 0777, true);
    }
}

class S3FileStorage implements FileStorage
{
    // S3 allows at most 10000 parts; every part except the last must be at least 5 MB
    private const MAX_PARTS = 10000;
    private const MIN_PART_SIZE = 5 * 1024 * 1024;

    private S3Client|null $s3 = null;
    private array $config;
    private string $bucket, $directory;

    public function __construct(array $data)
    {
        $this->config = $data;
        $this->bucket = (string) $data['bucket'];
        $this->directory = (string) $data['directory'];
    }

    private function client(): S3Client
    {
        return $this->s3 ??= new S3Client([
            'version' => $this->config['version'] ?? 'latest',
            'region' => $this->config['region'],
            'credentials' => [
                'key' => $this->config['access_key'],
                'secret' => $this->config['secret_key']
            ],
            'endpoint' => $this->config['endpoint'],
            'use_path_style_endpoint' => $this->config['use_path_style_endpoint'] ?? false,
            'http' => [
                'connect_timeout' => 5,
                'timeout' => 120,
                'curl' => [
                    CURLOPT_LOW_SPEED_LIMIT => 1024,
                    CURLOPT_LOW_SPEED_TIME => 30,
                ],
            ],
            'retries' => [
                'mode' => 'standard',
                'max_attempts' => 3,
            ],
        ]);
    }

    private function key(string $name): string
    {
        return "{$this->directory}/$name";
    }

    public function has_file(string $name): bool
    {
        return $this->get_file($name) !== null;
    }

    public function get_file(string $name): BaseFile|null
    {
        try {
            $result = $this->client()->headObject([
                'Bucket' => $this->bucket,
                'Key' => $this->key($name)
            ]);
        } catch (Exception $e) {
            return null;
        }

        return $this->file_from_head($name, $result);
    }

    private function file_from_head(string $name, Aws\Result $result): BaseFile
    {
        $file = new BaseFile();

        $k = explode(".", $name, 2);

        $file->name = $k[0];
        $file->extension = $k[1];
        $file->mime = $result->get("ContentType");
        $file->size = $result->get("ContentLength");
        $file->path = "s3:///$name";

        return $file;
    }

    public function save_file(string $name, string $input_path, FileOptions|null $options = null): BaseFile|null
    {
        $state = [];
        try {
            $this->transfer_file($name, $input_path, $options, $state);
        } catch (Exception $e) {
            $this->abort_transfer($name, $state);
            return null;
        }
        return $this->get_file($name);
    }

    public function transfer_file(string $name, string $input_path, FileOptions|null $options, array &$state, callable|null $checkpoint = null, callable|null $should_continue = null): void
    {
        clearstatcache(true, $input_path);
        $size = filesize($input_path);
        if ($size === false) {
            throw new RuntimeException("Cannot read $input_path");
        }

        $params = ['Bucket' => $this->bucket, 'Key' => $this->key($name)];
        $object_params = $params;
        if ($options?->content_type) {
            $object_params['ContentType'] = $options->content_type;
        }

        $part_size = (int) ($state['part_size'] ?? max(self::MIN_PART_SIZE, CHUNK_SIZE, (int) ceil($size / self::MAX_PARTS)));

        if (empty($state['completed'])) {
            if ($size <= $part_size) {
                $this->client()->putObject($object_params + [
                    'Body' => $this->part_stream($input_path, 0, $size),
                    'ContentLength' => $size,
                ]);
            } else {
                $this->multipart_upload($input_path, $size, $part_size, $params, $object_params, $state, $checkpoint, $should_continue);
            }
            $state['completed'] = true;
            $checkpoint && $checkpoint($state);
        }

        $head = $this->client()->headObject($params);
        if ((int) $head->get('ContentLength') !== $size) {
            unset($state['completed'], $state['upload_id'], $state['parts']);
            $checkpoint && $checkpoint($state);
            throw new RuntimeException(sprintf('Size mismatch after upload: expected %d, storage has %d', $size, $head->get('ContentLength')));
        }
    }

    private function multipart_upload(string $input_path, int $size, int $part_size, array $params, array $object_params, array &$state, callable|null $checkpoint, callable|null $should_continue): void
    {
        $count = intdiv($size + $part_size - 1, $part_size);

        if (!empty($state['upload_id'])) {
            try {
                $state['parts'] = $this->list_parts($params, $state['upload_id'], $size, $part_size);
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() !== 'NoSuchUpload') {
                    throw $e;
                }
                try {
                    if ((int) $this->client()->headObject($params)->get('ContentLength') === $size) {
                        return;
                    }
                } catch (AwsException $e2) {
                    if ($e2->getStatusCode() !== 404) {
                        throw $e2;
                    }
                }
                unset($state['upload_id'], $state['parts']);
            }
        }

        if (empty($state['upload_id'])) {
            $result = $this->client()->createMultipartUpload($object_params);
            $state['upload_id'] = $result['UploadId'];
            $state['part_size'] = $part_size;
            $state['parts'] = [];
            $checkpoint && $checkpoint($state);
        }

        for ($n = 1; $n <= $count; $n++) {
            if (isset($state['parts'][$n])) {
                continue;
            }
            if ($should_continue && !$should_continue()) {
                throw new TransferCancelledException("Transfer of {$params['Key']} was cancelled");
            }

            $offset = ($n - 1) * $part_size;
            $length = min($part_size, $size - $offset);
            $result = $this->client()->uploadPart($params + [
                'UploadId' => $state['upload_id'],
                'PartNumber' => $n,
                'Body' => $this->part_stream($input_path, $offset, $length),
                'ContentLength' => $length,
            ]);

            $state['parts'][$n] = $result['ETag'];
            $checkpoint && $checkpoint($state);
        }

        if ($should_continue && !$should_continue()) {
            throw new TransferCancelledException("Transfer of {$params['Key']} was cancelled");
        }

        ksort($state['parts']);
        $parts = [];
        foreach ($state['parts'] as $n => $etag) {
            $parts[] = ['PartNumber' => (int) $n, 'ETag' => $etag];
        }

        $this->client()->completeMultipartUpload($params + [
            'UploadId' => $state['upload_id'],
            'MultipartUpload' => ['Parts' => $parts],
        ]);
    }

    private function list_parts(array $params, string $upload_id, int $size, int $part_size): array
    {
        $parts = [];
        $pages = $this->client()->getPaginator('ListParts', $params + ['UploadId' => $upload_id]);
        foreach ($pages as $page) {
            foreach ($page['Parts'] ?? [] as $p) {
                $n = (int) $p['PartNumber'];
                $expected = min($part_size, $size - ($n - 1) * $part_size);
                if ($n >= 1 && (int) $p['Size'] === $expected) {
                    $parts[$n] = $p['ETag'];
                }
            }
        }
        return $parts;
    }

    private function part_stream(string $path, int $offset, int $length): LimitStream
    {
        return new LimitStream(new LazyOpenStream($path, 'r'), $length, $offset);
    }

    public function abort_transfer(string $name, array $state): void
    {
        if (empty($state['upload_id'])) {
            return;
        }
        try {
            $this->client()->abortMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $this->key($name),
                'UploadId' => $state['upload_id'],
            ]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() !== 'NoSuchUpload') {
                throw $e;
            }
        }
    }

    public function list_multipart_uploads(int $before): array
    {
        $prefix = ltrim($this->directory, '/') . '/';
        $uploads = [];
        foreach ($this->client()->getPaginator('ListMultipartUploads', ['Bucket' => $this->bucket]) as $page) {
            foreach ($page['Uploads'] ?? [] as $u) {
                $initiated = $u['Initiated'] instanceof DateTimeInterface ? $u['Initiated']->getTimestamp() : strtotime((string) $u['Initiated']);
                if (str_starts_with(ltrim($u['Key'], '/'), $prefix) && $initiated < $before) {
                    $uploads[] = ['key' => $u['Key'], 'upload_id' => $u['UploadId'], 'initiated' => $initiated];
                }
            }
        }
        return $uploads;
    }

    public function abort_multipart_upload(string $key, string $upload_id): void
    {
        $this->client()->abortMultipartUpload(['Bucket' => $this->bucket, 'Key' => $key, 'UploadId' => $upload_id]);
    }

    public function delete_file(string $name): bool
    {
        $previous = SPOOL->discard_file($name);
        if ($previous !== null && $previous !== Spool::READY) {
            return true;
        }

        try {
            $this->client()->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $this->key($name)
            ]);
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    public function ensure_file(BaseFile &$file): bool
    {
        $name = "{$file->name}.{$file->extension}";
        if ($record = SPOOL->lookup_file($name)) {
            $file->path = "spool://{$record['id']}";
            return true;
        }

        $outage_key = CONFIG['instance']['id'] . ':s3_unreachable';
        if (MEMCACHED && MEMCACHED->get($outage_key)) {
            $file->path = "s3:///$name";
            return true;
        }

        try {
            $result = $this->client()->headObject([
                'Bucket' => $this->bucket,
                'Key' => $this->key($name),
                '@http' => ['connect_timeout' => 2, 'timeout' => 5],
                '@retries' => 1,
            ]);
        } catch (Exception $e) {
            if ($e instanceof AwsException && $e->getStatusCode() === 404) {
                return false;
            }
            error_log("S3 is unreachable, serving $name from the registry: {$e->getMessage()}");
            MEMCACHED?->set($outage_key, 1, 60);
            $file->path = "s3:///$name";
            return true;
        }

        $file->path = $this->file_from_head($name, $result)->path;
        return true;
    }

    public function is_available(): bool
    {
        return !empty($this->config['bucket']) && !empty($this->config['endpoint'])
            && !empty($this->config['access_key']) && !empty($this->config['secret_key']);
    }
}

define('FILESTORAGE', match (CONFIG['storage']['type']) {
    'local' => new LocalFileStorage(CONFIG['storage']['directory'], CONFIG['storage']['prefix']),
    's3' => new S3FileStorage(CONFIG['storage']),
    default => null
});