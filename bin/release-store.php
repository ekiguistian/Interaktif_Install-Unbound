<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') exit(1);
$action = $argv[1] ?? '';
$commit = $argv[2] ?? '';
$version = $argv[3] ?? '';
$pdo = db();

if ($action === 'installed' && $argc === 2) {
    $saved = $pdo->query("SELECT r.commit_hash AS commit_id, r.version FROM app_releases r
        INNER JOIN app_settings s ON s.setting_value=r.commit_hash WHERE s.setting_key='installed_release_commit'")->fetch();
    echo json_encode($saved ?: null, JSON_THROW_ON_ERROR);
    exit;
}
if ($action === 'forget-installed' && $argc === 2) {
    $pdo->exec("DELETE FROM app_settings WHERE setting_key='installed_release_commit'");
    exit;
}
if (!preg_match('/^[a-f0-9]{40}$/D', $commit)) exit(1);
$lookup = $pdo->prepare('SELECT version FROM app_releases WHERE commit_hash=?');
if ($action === 'lookup' && $argc === 3) {
    $lookup->execute([$commit]);
    echo (string)$lookup->fetchColumn();
    exit;
}
if ($argc !== 4 || !in_array($action, ['record-check', 'record-install'], true) || !preg_match('/^1\.0\.[0-9]{1,20}$/D', $version)) exit(1);

$pdo->beginTransaction();
$pdo->prepare('INSERT INTO app_releases (commit_hash,version) VALUES (?,?) ON DUPLICATE KEY UPDATE commit_hash=commit_hash')
    ->execute([$commit, $version]);
$lookup->execute([$commit]);
if ($lookup->fetchColumn() !== $version) throw new RuntimeException('Versi untuk commit ini sudah tercatat dengan nilai berbeda.');
if ($action === 'record-check') {
    $pdo->prepare('UPDATE app_releases SET checked_at=NOW() WHERE commit_hash=?')->execute([$commit]);
} else {
    $pdo->prepare('UPDATE app_releases SET installed_at=NOW() WHERE commit_hash=?')->execute([$commit]);
    $pdo->prepare("INSERT INTO app_settings (setting_key,setting_value) VALUES ('installed_release_commit',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
        ->execute([$commit]);
}
$pdo->commit();

// System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
