<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/config.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/partials.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/registry.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/utils.php';

$stats = FILEREGISTRY->get_stats();
if (IS_JSON_REQUEST) {
    send_json_response($stats);
}
?>
<!DOCTYPE html>
<html lang="<?= html_lang() ?>">

<head>
    <?php html_head(__('statistics')); ?>
</head>

<body>
    <header>
        <?php html_header(); ?>
        <h2><?= __('statistics') ?></h2>
        <?php if (isset($stats['last_update'])): ?>
            <p><i><?= __('last updated %s ago', format_timestamp((new DateTime())->setTimestamp($stats['last_update']))) ?></i></p>
        <?php endif; ?>
    </header>
    <main class="statistics">
        <section class="box">
            <div class="tab">
                <h3><?= __('about files & posts') ?></h3>
            </div>
            <div class="content">
                <table class="vertical left">
                    <?php if (isset($stats['serving_posts'])): ?>
                        <tr>
                            <th><?= __('serving posts') ?></th>
                            <td><?= $stats['serving_posts'] ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php if (isset($stats['serving_files'])): ?>
                        <tr>
                            <th><?= __('serving files') ?></th>
                            <td><?= $stats['serving_files'] ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php if (isset($stats['active_content'])): ?>
                        <tr>
                            <th><?= __('active content') ?></th>
                            <td><?= format_filesize($stats['active_content']) ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php if (isset($stats['average_file_size'])): ?>
                        <tr>
                            <th><?= __('average file size') ?></th>
                            <td><?= format_filesize($stats['average_file_size']) ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php if (isset($stats['average_upload_rate'])): ?>
                        <tr>
                            <th><?= __('average upload rate (per minute)') ?></th>
                            <td><?= sprintf("%.4f", $stats['average_upload_rate']) ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php if (isset($stats['serving_future_files'])): ?>
                        <tr>
                            <th><?= __('how many files can be uploaded') ?></th>
                            <td>~<?= $stats['serving_future_files'] ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php if (isset($stats['first_uploaded_at'])): ?>
                        <tr>
                            <th><?= __('first uploaded post') ?></th>
                            <td>
                                <?= __('%s ago (%s)', format_timestamp(new DateTime($stats['first_uploaded_at'])), $stats['first_uploaded_at']) ?>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php if (isset($stats['last_uploaded_at'])): ?>
                        <tr>
                            <th><?= __('last uploaded post') ?></th>
                            <td>
                                <?= __('%s ago (%s)', format_timestamp(new DateTime($stats['last_uploaded_at'])), $stats['last_uploaded_at']) ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>
        </section>

        <?php if (isset($stats['timeline'], CONFIG['driver']['chart'])): ?>
            <section class="box">
                <div class="tab">
                    <h3><?= __('posts uploaded (per quarter)') ?></h3>
                </div>
                <div class="content">
                    <noscript><?= __('JavaScript is required for bar chart') ?></noscript>
                    <canvas id="uploaded-files-chart"></canvas>
                </div>
            </section>
        <?php endif; ?>

        <?php if (isset($stats['most_viewed'])): ?>
            <section class="box">
                <div class="tab">
                    <h3><?= __('the most viewed posts') ?></h3>
                </div>
                <div class="content item-list">
                    <?php foreach ($stats['most_viewed'] as $post): ?>
                        <?php html_file_brick($post) ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>
    <footer>
        <?php html_footer(); ?>
        <?php html_legal(); ?>
        <?php html_motd(); ?>
    </footer>
</body>

<?php if (isset($stats['timeline'], CONFIG['driver']['chart'])): ?>
    <script src="<?= CONFIG['driver']['chart'] ?>"></script>
    <script>
        // TODO: place it in .js file
        new Chart("uploaded-files-chart", {
            type: "bar",
            data: {
                labels: <?= sprintf('[%s]', implode(', ', array_map(
                    fn($x) =>
                    sprintf("'%s-%s'", $x['year'], $x['quarter']),
                    $stats['timeline']
                ))) ?>,
                datasets: [{
                    data: <?= sprintf('[%s]', implode(', ', array_map(
                        fn($x) => $x['file_count'],
                        $stats['timeline']
                    ))) ?> }]
            },
            options: {
                plugins: {
                    legend: {
                        display: false
                    },
                }
            }
        });
    </script>
<?php endif; ?>

</html>