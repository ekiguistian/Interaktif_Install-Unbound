<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/filters.php';

$pdo = db();
$pdo->beginTransaction();
$insertSetting = $pdo->prepare('INSERT IGNORE INTO app_settings (setting_key,setting_value) VALUES (?,?)');
$insertSetting->execute(['whitelist_url', FILTER_DEFAULT_WHITELIST]);
$insertSetting->execute(['blocklist_redirect_ip', FILTER_DEFAULT_REDIRECT]);
$marker = $pdo->exec("INSERT IGNORE INTO app_settings (setting_key,setting_value) VALUES ('autoupdate_seeded_v1','1')");
if ($marker) {
    $sources = [
        'TrustPositif Gambling' => 'https://raw.githubusercontent.com/alsyundawy/TrustPositif/refs/heads/main/gambling_indonesia_domainonly.txt',
        'StevenBlack Gambling' => 'https://raw.githubusercontent.com/StevenBlack/hosts/master/alternates/gambling-only/hosts',
        'StevenBlack Hosts' => 'https://raw.githubusercontent.com/StevenBlack/hosts/master/hosts',
        'StevenBlack Porn' => 'https://raw.githubusercontent.com/StevenBlack/hosts/master/alternates/porn-only/hosts',
    ];
    for ($i = 1; $i <= 4; $i++) {
        $sources['TrustPositif Porn ' . $i] = 'https://raw.githubusercontent.com/ekiguistian/dns-trustpositif/refs/heads/main/porn_blocklist_' . $i . '.txt';
    }
    $insert = $pdo->prepare('INSERT IGNORE INTO blocklists (name,url,is_active) VALUES (?,?,1)');
    foreach ($sources as $name => $url) $insert->execute([$name, $url]);
}
$pdo->commit();
