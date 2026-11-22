<?php
define('GEN_TIMESTAMP', floor(microtime(true) * 1000));

if (isset($_SERVER['ANONUPLOAD_CONFIG_FILE_PATH'])) {
    define("CONFIG_FILE_PATH", $_SERVER['ANONUPLOAD_CONFIG_FILE_PATH']);
} else {
    define("CONFIG_FILE_PATH", $_SERVER['DOCUMENT_ROOT'] . '/anonupload.ini');
}

if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = 443;
}

$cfg = [
    "instance" => [
        "name" => $_SERVER['HTTP_HOST'],
        'id' => 'anonupload',
        "mirrors" => [],
        "url" => (empty($_SERVER['HTTPS']) ? 'http' : 'https') . "://$_SERVER[HTTP_HOST]",
        'linkname' => 'more...',
        "links" => [],
        "defaultstyle" => "default",
        'defaultlanguage' => 'en',
        'machine_id' => 1,
        'epoch' => 1609459200000,
    ],
    'siblings' => [
        'news' => null
    ],
    'storage' => [
        'type' => 'local',

        // local & sql
        'directory' => './userdata/uploads',
        'prefix' => '/userdata/uploads',

        // sql
        'driver' => "mysql",
        'host' => "localhost",
        'port' => 3306,
        'name' => "anonupload",
        'user' => "default",
        'pass' => "default",

        // s3 only
        'version' => 'latest',
        'access_key' => null,
        'secret_key' => null,
        'region' => null,
        'bucket' => null,
        'endpoint' => null,
        'use_path_style_endpoint' => true,

        'index_ping' => true
    ],
    // staged uploads: every upload is written to a local spool first and moved to the storage
    // by bin/transfer-worker.php. All upload limits live here (bytes and seconds).
    'spool' => [
        // must be outside the document root
        'directory' => '/var/spool/anonupload',
        // nginx 'internal' location that maps to "<directory>/data/" (X-Accel-Redirect),
        // empty: files are not served before they reach the storage
        'accel_location' => '/_spool',
        'max_upload_bytes' => 4 * 1024 * 1024 * 1024,
        'max_form_upload_bytes' => 1024 * 1024 * 1024,
        'chunk_size' => 16 * 1024 * 1024,
        'max_bytes' => 10 * 1024 * 1024 * 1024,
        'min_free_disk' => 2 * 1024 * 1024 * 1024,
        'max_active_uploads' => 32,
        'stale_upload_ttl' => 24 * 60 * 60,
        'reservation_ttl' => 60 * 60,
        'failed_retention' => 7 * 24 * 60 * 60,
        'max_attempts' => 20,
        'backoff_min' => 30,
        'backoff_max' => 600,
        'client_parallel_chunks' => 1,
        'log' => '/var/log/anonupload/transfer-worker.log'
    ],
    'memcached' => [
        'hosts' => null,
        'use_redis' => false
    ],
    'metadata' => [
        'type' => 'local',

        // local
        'directory' => './userdata/metadata',

        // sql
        'driver' => "mysql",
        'host' => "localhost",
        'port' => 3306,
        'name' => "anonupload",
        'user' => "default",
        'pass' => "default",
    ],
    "driver" => [
        "ruffle" => null,
        "chart" => null
    ],
    'catalogue' => [
        'public' => true,
        'limit' => 50,
        'filter_mime' => [],
        'cache_ttl' => 60
    ],
    "surpriseme" => [
        "enable" => false,
    ],
    "files" => [
        'track_ttl' => 300,
        'show_upload_timestamp' => true
    ],
    'id' => [
        'type' => 'chars',
        'pool' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789',
        'length' => 5,
        'prefix' => null
    ],
    "upload" => [
        "acceptedmimetypes" => [
            '3g2' => 'video/3gpp2',
            '3gp' => 'video/3gp',
            '7z' => 'application/x-7z-compressed',
            'aac' => 'audio/x-acc',
            'ac3' => 'audio/ac3',
            'ai' => 'application/postscript',
            'aif' => 'audio/x-aiff',
            'au' => 'audio/x-au',
            'avi' => 'video/x-msvideo',
            'bin' => 'application/macbinary',
            'bmp' => 'image/bmp',
            'cdr' => 'application/cdr',
            'cpt' => 'application/mac-compactpro',
            'crl' => 'application/pkix-crl',
            'crt' => 'application/x-x509-ca-cert',
            'csv' => 'text/x-comma-separated-values',
            'dcr' => 'application/x-director',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'dvi' => 'application/x-dvi',
            'eml' => 'message/rfc822',
            'exe' => 'application/x-msdownload',
            'f4v' => 'video/x-f4v',
            'flac' => 'audio/x-flac',
            'flv' => 'video/x-flv',
            'gif' => 'image/gif',
            'gpg' => 'application/gpg-keys',
            'gtar' => 'application/x-gtar',
            'gzip' => 'application/x-gzip',
            'hqx' => 'application/mac-binhex40',
            'ico' => 'image/x-icon',
            'ics' => 'text/calendar',
            'jar' => 'application/java-archive',
            'jp2' => 'image/jp2',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'json' => 'application/json',
            'kml' => 'application/vnd.google-earth.kml+xml',
            'kmz' => 'application/vnd.google-earth.kmz',
            'log' => 'text/plain',
            'm4a' => 'audio/x-m4a',
            'm4u' => 'application/vnd.mpegurl',
            'mid' => 'audio/midi',
            'mif' => 'application/vnd.mif',
            'mov' => 'video/quicktime',
            'movie' => 'video/x-sgi-movie',
            'mp3' => 'audio/mpeg',
            'mp4' => 'video/mp4',
            'mpeg' => 'video/mpeg',
            'oda' => 'application/oda',
            'ogg' => 'audio/ogg',
            'otf' => 'font/otf',
            'p10' => 'application/x-pkcs10',
            'p12' => 'application/x-pkcs12',
            'p7a' => 'application/x-pkcs7-signature',
            'p7c' => 'application/pkcs7-mime',
            'p7r' => 'application/x-pkcs7-certreqresp',
            'p7s' => 'application/pkcs7-signature',
            'pdf' => 'application/pdf',
            'pem' => 'application/x-x509-user-cert',
            'pgp' => 'application/pgp',
            'png' => 'image/png',
            'ppt' => 'application/powerpoint',
            'doc' => 'application/msword',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'psd' => 'application/x-photoshop',
            'ra' => 'audio/x-realaudio',
            'ram' => 'audio/x-pn-realaudio',
            'rar' => 'application/x-rar',
            'rpm' => 'audio/x-pn-realaudio-plugin',
            'rsa' => 'application/x-pkcs7',
            'rtf' => 'text/rtf',
            'rtx' => 'text/richtext',
            'rv' => 'video/vnd.rn-realvideo',
            'sit' => 'application/x-stuffit',
            'smil' => 'application/smil',
            'srt' => 'text/srt',
            'svg' => 'image/svg+xml',
            'swf' => 'application/x-shockwave-flash',
            'tar' => 'application/x-tar',
            'tgz' => 'application/x-gzip-compressed',
            'tiff' => 'image/tiff',
            'ttf' => 'font/ttf',
            'txt' => 'text/plain',
            'vcf' => 'text/x-vcard',
            'vlc' => 'application/videolan',
            'vtt' => 'text/vtt',
            'wav' => 'audio/x-wav',
            'wbxml' => 'application/wbxml',
            'webm' => 'video/webm',
            'webp' => 'image/webp',
            'wma' => 'audio/x-ms-wma',
            'wmlc' => 'application/wmlc',
            'wmv' => 'video/x-ms-wmv',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'xl' => 'application/excel',
            'xls' => 'application/msexcel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xml' => 'application/xml',
            'xsl' => 'text/xsl',
            'xspf' => 'application/xspf+xml',
            'z' => 'application/x-compress',
            'zip' => 'application/zip',
            'zsh' => 'text/x-scriptzsh',
            'lua' => 'text/x-lua'
        ],
        "convertextensions" => [
            'mov' => 'mp4',
            'mkv' => 'mp4'
        ],
        'max_files_per_request' => 5,
        "verifymimetype" => true,
        "stripexif" => true,
        "idcharacters" => "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_",
        "idlength" => 5,
        "idprefix" => false,
        "customid" => true,
        "customidregex" => "/^[A-za-z0-9]+$/",
        "customidlength" => 12,
        "zipwebapps" => false,
        "titlelength" => 100,
        "removeletterboxes" => false
    ],
    'expiration' => [
        'time' => [],
        'default' => null,
        'force_default' => false
    ],
    "externalupload" => [
        "enable" => true,
        "maxduration" => 60 * 5,
        "quality" => "worst"
    ],
    'thumbnails' => [
        'type' => 'none',
        'url' => null,
        'authorization_key' => null,
        'width' => 128,
        'height' => 128,
        'extension' => 'webp',
        'directory' => './userdata/thumbnails',
        'prefix' => '/userdata/thumbnails',
        'bucket' => null
    ],
    'views' => [
        'enabled' => false,
        'minimal_display_count' => 0,
        'ignore_user_agents' => [],
        'redirect_user_agents' => []
    ],
    'librecounter' => [
        'enabled' => false,
        'hidden' => false
    ],
    "report" => [
        'mail' => null
    ],
    'contact' => [
        'name' => 'contact',
        'url' => null
    ],
    "moderation" => [
        "path" => "{$_SERVER['DOCUMENT_ROOT']}/.anonuploadpasswd",
        "banfiles" => true,
        "hashpath" => "{$_SERVER['DOCUMENT_ROOT']}/.bannedhashes"
    ],
    'account' => [
        'enabled' => false,

        // supported services: ldap
        'type' => 'ldap',

        'name' => null,
        'registration_url' => null,

        // --- LDAP ---
        // e.g. ldap://localhost:389 or ldaps://ldap.example.com
        'uri' => 'ldap://localhost:389',
        'starttls' => false,
        'timeout' => 5,

        'base_dn' => 'ou=users,dc=example,dc=com',
        'user_dn' => 'uid=%s,ou=users,dc=example,dc=com',

        // optional service account to search for the user's DN with 'user_filter'
        'bind_dn' => null,
        'bind_password' => null,
        'user_filter' => '(uid=%s)',

        'attributes' => ['uid', 'cn', 'displayName', 'mail'],
        'cache_ttl' => 60 * 60,

        'group_base_dn' => 'ou=groups,dc=example,dc=com',
        'group_filter' => '(|(member={dn})(uniqueMember={dn})(memberUid={username}))',
        'group_name_attribute' => 'cn',

        'moderator_group' => 'moderators',
        'admin_group' => 'admins',

        'cookie_name' => 'token',
        'cookie_time' => 60 * 60 * 24 * 30,
        'username_min_length' => 3,
        'username_max_length' => 20,
        'password_min_length' => 8
    ],
    'stats' => [
        'enabled' => false,
        'disk_size' => 0,
        'ttl' => 300
    ]
];

