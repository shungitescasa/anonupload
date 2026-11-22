<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/utils.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/partials.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/spool.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/alert.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
unset($_SESSION['alert']);

$ids = array_slice(array_filter(explode(',', is_string($_GET['id'] ?? null) ? $_GET['id'] : ''), [Spool::class, 'is_id']), 0, (int) CONFIG['upload']['max_files_per_request']);
$records = array_values(array_filter(array_map(fn($id) => SPOOL->load($id), $ids)));

if (empty($records)) {
    $error = '404 ' . __('Upload not found');
    $error_redirect = true;
    include "{$_SERVER['DOCUMENT_ROOT']}/lib/pages/error.php";
    die();
}

header('Cache-Control: no-store');

if (IS_JSON_REQUEST) {
    send_json_response(array_map(fn($r) => [
        'id' => $r['id'],
        'state' => $r['state'],
        'size' => (int) $r['size'],
        'post_url' => isset($r['post_id']) ? CONFIG['instance']['url'] . "/{$r['post_id']}" : null,
        'error' => $r['error'] ?? null,
    ], $records));
}

$pending = false;
foreach ($records as $r) {
    $pending = $pending || in_array($r['state'], [Spool::UPLOADING, Spool::QUEUED, Spool::TRANSFERRING], true);
}

function status_text(array $r): string
{
    $t = $r['transfer'] ?? [];
    switch ($r['state']) {
        case Spool::UPLOADING:
            if (isset($r['chunk_size'])) {
                return __('uploading: %d of %d parts received', count($r['received'] ?? []), Spool::chunk_count((int) $r['size'], (int) $r['chunk_size']));
            }
            return __('processing...');
        case Spool::QUEUED:
            if (!empty($t['last_error'])) {
                return __('waiting for the storage (attempt %d failed, retrying in %s)', $t['attempts'] ?? 1, format_timestamp((new DateTime())->setTimestamp(max(time() + 1, (int) ($t['next_attempt_at'] ?? 0)))));
            }
            return __('waiting to be moved to the storage');
        case Spool::TRANSFERRING:
            $done = min((int) $r['size'], count($t['parts'] ?? []) * (int) ($t['part_size'] ?? 0));
            return __('moving to the storage: %d%%', $r['size'] > 0 ? intdiv($done * 100, (int) $r['size']) : 0);
        case Spool::READY:
            return __('stored');
        case Spool::FAILED:
            return __('could not be moved to the storage; the file is kept on this server for now');
        case Spool::REJECTED:
            return __('rejected: %s', $r['error'] ?? __('Unknown errors'));
        case Spool::ABORTED:
            return __('cancelled');
        case Spool::DELETED:
            return __('deleted');
    }
    return $r['state'];
}
?>
<!DOCTYPE html>
<html lang="<?= html_lang() ?>">

<head>
    <?php html_head(__('upload status')); ?>
    <?php if ($pending): ?>
        <meta http-equiv="refresh" content="5">
    <?php endif; ?>
</head>

<body>
    <header>
        <?php html_header(); ?>
        <h2><?= __('upload status') ?></h2>
        <?php if ($pending): ?>
            <p><?= __('Your links work already. This page refreshes every few seconds until everything is stored.') ?></p>
        <?php endif; ?>
    </header>
    <main>
        <?php foreach ($records as $r): ?>
            <section class="box upload-status" data-state="<?= htmlspecialchars($r['state']) ?>">
                <div class="tab">
                    <h3><?= htmlspecialchars($r['client_name'] ?? '') ?: htmlspecialchars($r['file'] ?? $r['id']) ?></h3>
                </div>
                <div class="content">
                    <table class="vertical left">
                        <tr>
                            <th><?= __('status') ?></th>
                            <td><?= htmlspecialchars(status_text($r)) ?></td>
                        </tr>
                        <tr>
                            <th><?= __('size') ?></th>
                            <td><?= format_filesize((int) $r['size']) ?></td>
                        </tr>
                        <?php if (isset($r['post_id'])): ?>
                            <tr>
                                <th><?= __('link') ?></th>
                                <td><a
                                        href="/<?= htmlspecialchars($r['post_id']) ?>"><?= htmlspecialchars(CONFIG['instance']['url'] . "/{$r['post_id']}") ?></a>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php if (isset($r['file']) && in_array($r['state'], Spool::SPOOLED_STATES, true)): ?>
                            <tr>
                                <th><?= __('download') ?></th>
                                <td><a href="/raw/<?= htmlspecialchars($r['file']) ?>"><?= htmlspecialchars($r['file']) ?></a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </section>
        <?php endforeach; ?>
        <p><a href="/"><?= __('upload more files') ?></a></p>
    </main>
    <footer>
        <?php html_footer(); ?>
    </footer>
</body>

</html>