<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/partials.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/registry.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/alert.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/account.php";

if (!CONFIG['catalogue']['public'] && ACCOUNT_SERVICE?->get_user() === null) {
    generate_alert('/account/', 'File catalogue is unavailable for anonymous users', 403);
}

$page = intval($_GET['p'] ?? '1');
if ($page < 1) {
    $page = 1;
}

$sort = $_GET['s'] ?? 'recent';

$posts = FILEREGISTRY->get_posts(
    page: $page,
    sort: $sort,
    visibility: Post::VISIBILITY_PUBLIC,
    use_cache: true,
    count_pages: true
);
?>
<!DOCTYPE html>
<html>

<head>
    <?php html_head("page {$posts->page}/{$posts->total_pages} - {$posts->count} posts - the catalogue"); ?>
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
                <p>Nothing found.</p>
            <?php endif; ?>
        </section>
    </main>
    <footer>
        <?php html_footer(); ?>
    </footer>
</body>

</html>