if (file_exists(CONFIG_FILE_PATH)) {
    $c = parse_ini_file(CONFIG_FILE_PATH, true);
    foreach ($c as $s => $r) {
        if (!array_key_exists($s, $cfg)) {
            continue;
        }

        foreach ($r as $k => $v) {
            if (!array_key_exists($k, $cfg[$s])) {
                continue;
            }

            // value is a map
            if (str_starts_with($v, '{') && str_ends_with($v, '}')) {
                $c = strlen($v);
                $v = substr($v, 1, $c - 2);
                $ls = explode("\t", $v);
                $arr = [];

                foreach ($ls as $l) {
                    $p = explode("=", $l, 2);
                    $arr[$p[0]] = $p[1];
                }

                $v = $arr;
            }
            // value is an array
            else if (str_starts_with($v, '[') && str_ends_with($v, ']')) {
                $c = strlen($v);
                $v = substr($v, 1, $c - 2);
                $v = explode("\t", $v);
            }
            // value is a boolean
            else if ($v === 'true' || $v === 'false') {
                $v = $v === 'true';
            }

            $cfg[$s][$k] = $v;
        }
    }
}

if ($cfg['metadata']['type'] === 'sql' && !empty($cfg['metadata']['host'])) {
    $cfg['metadata']['url'] = "{$cfg['metadata']['driver']}:host={$cfg['metadata']['host']};dbname={$cfg['metadata']['name']};port={$cfg['metadata']['port']}";
}

