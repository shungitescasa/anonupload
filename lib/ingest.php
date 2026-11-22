<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/vendor/autoload.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/utils.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/spool.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/file.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/id.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/registry.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/account.php";

class UploadOptions
{
    public bool $single_url = false, $strip_exif = false, $preserve_original_name = false;
    public string|null $expires_in = null, $password = null, $description = null;
    // ['id' => username, 'username' => display name]
    public array|null $signer = null;

    public static function from_input(array $input): UploadOptions
    {
        $o = new UploadOptions();
        $o->single_url = boolval($input['single_url'] ?? '0');
        $o->strip_exif = boolval($input['strip_exif'] ?? $input['strip_exif_data'] ?? '0');
        $o->preserve_original_name = boolval($input['preserve_original_name'] ?? $input['preserve_original_filename'] ?? '0');

        $expire_in = is_string($input['expires_in'] ?? null) ? $input['expires_in'] : null;
        if (CONFIG['expiration']['force_default'] || $expire_in === null || !array_key_exists($expire_in, CONFIG['expiration']['time'])) {
            $expire_in = CONFIG['expiration']['default'];
        }
        $o->expires_in = $expire_in;

        $password = $input['password'] ?? null;
        $o->password = is_string($password) && $password !== '' ? $password : null;

        $description = trim(is_string($input['description'] ?? null) ? $input['description'] : '');
        $o->description = $description === '' ? null : $description;

        if (boolval($input['sign_post'] ?? '0')) {
            $signer = ACCOUNT_SERVICE?->get_user();
            if ($signer === null) {
                throw new HTTPException(__('You must be logged in to sign posts'), 401);
            }
            $o->signer = ['id' => $signer->username, 'username' => $signer->name];
        }

        return $o;
    }

    public function to_array(): array
    {
        return get_object_vars($this);
    }

    public static function from_array(array $a): UploadOptions
    {
        $o = new UploadOptions();
        foreach (get_object_vars($o) as $k => $_) {
            if (array_key_exists($k, $a)) {
                $o->$k = $a[$k];
            }
        }
        return $o;
    }
}

function calculate_expiration_date(string|null $expire_in, DateTime $upload_date): DateTime|null
{
    if ($expire_in === null || $expire_in === 'ne') {
        return null;
    }
    if ($expire_in === 're') {
        return clone $upload_date;
    }

    $v = intval(substr($expire_in, 0, strlen($expire_in) - 1));
    $secs = match (substr($expire_in, -1)) {
        'd' => 86400,
        'h' => 3600,
        'm' => 60,
        default => 0
    };

    return (new DateTime())->setTimestamp(time() + $v * $secs);
}

function create_post(UploadOptions $options, DateTime $upload_date, FileTrackStatus|null $status = null): Post
{
    $status?->send('up_genid', __('re-rolling IDs...'));
    $attempts = 0;
    $id_length = CONFIG["id"]["length"];
    $id_prefix = CONFIG["id"]["prefix"] ?? '';
    do {
        $post_id = $id_prefix . IDENTIFIER->generate($id_length);
        if ($attempts > 20) {
            $id_length++;
            $attempts = 0;
        }
        $attempts++;
    } while (FILEREGISTRY->has_post($post_id));

    $description = null;
    if ($options->description !== null) {
        $pdown = new Parsedown();
        $pdown->setSafeMode(true);
        $description = $pdown->text($options->description);
    }

    $p = new Post();
    $p->id = $post_id;
    $p->uploaded_at = $upload_date;
    $p->expires_at = calculate_expiration_date($options->expires_in, $upload_date);
    $p->password = $options->password;
    $p->description = $description;
    $p->uploaded_by = $options->signer;

    if (!FILEREGISTRY->put_post($p)) {
        throw new RuntimeException("Failed to save post");
    }

    return $p;
}

function delete_empty_posts(array &$posts)
{
    $posts = array_values(array_filter(
        $posts,
        function ($post) {
            if (is_array($post)) {
                return true;
            }

            $has_attachments = isset($post->attachments) && !empty($post->attachments);
            if (!$has_attachments) {
                FILEREGISTRY->delete_post($post);
                return false;
            }
            return true;
        }
    ));
}

