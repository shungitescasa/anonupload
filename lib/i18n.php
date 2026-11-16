<?php
const I18N_SOURCE_LANGUAGE = 'en';
const I18N_SOURCE_LANGUAGE_NAME = 'English';
const I18N_DOMAIN = 'messages';
const I18N_COOKIE = 'lang';

define('I18N_DIRECTORY', "{$_SERVER['DOCUMENT_ROOT']}/locale");

class MoCatalog
{
    /** @var array<string, string[]> msgid => translations (one per plural form) */
    private array $messages = [];
    private array $headers = [];
    private int $plural_count = 2;
    private ?Closure $plural_rule = null;

    public function __construct(string $path)
    {
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) < 28) {
            throw new RuntimeException("Failed to read translation catalog: $path");
        }

        $magic = unpack('V', $data)[1];
        $endian = match ($magic) {
            0x950412de => 'V',
            0xde120495 => 'N',
            default => throw new RuntimeException("Not a gettext catalog: $path")
        };

        [, $count, $originals, $translations] = array_values(unpack("{$endian}4", $data, 4));

        for ($i = 0; $i < $count; $i++) {
            [$olen, $ooff] = array_values(unpack("{$endian}2", $data, $originals + $i * 8));
            [$tlen, $toff] = array_values(unpack("{$endian}2", $data, $translations + $i * 8));

            // "singular\0plural" -> "singular"
            $msgid = explode("\0", substr($data, $ooff, $olen), 2)[0];
            $this->messages[$msgid] = explode("\0", substr($data, $toff, $tlen));
        }

        foreach (explode("\n", $this->messages[''][0] ?? '') as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $this->headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }
        unset($this->messages['']);

        $plural_forms = $this->headers['plural-forms'] ?? '';
        if (preg_match('/nplurals\s*=\s*(\d+)\s*;\s*plural\s*=\s*([^;]+)/', $plural_forms, $m)) {
            $this->plural_count = max(1, intval($m[1]));
            $this->plural_rule = PluralExpression::compile($m[2]);
        }
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function translate(string $msgid): ?string
    {
        $t = $this->messages[$msgid][0] ?? '';
        return $t !== '' ? $t : null;
    }

    public function translate_plural(string $msgid, int $n): ?string
    {
        $forms = $this->messages[$msgid] ?? null;
        if ($forms === null) {
            return null;
        }

        $index = $this->plural_rule ? ($this->plural_rule)($n) : ($n === 1 ? 0 : 1);
        $index = max(0, min($index, $this->plural_count - 1));

        $t = $forms[$index] ?? '';
        return $t !== '' ? $t : null;
    }
}

/**
 * Evaluates the C-like "plural=" expression from the Plural-Forms header without eval().
 */
class PluralExpression
{
    private array $tokens;
    private int $pos = 0;

    private function __construct(string $expression)
    {
        preg_match_all('/\s*(\d+|n|&&|\|\||==|!=|<=|>=|[-+*\/%<>!?:()])/A', $expression, $m);
        if (implode('', $m[0]) !== $expression) {
            throw new RuntimeException("Invalid plural expression: $expression");
        }
        $this->tokens = $m[1];
    }

    public static function compile(string $expression): Closure
    {
        $parser = new self(trim($expression));
        $node = $parser->ternary();
        if ($parser->pos !== count($parser->tokens)) {
            throw new RuntimeException("Invalid plural expression: $expression");
        }
        return fn(int $n): int => intval($node($n));
    }

    private function peek(): ?string
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function expect(string $token): void
    {
        if ($this->peek() !== $token) {
            throw new RuntimeException("Expected '$token' in plural expression");
        }
        $this->pos++;
    }

    private function ternary(): Closure
    {
        $condition = $this->binary(0);
        if ($this->peek() !== '?') {
            return $condition;
        }
        $this->pos++;
        $then = $this->ternary();
        $this->expect(':');
        $else = $this->ternary();
        return fn($n) => $condition($n) ? $then($n) : $else($n);
    }

    private const PRECEDENCE = [
        ['||'],
        ['&&'],
        ['==', '!='],
        ['<', '>', '<=', '>='],
        ['+', '-'],
        ['*', '/', '%'],
    ];

    private function binary(int $level): Closure
    {
        if ($level >= count(self::PRECEDENCE)) {
            return $this->unary();
        }

        $left = $this->binary($level + 1);
        while (in_array($op = $this->peek(), self::PRECEDENCE[$level], true)) {
            $this->pos++;
            $right = $this->binary($level + 1);
            $l = $left;
            $left = fn($n) => self::apply($op, $l($n), $right($n));
        }
        return $left;
    }

    private function unary(): Closure
    {
        $token = $this->peek();
        $this->pos++;

        if ($token === '!') {
            $operand = $this->unary();
            return fn($n) => intval(!$operand($n));
        }
        if ($token === '(') {
            $inner = $this->ternary();
            $this->expect(')');
            return $inner;
        }
        if ($token === 'n') {
            return fn($n) => $n;
        }
        if ($token !== null && ctype_digit($token)) {
            $value = intval($token);
            return fn($n) => $value;
        }

        throw new RuntimeException("Unexpected token in plural expression: " . ($token ?? 'end'));
    }

