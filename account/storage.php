<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/alert.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/account.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/preferences.php";

const ACCOUNT_DATA_SECTIONS = ['history', 'favorites'];

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    generate_alert('/account/', __('Method not allowed'), 405, null, true);
}

header('Cache-Control: no-store');

$user = ACCOUNT_SERVICE?->get_user();
if ($user === null) {
    generate_alert('/account/', __('Not authorized'), 401, null, true);
}

if (!ACCOUNT_SERVICE->is_storage_available()) {
    generate_alert('/account/', __('Account storage is unavailable'), 503, null, true);
}

// newest encrypted sections and this browser's preferences, so the client can apply them
if ($method === 'GET') {
    try {
        $data = ACCOUNT_SERVICE->get_storage_data($user) ?? [];
    } catch (Exception $e) {
        error_log("Failed to load account data: {$e->getMessage()}");
        generate_alert('/account/', __('Failed to load account data'), 500, null, true);
    }

    $response = [
        'preferences' => is_encrypted_blob($data['preferences'] ?? null, PREFERENCES_MAX_BLOB_SIZE) ? $data['preferences'] : null,
        'current' => get_preferences()
    ];

    foreach (ACCOUNT_DATA_SECTIONS as $section) {
        $response[$section] = is_encrypted_blob($data[$section] ?? null) ? $data[$section] : null;
    }

    generate_alert('/account/', null, 200, $response, true);
}

// replaces the given sections
$sections = [];
foreach (ACCOUNT_DATA_SECTIONS as $section) {
    if (!isset($_POST[$section])) {
        continue;
    }

    $blob = is_string($_POST[$section]) ? json_decode($_POST[$section], true) : null;
    if (!is_encrypted_blob($blob)) {
        generate_alert('/account/', __('Malformed encrypted %s', $section), 400, null, true);
    }

    $sections[$section] = ['iv' => $blob['iv'], 'data' => $blob['data']];
}

if (empty($sections)) {
    generate_alert('/account/', __('Nothing to save'), 400, null, true);
}

try {
    $data = ACCOUNT_SERVICE->get_storage_data($user) ?? [];

    if (!ACCOUNT_SERVICE->put_storage_data($user, array_merge($data, $sections))) {
        throw new RuntimeException('put_storage_data returned false');
    }
} catch (Exception $e) {
    error_log("Failed to save account data: {$e->getMessage()}");
    generate_alert('/account/', __('Failed to save account data'), 500, null, true);
}

generate_alert('/account/', __('Saved!'), 200, array_keys($sections), true);
