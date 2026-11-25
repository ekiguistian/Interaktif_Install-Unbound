<?php
declare(strict_types=1);
require_once __DIR__ . '/domains.php';

const FILTER_OUTPUT = '/etc/unbound/blocklist/blocklist.conf';
const WHITELIST_OUTPUT = '/etc/unbound/blocklist/whitelist.conf';
const FILTER_INCLUDE = '/etc/unbound/unbound.conf.d/90-unbound-web-blocklist.conf';
const FILTER_STATE = '/var/lib/unbound-web-filters';
const FILTER_DEFAULT_WHITELIST = '';
const FILTER_DEFAULT_REDIRECT = '192.168.1.2';

function filter_options(PDO $pdo): array
{
    $settings = $pdo->query("SELECT setting_key,setting_value FROM app_settings WHERE setting_key IN ('whitelist_url','blocklist_redirect_ip')")->fetchAll(PDO::FETCH_KEY_PAIR);
    return ['whitelist_url' => $settings['whitelist_url'] ?? FILTER_DEFAULT_WHITELIST,
        'redirect_ip' => $settings['blocklist_redirect_ip'] ?? FILTER_DEFAULT_REDIRECT];
}

function filter_normalize(string $value, bool $block): ?string
{
    $value = strtolower(trim($value));
    if (preg_match('~^[a-z][a-z0-9+.-]*://~', $value)) {
        $value = (string)(parse_url($value, PHP_URL_HOST) ?: '');
    } elseif (str_starts_with($value, '||')) {
        $value = preg_split('/[\^\/\$]/', substr($value, 2), 2)[0];
    }
    if (str_starts_with($value, '*.')) $value = substr($value, 2);
    $value = rtrim($value, '.');
    if ($block && str_starts_with($value, 'www.')) $value = substr($value, 4);
    return valid_domain($value) ? $value : null;
}

function filter_json_values(mixed $data): Generator
{
    if (is_string($data)) { yield $data; return; }
    if (!is_array($data)) return;
    foreach ($data as $key => $value) {
        if (is_string($key)) yield $key;
        yield from filter_json_values($value);
    }
}

/** Read domain, hosts, URL, Adblock, CSV and JSON feeds without treating HTML as a feed. */
function filter_read_domains(string $path, bool $block): Generator
{
    $handle = fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('File sumber tidak dapat dibaca.');
    try {
        $prefix = ltrim((string)fread($handle, 1024), "\xEF\xBB\xBF \t\r\n");
        rewind($handle);
        if (str_starts_with($prefix, '<')) throw new RuntimeException('Sumber berisi HTML, bukan daftar domain.');
        if (str_starts_with($prefix, '{') || (str_starts_with($prefix, '[') && !str_starts_with($prefix, '[Adblock'))) {
            $json = json_decode(ltrim((string)stream_get_contents($handle), "\xEF\xBB\xBF"), true, 64, JSON_THROW_ON_ERROR);
            foreach (filter_json_values($json) as $value) {
                $domain = filter_normalize($value, $block);
                if ($domain !== null) yield $domain;
            }
            return;
        }
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $first = ltrim((string)($row[0] ?? ''), "\xEF\xBB\xBF \t");
            if ($first === '' || preg_match('/^(?:#|!|@@|\[Adblock)/', $first)) continue;
            foreach ($row as $cell) {
                // Hosts files can contain several aliases; remove inline comments first.
                $cell = preg_replace('/\s+#.*$/', '', (string)$cell);
                foreach (preg_split('/\s+/', trim($cell)) as $value) {
                    if (str_contains($value, '##') || str_contains($value, '#@#')) continue;
                    $domain = filter_normalize(ltrim($value, "\xEF\xBB\xBF"), $block);
                    if ($domain !== null) yield $domain;
                }
            }
        }
    } finally { fclose($handle); }
}

function filter_is_allowed(string $domain, array $whitelist): bool
{
    do {
        if (isset($whitelist[$domain])) return true;
        $dot = strpos($domain, '.');
        $domain = $dot === false ? '' : substr($domain, $dot + 1);
    } while (str_contains($domain, '.'));
    return false;
}

function filter_config_lines(array $blocked, string $redirect): Generator
{
    if (filter_var($redirect, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) throw new RuntimeException('IP redirect tidak valid.');
    yield "# Dikelola Unbound Web Manager.\n";
    foreach ($blocked as $domain => $_) {
        yield 'local-zone: "' . $domain . '." redirect' . "\n";
        yield 'local-data: "' . $domain . '. A ' . $redirect . '"' . "\n";
    }
}

function whitelist_config_lines(array $blocked, array $whitelist): Generator
{
    yield "# Dikelola Unbound Web Manager.\n";
    // Override a matching parent redirect without taking over unrelated local zones.
    foreach ($whitelist as $domain => $_) {
        $parent = $domain;
        while (($dot = strpos($parent, '.')) !== false) {
            $parent = substr($parent, $dot + 1);
            if (isset($blocked[$parent])) {
                yield 'local-zone: "' . $domain . '." transparent' . "\n";
                break;
            }
        }
    }
}

/** Detect an existing explicit/wildcard include, excluding the panel's own wrapper. */
function filter_has_include(string $config, string $target, array &$seen = [], bool $skipManaged = true): bool
{
    $real = realpath($config) ?: $config;
    if (isset($seen[$real]) || ($skipManaged && $real === FILTER_INCLUDE) || !is_file($config)) return false;
    $seen[$real] = true;
    if (count($seen) > 256) throw new RuntimeException('Terlalu banyak file include Unbound.');
    if (in_array($real, [FILTER_OUTPUT, WHITELIST_OUTPUT], true)) return false;
    foreach (new SplFileObject($config) as $line) {
        if (!preg_match('/^\s*include(?:-toplevel)?:\s*(?:"([^"]+)"|([^\s#]+))/', $line, $match)) continue;
        $pattern = $match[1] !== '' ? $match[1] : $match[2];
        if (!str_starts_with($pattern, '/') && !(PHP_OS_FAMILY === 'Windows' && preg_match('~^[A-Za-z]:/~', $pattern))) {
            $pattern = '/etc/unbound/' . $pattern;
        }
        if ($pattern === $target || fnmatch($pattern, $target, FNM_PATHNAME)) return true;
        foreach (glob($pattern) ?: [] as $child) {
            if (filter_has_include($child, $target, $seen, $skipManaged)) return true;
        }
    }
    return false;
}
