<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/utils.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/alert.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/registry.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/account.php";

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    generate_alert('/', __('Method not allowed'), 405);
}

$params = request_params();
$id = is_string($params['id'] ?? null) ? trim($params['id']) : '';
$key = is_string($params['key'] ?? null) ? trim($params['key']) : '';

if (empty($id)) {
    generate_alert('/', __('Post ID must be set!'), 400);
}

$post = FILEREGISTRY->get_post($id);
if (!$post) {
    generate_alert('/', __('Post does not exist or has been deleted'), 404);
}

$moderator = is_moderator();
$key_valid = !empty($key) && $post->password !== null && password_verify($key, $post->password);

if (!$moderator && !$key_valid) {
    generate_alert('/', __('Incorrect password or this post cannot be edited'), 403);
}

$changed = false;

if (array_key_exists('visibility', $params)) {
    if (!$moderator) {
        generate_alert('/', __('Only moderators can change post visibility'), 403);
    }

    $visibility = is_scalar($params['visibility']) ? filter_var($params['visibility'], FILTER_VALIDATE_INT) : false;
    if ($visibility === false || !Post::is_valid_visibility($visibility)) {
        generate_alert('/', __('Invalid visibility value'), 400);
    }

    $post->visibility = $visibility;
    $changed = true;
}

if (!$changed) {
    generate_alert('/', __('Nothing to edit'), 400);
}

if (!FILEREGISTRY->put_post($post)) {
    generate_alert('/', __('Failed to edit the post. Try again later.'), 500);
}

generate_alert($post->url(), __('Post %s has been edited', $post->id), 200, $post);
