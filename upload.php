<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/vendor/autoload.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/utils.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/alert.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/storage.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/registry.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/id.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/file.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/account.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/spool.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/ingest.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    generate_alert(
        '/',
        __('Method not allowed'),
        405,
        null
    );
    exit;
}

$status = new FileTrackStatus(
    isset($_POST['track_id']) && !empty(trim($_POST['track_id'])) ?
    trim($_POST['track_id'])
    : "0"
);

$uploaded_posts = [];
$spool_ids = [];

try {
    if (!FILESTORAGE->is_available() || !FILEREGISTRY->is_available() || !SPOOL->is_available()) {
        throw new HTTPException(__('File upload is temporarily unavailable. Please try again later!'), 500);
    }

    // PHP drops the whole body if it is bigger than post_max_size
    if (empty($_FILES) && empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        throw new HTTPException(__('Exceeded filesize limit'), 413);
    }

    $files = [];

    $status->send('up_prep', __('preparing...'));

    if (isset($_FILES['file'])) {
        $f = $_FILES['file'];
        // multiple files (rearraging the array)
        if (is_array($f['tmp_name'])) {
            $c = count($f['tmp_name']);
            $ks = array_keys($f);
            for ($i = 0; $i < $c; $i++) {
                $file = [];
                foreach ($ks as $k) {
                    $file[$k] = $f[$k][$i];
                }
                array_push($files, $file);
            }
        } else {
            array_push($files, $f);
        }
    }
    // -- load file from base64
    else if (isset($_POST['base64']) && is_string($_POST['base64'])) {
        $parts = explode(',', $_POST['base64'], 2);
        $meta = $parts[0];
        if (!str_starts_with($meta, 'data:image/') || !str_ends_with($meta, ';base64')) {
            throw new HTTPException(__('Invalid base64 data'));
        }

        $tmp = tempnam(SPOOL->tmp_dir(), 'b64');
        if ($tmp === false || file_put_contents($tmp, base64_decode($parts[1] ?? '')) === false) {
            throw new RuntimeException(__('Failed to save file. Try again later.'));
        }

        array_push($files, [
            'tmp_name' => $tmp,
            'name' => '',
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp),
            'base64' => true
        ]);
    } else {
        throw new HTTPException(__('No files to upload'));
    }

    $file_count = count($files);

    if ($file_count > CONFIG['upload']['max_files_per_request']) {
        throw new HTTPException(__('Exceeded file upload limit'), 413);
    } else if ($file_count === 0) {
        throw new HTTPException(__('No files to upload'));
    }

    $options = UploadOptions::from_input($_POST);

    $root_post = $options->single_url ? create_post($options, new DateTime(), $status) : null;
    $uploaded_posts = $options->single_url ? [$root_post] : [];
    $error_codes = [];

    for ($i = 0; $i < $file_count; $i++) {
        $file = $files[$i];
        $ic = $i + 1;

        try {
            if (
                !isset($file['error']) ||
                is_array($file['error'])
            ) {
                throw new HTTPException(__('Invalid parameters'));
            }

            // checking file error
            switch ($file['error']) {
                case UPLOAD_ERR_OK:
                    break;
                case UPLOAD_ERR_NO_FILE:
                    throw new HTTPException(__('No file sent'));
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    throw new HTTPException(__('Exceeded filesize limit'), 413);
                default:
                    throw new HTTPException(__('Unknown errors'));
            }

            $size = (int) $file['size'];
            if ($size > MAX_FORM_UPLOAD_BYTES) {
                throw new HTTPException(__('Exceeded filesize limit'), 413);
            }

            // into the spool. upload_tmp_dir is on the spool's filesystem, so this is a rename, not a copy
            $record = SPOOL->create([
                'source' => 'form',
                'size' => $size,
                'client_name' => mb_substr((string) ($file['name'] ?? ''), 0, 255),
                'client_type' => (string) ($file['type'] ?? ''),
            ], $size, 0);
            $spool_ids[] = $record['id'];

            $dst = SPOOL->data_path($record['id']);
            $moved = empty($file['base64']) ? move_uploaded_file($file['tmp_name'], $dst) : rename($file['tmp_name'], $dst);
            if (!$moved) {
                SPOOL->reject($record['id'], __('Failed to save file. Try again later.'));
                throw new RuntimeException(__('Failed to save file. Try again later.'));
            }
            chmod($dst, 0640);

            $post = enqueue($record['id'], $options, $root_post, $status, $ic, $file_count);

            if (CONFIG['views']['enabled']) {
                $viewed_posts = $_SESSION['viewed_posts'] ?? [];
                if (!in_array($post->name(), $viewed_posts)) {
                    array_push($viewed_posts, $post->name());
                }
                $_SESSION['viewed_posts'] = $viewed_posts;
            }

            if (!$options->single_url) {
                array_push($uploaded_posts, $post);
            }
        } catch (Exception $e) {
            if (!empty($file['base64'])) {
                @unlink($file['tmp_name']);
            }
            $error_codes[] = $e instanceof HTTPException ? $e->getStatusCode() : 400;
            if ($e instanceof SpoolException && $e->retry_after) {
                header("Retry-After: {$e->retry_after}");
            }
            array_push($uploaded_posts, [
                'original_name' => $file['name'] ?? '',
                'error' => $e->getMessage()
            ]);
        }
    }

    // there was thumbnail generator
    // now thumbnails are generated by bin/transfer-worker.php once the files are in the storage

    delete_empty_posts($uploaded_posts);

    if (empty($uploaded_posts)) {
        throw new HTTPException(__('No posts'));
    }

    if (isset($_POST['save_upload_list']) && boolval($_POST['save_upload_list'])) {
        $_SESSION['recently_uploaded_files'] = $uploaded_posts;
    }

    $bad_status_count = 0;
    foreach ($uploaded_posts as $x) {
        if (is_array($x) && isset($x['error']))
            $bad_status_count++;
    }

    $upload_fail = $bad_status_count === count($uploaded_posts);

    if ($upload_fail) {
        $status->send('error', null);
    } else {
        $status->send('success', null);
    }

    // a single failed file keeps its own status code
    $code = $upload_fail ? (count(array_unique($error_codes)) === 1 ? $error_codes[0] : 400) : 201;

    generate_alert(
        // browsers go to the status page, which also shows the errors; if nothing reached the spool,
        // the home page shows them
        empty($spool_ids) ? '/' : '/status?id=' . implode(',', $spool_ids),
        $upload_fail ? implode('; ', array_unique(array_column($uploaded_posts, 'error'))) : null,
        $code,
        match (true) {
            count($uploaded_posts) == 1 => $uploaded_posts[0],
            default => $uploaded_posts
        }
    );
} catch (HTTPException $e) {
    $status->send('error', $e->getMessage());
    delete_empty_posts($uploaded_posts);
    if ($e instanceof SpoolException && $e->retry_after) {
        header("Retry-After: {$e->retry_after}");
    }
    generate_alert('/', $e->getMessage(), $e->getStatusCode());
} catch (Exception $e) {
    delete_empty_posts($uploaded_posts);
    generate_alert('/', $e->getMessage(), 400);
}
