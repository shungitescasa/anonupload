<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/partials.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/alert.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/account.php";

$user = ACCOUNT_SERVICE?->get_user();
$unauthorized = $user === null;
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
            <h2>account</h2>
        <?php else: ?>
            <h2>hi, <?= htmlspecialchars($user->name) ?></h2>
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

                        <form action="/account/logout" method="post">
                            <button type="submit">log out</button>
                        </form>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>
        <section>
            <?php if ($unauthorized): ?>
                <h3>this browser</h3>
                <hr>
                <p>this data is stored locally, which means it will be deleted once you clear your browser.</p>
            <?php else: ?>
                <h3>this account</h3>
            <?php endif; ?>
            <table class="vertical">
                <tr>
                    <th>
                        <a href="/history">/history</a>
                    </th>
                    <td>
                        <i>your uploaded files</i>
                    </td>
                </tr>
                <tr>
                    <th>
                        <a href="/favorites">/favorites</a>
                    </th>
                    <td>
                        <i>files you like</i>
                    </td>
                </tr>
            </table>
        </section>
    </main>
    <footer>
        <?php html_footer(); ?>
        <?php html_legal(); ?>
        <?php html_motd(); ?>
    </footer>
</body>

</html>