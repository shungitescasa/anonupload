<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/partials.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/registry.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/alert.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/account.php";

if (!CONFIG['catalogue']['public'] && ACCOUNT_SERVICE?->get_user() === null) {
    generate_alert('/account/', __('File catalogue is unavailable for anonymous users'), 403);
}

$page = intval($_GET['p'] ?? '1');
if ($page < 1) {
    $page = 1;
}

$sort = $_GET['s'] ?? 'recent';

$visibility = [Post::VISIBILITY_PUBLIC];
if (is_moderator()) {
    if (!empty($_GET['unlisted'])) {
        $visibility[] = Post::VISIBILITY_UNLISTED;
    }
    if (!empty($_GET['pending'])) {
        $visibility[] = Post::VISIBILITY_PENDING;
    }
}

$posts = FILEREGISTRY->get_posts(
    page: $page,
    sort: $sort,
    visibility: $visibility,
    use_cache: true,
    count_pages: true
);
?>
<!DOCTYPE html>
<html lang="<?= html_lang() ?>">

<head>
    <?php html_head(__('page %d/%d', $posts->page, $posts->total_pages) . ' - ' . _n('%d post', '%d posts', $posts->count) . ' - ' . __('the catalogue')); ?>
</head>

<body class="full-size">
    <header>
        <?php html_mini_header($posts); ?>
    </header>
    <main>
        <section class="item-list" id="posts">
            <?php foreach ($posts->posts as $post): ?>
                <?php html_post_brick($post); ?>
            <?php endforeach; ?>
            <?php if (empty($posts->posts)): ?>
                <p><?= __('Nothing found.') ?></p>
            <?php endif; ?>
        </section>
    </main>
    <footer>
        <?php html_footer(); ?>
    </footer>
</body>

</html>