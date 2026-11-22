<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/utils.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/spool.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/file.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/id.php";

$name = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
if (!Spool::is_file_name($name) && is_string($_GET['f'] ?? null)) {
    $name = $_GET['f'];
}
if (!Spool::is_file_name($name)) {
    http_response_code(404);
    die();
}

$accel = rtrim((string) CONFIG['spool']['accel_location'], '/');
$record = $accel !== '' ? SPOOL->lookup_file($name) : null;

if ($record) {
    $mime = $record['mime'] ?? 'application/octet-stream';
    $active = in_array($mime, ['image/svg+xml', 'application/xml', 'text/xml', 'text/xsl', 'application/xspf+xml', 'application/x-shockwave-flash'], true);

    header("Content-Type: $mime");
    header(($active ? 'Content-Disposition: attachment' : 'Content-Disposition: inline') . "; filename=\"$name\"");
    header('Cache-Control: private, max-age=60');
    header("X-Accel-Redirect: $accel/{$record['id']}");
    die();
}

$split = new SplitFilename($name);
$file = new BaseFile();
$file->name = $split->name;
$file->extension = (string) $split->extension;
$url = $file->storage_url();

if ($url === null) {
    http_response_code(404);
    die();
}

header('Cache-Control: no-store');
header("Location: $url", true, 302);
