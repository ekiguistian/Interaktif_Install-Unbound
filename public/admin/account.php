<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
require_once __DIR__ . '/../../app/smtp.php';
require_admin();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals(csrf_token(), form_string($_POST, 'csrf_token'))) {
            throw new DomainException('Sesi formulir berakhir. Muat ulang halaman.');
        }
        switch (form_string($_POST, 'action')) {
            case 'account':
                $version = change_panel_account(db(), $_POST);
                session_regenerate_id(true);
                $_SESSION['admin_session_version'] = $version;
                unset($_SESSION['csrf_token']);
                $_SESSION['account_notice'] = 'Pengaturan akun tersimpan.';
                break;
            case 'smtp':
                save_panel_smtp(db(), $_POST);
                $_SESSION['account_notice'] = 'Pengaturan SMTP tersimpan.';
                break;
            default:
                throw new DomainException('Aksi tidak tersedia.');
        }
        header('Location: /settings/account', true, 303);
        exit;
    } catch (DomainException $failure) {
        $error = $failure->getMessage();
    } catch (Throwable $failure) {
        error_log('Unbound Web account: gagal menyimpan pengaturan.');
        $error = 'Pengaturan gagal disimpan.';
    }
}
$account = panel_account(db());
$smtp = panel_smtp_settings(db());
$message = $_SESSION['account_notice'] ?? '';
unset($_SESSION['account_notice']);
page_start('Akun panel', true);
if ($message !== '') notice($message);
if ($error !== '') notice($error, 'error');
?>
<div class="grid gap-4 sm:grid-cols-2">
  <form method="post" class="panel p-5 space-y-4">
    <h2 class="font-semibold">Profil dan password</h2>
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <input type="hidden" name="action" value="account">
    <label class="block">Username
      <input class="form-input" name="username" value="<?=e($account['username'])?>" autocomplete="username" maxlength="255" required>
    </label>
    <label class="block">Email pemulihan
      <input class="form-input" name="recovery_email" value="<?=e($account['recovery_email'])?>" type="email" autocomplete="email" maxlength="254">
    </label>
    <label class="block">Password baru
      <input class="form-input" name="new_password" type="password" autocomplete="new-password" minlength="12" maxlength="128">
    </label>
    <label class="block">Ulangi password baru
      <input class="form-input" name="confirm_password" type="password" autocomplete="new-password" minlength="12" maxlength="128">
    </label>
    <label class="block">Password panel saat ini
      <input class="form-input" name="current_password" type="password" autocomplete="current-password" required>
    </label>
    <button class="btn-primary" type="submit">Simpan akun</button>
  </form>
  <form method="post" class="panel p-5 space-y-4">
    <h2 class="font-semibold">SMTP pemulihan</h2>
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <input type="hidden" name="action" value="smtp">
    <label class="flex items-center gap-2">
      <input type="checkbox" role="switch" name="enabled" value="1" <?= $smtp['enabled'] ? 'checked' : '' ?>>
      Aktifkan SMTP
    </label>
    <?php field('Host SMTP', 'host', $smtp['host'], required: false); ?>
    <label class="block">Keamanan koneksi
      <select class="form-select" name="encryption">
        <?php foreach (['starttls' => 'STARTTLS', 'ssl' => 'SSL/TLS', 'none' => 'None'] as $value => $label): ?>
          <option value="<?=e($value)?>" <?= $smtp['encryption'] === $value ? 'selected' : '' ?>><?=e($label)?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="block">Port
      <input class="form-input" name="port" type="number" min="1" max="65535" value="<?=e($smtp['port'])?>" required>
    </label>
    <?php field('Username SMTP', 'smtp_username', $smtp['username'], hint: 'Kosongkan untuk relay tanpa autentikasi.', required: false); ?>
    <?php field('Email sender', 'sender', $smtp['sender'], 'email', required: false); ?>
    <label class="block">Password SMTP
      <input class="form-input" name="smtp_password" type="password" autocomplete="new-password" maxlength="512"
        <?= $smtp['encrypted_password'] !== null ? 'placeholder="Kosongkan untuk memakai password tersimpan"' : '' ?>>
    </label>
    <label class="block">Password panel saat ini
      <input class="form-input" name="current_password" type="password" autocomplete="current-password" required>
    </label>
    <button class="btn-primary" type="submit">Simpan SMTP</button>
  </form>
</div>
<?php page_end(); ?>

<?php // System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
