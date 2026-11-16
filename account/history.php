<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/config.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/partials.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/account.php';
?>
<!DOCTYPE html>
<html lang="<?= html_lang() ?>">

<head>
    <?php html_head(__('file upload history')); ?>
</head>

<body>
    <header>
        <?php html_header(); ?>
        <h2><?= __('file upload history') ?></h2>
        <?php if (ACCOUNT_SERVICE?->get_user() === null): ?>
            <p><?= __('every file you uploaded from this device is stored in local storage') ?></p>
        <?php else: ?>
            <p><?= __('every file you uploaded is synchronized between devices') ?></p>
        <?php endif; ?>
    </header>
    <main>
        <noscript><?= __('JavaScript is required for file upload history.') ?></noscript>
        <section class="item-list" id="file-history"></section>
    </main>
    <footer>
        <?php html_footer(); ?>
        <?php html_legal(); ?>
        <?php html_motd(); ?>
    </footer>
</body>

<script src="/static/scripts/file.js"></script>
<script src="/static/scripts/pages/history.js"></script>

</html>