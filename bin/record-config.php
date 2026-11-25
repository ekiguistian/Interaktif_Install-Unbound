<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli' || posix_geteuid() !== 0) exit(1);
$state = json_decode((string)stream_get_contents(STDIN, 8388608), true, flags: JSON_THROW_ON_ERROR);
$pdo = db();
$pdo->beginTransaction();
$pdo->prepare('INSERT INTO unbound_network_config (id,revision,settings) VALUES (1,?,?) ON DUPLICATE KEY UPDATE revision=VALUES(revision),settings=VALUES(settings)')
    ->execute([$state['revision'], json_encode($state['network'], JSON_THROW_ON_ERROR)]);
if (!array_key_exists('content', $state)) {
    $pdo->commit();
    exit;
}
$pdo->prepare('INSERT INTO unbound_config (id,content) VALUES (1,?) ON DUPLICATE KEY UPDATE content=VALUES(content)')
    ->execute([$state['content']]);
$save = $pdo->prepare('INSERT INTO app_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
foreach ($state['filters'] as $key => $value) {
    $save->execute(['unbound_filter_' . $key, $value ? '1' : '0']);
}
foreach (['root-hints', 'module-config', 'val-permissive-mode'] as $option) {
    $save->execute(['unbound_' . $option, $state['resolver'][$option] ?? '']);
}
foreach (['log-queries', 'log-replies'] as $option) {
    $save->execute(['unbound_' . $option, $state['resolver'][$option] ?? 'no']);
}
$pdo->commit();

// System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
