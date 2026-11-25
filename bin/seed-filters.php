<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/filters.php';

$pdo = db();
$pdo->beginTransaction();
$insertSetting = $pdo->prepare('INSERT IGNORE INTO app_settings (setting_key,setting_value) VALUES (?,?)');
$insertSetting->execute(['whitelist_url', FILTER_DEFAULT_WHITELIST]);
$insertSetting->execute(['blocklist_redirect_ip', FILTER_DEFAULT_REDIRECT]);
$pdo->commit();

// System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
