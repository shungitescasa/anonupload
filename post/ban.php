<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/utils.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/alert.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/storage.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/registry.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/account.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    generate_alert('/', 'Method not allowed', 405);
}

if (!is_moderator()) {
    generate_alert('/account/', 'Only moderators can ban posts', 403);
}

$params = request_params();
$id = is_string($params['id'] ?? null) ? trim($params['id']) : '';
$reason = is_string($params['reason'] ?? null) ? str_safe($params['reason'], 255) : '';

if (empty($id)) {
    generate_alert('/', 'Post ID must be set!', 400);
}

$post = FILEREGISTRY->get_post($id);
if (!$post) {
    generate_alert('/', 'Post does not exist or has been deleted', 404);
}

foreach ($post->attachments as $file) {
    if (!FILEREGISTRY->ban_file($file->hash, $reason !== '' ? $reason : null)) {
        generate_alert('/', 'Failed to ban the post files. Try again later.', 500);
    }
}

if (!FILEREGISTRY->delete_post($post)) {
    generate_alert('/', 'Files have been banned, but the post could not be deleted. Try again later.', 500);
}

generate_alert('/', "Post {$post->id} has been banned", 200, $post);
