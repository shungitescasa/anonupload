<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/vendor/autoload.php";
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";

define('IS_JSON_REQUEST', isset($_SERVER['HTTP_ACCEPT']) && $_SERVER['HTTP_ACCEPT'] == 'application/json');

function send_json_response(mixed $data, string|null $message = null, int $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json');
    die(json_encode([
        'status_code' => $code,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_SLASHES));
}

function format_timestamp(string|DateTime $datetime)
{
    $dt = $datetime instanceof DateTime ? $datetime : new DateTime($datetime);
    $now = new DateTime();

    $diff = match ($dt->getTimestamp() < $now->getTimestamp()) {
        true => $dt->diff($now),
        false => $now->diff($dt)
    };

    $seconds = $diff->s;
    $days = $diff->d;
    $minutes = $diff->i;
    $hours = $diff->h;
    $years = $diff->y;
    $months = $diff->m;

    if ($years == 0 && $months == 0 && $days == 0 && $hours == 0 && $minutes == 0) {
        return _n('%d second', '%d seconds', $seconds);
    } else if ($years == 0 && $months == 0 && $days == 0 && $hours == 0) {
        return _n('%d minute', '%d minutes', $minutes);
    } else if ($years == 0 && $months == 0 && $days == 0) {
        return _n('%d hour', '%d hours', $hours);
    } else if ($years == 0 && $months == 0) {
        return _n('%d day', '%d days', $days);
    } else if ($years == 0) {
        return _n('%d month', '%d months', $months);
    } else {
        return _n('%d year', '%d years', $years);
    }
}

function format_filesize($file_size)
{
    $suffix = __('MB');
    $file_size /= 1024 * 1024; // MB

    if ($file_size >= 1024) {
        $file_size /= 1024;
        $suffix = __('GB');
    }

    return sprintf('%.2f%s', $file_size, $suffix);
}

function str_safe(string $s, int|null $max_length, bool $remove_new_lines = true): string
{
    $output = $s;

    if ($remove_new_lines) {
        $output = str_replace(PHP_EOL, "", $output);
    }

    $output = htmlspecialchars($output);
    $output = strip_tags($output);

    if ($max_length) {
        $output = substr($output, 0, $max_length);
    }

    $output = trim($output);

    return $output;
}

function get_commit(): ?array
{
    if ($s = file_get_contents("{$_SERVER['DOCUMENT_ROOT']}/.git/refs/heads/master")) {
        return [
            'sha' => $s
        ];
    }

    return null;
}

class HTTPException extends Exception
{
    private int $statusCode;
    public function __construct(string $message = "", int $statusCode = 400)
    {
        parent::__construct($message, 0, null);
        $this->statusCode = $statusCode;
    }

    public function as_response()
    {
        http_response_code($this->statusCode);
    }

    public function getStatusCode()
    {
        return $this->statusCode;
    }
}

/**
 * Returns request parameters from the query string merged with the request body.
 * PHP populates $_POST only for POST requests, so PATCH/DELETE bodies
 * (url-encoded or JSON) are parsed manually.
 */
function request_params(): array
{
    $body = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
        $body = $_POST;
    } else {
        $raw = file_get_contents('php://input');
        $content_type = strtolower($_SERVER['CONTENT_TYPE'] ?? '');

        if ($raw !== false && $raw !== '') {
            if (str_starts_with($content_type, 'application/json')) {
                $body = json_decode($raw, true);
                if (!is_array($body)) {
                    $body = [];
                }
            } else {
                parse_str($raw, $body);
            }
        }
    }

    return array_merge($_GET, $body);
}
