<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/utils.php";

define('SPOOL_DIR', rtrim((string) CONFIG['spool']['directory'], '/'));
define('MAX_UPLOAD_BYTES', (int) CONFIG['spool']['max_upload_bytes']);
define('MAX_FORM_UPLOAD_BYTES', (int) CONFIG['spool']['max_form_upload_bytes']);
define('CHUNK_SIZE', (int) CONFIG['spool']['chunk_size']);
define('SPOOL_MAX_BYTES', (int) CONFIG['spool']['max_bytes']);
define('MIN_FREE_DISK', (int) CONFIG['spool']['min_free_disk']);
define('STALE_UPLOAD_TTL', (int) CONFIG['spool']['stale_upload_ttl']);
define('FAILED_RETENTION', (int) CONFIG['spool']['failed_retention']);

class SpoolException extends HTTPException
{
    public int|null $retry_after;

    public function __construct(string $message, int $status, int|null $retry_after = null)
    {
        parent::__construct($message, $status);
        $this->retry_after = $retry_after;
    }
}

class Spool
{
    public const UPLOADING = 'uploading';
    public const QUEUED = 'queued';
    public const TRANSFERRING = 'transferring';
    public const READY = 'ready';
    public const FAILED = 'failed';
    public const REJECTED = 'rejected';
    public const ABORTED = 'aborted';
    public const DELETED = 'deleted';

    public const SPOOLED_STATES = [self::QUEUED, self::TRANSFERRING, self::FAILED];

    private array $spooled_cache = [];

    public function __construct(public readonly string $dir)
    {
    }

    public static function is_id(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[0-9a-f]{32}$/', $id) === 1;
    }

    public static function is_file_name(mixed $name): bool
    {
        return is_string($name) && preg_match('/^[0-9A-Za-z]{1,64}\.[0-9A-Za-z]{1,16}$/', $name) === 1;
    }

    public function data_path(string $id): string
    {
        return "{$this->dir}/data/$id";
    }

    public function tmp_dir(): string
    {
        return "{$this->dir}/tmp";
    }

    private function state_path(string $id): string
    {
        return "{$this->dir}/state/$id.json";
    }

    private function lock_path(string $id): string
    {
        return "{$this->dir}/state/$id.lock";
    }

    private function index_path(string $name): string
    {
        return "{$this->dir}/files/$name";
    }

    public function is_available(): bool
    {
        foreach (['data' => 0750, 'state' => 0700, 'files' => 0700, 'tmp' => 0700] as $d => $mode) {
            $path = "{$this->dir}/$d";
            if (!is_dir($path) && !@mkdir($path, $mode, true)) {
                return false;
            }
            if (!is_writable($path)) {
                return false;
            }
        }
        return true;
    }

    public function load(string $id): array|null
    {
        if (!self::is_id($id)) {
            return null;
        }
        $json = @file_get_contents($this->state_path($id));
        if ($json === false) {
            return null;
        }
        $record = json_decode($json, true);
        return is_array($record) ? $record : null;
    }

    public function records(): array
    {
        $records = [];
        foreach (glob("{$this->dir}/state/*.json") ?: [] as $path) {
            if ($r = $this->load(basename($path, '.json'))) {
                $records[$r['id']] = $r;
            }
        }
        return $records;
    }

    public function create(array $fields, int $reserve, int $disk_bytes): array
    {
        return $this->with_lock("{$this->dir}/spool.lock", function () use ($fields, $reserve, $disk_bytes) {
            $usage = $this->usage();
            if (($fields['source'] ?? null) === 'chunked' && $usage['active'] >= (int) CONFIG['spool']['max_active_uploads']) {
                throw new SpoolException(__('Too many uploads are in progress. Please try again later.'), 503, 60);
            }
            $this->check_capacity($reserve, $disk_bytes, $usage['reserved']);

            $now = time();
            $record = array_merge($fields, [
                'id' => bin2hex(random_bytes(16)),
                'state' => self::UPLOADING,
                'created_at' => $now,
                'updated_at' => $now,
                'config' => CONFIG_FILE_PATH,
                'instance' => CONFIG['instance']['id'],
            ]);
            $this->write($record);
            return $record;
        });
    }

