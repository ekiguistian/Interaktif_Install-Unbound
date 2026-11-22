<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/layout.php';

if (admin_logged_in()) {
    header('Location: /settings');
    exit;
}
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals(csrf_token(), $csrf)) {
        $message = 'Sesi formulir berakhir. Muat ulang halaman.';
    } else {
        try {
            $statement = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE remote_addr = ? AND attempted_at > NOW() - INTERVAL 15 MINUTE');
            $statement->execute([client_ip()]);
            $tooMany = (int)$statement->fetchColumn() >= 8;
            $username = (string)($_POST['username'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $expectedUser = app_env('ADMIN_USER');
            $expectedPass = app_env('ADMIN_PASS');
            $valid = !$tooMany && $expectedUser !== '' && $expectedPass !== '' &&
                !str_starts_with($expectedPass, 'replace_with_') &&
                hash_equals($expectedUser, $username) && hash_equals($expectedPass, $password);
            if ($valid) {
                session_regenerate_id(true);
                $_SESSION['admin_user'] = $expectedUser;
                db()->prepare('DELETE FROM login_attempts WHERE remote_addr = ?')->execute([client_ip()]);
                $next = (string)($_POST['next'] ?? '/settings');
                if (!str_starts_with($next, '/settings') && $next !== '/wizard') {
                    $next = '/settings';
                }
                header('Location: ' . $next);
                exit;
            }
            db()->prepare('INSERT INTO login_attempts (remote_addr) VALUES (?)')->execute([client_ip()]);
            $message = $tooMany ? 'Terlalu banyak percobaan. Coba lagi setelah 15 menit.' : 'Username atau password tidak benar.';
        } catch (Throwable $error) {
            error_log('Unbound Web login: ' . $error->getMessage());
            $message = 'Database belum siap. Periksa konfigurasi .env.';
        }
    }
}
$next = (string)($_GET['next'] ?? $_POST['next'] ?? '/settings');
if (!str_starts_with($next, '/settings') && $next !== '/wizard') {
    $next = '/settings';
}
page_start('Login admin');
?>
<div class="mx-auto max-w-md">
  <section class="panel p-6 sm:p-8">
    <div class="mb-6 grid h-12 w-12 place-items-center rounded-2xl bg-cyan-400/10 text-xl text-cyan-300"><i class="fa-solid fa-lock"></i></div>
    <h2 class="text-xl font-semibold">Masuk ke Settings</h2>
    <p class="mt-2 text-sm leading-6 text-slate-400">Kredensial dibaca dari file <code>/etc/unbound-web/.env</code>. Ubah username atau password hanya melalui file tersebut.</p>
    <?php if ($message !== ''): ?><div class="mt-5 rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-sm text-rose-200"><?= e($message) ?></div><?php endif; ?>
    <form method="post" class="mt-6 space-y-4">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="next" value="<?= e($next) ?>">
      <label class="block"><span class="mb-2 block text-sm">Username</span><input class="form-input" name="username" autocomplete="username" required></label>
      <label class="block"><span class="mb-2 block text-sm">Password</span><input class="form-input" type="password" name="password" autocomplete="current-password" required></label>
      <button class="btn-primary w-full" type="submit"><i class="fa-solid fa-arrow-right-to-bracket"></i>Masuk</button>
    </form>
    <p class="mt-5 text-xs leading-5 text-slate-500">Percobaan login dibatasi. Tidak ada menu untuk mengganti kredensial melalui web.</p>
  </section>
</div>
<?php page_end(); ?>
