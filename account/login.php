<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/partials.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/alert.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/account.php";

if (ACCOUNT_SERVICE === null) {
    generate_alert('/', __('Authentication is disabled!'), 403);
}

if (ACCOUNT_SERVICE->get_user() !== null) {
    header('Location: /account/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $profile = ACCOUNT_SERVICE->login($_POST);
    } catch (InvalidArgumentException $e) {
        generate_alert('/account/login', $e->getMessage(), 400);
    } catch (RuntimeException $e) {
        error_log("Account service: {$e->getMessage()}");
        generate_alert('/account/login', __('Authentication service is unavailable. Try again later.'), 503);
    }

    if ($profile === null) {
        generate_alert('/account/login', __('Invalid username or password'), 401);
    }

    generate_alert('/account/', __('Logged in!'), 200, $profile);
}

$title = __('log in to %s account', CONFIG['account']['name'] ?? CONFIG['instance']['name']);
?>
<!DOCTYPE html>
<html lang="<?= html_lang() ?>">

<head>
    <?php html_head($title); ?>
</head>

<body>
    <header>
        <?php html_header() ?>
        <h2><?= $title ?></h2>
        <?php display_alert() ?>
    </header>
    <main>
        <form method="post" action="/account/login" class="preferences" id="login">
            <fieldset>
                <legend><?= __('account credentials') ?></legend>

                <table class="vertical">
                    <tr>
                        <th><label for="username"><?= __('username:') ?></label></th>
                        <td>
                            <input type="text" name="username" id="username" autocomplete="username" required
                                minlength="<?= CONFIG['account']['username_min_length'] ?>"
                                maxlength="<?= CONFIG['account']['username_max_length'] ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="password"><?= __('password:') ?></label></th>
                        <td>
                            <input type="password" name="password" id="password" autocomplete="current-password"
                                required minlength="<?= CONFIG['account']['password_min_length'] ?>">
                        </td>
                    </tr>
                    <tr>
                        <th></th>
                        <td>
                            <input type="checkbox" name="remember" id="remember" value="1">
                            <label for="remember"><?= __('remember me') ?></label>
                        </td>
                    </tr>
                </table>
            </fieldset>
            <div>
                <button type="submit"><?= __('log in') ?></button>
                <?php if (!empty(CONFIG['account']['registration_url'])): ?>
                    <a href="<?= CONFIG['account']['registration_url'] ?>" target="_blank"><?= __('register') ?></a>
                <?php endif; ?>
            </div>
        </form>
    </main>
    <footer>
        <?php html_footer(); ?>
        <?php html_legal(); ?>
        <?php html_motd(); ?>
    </footer>
</body>

<script src="/static/scripts/pages/login.js"></script>

</html>