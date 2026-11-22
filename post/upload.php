<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/vendor/autoload.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/utils.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/storage.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/registry.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/spool.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/ingest.php";

header('Cache-Control: no-store');

function api_response(int $code, mixed $data = null, string|null $message = null, int|null $retry_after = null): never
{
    if ($retry_after !== null) {
        header("Retry-After: $retry_after");
    }
    send_json_response($data, $message, $code);
}

function api_json_body(): array
{
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        throw new HTTPException(__('Expected a JSON body (Content-Type: application/json)'), 415);
    }
    $raw = file_get_contents('php://input', false, null, 0, 65536);
    $body = $raw === '' || $raw === false ? [] : json_decode($raw, true);
    if (!is_array($body)) {
        throw new HTTPException(__('Malformed JSON body'), 400);
    }
    return $body;
}

function api_status(array $r): array
{
    $data = [
        'id' => $r['id'],
        'state' => $r['state'],
        'size' => (int) $r['size'],
        'chunk_size' => isset($r['chunk_size']) ? (int) $r['chunk_size'] : null,
        'chunks' => isset($r['chunk_size']) ? Spool::chunk_count((int) $r['size'], (int) $r['chunk_size']) : null,
        'received' => $r['received'] ?? [],
        'received_bytes' => (int) ($r['received_bytes'] ?? 0),
        'error' => $r['error'] ?? null,
        'status_url' => CONFIG['instance']['url'] . "/status?id={$r['id']}",
        'post_url' => isset($r['post_id']) ? CONFIG['instance']['url'] . "/{$r['post_id']}" : null,
    ];
    return $data;
}

function api_post_response(Post $post, array $ids, array $errors, int $code): never
{
    $data = $post->jsonSerialize();
    $data['status_url'] = CONFIG['instance']['url'] . '/status?id=' . implode(',', $ids);
    if (!empty($errors)) {
        $data['errors'] = $errors;
    }
    api_response($code, $data);
}

function api_completed(array $r): never
{
    if (in_array($r['state'], [Spool::ABORTED, Spool::DELETED], true)) {
        api_response(410, api_status($r), __('This upload was cancelled'));
    }
    if ($r['state'] === Spool::REJECTED) {
        api_response(422, api_status($r), $r['error'] ?? __('Invalid file format.'));
    }
    $post = isset($r['post_id']) ? FILEREGISTRY->get_post($r['post_id']) : null;
    if ($post === null) {
        api_response(410, api_status($r), __('Post does not exist or has been deleted'));
    }
    api_post_response($post, [$r['id']], [], 200);
}

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (!preg_match('#^/post/upload(?:/([0-9a-f]{32})(?:/chunk/(\d{1,6})|/(complete))?)?/?$#', $path, $m)) {
    api_response(404, null, __('Not Found'));
}
$id = $m[1] ?? '';
$chunk = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
$action = $chunk !== null ? 'chunk' : (($m[3] ?? '') === 'complete' ? 'complete' : null);
$method = $_SERVER['REQUEST_METHOD'];

