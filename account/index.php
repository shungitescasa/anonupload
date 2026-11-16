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
$languages = i18n_available_languages();
$storage_available = !$unauthorized && ACCOUNT_SERVICE->is_storage_available();
?>
<!DOCTYPE html>
<html lang="<?= html_lang() ?>">

<head>
    <?php html_head(__('account')); ?>
</head>

<body>
    <header>
        <?php html_header() ?>
        <?php if ($unauthorized): ?>
            <h1><?= __('account') ?></h1>
        <?php else: ?>
            <h1><?= __('hi, %s', htmlspecialchars($user->name)) ?></h1>
        <?php endif; ?>
        <?php display_alert() ?>
    </header>
    <main class="account-main">
        <?php if (ACCOUNT_SERVICE !== null): ?>
            <?php if ($unauthorized): ?>
                <section class="box">
                    <p>
                        <?= __('%s account syncs your uploaded files and favorites across devices.', CONFIG['account']['name'] ?? CONFIG['instance']['name']) ?>
                        <a href="/account/login"><?= __('Sign in today!') ?></a>
                    </p>
                </section>
            <?php else: ?>
                <section class="box">
                    <div class="tab"><?= __('account') ?></div>
                    <div class="content">
                        <table class="vertical">
                            <tr>
                                <th><?= __('Name:') ?></th>
                                <td>
                                    <?= htmlspecialchars($user->name) ?>
                                    <i>(<?= htmlspecialchars($user->username) ?>)</i>
                                </td>
                            </tr>
                            <?php if ($user->email !== null): ?>
                                <tr>
                                    <th><?= __('E-Mail:') ?></th>
                                    <td><span class="spoiler"><?= htmlspecialchars($user->email) ?></span></td>
                                </tr>
                            <?php endif; ?>
                            <?php if (!empty($user->groups)): ?>
                                <tr>
                                    <th><?= __('Groups:') ?></th>
                                    <td><?= implode(', ', $user->groups) ?></td>
                                </tr>
                            <?php endif; ?>
                        </table>

                        <form action="/account/logout" method="post" id="logout">
                            <button type="submit"><?= __('log out') ?></button>
                        </form>
                    </div>
                </section>

                <?php if ($user->is_moderator() || $user->is_admin()): ?>
                    <section>
                        <h2><?= __('system') ?></h2>
                        <table class="vertical">
                            <?php if ($user->is_moderator()): ?>
                                <tr>
                                    <th>
                                        <a href="/system/approval"><?= __('Media Approval System') ?></a>
                                    </th>
                                    <td>
                                        <i><?= __('approve the new files for public access or leave them unlisted permanently') ?></i>
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
                <h2><?= __('this browser') ?></h2>
                <hr>
                <p><?= __('this data is stored locally, which means it will be deleted once you clear your browser.') ?></p>
            <?php else: ?>
                <h2><?= __('my account') ?></h2>
            <?php endif; ?>
            <table class="vertical">
                <tr>
                    <th>
                        <a href="/account/history"><?= __('history') ?></a>
                    </th>
                    <td>
                        <i><?= __('your uploaded files') ?></i>
                    </td>
                </tr>
                <tr>
                    <th>
                        <a href="/account/favorites"><?= __('favorites') ?></a>
                    </th>
                    <td>
                        <i><?= __('saved files that you liked') ?></i>
                    </td>
                </tr>
            </table>
        </section>

        <section>
            <h2><?= __('my preferences') ?></h2>
            <hr>
            <?php if ($unauthorized): ?>
                <p><?= __("preferences are saved in this browser's cookies.") ?></p>
            <?php elseif (!$storage_available): ?>
                <p><?= __("account storage is unavailable, so preferences are saved in this browser's cookies only.") ?></p>
            <?php else: ?>
                <noscript>
                    <p><?= __("preferences are encrypted with your password before they're saved to your account, which requires JavaScript. without it, they're saved in this browser's cookies only.") ?>
                    </p>
                </noscript>
            <?php endif; ?>
            <p id="preferences-status" hidden></p>
            <form autocomplete="off" method="post" action="/account/save" class="preferences" id="preferences"
                <?= $unauthorized ? '' : 'data-authorized' ?> <?= $storage_available ? 'data-storage' : '' ?>>
                <fieldset>
                    <legend><?= __('general') ?></legend>

                    <?php if (!empty(THEME_LIST)): ?>
                        <label for="theme"><?= __('theme:') ?></label>
                        <select name="theme" id="theme">
                            <option value="default" <?= 'default' === $theme ? 'selected' : '' ?>><?= __('Default') ?></option>
                            <?php foreach (THEME_LIST as $name): ?>
                                <option value="<?= $name ?>" <?= $name === $theme ? 'selected' : '' ?>>
                                    <?= $name ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </fieldset>

                <?php if (count($languages) > 1): ?>
                    <fieldset>
                        <legend><?= __('language') ?></legend>

                        <ul class="language-list">
                            <?php foreach ($languages as $code): ?>
                                <?php $lang_name = htmlspecialchars(i18n_language_name($code)); ?>
                                <li>
                                    <input type="radio" name="language" id="language-<?= $code ?>" value="<?= $code ?>"
                                        <?= $code === $preferences['language'] ? 'checked' : '' ?>>
                                    <label for="language-<?= $code ?>" lang="<?= str_replace('_', '-', $code) ?>">
                                        <img src="/static/img/flags/<?= $code ?>.png" alt="<?= $lang_name ?>"
                                            title="<?= $lang_name ?>" class="flag">
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </fieldset>
                <?php endif; ?>

                <fieldset>
                    <legend><?= __('files') ?></legend>

                    <input type="checkbox" name="noloop" id="noloop" value="1" <?= $preferences['noloop'] ? 'checked' : '' ?>>
                    <label for="noloop"><?= __('disable video looping') ?></label>

                    <br>

                    <input type="checkbox" name="noautoplay" id="noautoplay" value="1" <?= $preferences['noautoplay'] ? 'checked' : '' ?>>
                    <label for="noautoplay"><?= __('disable autoplay') ?></label>
                </fieldset>
                <noscript>
                    <div>
                        <button type="submit"><?= __('save') ?></button>
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