    public function update(string $id, callable $fn): array|null
    {
        if (!self::is_id($id)) {
            return null;
        }
        return $this->with_lock($this->lock_path($id), function () use ($id, $fn) {
            $record = $this->load($id);
            if ($record === null) {
                return null;
            }
            $new = $fn($record);
            if (is_array($new)) {
                $this->write($new);
                return $new;
            }
            return $record;
        });
    }

    public function set_state(string $id, string $state, array $fields = []): array|null
    {
        return $this->update($id, function (array $r) use ($state, $fields) {
            $r = array_merge($r, $fields);
            $r['state'] = $state;
            if (in_array($state, [self::READY, self::FAILED, self::REJECTED, self::ABORTED, self::DELETED], true)) {
                $r['finished_at'] = time();
            }
            return $r;
        });
    }

    public function reject(string $id, string $message): void
    {
        $record = $this->set_state($id, self::REJECTED, ['error' => $message]);
        @unlink($this->data_path($id));
        if (isset($record['file'])) {
            $this->unlink_file($record['file'], $id);
        }
    }

    public function remove(string $id): void
    {
        if (!self::is_id($id)) {
            return;
        }
        $record = $this->load($id);
        @unlink($this->data_path($id));
        if (isset($record['file'])) {
            $this->unlink_file($record['file'], $id);
        }
        @unlink($this->state_path($id));
        @unlink($this->lock_path($id));
        @unlink("{$this->dir}/state/$id.busy");
    }

    public function try_lock(string $id)
    {
        if (!self::is_id($id)) {
            return null;
        }
        $h = fopen("{$this->dir}/state/$id.busy", 'c');
        if ($h === false || !flock($h, LOCK_EX | LOCK_NB)) {
            $h && fclose($h);
            return null;
        }
        return $h;
    }