function enqueue(string $id, UploadOptions $options, Post|null $post = null, FileTrackStatus|null $status = null, int $index = 1, int $count = 1): Post
{
    $record = SPOOL->load($id);
    if ($record === null || $record['state'] !== Spool::UPLOADING) {
        throw new HTTPException(__('Upload not found'), 404);
    }
    $path = SPOOL->data_path($id);
    $created_post = false;
    $name = null;

    $finished = false;
    register_shutdown_function(function () use ($id, &$finished) {
        if (!$finished) {
            SPOOL->reject($id, __('The upload was interrupted'));
        }
    });

    try {
        $status?->send('up_integrity', __('verifying file integrity... (%d/%d)', $index, $count));

        // checking file mimetype
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        if (false === $file_ext = array_search($finfo->file($path), CONFIG["upload"]["acceptedmimetypes"], true)) {
            throw new HTTPException(__('Invalid file format.'));
        }

        // verifying file mimetype
        $file_mime = CONFIG["upload"]["acceptedmimetypes"][$file_ext];
        $is_media = str_starts_with($file_mime, 'image/') || str_starts_with($file_mime, 'video/') || str_starts_with($file_mime, 'audio/');
        if (CONFIG["upload"]["verifymimetype"] && $is_media && !verify_mimetype($path, $file_mime)) {
            throw new HTTPException(__('Invalid file format.'));
        }

        // striping exif data (images only: ImageMagick would decode and re-encode every frame of a video)
        if (CONFIG["upload"]["stripexif"] && $options->strip_exif && str_starts_with($file_mime, 'image/') && !strip_exif($path)) {
            throw new HTTPException(__('Failed to strip EXIF tags.'));
        }

        clearstatcache(true, $path);
        $size = filesize($path);
        $file_hash = hash_file("sha256", $path);
        if (!$file_hash) {
            throw new RuntimeException(__('Failed to calculate file hash'));
        }

        // checking if the file is banned
        if ($ban = FILEREGISTRY->get_file_ban($file_hash)) {
            $message = empty($ban['reason'])
                ? __('This file has been banned')
                : __('This file has been banned: %s', $ban['reason']);
            throw new HTTPException($message, 403);
        }

        if ($post === null) {
            $post = create_post($options, new DateTime(), $status);
            $created_post = true;
        }

        // searching for similar file
        $base_file = FILEREGISTRY->get_file_by_hash($file_hash);
        if (!$base_file) {
            $status?->send('up_save', __('saving...') . " ($index/$count)");

            $snowflake_id = new SnowflakeIdentifier(CONFIG['instance']['machine_id'], CONFIG['instance']['epoch']);

            $base_file = new BaseFile();
            $base_file->name = (string) $snowflake_id->generate();
            $base_file->extension = $file_ext;
            $base_file->mime = $file_mime;
            $base_file->size = $size;
            $base_file->hash = $file_hash;
            $base_file->metadata = FileMetadata::from_file_path($path, $file_mime);
            $base_file->path = "spool://$id";

            $name = "{$base_file->name}.$file_ext";
            SPOOL->link_file($name, $id);
        }

        $client_name = (string) ($record['client_name'] ?? '');
        $base_file->original_filename = $options->preserve_original_name && $client_name !== '' ? str_safe($client_name, null) : null;

        if (!FILEREGISTRY->attach_to_post($post, $base_file)) {
            throw new HTTPException(__('Failed to attach file to the post.'));
        }

        $fields = ['post_id' => $post->id, 'mime' => $file_mime, 'size' => $size, 'hash' => $file_hash, 'options' => null];
        if ($name !== null) {
            SPOOL->set_state($id, Spool::QUEUED, $fields + ['file' => $name, 'queued_at' => time()]);
        } else {
            // the same bytes are stored (or queued) already: this copy is not needed
            SPOOL->set_state($id, Spool::READY, $fields + ['duplicate_of' => "{$base_file->name}.{$base_file->extension}"]);
            @unlink($path);
        }
        $finished = true;

        return $post;
    } catch (Throwable $e) {
        $finished = true;
        SPOOL->reject($id, $e instanceof HTTPException ? $e->getMessage() : __('Failed to process the file'));
        if ($name !== null) {
            SPOOL->unlink_file($name, $id);
        }
        if ($created_post && empty($post->attachments)) {
            FILEREGISTRY->delete_post($post);
        }
        throw $e;
    }
}
