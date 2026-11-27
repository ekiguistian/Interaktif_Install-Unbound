<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function page_start(string $title, bool $admin = false, string $view = ''): void
{
    $fullTitle = e($title) . ' | Unbound Web Manager';
    $csrfMeta = admin_logged_in() ? '<meta name="csrf-token" content="' . e(csrf_token()) . '">' : '';
    $cssVersion = (string)(@filemtime(__DIR__ . '/../public/assets/app.css') ?: 1);
    $themeVersion = (string)(@filemtime(__DIR__ . '/../public/assets/theme.js') ?: 1);
    $bodyClass = $view === 'login' ? 'login-page' : '';
    echo <<<HTML
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  $csrfMeta
  <title>$fullTitle</title>
  <script src="/assets/theme.js?v=$themeVersion"></script>
  <link rel="stylesheet" href="/assets/utilities.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
  <script src="/assets/chart.umd.js"></script>
  <link rel="stylesheet" href="/assets/app.css?v=$cssVersion">
</head>
<body class="min-h-screen antialiased $bodyClass">
<div class="site-frame">
HTML;
    if ($admin) {
        echo '<nav class="floating-nav panel" aria-label="Navigasi admin">';
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $path = str_starts_with((string)$path, '/admin/') ? '/settings/' . substr($path, 7) : $path;
        $unboundLinks = [
            ['/settings/unbound', 'Kontrol & file'],
            ['/settings/unbound-dot', 'DoT'],
            ['/settings/unbound-doh', 'DoH'],
            ['/settings/unbound-log', 'Log langsung'],
            ['/settings/blocklists', 'Blocklist & Whitelist'],
            ['/settings/cron', 'Jadwal'],
            ['/settings/dnsperf', 'Uji DNS'],
            ['/settings/domains', 'Domain uji'],
        ];
        $unboundSection = in_array($path, array_column($unboundLinks, 0), true);
        $links = [
            ['/', 'Monitor', 'fa-wave-square', $path === '/'],
            ['/settings/unbound', 'Unbound', 'fa-server', $unboundSection],
            ['/settings/zabbix', 'Zabbix Agent', 'fa-chart-line', $path === '/settings/zabbix'],
            ['/settings/firewall', 'Firewall', 'fa-fire', $path === '/settings/firewall'],
            ['/settings/update', 'Pembaruan', 'fa-cloud-arrow-down', $path === '/settings/update'],
            ['/settings/account', 'Akun', 'fa-user-gear', $path === '/settings/account'],
        ];
        foreach ($links as [$href, $label, $icon, $active]) {
            $classes = $active ? 'nav-link nav-active' : 'nav-link';
            $current = $path === $href ? ' aria-current="page"' : ($active ? ' aria-current="location"' : '');
            echo '<a class="' . $classes . '" href="' . e($href) . '"' . $current . '><i class="fa-solid ' . e($icon) . '" aria-hidden="true"></i><span>' . e($label) . '</span></a>';
        }
        echo '<form method="post" action="/logout"><input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
        echo '<button type="submit" class="nav-link"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i>Keluar</button></form></nav>';
    }
    $mainClass = $view === 'dashboard' ? 'monitor-page' : ($view === 'login' ? 'login-main' : 'max-w-7xl');
    echo '<main class="mx-auto w-full px-5 py-8 sm:px-8 ' . $mainClass . '">';
    echo '<header class="page-heading"><div>';
    if ($view === 'login') {
        echo '<span class="login-brand"><i class="fa-solid fa-server" aria-hidden="true"></i> Unbound Web Manager</span>';
    } else {
        echo '<h1>' . e($title) . '</h1>';
    }
    echo '</div><label class="theme-picker"><i class="fa-solid fa-circle-half-stroke" aria-hidden="true"></i><span>Tema</span><select data-theme-picker aria-label="Tema tampilan"><option value="system">Sistem</option><option value="light">Terang</option><option value="dark">Gelap</option></select></label></header>';
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
    echo '</main><footer class="site-footer">Created With Love By Eki Guistian - IT Magang</footer></div>';
    echo '<script src="/assets/app.js?v=' . e($jsVersion) . '" defer></script></body></html>';
}

function card(string $title, string $icon, string $body, string $href): void
{
    echo '<a href="' . e($href) . '" class="panel group block p-5 transition hover:border-cyan-400/40 hover:bg-slate-900">';
    echo '<span class="mb-4 grid h-10 w-10 place-items-center rounded-xl bg-cyan-400/10 text-cyan-300"><i class="fa-solid ' . e($icon) . '"></i></span>';
    echo '<h2 class="font-semibold text-white">' . e($title) . '</h2><p class="mt-2 text-sm leading-6 text-slate-400">' . e($body) . '</p>';
    echo '<span class="mt-4 inline-block text-sm font-medium text-cyan-300">Buka <i class="fa-solid fa-arrow-right ml-1 transition group-hover:translate-x-1"></i></span></a>';
}

function panel_password_fields(bool $required = true): void
{
    $mandatory = $required ? ' required' : '';
    ?>
    <label class="block">Password baru
      <input class="form-input" name="new_password" type="password" autocomplete="new-password"
        pattern="<?=e(PANEL_PASSWORD_PATTERN)?>" title="Gunakan 4–12 karakter" aria-describedby="password-hint"<?=$mandatory?>>
      <span id="password-hint" class="mt-1 block text-sm text-slate-400">Gunakan 4–12 karakter.<?=$required ? '' : ' Kosongkan jika password tetap sama.'?></span>
    </label>
    <label class="block">Ulangi password baru
      <input class="form-input" name="confirm_password" type="password" autocomplete="new-password"
        pattern="<?=e(PANEL_PASSWORD_PATTERN)?>" title="Gunakan 4–12 karakter"<?=$mandatory?>>
    </label>
    <?php
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

// System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
