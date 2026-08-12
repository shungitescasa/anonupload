<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/config.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/partials.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/lib/utils.php';

if (IS_JSON_REQUEST) {
    $x = get_commit();
    $x['app_name'] = 'anonupload';
    send_json_response($x);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$recently_uploaded_files = $_SESSION['recently_uploaded_files'] ?? [];
unset($_SESSION['recently_uploaded_files']);
?>
<!DOCTYPE html>
<html>

<head>
    <?php html_head(); ?>
</head>

<body>
    <header>
        <?php html_header(); ?>
        <h2>
            max upload size is <?= get_cfg_var(option: 'upload_max_filesize') ?>
            <?php if (CONFIG['upload']['force_default_expiration'] && CONFIG['upload']['default_expiration'] !== 'ne'): ?>
                & files expire after <?= CONFIG['upload']['expiration'][CONFIG['upload']['default_expiration']] ?>
            <?php endif; ?>
        </h2>
    </header>
    <main>
        <form action="/upload" method="post" enctype="multipart/form-data" autocomplete="off" id="form-upload">
            <button id="huge-upload-button" style="display:none">click, drop, or paste files here</button>
            <input type="file" name="file[]" id="upload-file" required multiple>
            <div class="options">
                <div class="controls">
                    <fieldset>
                        <legend>general</legend>

                        <table class="vertical">
                            <tr>
                                <th><label for="file-password">password<sup class="hint iconless"
                                            title="for file deletion">[?]</sup>:</label>
                                </th>
                                <td><input type="password" name="password" id="file-password"
                                        placeholder="leave empty for permanent file"></td>
                            </tr>
                            <tr>
                                <th><label for="file-singleurl">single URL<sup class="hint iconless"
                                            title="attach all files to a single link">[?]</sup>:</label></th>
                                <td><input type="checkbox" name="single_url" id="file-singleurl" value="1"></td>
                            </tr>
                            <?php if (!empty(CONFIG['upload']['expiration']) && !CONFIG['upload']['force_default_expiration']): ?>
                                <tr>
                                    <th><label for="file-expiration">expires in:</label></th>
                                    <td>
                                        <select name="expires_in" id="file-expiration">
                                            <?php foreach (CONFIG['upload']['expiration'] as $v => $t): ?>
                                                <option value="<?= $v ?>" <?= $v === CONFIG['upload']['default_expiration'] ? 'selected' : '' ?>><?= $t ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    </fieldset>
                    <fieldset>
                        <legend>miscellaneous</legend>

                        <table class="vertical">
                            <tr>
                                <th><label for="file-originalname">preserve original filename:</label></th>
                                <td><input type="checkbox" name="preserve_original_filename" id="file-originalname"
                                        value="1"></td>
                            </tr>
                            <tr>
                                <th><label for="file-exif">strip EXIF data:</label></th>
                                <td><input type="checkbox" name="strip_exif_data" id="file-exif" value="1"></td>
                            </tr>
                        </table>
                    </fieldset>
                </div>
                <fieldset class="description">
                    <legend>description</legend>
                    <textarea name="description" placeholder="write your SNCA here... (Markdown supported)"></textarea>
                </fieldset>
            </div>
            <button type="submit">upload</button>
            <input type="hidden" name="save_upload_list" value="1">
        </form>
        <p class="hint" id="upload-hint">the page will be refreshed once all uploads are complete</p>
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
                                        <img src="/static/img/icons/file_image.png" alt="[I]" title="image file">
                                    <?php elseif (str_starts_with($f->mime, "video/")): ?>
                                        <img src="/static/img/icons/file_video.png" alt="[V]" title="video file">
                                    <?php elseif (str_starts_with($f->mime, "audio/")): ?>
                                        <img src="/static/img/icons/file_audio.png" alt="[A]" title="audio file">
                                    <?php elseif (str_starts_with($f->mime, "text/")): ?>
                                        <img src="/static/img/icons/file_text.png" alt="[T]" title="text file">
                                    <?php elseif ($f->mime === "application/x-shockwave-flash"): ?>
                                        <img src="/static/img/icons/file_flash.png" alt="[S]" title="flash file">
                                    <?php else: ?>
                                        <img src="/static/img/icons/file.png" alt="[F]" title="file">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <p class="file-name">
                                        <?= "{$f->id}.{$f->extension}" ?>
                                    </p>
                                </td>
                                <td>
                                    <div class="details">
                                        <a href="<?= $f->url ?>" class="button" target="_blank">open</a>
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
<script src="/static/scripts/pages/home.js"></script>

</html>