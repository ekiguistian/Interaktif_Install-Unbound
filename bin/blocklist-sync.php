<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/functions.php';

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    fwrite(STDERR, "Sinkronisasi blocklist harus berjalan sebagai root melalui management helper.\n");
    exit(1);
}

function parse_blocklist_line(string $line): ?string
{
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '!')) {
        return null;
    }
    if (str_starts_with($line, '@@')) {
        return null;
    }
    if (preg_match('/^(?:0\.0\.0\.0|127\.0\.0\.1|::1|0{1,4}:0{1,4}::)\s+([A-Za-z0-9*.-]+)/i', $line, $match)) {
        $line = $match[1];
    } elseif (str_starts_with($line, '||')) {
        $line = substr($line, 2);
        $line = preg_split('/[\^\/\$]/', $line, 2)[0] ?? '';
    } elseif (preg_match('/^\[Adblock[^]]*\]/i', $line)) {
        return null;
    } else {
        $fields = preg_split('/\s+/', $line);
        $line = count($fields) > 1 && filter_var($fields[0], FILTER_VALIDATE_IP)
            ? $fields[1]
            : ($fields[0] ?? '');
    }
    $line = strtolower(trim($line, " \t\r\n.*"));
    if (str_starts_with($line, 'www.')) {
        $line = substr($line, 4);
    }
    return valid_domain($line) ? $line : null;
}

