<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/config.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/partials.php';
?>
<!DOCTYPE html>
<html>

<head>
    <?php html_head("favorites"); ?>
</head>

<body>
    <header>
        <?php html_header(); ?>
        <h2>favorites</h2>
        <p>all the files you liked</p>
    </header>
    <main>
        <noscript>JavaScript is required for favorite files.</noscript>
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