    private function write(array $record): void
    {
        $path = $this->state_path($record['id']);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $record['updated_at'] = time();
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json) !== strlen($json)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to write upload state: $tmp");
        }
        chmod($tmp, 0600);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to replace upload state: $path");
        }
    }

    private function with_lock(string $path, callable $fn): mixed
    {
        $h = fopen($path, 'c');
        if ($h === false) {
            throw new RuntimeException("Failed to open lock file: $path");
        }
        try {
            if (!flock($h, LOCK_EX)) {
                throw new RuntimeException("Failed to lock: $path");
            }
            return $fn();
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    public function usage(): array
    {
        $now = time();
        $reserved = $actual = $active = 0;
        foreach ($this->records() as $r) {
            $size = (int) ($r['size'] ?? 0);
            switch ($r['state']) {
                case self::UPLOADING:
                    $received = (int) ($r['received_bytes'] ?? 0);
                    $actual += $received;
                    $active++;
                    $idle = $now - (int) ($r['updated_at'] ?? 0);
                    $reserved += $idle < (int) CONFIG['spool']['reservation_ttl'] ? max($size, $received) : $received;
                    break;
                case self::QUEUED:
                case self::TRANSFERRING:
                case self::FAILED:
                    $actual += $size;
                    $reserved += $size;
                    break;
            }
        }
        return ['reserved' => $reserved, 'actual' => $actual, 'active' => $active];
    }

    public function check_capacity(int $quota_bytes, int $disk_bytes, int|null $used = null, bool $new_upload = true): void
    {
        $status = $new_upload ? 507 : 503;
        $used ??= $this->usage()[$new_upload ? 'reserved' : 'actual'];
        if ($used + $quota_bytes > SPOOL_MAX_BYTES) {
            throw new SpoolException(__('The server is busy storing other uploads. Please try again later.'), $status, 300);
        }
        $free = @disk_free_space($this->dir);
        if ($free !== false && $free - $disk_bytes < MIN_FREE_DISK) {
            throw new SpoolException(__('The server is running out of disk space. Please try again later.'), $status, 300);
        }
    }

    public static function chunk_count(int $size, int $chunk_size): int
    {
        return intdiv($size + $chunk_size - 1, $chunk_size);
    }

    public function write_chunk(string $id, int $index, $input, int|null $declared_length): array
    {
        $record = $this->load($id);
        if ($record === null || ($record['source'] ?? null) !== 'chunked') {
            throw new SpoolException(__('Upload not found'), 404);
        }
        if ($record['state'] !== self::UPLOADING) {
            throw new SpoolException(__('This upload is %s and does not accept chunks', $record['state']), 409);
        }

        $chunk_size = (int) $record['chunk_size'];
        $count = self::chunk_count((int) $record['size'], $chunk_size);
        if ($index < 0 || $index >= $count) {
            throw new SpoolException(__('Chunk %d is out of range (0-%d)', $index, $count - 1), 400);
        }
        $expected = $index === $count - 1 ? (int) $record['size'] - $index * $chunk_size : $chunk_size;
        if ($declared_length !== null && $declared_length !== $expected) {
            throw new SpoolException(__('Chunk %d must be %d bytes, got %d', $index, $expected, $declared_length), $declared_length > $expected ? 413 : 400);
        }

        $is_new = !in_array($index, $record['received'] ?? [], true);
        $this->check_capacity($is_new ? $expected : 0, $expected, null, false);

        $path = $this->data_path($id);
        $out = fopen($path, 'c+b');
        if ($out === false) {
            throw new RuntimeException("Failed to open $path");
        }
        @chmod($path, 0640);
        try {
            if (fseek($out, $index * $chunk_size) !== 0) {
                throw new RuntimeException("Failed to seek in $path");
            }
            // at most one byte more than expected, to detect oversized bodies without reading them
            $written = stream_copy_to_stream($input, $out, $expected + 1);
            if ($written === false || !fflush($out)) {
                throw new RuntimeException("Failed to write chunk $index of $id");
            }
        } finally {
            fclose($out);
        }

        if ($written !== $expected) {
            throw new SpoolException(__('Chunk %d must be %d bytes, got %d', $index, $expected, $written), $written > $expected ? 413 : 400);
        }

        $updated = $this->update($id, function (array $r) use ($index, $expected) {
            if ($r['state'] !== self::UPLOADING) {
                return null;
            }
            $received = $r['received'] ?? [];
            if (!in_array($index, $received, true)) {
                $received[] = $index;
                sort($received);
                $r['received'] = $received;
                $r['received_bytes'] = (int) ($r['received_bytes'] ?? 0) + $expected;
            }
            return $r;
        });
        if ($updated === null || $updated['state'] !== self::UPLOADING) {
            throw new SpoolException(__('This upload was cancelled'), 409);
        }
        return $updated;
    }

    public function link_file(string $name, string $id): void
    {
        $path = $this->index_path($name);
        $tmp = "$path." . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $id) !== strlen($id) || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to write spool index: $path");
        }
        unset($this->spooled_cache[$name]);
    }

    public function unlink_file(string $name, string $id): void
    {
        if (!self::is_file_name($name)) {
            return;
        }
        $path = $this->index_path($name);
        if (trim((string) @file_get_contents($path)) === $id) {
            @unlink($path);
        }
        unset($this->spooled_cache[$name]);
    }

    public function lookup_file(string $name): array|null
    {
        if (!self::is_file_name($name)) {
            return null;
        }
        $id = trim((string) @file_get_contents($this->index_path($name)));
        $record = $id !== '' ? $this->load($id) : null;
        if ($record === null || !in_array($record['state'], self::SPOOLED_STATES, true) || !is_file($this->data_path($id))) {
            return null;
        }
        return $record;
    }

    public function is_spooled(string $name): bool
    {
        if (!self::is_file_name($name)) {
            return false;
        }
        return $this->spooled_cache[$name] ??= is_file($this->index_path($name));
    }

    public function discard_file(string $name): string|null
    {
        if (!self::is_file_name($name)) {
            return null;
        }
        $id = trim((string) @file_get_contents($this->index_path($name)));
        if (!self::is_id($id)) {
            return null;
        }
        $previous = null;
        $this->update($id, function (array $r) use (&$previous) {
            $previous = $r['state'];
            if (!in_array($previous, self::SPOOLED_STATES, true)) {
                return null;
            }
            $r['state'] = self::DELETED;
            $r['finished_at'] = time();
            return $r;
        });
        if ($previous !== null && $previous !== self::READY) {
            @unlink($this->data_path($id));
        }
        $this->unlink_file($name, $id);
        return $previous;
    }
}

define('SPOOL', new Spool(SPOOL_DIR));