$tempFiles = [];
$syncFailed = false;
try {
    if ($argc !== 3 || !in_array($argv[1], ['0','1'], true) || !in_array($argv[2], ['0','1'], true)) {
        throw new RuntimeException('Status filter tidak valid.');
    }
    $pdo = db();
    $sources = $argv[1] === '0' ? [] : $pdo->query('SELECT id,name,url FROM blocklists WHERE is_active=1 ORDER BY id')->fetchAll();
    $whitelistRows = $argv[2] === '0' ? [] : $pdo->query('SELECT domain FROM whitelists')->fetchAll();
    $whitelist = [];
    foreach ($whitelistRows as $row) {
        $domain = strtolower(rtrim((string)$row['domain'], '.'));
        if (valid_domain($domain)) {
            $whitelist[] = $domain;
        }
    }
    $blocked = [];
    $counts = [];
    foreach ($sources as $source) {
        $url = (string)$source['url'];
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int)$parts['port'] !== 443)) {
            throw new RuntimeException('URL blocklist tidak valid untuk sumber: ' . $source['name']);
        }
        $host = (string)$parts['host'];
        if (filter_var($host, FILTER_VALIDATE_IP) || !preg_match('/^[A-Za-z0-9.-]{1,253}$/', $host)) {
            throw new RuntimeException('URL sumber harus menggunakan hostname HTTPS publik.');
        }
        $resolved = gethostbynamel($host) ?: [];
        if (!$resolved) {
            throw new RuntimeException('Hostname sumber tidak dapat di-resolve: ' . $host);
        }
        foreach ($resolved as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('Alamat sumber harus mengarah ke IP publik.');
            }
        }
        $pinnedAddress = $resolved[0];
        $download = tempnam('/etc/unbound-web', 'blocklist-');
        if ($download === false) {
            throw new RuntimeException('File sementara blocklist tidak dapat dibuat.');
        }
        $tempFiles[] = $download;
        $result = CommandRunner::run(['/usr/bin/curl','--fail','--silent','--show-error','--proto','=https','--connect-timeout','10','--max-time','90','--max-filesize','20000000','--resolve',$host . ':443:' . $pinnedAddress,'--output',$download,'--',$url], 100);
        if ($result['code'] !== 0) {
            throw new RuntimeException('Unduhan gagal untuk ' . $source['name'] . ': ' . trim($result['output']));
        }
        $count = 0;
        $handle = fopen($download, 'rb');
        if ($handle === false) {
            throw new RuntimeException('File sumber tidak dapat dibaca.');
        }
        while (($line = fgets($handle)) !== false) {
            $domain = parse_blocklist_line($line);
            if ($domain === null) {
                continue;
            }
            $blocked[$domain] = true;
            $count++;
            if (count($blocked) > 300000) {
                fclose($handle);
                throw new RuntimeException('Jumlah domain melampaui batas pemrosesan 300.000.');
            }
        }
        fclose($handle);
        if ($count === 0) {
            throw new RuntimeException('Tidak ada domain valid pada sumber ' . $source['name'] . '; konfigurasi lama dipertahankan.');
        }
        $counts[(int)$source['id']] = $count;
        echo sprintf("Sumber %-24s %d domain diproses.\n", (string)$source['name'], $count);
        flush();
    }

    $whitelistSet = array_fill_keys($whitelist, true);
    foreach (array_keys($blocked) as $domain) {
        $candidate = $domain;
        while ($candidate !== '' && str_contains($candidate, '.')) {
            if (isset($whitelistSet[$candidate])) {
                unset($blocked[$domain]);
                break;
            }
            $dot = strpos($candidate, '.');
            $candidate = $dot === false ? '' : substr($candidate, $dot + 1);
        }
    }
    $confPath = '/etc/unbound/unbound.conf.d/90-unbound-web-blocklist.conf';
    $confDir = dirname($confPath);
    if (!is_dir($confDir) || !is_file('/etc/unbound/unbound.conf')) {
        throw new RuntimeException('Unbound belum dipasang atau konfigurasi dasarnya belum tersedia.');
    }
    if ($argv[1] === '0' && $argv[2] === '0' && !file_exists($confPath)) {
        echo "Filter sudah nonaktif.\n";
        return;
    }
    $newConf = tempnam($confDir, '.blocklist-');
    if ($newConf === false) {
        throw new RuntimeException('File konfigurasi blocklist sementara tidak dapat dibuat.');
    }
    $tempFiles[] = $newConf;
    $handle = fopen($newConf, 'wb');
    fwrite($handle, "server:\n");
    foreach (array_keys($blocked) as $domain) {
        fwrite($handle, '    local-zone: "' . $domain . '." always_nxdomain' . "\n");
    }
    foreach ($whitelist as $allowed) {
        $parent = $allowed;
        while (($dot = strpos($parent, '.')) !== false) {
            $parent = substr($parent, $dot + 1);
            if (isset($blocked[$parent])) {
                fwrite($handle, '    local-zone: "' . $allowed . '." transparent' . "\n");
                break;
            }
        }
    }
    fclose($handle);
    chmod($newConf, 0640);
    if ($blocked === []) {
        fwrite(STDOUT, "Whitelist aktif atau belum ada sumber aktif; domain blokir berjumlah nol.\n");
    }
    $check = CommandRunner::run(['/usr/sbin/unbound-checkconf', $newConf], 10);
    if ($check['code'] !== 0) {
        throw new RuntimeException('unbound-checkconf menolak blocklist: ' . trim($check['output']));
    }
    $old = is_file($confPath) ? file_get_contents($confPath) : null;
    if ($argv[1] === '0' && $argv[2] === '0') {
        if (!unlink($confPath)) throw new RuntimeException('Gagal melepas file filter.');
    } else {
        chgrp($newConf, 'unbound');
        chmod($newConf, 0640);
        if (!rename($newConf, $confPath)) throw new RuntimeException('File blocklist tidak dapat dipasang.');
    }
    $mainCheck = CommandRunner::run(['/usr/sbin/unbound-checkconf', '/etc/unbound/unbound.conf'], 10);
    if ($mainCheck['code'] !== 0) {
        if ($old === null) {
            unlink($confPath);
        } else {
            file_put_contents($confPath, $old, LOCK_EX);
            chown($confPath, 'root');
            chgrp($confPath, 'unbound');
            chmod($confPath, 0640);
        }
        throw new RuntimeException('Konfigurasi aktif tidak lolos validasi. Blocklist sebelumnya dipulihkan.');
    }
    if ($blocked) {
        $zones = CommandRunner::run(['/usr/sbin/unbound-checkconf','-o','local-zone','/etc/unbound/unbound.conf'], 20);
        $sample = array_key_first($blocked);
        if ($zones['code'] !== 0 || !preg_match('/^"?'.preg_quote($sample,'/').'\.?"?\s+always_nxdomain$/m', $zones['output'])) {
            if ($old === null) unlink($confPath);
            else file_put_contents($confPath, $old, LOCK_EX);
            throw new RuntimeException('File filter belum terbaca. Periksa include /etc/unbound/unbound.conf.d/*.conf di konfigurasi aktif.');
        }
    }
    $reload = CommandRunner::run(['/usr/bin/systemctl','reload','unbound'], 30);
    if ($reload['code'] !== 0) {
        if ($old === null) {
            unlink($confPath);
        } else {
            file_put_contents($confPath, $old, LOCK_EX);
            chown($confPath, 'root');
            chgrp($confPath, 'unbound');
            chmod($confPath, 0640);
        }
        CommandRunner::run(['/usr/bin/systemctl','reload','unbound'], 30);
        throw new RuntimeException('Unbound gagal reload. File blocklist sebelumnya dipulihkan.');
    }
    $update = $pdo->prepare('UPDATE blocklists SET domain_count=?,last_updated=NOW(),last_error=NULL WHERE id=?');
    foreach ($counts as $id => $count) {
        $update->execute([$count, $id]);
    }
    echo sprintf("Selesai: %d domain aktif setelah whitelist. Unbound sudah di-reload.\n", count($blocked));
} catch (Throwable $error) {
    $syncFailed = true;
    error_log('Unbound Web blocklist: ' . $error->getMessage());
    fwrite(STDERR, '[ERROR] ' . $error->getMessage() . "\n");
    if (isset($pdo) && $pdo instanceof PDO && isset($sources)) {
        $failed = $pdo->prepare('UPDATE blocklists SET last_error=? WHERE id=?');
        foreach ($sources as $source) {
            try { $failed->execute([substr($error->getMessage(), 0, 500), (int)$source['id']]); } catch (Throwable) { }
        }
    }
} finally {
    foreach ($tempFiles as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
if ($syncFailed) {
    exit(1);
}
