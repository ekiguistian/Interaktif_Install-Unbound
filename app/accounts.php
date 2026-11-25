<?php
declare(strict_types=1);

const RECOVERY_OTP_TTL = 600;
const RECOVERY_OTP_RESEND = 60;
const RECOVERY_OTP_ATTEMPTS = 5;

function form_string(array $form, string $name): string
{
    $value = $form[$name] ?? '';
    return is_string($value) ? $value : '';
}

function valid_recovery_email(string $email): bool
{
    return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function valid_panel_password(string $password): bool
{
    return strlen($password) >= 12 && strlen($password) <= 128 && trim($password) !== ''
        && strpbrk($password, "\0\r\n") === false;
}

function panel_password_hash(string $password): string
{
    return password_hash($password, PASSWORD_ARGON2ID);
}

function seed_panel_account(PDO $pdo, string $username, string $password, string $email): void
{
    if ($pdo->query('SELECT id FROM panel_account WHERE id=1')->fetchColumn() !== false) {
        return;
    }
    if ($username === '' || strlen($username) > 255 || $password === '' || str_starts_with($password, 'replace_with_')
        || ($email !== '' && !valid_recovery_email($email))) {
        throw new DomainException('Data akun awal tidak valid. Periksa konfigurasi instalasi.');
    }
    $pdo->prepare('INSERT INTO panel_account (id, username, password_hash, recovery_email, session_version) VALUES (1, ?, ?, ?, ?)')
        ->execute([$username, panel_password_hash($password), $email === '' ? null : $email, bin2hex(random_bytes(32))]);
}

function panel_account(PDO $pdo, bool $lock = false): array
{
    $account = $pdo->query('SELECT * FROM panel_account WHERE id=1' . ($lock ? ' FOR UPDATE' : ''))->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        throw new RuntimeException('Akun panel belum disiapkan oleh installer.');
    }
    return $account;
}

function with_panel_account(PDO $pdo, callable $operation): mixed
{
    $pdo->beginTransaction();
    try {
        $result = $operation(panel_account($pdo, true));
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function require_current_password(array $account, string $password): void
{
    if (strlen($password) > 4096 || !password_verify($password, $account['password_hash'])) {
        throw new DomainException('Password saat ini salah.');
    }
}

function change_panel_account(PDO $pdo, array $form): string
{
    $username = trim(form_string($form, 'username'));
    $email = trim(form_string($form, 'recovery_email'));
    $password = form_string($form, 'new_password');
    if ($email !== '' && !valid_recovery_email($email)) {
        throw new DomainException('Masukkan email pemulihan yang valid.');
    }
    if ($password !== '' && (!valid_panel_password($password) || $password !== form_string($form, 'confirm_password'))) {
        throw new DomainException('Password baru harus 12–128 byte dan cocok dengan konfirmasi.');
    }
    return with_panel_account($pdo, function (array $account) use ($pdo, $form, $username, $email, $password): string {
        require_current_password($account, form_string($form, 'current_password'));
        if ($username !== $account['username'] && !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.@-]{0,63}$/D', $username)) {
            throw new DomainException('Gunakan 1–64 karakter untuk username, berupa huruf, angka, atau . _ @ -.');
        }
        $version = bin2hex(random_bytes(32));
        $pdo->prepare('UPDATE panel_account SET username=?, password_hash=?, recovery_email=?, session_version=? WHERE id=1')
            ->execute([$username, $password === '' ? $account['password_hash'] : panel_password_hash($password), $email ?: null, $version]);
        $pdo->exec('DELETE FROM panel_recovery WHERE id=1');
        return $version;
    });
}

function panel_secret_key(): string
{
    $key = trim((string)@file_get_contents('/etc/unbound-web/account.key'));
    if (!preg_match('/^[a-f0-9]{64}$/D', $key)) {
        throw new RuntimeException('Kunci akun panel belum tersedia.');
    }
    return hex2bin($key);
}

function otp_digest(string $challenge, string $otp, string $key): string
{
    return hash_hmac('sha256', 'otp:' . $challenge . ':' . $otp, $key);
}

function issue_panel_otp(PDO $pdo, string $email, string $key, int $now): ?array
{
    if (!valid_recovery_email($email)) {
        return null;
    }
    return with_panel_account($pdo, function (array $account) use ($pdo, $email, $key, $now): ?array {
        if (strcasecmp($email, $account['recovery_email'] ?? '') !== 0) {
            return null;
        }
        $smtp = $pdo->query('SELECT * FROM panel_smtp WHERE id=1 AND enabled=1')->fetch(PDO::FETCH_ASSOC);
        if (!$smtp) {
            return null;
        }
        $last = $pdo->query('SELECT issued_at FROM panel_recovery WHERE id=1')->fetchColumn();
        if ($last !== false && $now - (int)$last < RECOVERY_OTP_RESEND) {
            return null;
        }
        $challenge = bin2hex(random_bytes(32));
        $otp = (string)random_int(10000000, 99999999);
        $pdo->exec('DELETE FROM panel_recovery WHERE id=1');
        $pdo->prepare('INSERT INTO panel_recovery (id, challenge, otp_hash, issued_at, attempts) VALUES (1, ?, ?, ?, 0)')
            ->execute([$challenge, otp_digest($challenge, $otp, $key), $now]);
        return ['challenge' => $challenge, 'otp' => $otp, 'recipient' => $account['recovery_email'], 'smtp' => $smtp];
    });
}

function redeem_panel_otp(PDO $pdo, string $challenge, string $otp, string $password, string $key, int $now): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $challenge) || !preg_match('/^[0-9]{8}$/D', $otp) || !valid_panel_password($password)) {
        return false;
    }
    return with_panel_account($pdo, function () use ($pdo, $challenge, $otp, $password, $key, $now): bool {
        $row = $pdo->query('SELECT * FROM panel_recovery WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if (!$row || !hash_equals($row['challenge'], $challenge) || $now - (int)$row['issued_at'] >= RECOVERY_OTP_TTL
            || (int)$row['attempts'] >= RECOVERY_OTP_ATTEMPTS) {
            return false;
        }
        if (!hash_equals($row['otp_hash'], otp_digest($challenge, $otp, $key))) {
            $pdo->exec('UPDATE panel_recovery SET attempts=attempts+1 WHERE id=1');
            return false;
        }
        $pdo->prepare('UPDATE panel_account SET password_hash=?, session_version=? WHERE id=1')
            ->execute([panel_password_hash($password), bin2hex(random_bytes(32))]);
        $pdo->exec('DELETE FROM panel_recovery WHERE id=1');
        return true;
    });
}

// System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
