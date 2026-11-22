<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/functions.php';
$pdo = db();
$pdo->beginTransaction();
$marker = $pdo->exec("INSERT IGNORE INTO app_settings VALUES ('dnsperf_seeded','1',NOW())");
if ($marker && !(int)$pdo->query('SELECT COUNT(*) FROM test_domains')->fetchColumn()) {
    $insert = $pdo->prepare('INSERT IGNORE INTO test_domains (domain,query_type) VALUES (?,?)');
    foreach (file(APP_ROOT . '/src/dnsperf-domains.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        [$domain, $type] = array_pad(explode(' ', trim($line), 2), 2, 'A');
        if (valid_domain($domain) && in_array($type,['A','AAAA','MX','TXT','NS','CNAME'],true)) {
            $insert->execute([$domain, $type]);
        }
    }
}
$pdo->commit();
