<?php
declare(strict_types=1);

require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/CommandRunner.php';

function panel_smtp_settings(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM panel_smtp WHERE id=1')->fetch(PDO::FETCH_ASSOC) ?: [
        'enabled' => 0, 'encryption' => 'starttls', 'host' => '', 'port' => 587,
        'username' => '', 'sender' => '', 'encrypted_password' => null,
    ];
}

function encrypt_smtp_password(string $password, string $key): string
{
    $iv = random_bytes(12);
    $tag = '';
    $encrypted = openssl_encrypt($password, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'unbound-web/smtp');
    if ($encrypted === false) {
        throw new RuntimeException('Password SMTP tidak dapat dienkripsi.');
    }
    return base64_encode($iv . $tag . $encrypted);
}

function decrypt_smtp_password(string $encoded, string $key): string
{
    $encrypted = base64_decode($encoded, true);
    if ($encrypted === false || strlen($encrypted) <= 28) {
        throw new RuntimeException('Password SMTP tidak dapat dibaca.');
    }
    $password = openssl_decrypt(substr($encrypted, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
        substr($encrypted, 0, 12), substr($encrypted, 12, 16), 'unbound-web/smtp');
    if ($password === false) {
        throw new RuntimeException('Password SMTP tidak dapat dibaca.');
    }
    return $password;
}

function save_panel_smtp(PDO $pdo, array $form): void
{
    $enabled = form_string($form, 'enabled') === '1';
    $encryption = form_string($form, 'encryption');
    $host = strtolower(trim(form_string($form, 'host')));
    $port = filter_var(form_string($form, 'port'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    $username = trim(form_string($form, 'smtp_username'));
    $sender = trim(form_string($form, 'sender'));
    $password = form_string($form, 'smtp_password');
    if (!in_array($encryption, ['starttls', 'ssl', 'none'], true) || $port === false) {
        throw new DomainException('Pilih mode SMTP dan port 1–65535.');
    }
    if (($host !== '' || $enabled) && (strlen($host) > 253
        || (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)))) {
        throw new DomainException('Masukkan hostname atau IP server SMTP yang valid.');
    }
    if (($sender !== '' || $enabled) && !valid_recovery_email($sender)) {
        throw new DomainException('Masukkan alamat email pengirim yang valid.');
    }
    if (!preg_match('/^[\x20-\x7e]{0,254}$/D', $username) || !preg_match('/^[\x20-\x7e]{0,512}$/D', $password)) {
        throw new DomainException('Nama pengguna atau password SMTP tidak valid.');
    }
    if ($encryption === 'none' && ($username !== '' || $password !== '')) {
        throw new DomainException('Gunakan STARTTLS atau SSL/TLS untuk autentikasi SMTP.');
    }
    if ($username === '' && $password !== '') {
        throw new DomainException('Isi nama pengguna SMTP jika memakai password SMTP.');
    }

    with_panel_account($pdo, function (array $account) use ($pdo, $form, $enabled, $encryption, $host, $port, $username, $sender, $password): void {
        require_current_password($account, form_string($form, 'current_password'));
        $old = panel_smtp_settings($pdo);
        $sameServer = $host === $old['host'] && $port === (int)$old['port']
            && $encryption === $old['encryption'] && $username === $old['username'];
        $encrypted = null;
        if ($username !== '') {
            $encrypted = $password !== '' ? encrypt_smtp_password($password, panel_secret_key())
                : ($sameServer ? $old['encrypted_password'] : null);
            if ($enabled && $encrypted === null) {
                throw new DomainException('Isi password SMTP untuk server dan nama pengguna ini.');
            }
        }
        $pdo->exec('DELETE FROM panel_smtp WHERE id=1');
        $pdo->prepare('INSERT INTO panel_smtp (id, enabled, encryption, host, port, username, sender, encrypted_password) VALUES (1, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([(int)$enabled, $encryption, $host, $port, $username, $sender, $encrypted]);
        $pdo->exec('DELETE FROM panel_recovery WHERE id=1');
    });
}

function send_panel_otp(array $delivery, string $key): void
{
    send_panel_mail($delivery['smtp'], $delivery['recipient'], $delivery['otp'], $key);
}

function send_panel_smtp_test(PDO $pdo): void
{
    $recipient = panel_account($pdo)['recovery_email'] ?? '';
    if (!valid_recovery_email($recipient)) {
        throw new DomainException('Simpan email pemulihan di profil akun sebelum menjalankan tes.');
    }
    $smtp = panel_smtp_settings($pdo);
    if (!$smtp['enabled']) {
        throw new DomainException('Aktifkan dan simpan konfigurasi SMTP sebelum menjalankan tes.');
    }
    send_panel_mail($smtp, $recipient, null);
}

function send_panel_mail(array $smtp, string $recipient, ?string $otp, ?string $key = null): void
{
    $smtp['password'] = $smtp['encrypted_password'] === null ? ''
        : decrypt_smtp_password($smtp['encrypted_password'], $key ?? panel_secret_key());
    unset($smtp['encrypted_password']);
    $payload = json_encode(['smtp' => $smtp, 'recipient' => $recipient, 'otp' => $otp], JSON_THROW_ON_ERROR);
    $result = CommandRunner::run(['/usr/bin/python3', '-I', __DIR__ . '/../bin/send-otp.py'], 15, $payload);
    if ($result['code'] !== 0) {
        throw new RuntimeException('Pengiriman email melalui SMTP gagal.');
    }
}

// System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