    private static function apply(string $op, int $a, int $b): int
    {
        return match ($op) {
            '||' => intval($a || $b),
            '&&' => intval($a && $b),
            '==' => intval($a === $b),
            '!=' => intval($a !== $b),
            '<' => intval($a < $b),
            '>' => intval($a > $b),
            '<=' => intval($a <= $b),
            '>=' => intval($a >= $b),
            '+' => $a + $b,
            '-' => $a - $b,
            '*' => $a * $b,
            '/' => $b === 0 ? 0 : intdiv($a, $b),
            '%' => $b === 0 ? 0 : $a % $b,
        };
    }
}

function i18n_catalog_path(string $code): string
{
    return I18N_DIRECTORY . "/$code/LC_MESSAGES/" . I18N_DOMAIN . '.mo';
}

/**
 * Returns the codes of all languages that can be selected, the source language first.
 */
function i18n_available_languages(): array
{
    static $languages = null;

    if ($languages === null) {
        $languages = [I18N_SOURCE_LANGUAGE];
        foreach (glob(i18n_catalog_path('*')) ?: [] as $path) {
            $code = basename(dirname($path, 2));
            if ($code !== I18N_SOURCE_LANGUAGE && preg_match('/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})*$/', $code)) {
                $languages[] = $code;
            }
        }
    }

    return $languages;
}

function i18n_is_available(mixed $code): bool
{
    return is_string($code) && in_array($code, i18n_available_languages(), true);
}

/**
 * Language name written in that language, taken from the "X-Language-Name" header of its catalog.
 */
function i18n_language_name(string $code): string
{
    if ($code === I18N_SOURCE_LANGUAGE) {
        return I18N_SOURCE_LANGUAGE_NAME;
    }

    try {
        $name = (new MoCatalog(i18n_catalog_path($code)))->header('X-Language-Name');
    } catch (RuntimeException $e) {
        $name = null;
    }

    if (!$name && class_exists('Locale')) {
        $name = Locale::getDisplayLanguage($code, $code);
    }

    return $name ?: $code;
}

/**
 * Picks a language from the browser's Accept-Language header, or the instance's default one.
 */
function i18n_detect_language(): string
{
    $languages = i18n_available_languages();
    $lookup = [];
    foreach ($languages as $code) {
        $lookup[strtolower($code)] = $code;
    }

    $ranges = [];
    foreach (explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') as $i => $part) {
        $params = explode(';', trim($part));
        $tag = strtolower(str_replace('-', '_', trim($params[0])));
        if ($tag === '' || $tag === '*') {
            continue;
        }

        $q = 1.0;
        foreach (array_slice($params, 1) as $param) {
            if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $param, $m)) {
                $q = floatval($m[1]);
            }
        }
        if ($q > 0) {
            $ranges[] = [$tag, $q, $i];
        }
    }

    usort($ranges, fn($a, $b) => [$b[1], $a[2]] <=> [$a[1], $b[2]]);

    foreach ($ranges as [$tag]) {
        // exact match (pt_br), then the primary language (pt)
        if (isset($lookup[$tag])) {
            return $lookup[$tag];
        }
        $primary = explode('_', $tag)[0];
        if (isset($lookup[$primary])) {
            return $lookup[$primary];
        }
    }

    $default = CONFIG['instance']['defaultlanguage'] ?? I18N_SOURCE_LANGUAGE;
    return i18n_is_available($default) ? $default : I18N_SOURCE_LANGUAGE;
}

final class I18n
{
    public static string $language = I18N_SOURCE_LANGUAGE;
    public static ?MoCatalog $catalog = null;
}

/**
 * Switches the language used by __() and _n(). Returns false if it is not available.
 */
function i18n_use(string $language): bool
{
    if (!i18n_is_available($language)) {
        return false;
    }

    $catalog = null;
    if ($language !== I18N_SOURCE_LANGUAGE) {
        try {
            $catalog = new MoCatalog(i18n_catalog_path($language));
        } catch (RuntimeException $e) {
            error_log($e->getMessage());
            return false;
        }
    }

    I18n::$language = $language;
    I18n::$catalog = $catalog;
    return true;
}

/**
 * Code of the current language, e.g. "en" or "pt_BR".
 */
function i18n_language(): string
{
    return I18n::$language;
}

function i18n_init(): void
{
    $cookie = $_COOKIE[I18N_COOKIE] ?? null;
    if (!is_string($cookie) || !i18n_use($cookie)) {
        i18n_use(i18n_detect_language());
    }
}

/**
 * Formats a date like "Nov 14, 2026" in the current language.
 */
function format_date(DateTimeInterface $date): string
{
    if (class_exists('IntlDateFormatter')) {
        $formatter = new IntlDateFormatter(I18n::$language, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, $date->getTimezone());
        $formatted = $formatter->format($date);
        if ($formatted !== false) {
            return $formatted;
        }
    }

    return $date->format('M d, Y');
}

/**
 * Current language as a BCP 47 tag for the "lang" attribute (pt_BR -> pt-BR).
 */
function html_lang(): string
{
    return str_replace('_', '-', I18n::$language);
}

/**
 * Translates a string. Extra arguments are formatted into it with sprintf().
 */
function __(string $msgid, mixed ...$args): string
{
    $text = I18n::$catalog?->translate($msgid) ?? $msgid;
    return empty($args) ? $text : vsprintf($text, $args);
}

/**
 * Translates a string that depends on a number. If no extra arguments are given,
 * the number itself is formatted into the string.
 */
function _n(string $singular, string $plural, int $n, mixed ...$args): string
{
    $text = I18n::$catalog?->translate_plural($singular, $n) ?? ($n === 1 ? $singular : $plural);
    return vsprintf($text, empty($args) ? [$n] : $args);
}

i18n_init();
