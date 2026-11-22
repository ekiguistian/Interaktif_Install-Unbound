<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const APP_ENV_FILE = '/etc/unbound-web/.env';

function load_app_env(string $file): array
{
    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException('File konfigurasi /etc/unbound-web/.env belum tersedia. Jalankan kembali install.sh untuk menyiapkan aplikasi.');
    }

    $values = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $match)) {
            continue;
        }
        $value = trim($match[2]);
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $quote = $value[0];
            $value = substr($value, 1, -1);
            if ($quote === '"') {
                $value = stripcslashes($value);
            }
        }
        $values[$match[1]] = $value;
    }
    return $values;
}

try {
    $APP_CONFIG = load_app_env(APP_ENV_FILE);
} catch (Throwable $error) {
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); }
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo $error->getMessage(), "\n";
    exit;
}

function app_env(string $key, string $default = ''): string
{
    global $APP_CONFIG;
    return (string)($APP_CONFIG[$key] ?? $default);
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $host = app_env('DB_HOST', '127.0.0.1');
    $name = app_env('DB_NAME', 'unbound_web');
    if (!preg_match('/^[A-Za-z0-9_.:-]{1,253}$/', $host) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
        throw new RuntimeException('Konfigurasi database tidak valid.');
    }
    $dsn = "mysql:host=$host;dbname=$name;charset=utf8mb4";
    $pdo = new PDO($dsn, app_env('DB_USER'), app_env('DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    return $pdo;
}

if (PHP_SAPI !== 'cli') {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    header('Cache-Control: no-store');
    session_name('unbound_web_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com data:; connect-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
}
