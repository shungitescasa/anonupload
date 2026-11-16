<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/account.php";

const PREFERENCES_COOKIE_TIME = 86400 * 30 * 180;
const PREFERENCES_MAX_BLOB_SIZE = 4096;

function normalize_preferences(array $input): array
{
    $theme = $input['theme'] ?? CONFIG['instance']['defaultstyle'];
    if (!is_string($theme) || ($theme !== 'default' && !in_array($theme, THEME_LIST, true))) {
        $theme = CONFIG['instance']['defaultstyle'];
    }

    $language = $input['language'] ?? null;
    if (!i18n_is_available($language)) {
        $language = i18n_language();
    }

    $flag = fn(string $k) => filter_var($input[$k] ?? false, FILTER_VALIDATE_BOOLEAN);

    return [
        'theme' => $theme,
        'language' => $language,
        'noloop' => $flag('noloop'),
        'noautoplay' => $flag('noautoplay')
    ];
}

function get_preferences(): array
{
    return normalize_preferences([
        'theme' => $_COOKIE['theme'] ?? null,
        'language' => i18n_language(),
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

    if ($preferences['language'] === i18n_detect_language()) {
        $remove(I18N_COOKIE);
    } else {
        setcookie(I18N_COOKIE, $preferences['language'], $expires, '/');
    }

    foreach (['noloop', 'noautoplay'] as $k) {
        if ($preferences[$k]) {
            setcookie($k, '1', $expires, '/');
        } else {
            $remove($k);
        }
    }
}
