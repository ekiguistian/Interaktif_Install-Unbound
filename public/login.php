<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/layout.php';

if (admin_logged_in()) {
    header('Location: /');
    exit;
}
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = form_string($_POST, 'csrf_token');
    if (!hash_equals(csrf_token(), $csrf)) {
        $message = 'Sesi formulir berakhir. Muat ulang halaman.';
    } else {
        try {
            $statement = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE remote_addr = ? AND attempted_at > NOW() - INTERVAL 15 MINUTE');
            $statement->execute([client_ip()]);
            $tooMany = (int)$statement->fetchColumn() >= 8;
            $username = form_string($_POST, 'username');
            $password = form_string($_POST, 'password');
            $account = panel_account(db());
            $valid = !$tooMany && strlen($password) <= 4096 && password_verify($password, $account['password_hash'])
                && hash_equals($account['username'], $username);
            if ($valid) {
                session_regenerate_id(true);
                $_SESSION = ['admin_session_version' => $account['session_version']];
                db()->prepare('DELETE FROM login_attempts WHERE remote_addr = ?')->execute([client_ip()]);
                $next = (string)($_POST['next'] ?? '/');
                if (!preg_match('~^/(?:settings(?:/[a-z]+(?:-[a-z]+)*)?)?$~D', $next)) {
                    $next = '/';
                }
                header('Location: ' . $next);
                exit;
            }
            if (!$tooMany) {
                db()->prepare('INSERT INTO login_attempts (remote_addr) VALUES (?)')->execute([client_ip()]);
            }
            $message = $tooMany ? 'Terlalu banyak percobaan. Coba lagi dalam 15 menit.' : 'Nama pengguna atau password salah.';
        } catch (Throwable $error) {
            error_log('Unbound Web login: ' . $error->getMessage());
            $message = 'Database belum siap. Periksa konfigurasi .env.';
        }
    }
}
$next = (string)($_GET['next'] ?? $_POST['next'] ?? '/');
if (!preg_match('~^/(?:settings(?:/[a-z]+(?:-[a-z]+)*)?)?$~D', $next)) {
    $next = '/';
}
page_start('Masuk', false, 'login');
$resetSuccess = !empty($_SESSION['password_reset_success']);
unset($_SESSION['password_reset_success']);
?>
<div class="login-content">
  <section class="panel login-card">
    <div class="login-icon"><i class="fa-solid fa-lock" aria-hidden="true"></i></div>
    <h1>Masuk ke panel Unbound</h1>
    <p class="mt-2 text-sm leading-6 text-slate-400">Masuk untuk memantau server DNS dan mengelola konfigurasi Unbound.</p>
    <?php if ($resetSuccess) notice('Password sudah diubah. Masuk dengan password baru.'); ?>
    <?php if ($message !== ''): ?><div class="mt-5 rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-sm text-rose-200"><?= e($message) ?></div><?php endif; ?>
    <form method="post" class="mt-6 space-y-4">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="next" value="<?= e($next) ?>">
      <label class="block"><span class="mb-2 block text-sm">Nama pengguna</span><input class="form-input" name="username" autocomplete="username" required></label>
      <label class="block"><span class="mb-2 block text-sm">Password</span><input class="form-input" type="password" name="password" autocomplete="current-password" required></label>
      <button class="btn-primary w-full" type="submit"><i class="fa-solid fa-arrow-right-to-bracket" aria-hidden="true"></i>Masuk</button>
    </form>
    <a class="mt-4 block text-sm text-cyan-300" href="/forgot-password">Lupa password?</a>
  </section>
</div>
<?php page_end(); ?>

<?php // System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
