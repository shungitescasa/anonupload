<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/partials.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/alert.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/account.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/preferences.php";

$user = ACCOUNT_SERVICE?->get_user();
$unauthorized = $user === null;
$preferences = get_preferences();
$theme = $preferences['theme'];
$storage_available = !$unauthorized && ACCOUNT_SERVICE->is_storage_available();
?>
<!DOCTYPE html>
<html>

<head>
    <?php html_head("account"); ?>
</head>

<body>
    <header>
        <?php html_header() ?>
        <?php if ($unauthorized): ?>
            <h1>account</h1>
        <?php else: ?>
            <h1>hi, <?= htmlspecialchars($user->name) ?></h1>
        <?php endif; ?>
        <?php display_alert() ?>
    </header>
    <main class="account-main">
        <?php if (ACCOUNT_SERVICE !== null): ?>
            <?php if ($unauthorized): ?>
                <section class="box">
                    <p>
                        <?= CONFIG['account']['name'] ?> account syncs your uploaded files and favorites
                        across devices. <a href="/account/login">Sign in today!</a>
                    </p>
                </section>
            <?php else: ?>
                <section class="box">
                    <div class="tab">account</div>
                    <div class="content">
                        <table class="vertical">
                            <tr>
                                <th>Name:</th>
                                <td>
                                    <?= htmlspecialchars($user->name) ?>
                                    <i>(<?= htmlspecialchars($user->username) ?>)</i>
                                </td>
                            </tr>
                            <?php if ($user->email !== null): ?>
                                <tr>
                                    <th>E-Mail:</th>
                                    <td><span class="spoiler"><?= htmlspecialchars($user->email) ?></span></td>
                                </tr>
                            <?php endif; ?>
                            <?php if (!empty($user->groups)): ?>
                                <tr>
                                    <th>Groups:</th>
                                    <td><?= implode(', ', $user->groups) ?></td>
                                </tr>
                            <?php endif; ?>
                        </table>

                        <form action="/account/logout" method="post" id="logout">
                            <button type="submit">log out</button>
                        </form>
                    </div>
                </section>

                <?php if ($user->is_moderator() || $user->is_admin()): ?>
                    <section>
                        <h2>system</h2>
                        <table class="vertical">
                            <?php if ($user->is_moderator()): ?>
                                <tr>
                                    <th>
                                        <a href="/system/approval">Media Approval System</a>
                                    </th>
                                    <td>
                                        <i>approve the new files for public access or leave them unlisted permanently</i>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    </section>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
        <section>
            <?php if ($unauthorized): ?>
                <h2>this browser</h2>
                <hr>
                <p>this data is stored locally, which means it will be deleted once you clear your browser.</p>
            <?php else: ?>
                <h2>this account</h2>
            <?php endif; ?>
            <table class="vertical">
                <tr>
                    <th>
                        <a href="/account/history">history</a>
                    </th>
                    <td>
                        <i>your uploaded files</i>
                    </td>
                </tr>
                <tr>
                    <th>
                        <a href="/account/favorites">favorites</a>
                    </th>
                    <td>
                        <i>files you like</i>
                    </td>
                </tr>
            </table>
        </section>

        <section>
            <h2>my preferences</h2>
            <hr>
            <?php if ($unauthorized): ?>
                <p>preferences are saved in this browser's cookies.</p>
            <?php elseif (!$storage_available): ?>
                <p>account storage is unavailable, so preferences are saved in this browser's cookies only.</p>
            <?php else: ?>
                <noscript>
                    <p>preferences are encrypted with your password before they're saved to your account, which requires
                        JavaScript. without it, they're saved in this browser's cookies only.</p>
                </noscript>
            <?php endif; ?>
            <p id="preferences-status" hidden></p>
            <form autocomplete="off" method="post" action="/account/save" class="preferences" id="preferences"
                <?= $unauthorized ? '' : 'data-authorized' ?> <?= $storage_available ? 'data-storage' : '' ?>>
                <fieldset>
                    <legend>general</legend>

                    <?php if (!empty(THEME_LIST)): ?>
                        <label for="theme">theme:</label>
                        <select name="theme" id="theme">
                            <option value="default" <?= 'default' === $theme ? 'selected' : '' ?>>Default</option>
                            <?php foreach (THEME_LIST as $name): ?>
                                <option value="<?= $name ?>" <?= $name === $theme ? 'selected' : '' ?>>
                                    <?= $name ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </fieldset>

                <fieldset>
                    <legend>files</legend>

                    <input type="checkbox" name="noloop" id="noloop" value="1" <?= $preferences['noloop'] ? 'checked' : '' ?>>
                    <label for="noloop">disable video looping</label>

                    <br>

                    <input type="checkbox" name="noautoplay" id="noautoplay" value="1" <?= $preferences['noautoplay'] ? 'checked' : '' ?>>
                    <label for="noautoplay">disable autoplay</label>
                </fieldset>
                <noscript>
                    <div>
                        <button type="submit">save</button>
                    </div>
                </noscript>
            </form>
        </section>
    </main>
    <footer>
        <?php html_footer(); ?>
        <?php html_legal(); ?>
        <?php html_motd(); ?>
    </footer>
</body>

<script src="/static/scripts/pages/account.js"></script>

</html>