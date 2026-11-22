<?php
declare(strict_types=1);

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$path = rawurldecode($path);
if ($path === '/index') {
    header('Location: /', true, 301);
    exit;
}

if (preg_match('~\.php$~i', $path)) {
    $clean = substr($path, 0, -4);
    $aliases = [
        '/index' => '/',
        '/admin' => '/settings',
        '/admin/index' => '/settings',
        '/admin/unbound' => '/settings/unbound',
        '/admin/blocklists' => '/settings/blocklists',
        '/admin/zabbix' => '/settings/zabbix',
        '/admin/firewall' => '/settings/firewall',
        '/admin/cron' => '/settings/cron',
        '/admin/dnsperf' => '/settings/dnsperf',
        '/admin/domains' => '/settings/domains',
        '/api/status' => '/api/stats',
        '/api/action' => '/api/action',
        '/api/stream' => '/api/stream',
    ];
    $clean = $aliases[$clean] ?? $clean;
    header('Location: ' . ($clean === '' ? '/' : $clean) . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
    exit;
}

$routes = [
    '/' => __DIR__ . '/pages/dashboard.php',
    '/login' => __DIR__ . '/login.php',
    '/logout' => __DIR__ . '/logout.php',
    '/settings' => __DIR__ . '/admin/index.php',
    '/settings/unbound' => __DIR__ . '/admin/unbound.php',
    '/settings/blocklists' => __DIR__ . '/admin/blocklists.php',
    '/settings/zabbix' => __DIR__ . '/admin/zabbix.php',
    '/settings/firewall' => __DIR__ . '/admin/firewall.php',
    '/settings/cron' => __DIR__ . '/admin/cron.php',
    '/settings/dnsperf' => __DIR__ . '/admin/dnsperf.php',
    '/settings/domains' => __DIR__ . '/admin/domains.php',
    '/admin' => __DIR__ . '/admin/index.php',
    '/admin/index' => __DIR__ . '/admin/index.php',
    '/admin/unbound' => __DIR__ . '/admin/unbound.php',
    '/admin/blocklists' => __DIR__ . '/admin/blocklists.php',
    '/admin/zabbix' => __DIR__ . '/admin/zabbix.php',
    '/admin/firewall' => __DIR__ . '/admin/firewall.php',
    '/admin/cron' => __DIR__ . '/admin/cron.php',
    '/admin/dnsperf' => __DIR__ . '/admin/dnsperf.php',
    '/admin/domains' => __DIR__ . '/admin/domains.php',
    '/api/stats' => __DIR__ . '/api/status.php',
    '/api/status' => __DIR__ . '/api/status.php',
    '/api/metrics' => __DIR__ . '/api/status.php',
    '/api/action' => __DIR__ . '/api/action.php',
    '/api/stream' => __DIR__ . '/api/stream.php',
];

if (!isset($routes[$path])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Halaman tidak ditemukan.\n";
    exit;
}

require $routes[$path];
