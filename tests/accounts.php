<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/smtp.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// SQLite exercises transaction behavior locally. A MySQL DSN runs the exact production schema and locking SQL.
class SqliteAccountTest extends PDO
{
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return parent::query(str_replace(' FOR UPDATE', '', $query), $fetchMode, ...$fetchModeArgs);
    }
}

$dsn = getenv('PANEL_TEST_DSN');
if ($dsn) {
    $db = new PDO($dsn, getenv('PANEL_TEST_DB_USER') ?: '', getenv('PANEL_TEST_DB_PASS') ?: '', [
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    preg_match_all('/CREATE TABLE IF NOT EXISTS (?:panel_account|panel_smtp|panel_recovery) \([\s\S]+?;/', $schema, $tables);
    check(count($tables[0]) === 3, 'All MySQL account tables are included.');
    foreach ($tables[0] as $sql) $db->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $sql));
} else {
    $db = new SqliteAccountTest('sqlite::memory:');
    $db->exec('CREATE TABLE panel_account (id INTEGER PRIMARY KEY, username TEXT, password_hash TEXT, recovery_email TEXT, session_version TEXT)');
    $db->exec('CREATE TABLE panel_smtp (id INTEGER PRIMARY KEY, enabled INTEGER, encryption TEXT, host TEXT, port INTEGER, username TEXT, sender TEXT, encrypted_password BLOB)');
    $db->exec('CREATE TABLE panel_recovery (id INTEGER PRIMARY KEY, challenge TEXT, otp_hash TEXT, issued_at INTEGER, attempts INTEGER)');
}

$legacyPassword = 'Legacy-password!';
$newPassword = 'A123456789!Z';
$resetPassword = 'B!2z';
foreach (['abc', 'A123456789!Z0', "abc\n", '    '] as $invalidPassword) {
    check(!valid_panel_password($invalidPassword), 'Password outside the policy is rejected.');
}
foreach ([$newPassword, $resetPassword, 'é🙂é🙂'] as $validPassword) {
    check(valid_panel_password($validPassword), 'Lengths 4 and 12 are valid and multibyte characters count as characters.');
}
check(panel_password_error($resetPassword, 'B!2y') !== null, 'Confirmation must match.');

seed_panel_account($db, 'legacy-user', $legacyPassword, '');
$original = panel_account($db);
check(password_verify($legacyPassword, $original['password_hash']) && $original['recovery_email'] === null, 'Legacy password still works outside the new range.');
seed_panel_account($db, '', '', '');
check(panel_account($db) === $original, 'Update skips existing account with no bootstrap credentials.');

$profile = ['username' => 'operator', 'current_password' => $legacyPassword, 'recovery_email' => 'Recovery@example.net'];
$newVersion = change_panel_account($db, $profile);
check(panel_account($db)['username'] === 'operator' && $newVersion !== $original['session_version'], 'Profile changes invalidate other sessions.');
check(password_verify($legacyPassword, panel_account($db)['password_hash']), 'Profile changes preserve existing password when left empty.');
try {
    change_panel_account($db, array_replace($profile, ['current_password' => 'wrong', 'username' => 'replacement']));
    throw new RuntimeException('Incorrect password was accepted.');
} catch (DomainException) {
    check(panel_account($db)['username'] === 'operator', 'Incorrect password cannot change the account.');
}
foreach ([
    ['new_password' => 'abc', 'confirm_password' => 'abc'],
    ['new_password' => 'A123456789!Z0', 'confirm_password' => 'A123456789!Z0'],
    ['confirm_password' => 'abcd'],
] as $invalidChange) {
    try {
        change_panel_account($db, $profile + $invalidChange);
        throw new RuntimeException('Invalid password change was accepted.');
    } catch (DomainException) {
        check(password_verify($legacyPassword, panel_account($db)['password_hash']), 'Invalid input preserves the existing password.');
    }
}
$newVersion = change_panel_account($db, $profile + ['new_password' => $newPassword, 'confirm_password' => $newPassword]);
check(password_verify($newPassword, panel_account($db)['password_hash']), 'The 12-character password saves from the account form.');

$smtp = ['host' => 'relay.example.net', 'port' => '25', 'encryption' => 'none', 'sender' => 'sender@example.net', 'current_password' => $newPassword];
$key = random_bytes(32);
save_panel_smtp($db, $smtp);
check(issue_panel_otp($db, $profile['recovery_email'], $key, 1000) === null, 'Disabled SMTP will not issue an OTP.');
save_panel_smtp($db, $smtp + ['enabled' => '1']);
check(issue_panel_otp($db, $smtp['sender'], $key, 1000) === null, 'SMTP sender cannot request an OTP.');
$delivery = issue_panel_otp($db, 'recovery@example.net', $key, 1000);
check($delivery !== null && $delivery['recipient'] === 'Recovery@example.net', 'OTP is addressed to the account recovery email.');
check(issue_panel_otp($db, $profile['recovery_email'], $key, 1059) === null, 'OTP requests share a resend cooldown.');
check(!redeem_panel_otp($db, str_repeat('0', 64), $delivery['otp'], $resetPassword, $key, 1001), 'Different browser cannot redeem the OTP.');
for ($i = 0; $i < RECOVERY_OTP_ATTEMPTS; $i++) {
    check(!redeem_panel_otp($db, $delivery['challenge'], '00000000', $resetPassword, $key, 1001), 'Wrong OTP is rejected.');
}
check(!redeem_panel_otp($db, $delivery['challenge'], $delivery['otp'], $resetPassword, $key, 1001), 'Five failed attempts lock the OTP.');
$delivery = issue_panel_otp($db, $profile['recovery_email'], $key, 1060);
check(!redeem_panel_otp($db, $delivery['challenge'], $delivery['otp'], $resetPassword, $key, 1660), 'Expired OTP cannot be used.');
$delivery = issue_panel_otp($db, $profile['recovery_email'], $key, 1661);
check(!redeem_panel_otp($db, $delivery['challenge'], $delivery['otp'], 'abc', $key, 1662), 'Reset rejects a 3-character password.');
check(!redeem_panel_otp($db, $delivery['challenge'], $delivery['otp'], 'A123456789!Z0', $key, 1662), 'Reset rejects a 13-character password.');
check(redeem_panel_otp($db, $delivery['challenge'], $delivery['otp'], $resetPassword, $key, 1662), 'Valid OTP can set a 4-character password.');
check(!redeem_panel_otp($db, $delivery['challenge'], $delivery['otp'], $newPassword, $key, 1663), 'OTP can only be used once.');
check(panel_account($db)['session_version'] !== $newVersion && password_verify($resetPassword, panel_account($db)['password_hash']),
    'Reset invalidates sessions and updates login.');

$encrypted = encrypt_smtp_password('smtp-secret', $key);
check($encrypted !== 'smtp-secret' && decrypt_smtp_password($encrypted, $key) === 'smtp-secret', 'SMTP password round trips with authenticated encryption.');
try {
    decrypt_smtp_password($encrypted, random_bytes(32));
    throw new RuntimeException('Invalid encryption key was accepted.');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'Password SMTP tidak dapat dibaca.', 'Incorrect key fails to decrypt.');
}
$db->exec('DELETE FROM panel_account WHERE id=1');
seed_panel_account($db, 'admin', 'Initial!', 'initial@example.net');
check(panel_account($db)['recovery_email'] === 'initial@example.net', 'Fresh installation seeds its recovery email.');
echo 'PASS: account migration, password changes, email matching, SMTP toggle, OTP throttling, expiry and single use (' . $db->getAttribute(PDO::ATTR_DRIVER_NAME) . ")\n";

// System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
