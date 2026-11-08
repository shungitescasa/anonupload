<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/alert.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/account.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    generate_alert('/account/', 'Method not allowed', 405);
}

if (ACCOUNT_SERVICE === null) {
    generate_alert('/', 'Authentication is disabled!', 403);
}

ACCOUNT_SERVICE->logout();
generate_alert('/account/', 'Logged out!');
