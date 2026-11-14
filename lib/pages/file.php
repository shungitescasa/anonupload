<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/config.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/partials.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/utils.php';

if (IS_JSON_REQUEST) {
    send_json_response($post);
}

$single_attachment = $post->single_attachment();

$description = null;
if ($single_attachment) {
    $description = "{$single_attachment->mime} - {$single_attachment->extension}";

    if (isset($single_attachment->metadata)) {
        $m = $single_attachment->metadata;
        if (isset($m->width, $m->height)) {
            $description .= " - {$m->width}x{$m->height}";
        }

        if (isset($m->duration)) {
            $dur = format_timestamp((new DateTime())->setTimestamp(time() + $m->duration));
            $description .= " - $dur";
        }

        if (isset($m->line_count)) {
            $description .= " - {$m->line_count} lines";
        }
    }
} else {
    $description = "Post with " . count($post->attachments) . " attachments";
}

if (isset($post->views) && $post->views >= CONFIG['views']['minimal_display_count']) {
    $description .= " - {$post->views} views";
}

if (isset($post->uploaded_at)) {
    $description .= " - uploaded " . format_timestamp($post->uploaded_at) . " ago";
}

?>
<!DOCTYPE html>
<html>

<head>
    <?php html_head($post->name(), $description, $post); ?>
</head>

<body>
    <section class="post-preview">
        <section class="disclaimer">
            <p>
                All trademarks and copyrights belong to their respective owners.
                The uploader is responsible for any content shared here.
            </p>
            <?php html_legal(); ?>
        </section>

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
                            <?php if ($single_attachment): ?>
                                <span id="post-id"><?= $post->id ?></span>.<span
                                    id="file-extension"><?= $single_attachment->extension ?></span>
                                (<span id="file-mime"><?= $single_attachment->mime ?></span>)
                            <?php else: ?>
                                <span id="post-id"><?= $post->id ?></span>
                                (<?= count($post->attachments) ?> attachments)
                            <?php endif; ?>
                        </p>
                        <ul class="horizontal bulletted">
                            <?php if (isset($post->uploaded_at)): ?>
                                <li id="post-timestamp" timestamp="<?= $post->uploaded_at->getTimestamp() ?>">
                                    uploaded
                                    <?= format_timestamp($post->uploaded_at) ?> ago
                                </li>
                            <?php endif; ?>
                            <?php if (isset($post->uploaded_by['username'])): ?>
                                <li id="post-signer">by <?= htmlspecialchars($post->uploaded_by['username']) ?></li>
                            <?php endif; ?>
                            <?php if (isset($post->views) && $post->views >= CONFIG['views']['minimal_display_count']): ?>
                                <li id="post-views"> <?= $post->views ?> views</li>
                            <?php endif; ?>
                        </ul>
                        <p style="display:none" id="post-url"><?= $post->url() ?></p>
                    </div>
                </div>
                <div>
                    <button class="hidden feed-description-opener"> description omitted. click to expand.</button>
                </div>
                <div class="control-buttons" id="control-buttons">
                    <?php if (isset($_GET['random'])): ?>
                        <a href="/?random" class="button">
                            <img src="/static/img/icons/reroll.png" alt="re-roll" title="re-roll" />
                        </a>
                    <?php endif; ?>

                    <a href="<?= $single_attachment?->raw_url() ?>" download="<?= $file_name ?>"
                        class="download-button button">
                        <img src="/static/img/icons/download.png" alt="download" title="download file" />
                    </a>
                    <a href="<?= $single_attachment?->raw_url() ?>" class="full-size-button button" target="_blank">
                        <img src="/static/img/icons/fullsize.png" alt="full size" title="open in full size" />
                    </a>

                    <?php if (CONFIG['report']['mail']): ?>
                        <a href="<?= sprintf('mailto:%s?subject=%s', CONFIG['report']['mail'], rawurlencode("File Report - $file_name")) ?>"
                            class="button">
                            <img src="/static/img/icons/flag.png" alt="report" title="report this file">
                        </a>
                    <?php endif; ?>
                </div>
            </section>

            <?php if (isset($post->description)): ?>
                <section class="feed-description">
                    <?= $post->description ?>
                </section>
            <?php endif; ?>
        </section>
    </section>
</body>

<?php if (isset($post->description)): ?>
    <script src="/static/scripts/pages/filedescription.js"></script>
<?php endif; ?>

<script src="/static/scripts/player.js"></script>
<script src="/static/scripts/favorites.js"></script>
<script src="/static/scripts/file.js"></script>
<script src="/static/scripts/pages/file.js"></script>

<?php if ($post->is_flash()): ?>
    <script src="<?= CONFIG['driver']['ruffle'] ?>"></script>
<?php endif; ?>

</html>