<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/smtp.php';

if (admin_logged_in()) {
    header('Location: /settings/account', true, 303);
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = form_string($_POST, 'action');
    if (!hash_equals(csrf_token(), form_string($_POST, 'csrf_token'))) {
        $error = 'Sesi formulir berakhir. Muat ulang halaman.';
    } elseif ($action === 'request') {
        $delivery = null;
        try {
            $key = panel_secret_key();
            $delivery = issue_panel_otp(db(), trim(form_string($_POST, 'email')), $key, time());
            if ($delivery !== null) {
                $_SESSION['recovery_challenge'] = $delivery['challenge'];
            }
        } catch (Throwable $failure) {
            error_log('Unbound Web OTP: permintaan pemulihan gagal.');
        }
        $_SESSION['recovery_step'] = true;
        $_SESSION['recovery_notice'] = 'Jika email terdaftar dan SMTP aktif, kode OTP akan dikirim.';
        session_write_close();
        header('Location: /forgot-password', true, 303);
        if (function_exists('fastcgi_finish_request')) {
            ignore_user_abort(true);
            fastcgi_finish_request();
        }
        if ($delivery !== null) {
            try {
                send_panel_otp($delivery, $key);
            } catch (Throwable $failure) {
                error_log('Unbound Web OTP: pengiriman gagal. Periksa pengaturan dan koneksi SMTP.');
            }
        }
        exit;
    } elseif ($action === 'reset') {
        $password = form_string($_POST, 'new_password');
        if (!valid_panel_password($password) || $password !== form_string($_POST, 'confirm_password')) {
            $error = 'Password baru harus 12–128 byte dan cocok dengan konfirmasi.';
        } else {
            try {
                if (redeem_panel_otp(db(), form_string($_SESSION, 'recovery_challenge'), trim(form_string($_POST, 'otp')),
                    $password, panel_secret_key(), time())) {
                    try {
                        db()->prepare('DELETE FROM login_attempts WHERE remote_addr=?')->execute([client_ip()]);
                    } catch (Throwable $failure) {
                        error_log('Unbound Web OTP: batas login tidak dapat direset.');
                    }
                    session_regenerate_id(true);
                    $_SESSION = ['password_reset_success' => true];
                    header('Location: /login', true, 303);
                    exit;
                }
            } catch (Throwable $failure) {
                error_log('Unbound Web OTP: reset password gagal.');
            }
            $error = 'Kode OTP salah, kedaluwarsa, atau sudah dipakai. Coba lagi atau minta kode baru.';
        }
    }
}

$message = $_SESSION['recovery_notice'] ?? '';
unset($_SESSION['recovery_notice']);
page_start('Pemulihan password', false, 'login');
?>
<div class="login-content">
  <section class="panel login-card">
    <h1>Pemulihan password</h1>
    <?php if ($message !== '') notice($message); ?>
    <?php if ($error !== '') notice($error, 'error'); ?>
    <form method="post" class="mt-6 space-y-4">
      <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="request">
      <label class="block">Email pemulihan
        <input class="form-input" name="email" type="email" autocomplete="email" maxlength="254" required>
      </label>
      <button class="btn-secondary" type="submit">Kirim kode OTP</button>
    </form>
    <?php if (!empty($_SESSION['recovery_step'])): ?>
      <form method="post" class="mt-6 space-y-4">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="reset">
        <label class="block">Kode OTP
          <input class="form-input" name="otp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{8}" maxlength="8" required>
        </label>
        <label class="block">Password baru
          <input class="form-input" name="new_password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required>
        </label>
        <label class="block">Ulangi password baru
          <input class="form-input" name="confirm_password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required>
        </label>
        <button class="btn-primary" type="submit">Reset password</button>
      </form>
    <?php endif; ?>
    <a class="mt-4 block text-sm text-cyan-300" href="/login">Kembali ke login</a>
  </section>
</div>
<?php page_end(); ?>

<?php // System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
