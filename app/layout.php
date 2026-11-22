<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function page_start(string $title, bool $admin = false): void
{
    $fullTitle = e($title) . ' | Unbound Web Manager';
    $csrfMeta = admin_logged_in() ? '<meta name="csrf-token" content="' . e(csrf_token()) . '">' : '';
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
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
<div class="min-h-screen lg:flex">
HTML;
    if ($admin) {
        echo '<aside class="w-full border-b border-slate-800 bg-slate-900/80 p-5 lg:fixed lg:inset-y-0 lg:w-64 lg:border-b-0 lg:border-r">';
        echo '<a href="/settings" class="flex items-center gap-3 text-lg font-semibold"><span class="grid h-10 w-10 place-items-center rounded-xl bg-cyan-400/10 text-cyan-300"><i class="fa-solid fa-shield-halved"></i></span>Unbound Control</a>';
        echo '<nav class="mt-7 grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-1">';
        $links = [
            ['/settings', 'Ikhtisar', 'fa-gauge-high'],
            ['/settings/unbound', 'Konfigurasi DNS', 'fa-sliders'],
            ['/settings/blocklists', 'Blocklist & whitelist', 'fa-shield-halved'],
            ['/settings/zabbix', 'Zabbix Agent', 'fa-chart-line'],
            ['/settings/firewall', 'Firewall', 'fa-fire'],
            ['/settings/cron', 'Jadwal sistem', 'fa-clock'],
            ['/settings/dnsperf', 'Uji dnsperf', 'fa-gauge'],
            ['/settings/domains', 'Domain pengujian', 'fa-list'],
        ];
        foreach ($links as [$href, $label, $icon]) {
            $active = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) === $href;
            $classes = $active ? 'nav-link nav-active' : 'nav-link';
            echo '<a class="' . $classes . '" href="' . e($href) . '"><i class="fa-solid ' . e($icon) . ' w-5 text-center"></i><span>' . e($label) . '</span></a>';
        }
        echo '</nav><div class="mt-7 flex items-center justify-between border-t border-slate-800 pt-5 text-sm text-slate-400"><a href="/" class="hover:text-cyan-300"><i class="fa-solid fa-arrow-up-right-from-square mr-2"></i>Dashboard publik</a><a href="/logout" class="hover:text-rose-300">Keluar</a></div></aside>';
        echo '<main class="min-w-0 flex-1 p-5 sm:p-8 lg:ml-64">';
    } else {
        echo '<main class="mx-auto w-full max-w-7xl px-5 py-8 sm:px-8">';
    }
    echo '<header class="mb-8 flex flex-wrap items-center justify-between gap-4"><div><p class="text-xs font-semibold uppercase tracking-[.24em] text-cyan-300">DNS Operations</p><h1 class="mt-2 text-2xl font-semibold tracking-tight">' . e($title) . '</h1></div>';
    if ($admin && admin_logged_in()) {
        echo '<span class="rounded-full border border-slate-700 px-3 py-1.5 text-sm text-slate-300"><i class="fa-solid fa-user-shield mr-2 text-cyan-300"></i>' . e($_SESSION['admin_user']) . '</span>';
    }
    echo '</header>';
}

function page_end(): void
{
    echo '</main></div><script src="/assets/app.js" defer></script></body></html>';
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
