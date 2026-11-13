<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";

const PREFERENCES_COOKIE_TIME = 86400 * 30 * 180;
const PREFERENCES_MAX_BLOB_SIZE = 4096;

function normalize_preferences(array $input): array
{
    $theme = $input['theme'] ?? CONFIG['instance']['defaultstyle'];
    if (!is_string($theme) || ($theme !== 'default' && !in_array($theme, THEME_LIST, true))) {
        $theme = CONFIG['instance']['defaultstyle'];
    }

    $flag = fn(string $k) => filter_var($input[$k] ?? false, FILTER_VALIDATE_BOOLEAN);

    return [
        'theme' => $theme,
        'noloop' => $flag('noloop'),
        'noautoplay' => $flag('noautoplay')
    ];
}

function get_preferences(): array
{
    return normalize_preferences([
        'theme' => $_COOKIE['theme'] ?? null,
        'noloop' => isset($_COOKIE['noloop']),
        'noautoplay' => isset($_COOKIE['noautoplay'])
    ]);
}

function set_preferences_cookies(array $preferences): void
{
    $preferences = normalize_preferences($preferences);
    $expires = time() + PREFERENCES_COOKIE_TIME;
    $remove = fn(string $k) => setcookie($k, '', time() - 3600, '/');

    if ($preferences['theme'] === CONFIG['instance']['defaultstyle']) {
        $remove('theme');
    } else {
        setcookie('theme', $preferences['theme'], $expires, '/');
    }

    foreach (['noloop', 'noautoplay'] as $k) {
        if ($preferences[$k]) {
            setcookie($k, '1', $expires, '/');
        } else {
            $remove($k);
        }
    }
}

function is_encrypted_blob(mixed $blob): bool
{
    if (!is_array($blob) || !is_string($blob['iv'] ?? null) || !is_string($blob['data'] ?? null)) {
        return false;
    }

    $iv = base64_decode($blob['iv'], true);
    $data = base64_decode($blob['data'], true);

    return $iv !== false && strlen($iv) === 12
        && $data !== false && $data !== '' && strlen($blob['data']) <= PREFERENCES_MAX_BLOB_SIZE;
}
