<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/CommandRunner.php';

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function verify_csrf(?string $token): void
{
    if ($token === null || !hash_equals(csrf_token(), $token)) {
        json_response(['ok' => false, 'message' => 'Sesi formulir sudah tidak berlaku. Muat ulang halaman.'], 419);
    }
}

function admin_logged_in(): bool
{
    return isset($_SESSION['admin_user']) && is_string($_SESSION['admin_user']);
}

function require_admin(bool $json = false): void
{
    if (admin_logged_in()) {
        return;
    }
    if ($json) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan.'], 401);
    }
    $next = rawurlencode((string)($_SERVER['REQUEST_URI'] ?? '/settings'));
    header("Location: /login?next=$next", true, 302);
    exit;
}

function valid_domain(string $domain): bool
{
    $domain = rtrim(strtolower(trim($domain)), '.');
    if (strlen($domain) < 1 || strlen($domain) > 253 || filter_var($domain, FILTER_VALIDATE_IP)) {
        return false;
    }
    $labels = explode('.', $domain);
    if (count($labels) < 2) {
        return false;
    }
    foreach ($labels as $label) {
        if (strlen($label) < 1 || strlen($label) > 63 || !preg_match('/^(?!-)[a-z0-9-]+(?<!-)$/', $label)) {
            return false;
        }
    }
    return true;
}

function valid_ip_or_cidr(string $value): bool
{
    $parts = explode('/', trim($value));
    if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
        return false;
    }
    if (isset($parts[1])) {
        if (!ctype_digit($parts[1])) {
            return false;
        }
        $max = str_contains($parts[0], ':') ? 128 : 32;
        if ((int)$parts[1] < 0 || (int)$parts[1] > $max) {
            return false;
        }
    }
    return true;
}

function valid_private_ipv4(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }
    $octets = array_map('intval', explode('.', $ip));
    return $octets[0] === 127 || $octets[0] === 10 ||
        ($octets[0] === 172 && $octets[1] >= 16 && $octets[1] <= 31) ||
        ($octets[0] === 192 && $octets[1] === 168);
}

function valid_public_https_url(string $url): bool
{
    if (strlen($url) > 2048) {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) ||
        isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int)$parts['port'] !== 443)) {
        return false;
    }
    $host = strtolower((string)$parts['host']);
    if (filter_var($host, FILTER_VALIDATE_IP) || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host)) {
        return false;
    }
    $addresses = gethostbynamel($host) ?: [];
    if (!$addresses) {
        return false;
    }
    foreach ($addresses as $address) {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
    }
    return true;
}

function system_status(): array
{
    $cacheFile = '/var/lib/unbound-web/system-status.json';
    if (is_readable($cacheFile) && (time() - (int)filemtime($cacheFile)) < 70) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['unbound'], $cached['zabbix'])) {
            return $cached;
        }
    }
    return read_service_status();
}

function read_service_status(): array
{
    $result = CommandRunner::run(['/usr/bin/sudo', '-n', '/usr/local/sbin/unbound-web-manage', 'service-status']);
    if ($result['code'] !== 0) {
        return ['unbound' => 'unknown', 'unbound_version' => '', 'zabbix' => 'unknown', 'error' => 'Status service belum dapat dibaca.'];
    }
    $status = ['unbound' => 'not-installed', 'unbound_version' => '', 'zabbix' => 'not-installed'];
    foreach (preg_split('/\R/', trim($result['output'])) as $line) {
        if (preg_match('/^(unbound|zabbix)=([a-z-]+)$/', $line, $match)) {
            $status[$match[1]] = $match[2];
        } elseif (preg_match('/^unbound_version=([0-9.]+)$/', $line, $match)) {
            $status['unbound_version'] = $match[1];
        }
    }
    return $status;
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

function fail_validation(string $message): never
{
    json_response(['ok' => false, 'message' => $message], 422);
}

const FIREWALL_PROTOCOLS = ['any', 'tcp-udp', 'tcp', 'udp', 'icmp', 'esp', 'ah', 'gre', 'igmp', 'vrrp'];

function manage(array $args, ?string $input = null, int $timeout = 45): array
{
    return CommandRunner::run(['/usr/bin/sudo', '-n', '/usr/local/sbin/unbound-web-manage', ...$args], $timeout, $input);
}