try {
    if (!SPOOL->is_available() || !FILESTORAGE?->is_available() || !FILEREGISTRY?->is_available()) {
        throw new SpoolException(__('File upload is temporarily unavailable. Please try again later!'), 503, 60);
    }

    if ($id === '' && $method === 'POST') {
        $body = api_json_body();
        $name = $body['filename'] ?? '';
        $size = $body['size'] ?? null;
        $type = $body['content_type'] ?? '';
        if (!is_string($name) || !is_string($type) || !is_int($size)) {
            throw new HTTPException(__('filename, size and content_type are required'), 400);
        }
        if ($size <= 0) {
            throw new HTTPException(__('No file sent'), 400);
        }
        if ($size > MAX_UPLOAD_BYTES) {
            throw new HTTPException(__('Exceeded filesize limit (%s)', format_filesize(MAX_UPLOAD_BYTES)), 413);
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $types = CONFIG['upload']['acceptedmimetypes'];
        if ($ext !== '' && $type !== '' && !isset($types[$ext]) && !in_array($type, $types, true)) {
            throw new HTTPException(__('Invalid file format.'), 415);
        }

        $options = UploadOptions::from_input($body);
        $record = SPOOL->create([
            'source' => 'chunked',
            'size' => $size,
            'chunk_size' => CHUNK_SIZE,
            'client_name' => mb_substr($name, 0, 255),
            'client_type' => mb_substr($type, 0, 255),
            'options' => $options->to_array(),
            'received' => [],
            'received_bytes' => 0,
        ], $size, $size);

        api_response(201, [
            'id' => $record['id'],
            'chunk_size' => CHUNK_SIZE,
            'max_size' => MAX_UPLOAD_BYTES,
            'chunks' => Spool::chunk_count($size, CHUNK_SIZE),
            'parallel' => max(1, min(2, (int) CONFIG['spool']['client_parallel_chunks'])),
            'status_url' => CONFIG['instance']['url'] . "/status?id={$record['id']}",
        ]);
    }

    if ($id === '') {
        header('Allow: POST');
        api_response(405, null, __('Method not allowed'));
    }

    if ($action === 'chunk') {
        if ($method !== 'PUT') {
            header('Allow: PUT');
            api_response(405, null, __('Method not allowed'));
        }
        $length = isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] !== '' ? (int) $_SERVER['CONTENT_LENGTH'] : null;
        $input = fopen('php://input', 'rb');
        $r = SPOOL->write_chunk($id, $chunk, $input, $length);
        fclose($input);
        api_response(200, [
            'index' => $chunk,
            'received' => count($r['received']),
            'chunks' => Spool::chunk_count((int) $r['size'], (int) $r['chunk_size']),
            'received_bytes' => (int) $r['received_bytes'],
        ]);
    }

    $record = SPOOL->load($id);
    if ($record === null) {
        api_response(404, null, __('Upload not found'));
    }

    if ($action === 'complete') {
        if ($method !== 'POST') {
            header('Allow: POST');
            api_response(405, null, __('Method not allowed'));
        }
        if ($record['state'] !== Spool::UPLOADING) {
            api_completed($record);
        }

        $body = ($_SERVER['CONTENT_LENGTH'] ?? '0') !== '0' && ($_SERVER['CONTENT_LENGTH'] ?? '') !== '' ? api_json_body() : [];
        $attach = $body['attach'] ?? [];
        if (!is_array($attach) || count($attach) >= (int) CONFIG['upload']['max_files_per_request'] || !array_all($attach, [Spool::class, 'is_id'])) {
            throw new HTTPException(__('Exceeded file upload limit'), 413);
        }
        $ids = array_values(array_unique([$id, ...$attach]));

        $locks = [];
        foreach ($ids as $i) {
            if (!($locks[] = SPOOL->try_lock($i))) {
                throw new SpoolException(__('This upload is being completed, please wait'), 503, 5);
            }
        }

        $records = [];
        foreach ($ids as $i) {
            $r = SPOOL->load($i);
            if ($r === null || ($r['source'] ?? null) !== 'chunked') {
                api_response(404, null, __('Upload not found'));
            }
            if ($i === $id && $r['state'] !== Spool::UPLOADING) {
                api_completed($r);
            }
            if ($r['state'] !== Spool::UPLOADING) {
                api_response(409, api_status($r), __('This upload is %s and cannot be completed', $r['state']));
            }
            $count = Spool::chunk_count((int) $r['size'], (int) $r['chunk_size']);
            clearstatcache(true, SPOOL->data_path($i));
            if (count($r['received'] ?? []) !== $count || @filesize(SPOOL->data_path($i)) !== (int) $r['size']) {
                api_response(409, api_status($r), __('Some chunks are missing'));
            }
            $records[] = $r;
        }

        $options = UploadOptions::from_array($record['options'] ?? []);
        $post = count($records) > 1 ? create_post($options, new DateTime()) : null;
        $errors = [];
        $codes = [];
        foreach ($records as $n => $r) {
            try {
                $post = enqueue($r['id'], $options, $post, null, $n + 1, count($records));
            } catch (Exception $e) {
                $codes[] = $e instanceof HTTPException ? $e->getStatusCode() : 400;
                $errors[] = ['original_name' => $r['client_name'] ?? '', 'error' => $e->getMessage()];
            }
        }

        if ($post === null || empty($post->attachments)) {
            if ($post !== null) {
                FILEREGISTRY->delete_post($post);
            }
            api_response(count(array_unique($codes)) === 1 ? $codes[0] : 400, ['errors' => $errors], $errors[0]['error'] ?? __('No posts'));
        }
        api_post_response($post, $ids, $errors, 201);
    }

    if ($method === 'GET') {
        api_response(200, api_status($record));
    }

    if ($method === 'DELETE') {
        if (in_array($record['state'], [Spool::ABORTED, Spool::REJECTED], true)) {
            api_response(200, api_status($record));
        }
        if ($record['state'] !== Spool::UPLOADING) {
            api_response(409, api_status($record), __('This upload is complete; delete the post with its deletion URL'));
        }
        if (!($lock = SPOOL->try_lock($id))) {
            throw new SpoolException(__('This upload is being completed, please wait'), 503, 5);
        }
        $record = SPOOL->set_state($id, Spool::ABORTED);
        @unlink(SPOOL->data_path($id));
        api_response(200, api_status($record));
    }

    header('Allow: GET, DELETE');
    api_response(405, null, __('Method not allowed'));
} catch (SpoolException $e) {
    api_response($e->getStatusCode(), null, $e->getMessage(), $e->retry_after);
} catch (HTTPException $e) {
    api_response($e->getStatusCode(), null, $e->getMessage());
} catch (Throwable $e) {
    error_log("post/upload: {$e->getMessage()} at {$e->getFile()}:{$e->getLine()}");
    api_response(500, null, __('Something went wrong'));
}
