<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function page_start(string $title, bool $admin = false, bool $liveMonitor = false): void
{
    $fullTitle = e($title) . ' | Unbound Web Manager';
    $csrfMeta = admin_logged_in() ? '<meta name="csrf-token" content="' . e(csrf_token()) . '">' : '';
    $cssVersion = (string)(@filemtime(__DIR__ . '/../public/assets/app.css') ?: 1);
    echo <<<HTML
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  $csrfMeta
  <title>$fullTitle</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
  <link rel="stylesheet" href="/assets/app.css?v=$cssVersion">
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
<div class="min-h-screen">
HTML;
    if ($admin) {
        echo '<nav class="floating-nav panel" aria-label="Navigasi admin">';
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $path = str_starts_with((string)$path, '/admin/') ? '/settings/' . substr($path, 7) : $path;
        $unboundLinks = [
            ['/settings/unbound', 'Konfigurasi'],
            ['/settings/blocklists', 'Blocklist & Whitelist'],
            ['/settings/cron', 'Cron'],
            ['/settings/dnsperf', 'Uji DNS'],
            ['/settings/domains', 'Domain uji'],
        ];
        $unboundSection = in_array($path, array_column($unboundLinks, 0), true);
        $links = [
            ['/settings/unbound', 'Unbound', 'fa-server', $unboundSection],
            ['/settings/zabbix', 'Zabbix Agent', 'fa-chart-line', $path === '/settings/zabbix'],
            ['/settings/firewall', 'Firewall', 'fa-fire', $path === '/settings/firewall'],
        ];
        foreach ($links as [$href, $label, $icon, $active]) {
            $classes = $active ? 'nav-link nav-active' : 'nav-link';
            $current = $path === $href ? ' aria-current="page"' : ($active ? ' aria-current="location"' : '');
            echo '<a class="' . $classes . '" href="' . e($href) . '"' . $current . '><i class="fa-solid ' . e($icon) . '" aria-hidden="true"></i><span>' . e($label) . '</span></a>';
        }
        echo '<form method="post" action="/logout"><input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
        echo '<button type="submit" class="nav-link"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i>Log Out</button></form></nav>';
    } elseif ($liveMonitor) {
        $needsInstall = system_status()['unbound'] !== 'active';
        $destination = $needsInstall ? '/wizard' : '/settings';
        $label = $needsInstall ? 'Install Wizard' : 'Setting';
        $icon = $needsInstall ? 'fa-wand-magic-sparkles' : 'fa-sliders';
        echo '<nav class="floating-nav panel" aria-label="Navigasi monitoring"><a id="main-action" class="nav-link" href="' . $destination . '"><i class="fa-solid ' . $icon . '" aria-hidden="true"></i><span id="main-action-text">' . $label . '</span></a></nav>';
    }
    echo '<main class="mx-auto w-full max-w-7xl px-5 py-8 sm:px-8">';
    echo '<header class="mb-8 flex flex-wrap items-center justify-between gap-4"><div><p class="text-xs font-semibold uppercase tracking-[.24em] text-cyan-300">DNS Operations</p><h1 class="mt-2 text-2xl font-semibold tracking-tight">' . e($title) . '</h1></div>';
    if ($admin && admin_logged_in()) {
        echo '<a class="btn-secondary" href="/">Live Monitor</a>';
    }
    echo '</header>';
    if ($admin && $unboundSection) {
        echo '<nav class="mb-5 flex flex-wrap gap-2" aria-label="Pengaturan Unbound">';
        foreach ($unboundLinks as [$href, $label]) {
            $current = $path === $href ? ' aria-current="page"' : '';
            echo '<a class="nav-link' . ($current !== '' ? ' nav-active' : '') . '" href="' . e($href) . '"' . $current . '>' . e($label) . '</a>';
        }
        echo '</nav>';
    }
}

function page_end(): void
{
    $jsVersion = (string)(@filemtime(__DIR__ . '/../public/assets/app.js') ?: 1);
    echo '</main></div><script src="/assets/app.js?v=' . e($jsVersion) . '" defer></script></body></html>';
}

function card(string $title, string $icon, string $body, string $href): void
{
    echo '<a href="' . e($href) . '" class="panel group block p-5 transition hover:border-cyan-400/40 hover:bg-slate-900">';
    echo '<span class="mb-4 grid h-10 w-10 place-items-center rounded-xl bg-cyan-400/10 text-cyan-300"><i class="fa-solid ' . e($icon) . '"></i></span>';
    echo '<h2 class="font-semibold text-white">' . e($title) . '</h2><p class="mt-2 text-sm leading-6 text-slate-400">' . e($body) . '</p>';
    echo '<span class="mt-4 inline-block text-sm font-medium text-cyan-300">Buka modul <i class="fa-solid fa-arrow-right ml-1 transition group-hover:translate-x-1"></i></span></a>';
}

function field(string $label, string $name, string $value = '', string $type = 'text', string $hint = '', bool $required = true): void
{
    echo '<label class="block"><span class="mb-2 block text-sm font-medium text-slate-200">' . e($label) . '</span>';
    echo '<input class="form-input" name="' . e($name) . '" type="' . e($type) . '" value="' . e($value) . '"' . ($required ? ' required' : '') . '>';
    if ($hint !== '') {
        echo '<span class="mt-1.5 block text-xs text-slate-500">' . e($hint) . '</span>';
    }
    echo '</label>';
}

function notice(string $text, string $kind = 'info'): void
{
    $styles = $kind === 'error' ? 'border-rose-500/40 bg-rose-500/10 text-rose-200' : 'border-cyan-500/30 bg-cyan-500/10 text-cyan-100';
    echo '<div class="mb-5 rounded-xl border px-4 py-3 text-sm ' . $styles . '">' . e($text) . '</div>';
}
