<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/alert.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/account.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/preferences.php";

$method = $_SERVER['REQUEST_METHOD'];

// newest account preferences (encrypted) and this browser's preferences, so the client can apply them
if ($method === 'GET' && IS_JSON_REQUEST) {
    header('Cache-Control: no-store');

    $user = ACCOUNT_SERVICE?->get_user();
    if ($user === null) {
        generate_alert('/account/', 'Not authorized', 401);
    }

    if (!ACCOUNT_SERVICE->is_storage_available()) {
        generate_alert('/account/', 'Account storage is unavailable', 503);
    }

    try {
        $saved = ACCOUNT_SERVICE->get_storage_data($user)['preferences'] ?? null;
    } catch (Exception $e) {
        error_log("Failed to load account preferences: {$e->getMessage()}");
        generate_alert('/account/', 'Failed to load account preferences', 500);
    }

    generate_alert('/account/', null, 200, [
        'saved' => is_encrypted_blob($saved) ? $saved : null,
        'current' => get_preferences()
    ]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    generate_alert('/account/', 'Method not allowed', 405);
}

$preferences = normalize_preferences($_POST);

// account preferences are encrypted with the user's password on the client side,
// so they can only be saved to the account if JavaScript is enabled
$storage = $_POST['storage'] ?? null;
if (is_string($storage)) {
    $storage = $storage !== '' ? json_decode($storage, true) : null;
    if ($storage === null && $_POST['storage'] !== '') {
        generate_alert('/account/', 'Malformed encrypted preferences', 400);
    }
}
if ($storage !== null && !is_encrypted_blob($storage)) {
    generate_alert('/account/', 'Malformed encrypted preferences', 400);
}

set_preferences_cookies($preferences);

$user = ACCOUNT_SERVICE?->get_user();
if ($user === null) {
    generate_alert('/account/', 'Preferences saved in this browser!', 200, $preferences);
}

if (!empty($_POST['_loaded'])) {
    generate_alert('/account/', 'Preferences loaded from your account!', 200, $preferences);
}

if ($storage === null) {
    generate_alert('/account/', 'Preferences saved in this browser only. JavaScript is required to save them to your account.', 200, $preferences);
}

if (!ACCOUNT_SERVICE->is_storage_available()) {
    generate_alert('/account/', 'Preferences saved in this browser only: account storage is unavailable.', 503, $preferences);
}

try {
    $data = ACCOUNT_SERVICE->get_storage_data($user) ?? [];
    $data['preferences'] = ['iv' => $storage['iv'], 'data' => $storage['data']];

    if (!ACCOUNT_SERVICE->put_storage_data($user, $data)) {
        throw new RuntimeException('put_storage_data returned false');
    }
} catch (Exception $e) {
    error_log("Failed to save account preferences: {$e->getMessage()}");
    generate_alert('/account/', 'Preferences saved in this browser only: failed to save them to your account.', 500, $preferences);
}

generate_alert('/account/', 'Preferences saved!', 200, $preferences);
