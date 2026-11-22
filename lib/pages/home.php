<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/config.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/partials.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/utils.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/alert.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/spool.php';

if (IS_JSON_REQUEST) {
    $x = get_commit() ?? ['sha' => 'N/A'];
    $x['app_name'] = 'anonupload';
    send_json_response($x);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$recently_uploaded_files = $_SESSION['recently_uploaded_files'] ?? [];
unset($_SESSION['recently_uploaded_files']);

$user = ACCOUNT_SERVICE?->get_user();

// the plain form is limited by MAX_FORM_UPLOAD_BYTES, the chunked uploads of upload.js by MAX_UPLOAD_BYTES
$limit_text = function (int $bytes): string {
    if (!empty(CONFIG['expiration']['time']) && CONFIG['expiration']['force_default'] && CONFIG['expiration']['default'] !== 'ne') {
        return __('max upload size is %s & files expire after %s', format_filesize($bytes), CONFIG['expiration']['time'][CONFIG['expiration']['default']]);
    }
    return __('max upload size is %s', format_filesize($bytes));
};
?>
<!DOCTYPE html>
<html lang="<?= html_lang() ?>">

<head>
    <?php html_head(); ?>
</head>

<body>
    <header>
        <?php html_header(); ?>
        <h2 style="text-align:center" id="upload-limits"
            data-chunked-text="<?= htmlspecialchars($limit_text(MAX_UPLOAD_BYTES)) ?>">
            <?= $limit_text(MAX_FORM_UPLOAD_BYTES) ?>
        </h2>
    </header>
    <main>
        <?php display_alert(); ?>
        <form action="/upload" method="post" enctype="multipart/form-data" autocomplete="off" id="form-upload"
            data-max-size="<?= MAX_UPLOAD_BYTES ?>"
            data-max-files="<?= (int) CONFIG['upload']['max_files_per_request'] ?>">
            <button id="huge-upload-button" style="display:none"
                data-text="<?= __('click, drop, or paste files here') ?>"
                data-drop-text="<?= __('drop files here') ?>"><?= __('click, drop, or paste files here') ?></button>
            <input type="file" name="file[]" id="upload-file" required multiple>
            <div class="options">
                <div class="controls">
                    <fieldset>
                        <legend><?= __('general') ?></legend>

                        <table class="vertical">
                            <tr>
                                <th><label for="file-password"><?= __('password') ?><sup class="hint iconless"
                                            title="<?= __('for file deletion') ?>">[?]</sup>:</label>
                                </th>
                                <td><input type="password" name="password" id="file-password"
                                        placeholder="<?= __('leave empty for permanent file') ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="file-singleurl"><?= __('single URL') ?><sup class="hint iconless"
                                            title="<?= __('attach all files to a single link') ?>">[?]</sup>:</label>
                                </th>
                                <td><input type="checkbox" name="single_url" id="file-singleurl" value="1"></td>
                            </tr>
                            <?php if ($user !== null): ?>
                                <tr>
                                    <th><label for="file-sign"><?= __('sign this post') ?><sup class="hint iconless"
                                                title="<?= __('link this post with your account (%s)', htmlspecialchars($user->username)) ?>">[?]</sup>:</label>
                                    </th>
                                    <td><input type="checkbox" name="sign_post" id="file-sign" value="1"></td>
                                </tr>
                            <?php endif; ?>
                            <?php if (!empty(CONFIG['expiration']['time']) && !CONFIG['expiration']['force_default']): ?>
                                <tr>
                                    <th><label for="file-expiration"><?= __('expires in:') ?></label></th>
                                    <td>
                                        <select name="expires_in" id="file-expiration">
                                            <?php foreach (CONFIG['expiration']['time'] as $v => $t): ?>
                                                <option value="<?= $v ?>" <?= $v === CONFIG['expiration']['default'] ? 'selected' : '' ?>><?= $t ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    </fieldset>
                    <fieldset>
                        <legend><?= __('miscellaneous') ?></legend>

                        <table class="vertical">
                            <tr>
                                <th><label for="file-originalname"><?= __('preserve original filename:') ?></label></th>
                                <td><input type="checkbox" name="preserve_original_filename" id="file-originalname"
                                        value="1"></td>
                            </tr>
                            <tr>
                                <th><label for="file-exif"><?= __('strip EXIF data:') ?></label></th>
                                <td><input type="checkbox" name="strip_exif_data" id="file-exif" value="1"></td>
                            </tr>
                        </table>
                    </fieldset>
                </div>
                <fieldset class="description">
                    <legend><?= __('description') ?></legend>
                    <textarea name="description"
                        placeholder="<?= __('tell us what you are going to upload... (Markdown supported)') ?>"></textarea>
                </fieldset>
            </div>
            <button type="submit"><?= __('upload') ?></button>
            <input type="hidden" name="save_upload_list" value="1">
        </form>
        <p class="hint" id="upload-hint"
            data-js-text="<?= __('the upload will start immediately after selecting the file') ?>">
            <?= __('the page will be refreshed once all uploads are complete') ?>
        </p>
        <section id="file-upload-queue">
            <table>
                <thead>
                    <tr>
                        <th></th>
                        <th></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recently_uploaded_files as $f): ?>
                        <?php if (is_array($f)): ?>
                            <tr>
                                <td></td>
                                <td>
                                    <p class="file-name"><?= $f['original_name'] ?></p>
                                </td>
                                <td>
                                    <p class="error status"><?= $f['error'] ?></p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td>
                                    <?php if (str_starts_with($f->mime, "image/")): ?>
                                        <img src="/static/img/icons/file_image.png" alt="[I]" title="<?= __('image file') ?>">
                                    <?php elseif (str_starts_with($f->mime, "video/")): ?>
                                        <img src="/static/img/icons/file_video.png" alt="[V]" title="<?= __('video file') ?>">
                                    <?php elseif (str_starts_with($f->mime, "audio/")): ?>
                                        <img src="/static/img/icons/file_audio.png" alt="[A]" title="<?= __('audio file') ?>">
                                    <?php elseif (str_starts_with($f->mime, "text/")): ?>
                                        <img src="/static/img/icons/file_text.png" alt="[T]" title="<?= __('text file') ?>">
                                    <?php elseif ($f->mime === "application/x-shockwave-flash"): ?>
                                        <img src="/static/img/icons/file_flash.png" alt="[S]" title="<?= __('flash file') ?>">
                                    <?php else: ?>
                                        <img src="/static/img/icons/file.png" alt="[F]" title="<?= __('file') ?>">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <p class="file-name">
                                        <?= "{$f->id}.{$f->extension}" ?>
                                    </p>
                                </td>
                                <td>
                                    <div class="details">
                                        <a href="<?= $f->url ?>" class="button" target="_blank"><?= __('open') ?></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </main>
    <footer>
        <?php html_footer(); ?>
        <?php html_legal(); ?>
        <?php if (isset(CONFIG['siblings']['news'])): ?>
            <p id="latest-news"></p>
        <?php endif; ?>
        <?php html_motd(); ?>
    </footer>
</body>

<?php if (isset(CONFIG['siblings']['news'])): ?>
    <script src="<?= CONFIG['siblings']['news'] ?>"></script>
<?php endif; ?>

<script src="/static/scripts/options.js"></script>
<script src="/static/scripts/file.js"></script>
<script src="/static/scripts/upload.js"></script>
<script src="/static/scripts/pages/home.js"></script>

</html>