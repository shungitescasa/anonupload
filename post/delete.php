<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/utils.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/alert.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/storage.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/registry.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/account.php";

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
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

$key_valid = !empty($key) && $post->password !== null && password_verify($key, $post->password);

if (!$key_valid && !is_moderator()) {
    generate_alert('/', __('Incorrect password or this post cannot be deleted'), 403);
}

if (!FILEREGISTRY->delete_post($post)) {
    generate_alert('/', __('Failed to delete the post. Try again later.'), 500);
}

generate_alert('/', __('Post %s has been deleted', $post->id), 200, $post);
