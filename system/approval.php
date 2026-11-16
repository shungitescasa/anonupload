<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/utils.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/alert.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/partials.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/registry.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/account.php";

if (!is_moderator()) {
    generate_alert('/account/', __('Only moderators can access this page'), 403);
}

$posts = FILEREGISTRY->get_posts(
    page: 1,
    sort: 'oldest',
    visibility: Post::VISIBILITY_PENDING,
    use_cache: false,
    count_pages: true
);

// choosing the post to review: either the requested one or the first one in the queue
$post = null;
$next_post = null;
$selected_id = is_string($_GET['id'] ?? null) ? trim($_GET['id']) : null;

foreach ($posts->posts as $i => $p) {
    if ($selected_id === null || $p->id === $selected_id) {
        $post = $p;
        $next_post = $posts->posts[$i + 1] ?? $posts->posts[$i - 1] ?? null;
        break;
    }
}

// the requested post is not in the queue
if (!$post && $selected_id !== null) {
    $post = FILEREGISTRY->get_post($selected_id);
    $next_post = $posts->posts[0] ?? null;
}

$next_url = '/system/approval' . ($next_post ? '?id=' . urlencode($next_post->id) : '');
$single_attachment = $post?->single_attachment();
?>
<!DOCTYPE html>
<html lang="<?= html_lang() ?>">

<head>
    <?php html_head('MAS - ' . _n('%d pending post', '%d pending posts', $posts->count)); ?>
</head>

<body class="full-size approval">
    <main class="approval-main">
        <section class="approval-content">
            <?php if ($post): ?>
                <section class="post-preview">
                    <section class="feed" feed-id="<?= $post->id ?>" feed-url="<?= $post->url() ?>">
                        <?php if (!$single_attachment): ?>
                            <button class="feed-controller" data-action="back" disabled></button>
                        <?php endif; ?>

                        <div class="file-attachments">
                            <?php foreach ($post->attachments as $file): ?>
                                <div class="preview">
                                    <?php html_file_full($file); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (!$single_attachment): ?>
                            <button class="feed-controller" data-action="next" disabled></button>
                        <?php endif; ?>
                    </section>

                    <section class="feed-metadata">
                        <section class="file-metadata">
                            <div class="left">
                                <div class="icon">
                                    <?= html_mini_icon(); ?>
                                </div>
                                <div class="metadata">
                                    <p>
                                        <a href="<?= $post->url() ?>" target="_blank">
                                            <?php if ($single_attachment): ?>
                                                <?= $post->id ?>.<?= $single_attachment->extension ?>
                                            <?php else: ?>
                                                <?= $post->id ?>
                                                (<?= _n('%d attachment', '%d attachments', count($post->attachments)) ?>)
                                            <?php endif; ?>
                                        </a>

                                        (<?= $single_attachment->mime ?>)
                                    </p>
                                    <ul class="horizontal bulletted">
                                        <?php if (isset($post->uploaded_at)): ?>
                                            <li><?= __('uploaded %s ago', format_timestamp($post->uploaded_at)) ?></li>
                                        <?php endif; ?>
                                        <?php if (isset($post->expires_at)): ?>
                                            <li><?= __('expires in %s', format_timestamp($post->expires_at)) ?></li>
                                        <?php endif; ?>
                                        <?php if (isset($post->views)): ?>
                                            <li><?= _n('%d view', '%d views', $post->views) ?></li>
                                        <?php endif; ?>
                                        <li>
                                            <?= match ($post->visibility) {
                                                Post::VISIBILITY_PUBLIC => __('public'),
                                                Post::VISIBILITY_UNLISTED => __('unlisted'),
                                                default => __('pending approval')
                                            } ?>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            <div class="right approval-controls" id="approval-controls" data-post-id="<?= $post->id ?>"
                                data-next-url="<?= htmlspecialchars($next_url) ?>">
                                <div class="buttons">
                                    <button type="button" data-action="delete"><?= __('delete') ?></button>
                                    <button type="button" data-action="ban"><?= __('ban') ?></button>
                                    <button type="button" data-action="visibility"
                                        data-visibility="<?= Post::VISIBILITY_UNLISTED ?>"><?= __('unlisted') ?></button>
                                    <button type="button" data-action="visibility"
                                        data-visibility="<?= Post::VISIBILITY_PUBLIC ?>"><?= __('public') ?></button>
                                </div>
                                <p class="approval-status" id="approval-status"></p>
                            </div>
                        </section>

                        <?php if (isset($post->description)): ?>
                            <section class="feed-description">
                                <?= $post->description ?>
                            </section>
                        <?php endif; ?>
                    </section>
                </section>
            <?php else: ?>
                <section class="approval-empty">
                    <p><?= __('Nothing to approve.') ?></p>
                </section>
            <?php endif; ?>
        </section>

        <aside class="approval-sidebar">
            <p class="approval-count">
                <?= _n('%d pending post', '%d pending posts', $posts->count) ?>
                <?php if ($posts->count > count($posts->posts)): ?>
                    (<?= __('showing first %d', count($posts->posts)) ?>)
                <?php endif; ?>
            </p>
            <?php foreach ($posts->posts as $p): ?>
                <?php html_post_brick($p, '/system/approval?id=' . urlencode($p->id), $p->id === $post?->id); ?>
            <?php endforeach; ?>
        </aside>
    </main>
</body>

<?php if ($post): ?>
    <script src="/static/scripts/player.js"></script>
    <script src="/static/scripts/pages/approval.js"></script>

    <?php if ($post->is_flash()): ?>
        <script src="<?= CONFIG['driver']['ruffle'] ?>"></script>
    <?php endif; ?>
<?php endif; ?>

</html>