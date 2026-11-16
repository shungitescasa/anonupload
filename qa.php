<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/vendor/autoload.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/alert.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/partials.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/utils.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/alert.php";

$file_path = "{$_SERVER['DOCUMENT_ROOT']}/QA.txt";
if (!file_exists($file_path)) {
    generate_alert('/', __('QA is not set!'), 500);
}

$markdown = new Parsedown();

$data = [
    'content' => $markdown->text(file_get_contents($file_path) ?: __("No questions - no answers.")),
    'last_updated' => (new DateTime())->setTimestamp(filemtime($file_path) ?: 0)
];

if ($data['last_updated']->getTimestamp() === 0)
    $data['last_updated'] = null;
?>
<!DOCTYPE html>
<html lang="<?= html_lang() ?>">

<head>
    <?php html_head(__('question & answer')); ?>
    <style>
        main h1 {
            margin-top: 0.8em;
        }

        main h2 {
            margin-top: 0.5em;
            margin-left: 0.25em;
        }

        main p {
            margin-left: 1.25em;
        }
    </style>
</head>

<body>
    <header>
        <?php html_header(); ?>
        <h2><?= __('question & answer') ?></h2>
        <?php if ($data['last_updated']): ?>
            <p><?= __('last updated: %s', format_date($data['last_updated'])) ?></p>
        <?php endif; ?>
    </header>
    <main>
        <?= $data['content'] ?>
    </main>
    <footer>
        <?php html_footer(); ?>
        <?php html_legal(); ?>
        <?php html_motd(); ?>
    </footer>
</body>

</html>