define("CONFIG", $cfg);
define("CLIENT_REQUIRES_JSON", isset($_SERVER["HTTP_ACCEPT"]) && $_SERVER["HTTP_ACCEPT"] == "application/json");

// library existence check
$imagemagick = null;

if (function_exists('shell_exec')) {
    if (shell_exec("which magick")) {
        $imagemagick = [
            "identify" => "magick identify",
            "convert" => "magick"
        ];
    } else if (shell_exec("which identify") && shell_exec("which convert")) {
        $imagemagick = [
            "identify" => "identify",
            "convert" => "convert"
        ];
    }
}

define("IMAGEMAGICK_COMMAND", $imagemagick);

define("THEME_LIST", array_map(fn($x) => basename($x), glob("{$_SERVER['DOCUMENT_ROOT']}/static/themes/*", GLOB_ONLYDIR)));


// -- setting up cache system
$mem = null;

if ($cfg['memcached']['hosts']) {
    $hosts = explode("\t", $cfg['memcached']['hosts']);
    $hs = [];
    foreach ($hosts as $host) {
        $parts = explode(" ", $host, 3);
        $h = $parts[0];
        $p = $parts[1];
        $w = $parts[2] ?? 0;
        array_push($hs, [$h, $p, $w]);
    }

    if (extension_loaded('memcached') && !$cfg['memcached']['use_redis']) {
        $mem = new Memcached();
        foreach ($hs as $h) {
            $mem->addServer($h[0], $h[1], $h[2]);
        }
    } else if (extension_loaded('redis') && count($hs) > 0) {
        $mem = new Redis();
        $h = $hs[0];
        $mem->connect($h[0], $h[1]);
        $mem->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
    }
}

define("MEMCACHED", $mem);

include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/i18n.php";