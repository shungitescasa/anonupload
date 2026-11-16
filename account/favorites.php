<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/config.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/partials.php';
?>
<!DOCTYPE html>
<html lang="<?= html_lang() ?>">

<head>
    <?php html_head(__('favorites')); ?>
</head>

<body>
    <header>
        <?php html_header(); ?>
        <h2><?= __('favorites') ?></h2>
        <p><?= __('all the files you liked') ?></p>
    </header>
    <main>
        <noscript><?= __('JavaScript is required for favorite files.') ?></noscript>
        <section class="item-list" id="favorite-files"></section>
    </main>
    <footer>
        <?php html_footer(); ?>
        <?php html_legal(); ?>
        <?php html_motd(); ?>
    </footer>
</body>

<script src="/static/scripts/file.js"></script>
<script src="/static/scripts/favorites.js"></script>
<script src="/static/scripts/pages/favorites.js"></script>

</html>