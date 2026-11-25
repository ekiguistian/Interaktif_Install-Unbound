<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/filters.php';

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    fwrite(STDERR, "Jalankan sinkronisasi melalui management helper.\n");
    exit(1);
}
ini_set('memory_limit', '768M');
set_time_limit(0);
umask(0027);
set_error_handler(static function (int $level, string $message): never { throw new RuntimeException($message); });

function sync_log(string $message): void
{
    $line = date('Y-m-d H:i:s') . ' - ' . $message . "\n";
    echo $line;
    flush();
    file_put_contents('/var/log/unbound-web-filters.log', $line, FILE_APPEND | LOCK_EX);
}

function sync_write($handle, string $text): void
{
    if (fwrite($handle, $text) !== strlen($text)) throw new RuntimeException('Penulisan file tidak lengkap. Periksa ruang disk.');
}

function sync_buffer($handle, string &$buffer, string $text = '', bool $flush = false): void
{
    $buffer .= $text;
    if ($flush || strlen($buffer) >= 65536) {
        sync_write($handle, $buffer);
        $buffer = '';
    }
}

/** Stage on the destination filesystem; never silently fall back to /tmp. */
function sync_install(string $source, string $target, int $mode = 0640, int|string $group = 'unbound', int $owner = 0): void
{
    if (is_link($target)) throw new RuntimeException('File tujuan tidak boleh berupa symlink: ' . $target);
    $temporary = dirname($target) . '/.unbound-web-' . bin2hex(random_bytes(8));
    try {
        $out = fopen($temporary, 'xb');
        $in = fopen($source, 'rb');
        try {
            if (stream_copy_to_stream($in, $out) !== filesize($source)) throw new RuntimeException('Salinan file tidak lengkap.');
            fflush($out);
            fsync($out);
        } finally { fclose($in); fclose($out); }
        chown($temporary, $owner);
        chgrp($temporary, $group);
        chmod($temporary, $mode);
        rename($temporary, $target);
    } finally { if (is_file($temporary)) unlink($temporary); }
}

function sync_download(string $url, string $target): void
{
    if (!valid_public_https_url($url)) throw new RuntimeException('Sumber harus berupa URL HTTPS publik yang valid.');
    $host = (string)parse_url($url, PHP_URL_HOST);
    $addresses = gethostbynamel($host) ?: [];
    foreach ($addresses as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('Sumber mengarah ke jaringan nonpublik.');
        }
    }
    if (!$addresses) throw new RuntimeException('DNS sumber tidak dapat di-resolve.');
    $result = CommandRunner::run(['/usr/bin/curl', '--fail', '--silent', '--show-error', '--proto', '=https',
        '--noproxy', '*', '--connect-timeout', '10', '--max-time', '90', '--max-filesize', '80000000',
        '--retry', '1', '--retry-delay', '1', '--retry-connrefused',
        '--resolve', $host . ':443:' . implode(',', $addresses), '--output', $target, '--', $url], 100);
    if ($result['code'] !== 0) throw new RuntimeException('Unduhan gagal: ' . trim($result['output']));
    if (filesize($target) > 80000000) throw new RuntimeException('Sumber melebihi 80 MB.');
}

$stage = null;
$backups = [];
$changed = false;
$reloadAttempted = false;
$failed = false;
try {
    if ($argc !== 3 || !in_array($argv[1], ['0','1'], true) || !in_array($argv[2], ['0','1'], true)) throw new RuntimeException('Status filter tidak valid.');
    if (!is_file('/etc/unbound/unbound.conf')) throw new RuntimeException('Konfigurasi Unbound belum tersedia.');
    if (!is_dir(FILTER_STATE) || is_link(FILTER_STATE) || fileowner(FILTER_STATE) !== 0) throw new RuntimeException('Direktori sinkronisasi belum siap. Jalankan kembali install.sh.');
    $stage = FILTER_STATE . '/.sync-' . bin2hex(random_bytes(8));
    mkdir($stage, 0700);
    $pdo = db();
    $options = filter_options($pdo);
    $sources = $argv[1] === '1' ? $pdo->query('SELECT id,name,url FROM blocklists WHERE is_active=1 ORDER BY id')->fetchAll() : [];
    $counts = [];
    $blocked = [];
    $whitelist = [];
    $rawCount = 0;
    $raw = fopen($stage . '/domain-blocklist.txt', 'wb');
    $rawBuffer = '';
    try {
        foreach ($sources as $source) {
            sync_log('Mengunduh blocklist: ' . $source['name']);
            $download = $stage . '/download';
            sync_download($source['url'], $download);
            $count = 0;
            foreach (filter_read_domains($download, true) as $domain) {
                sync_buffer($raw, $rawBuffer, $domain . "\n");
                $blocked[$domain] = true;
                $count++;
                if (count($blocked) > 2000000) throw new RuntimeException('Jumlah domain unik melebihi 2.000.000.');
            }
            if (!$count) throw new RuntimeException('Tidak ada domain valid pada sumber ' . $source['name']);
            $rawCount += $count;
            $counts[(int)$source['id']] = $count;
            sync_log($source['name'] . ': ' . number_format($count) . ' domain diproses.');
        }
        sync_buffer($raw, $rawBuffer, '', true);
    } finally { fclose($raw); }
    $normalizedCount = count($blocked);
    $remoteCount = 0;
    $manualCount = 0;
    if ($argv[2] === '1') {
        if ($options['whitelist_url'] !== '') {
            sync_log('Mengunduh whitelist otomatis.');
            sync_download($options['whitelist_url'], $stage . '/whitelist-download');
            foreach (filter_read_domains($stage . '/whitelist-download', false) as $domain) {
                $whitelist[$domain] = true;
                if (count($whitelist) > 1000000) throw new RuntimeException('Whitelist melebihi 1.000.000 domain.');
            }
            $remoteCount = count($whitelist);
            if (!$remoteCount) throw new RuntimeException('Sumber whitelist kosong atau tidak valid.');
        }
        foreach ($pdo->query('SELECT domain FROM whitelists') as $row) {
            $domain = filter_normalize($row['domain'], false);
            if ($domain !== null) { $whitelist[$domain] = true; $manualCount++; }
        }
    }
    ksort($whitelist, SORT_STRING);
    foreach ($blocked as $domain => $_) {
        if (filter_is_allowed($domain, $whitelist)) unset($blocked[$domain]);
    }
    ksort($blocked, SORT_STRING);
    foreach (['last-temp-final.txt' => $blocked, 'last-verified-whitelist.txt' => $whitelist] as $name => $domains) {
        $handle = fopen($stage . '/' . $name, 'wb');
        $buffer = '';
        try {
            foreach ($domains as $domain => $_) sync_buffer($handle, $buffer, $domain . "\n");
            sync_buffer($handle, $buffer, '', true);
        }
        finally { fclose($handle); }
    }
    sync_log(sprintf('Normalisasi: %d unik; whitelist: %d URL + %d manual; hasil akhir: %d domain.', $normalizedCount, $remoteCount, $manualCount, count($blocked)));
    $handle = fopen($stage . '/generated.conf', 'wb');
    $buffer = '';
    try {
        foreach (filter_config_lines($blocked, $whitelist, $options['redirect_ip']) as $line) sync_buffer($handle, $buffer, $line);
        sync_buffer($handle, $buffer, '', true);
    }
    finally { fclose($handle); }
    $finalCount = count($blocked);
    $whitelistCount = count($whitelist);
    // Leave RAM available for unbound-checkconf and the resolver during reload.
    unset($blocked, $whitelist, $domains);
    gc_collect_cycles();
    gc_mem_caches();

    foreach ([dirname(FILTER_OUTPUT), dirname(FILTER_INCLUDE)] as $dir) {
        if (is_link($dir)) throw new RuntimeException('Direktori filter tidak boleh berupa symlink.');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            chmod($dir, 0755);
        }
        if ($dir === dirname(FILTER_OUTPUT)) {
            chgrp($dir, 'unbound');
            chmod($dir, 0750);
        }
        if (!is_writable($dir)) throw new RuntimeException('Direktori ' . $dir . ' read-only. Jalankan kembali install.sh agar izin layanan PHP-FPM diperbarui.');
    }
    foreach ([FILTER_OUTPUT, FILTER_INCLUDE] as $index => $path) {
        if (is_link($path)) throw new RuntimeException('File filter tidak boleh berupa symlink.');
        $backups[$path] = null;
        if (is_file($path)) {
            $stat = stat($path);
            $backup = $stage . '/backup-' . $index;
            copy($path, $backup);
            $backups[$path] = [$backup, $stat['mode'] & 0777, $stat['gid'], $stat['uid']];
        }
    }
    $alreadyIncluded = filter_has_include('/etc/unbound/unbound.conf', FILTER_OUTPUT);
    $changed = true;
    sync_install($stage . '/generated.conf', FILTER_OUTPUT);
    if (in_array('1', [$argv[1], $argv[2]], true) && !$alreadyIncluded) {
        file_put_contents($stage . '/include.conf', "# Dikelola Unbound Web Manager.\nserver:\n    include: \"" . FILTER_OUTPUT . "\"\n");
        sync_install($stage . '/include.conf', FILTER_INCLUDE);
    } elseif (is_file(FILTER_INCLUDE)) { unlink(FILTER_INCLUDE); }
    if ($finalCount > 0) {
        // Unbound 1.13 (Ubuntu 22.04) cannot print local-zone with checkconf -o.
        $seen = [];
        if (!filter_has_include('/etc/unbound/unbound.conf', FILTER_OUTPUT, $seen, false)) {
            throw new RuntimeException('File filter belum terbaca. Tambahkan include-toplevel: "/etc/unbound/unbound.conf.d/*.conf" pada konfigurasi Unbound, atau include file blocklist.conf di dalam server:.');
        }
    }
    // Validate the entire configuration once, as the running daemon's user.
    // This checks include permissions on reload and avoids parsing million-domain
    // feeds three times just to discover the username and validate each file.
    $pidResult = CommandRunner::run(['/usr/bin/systemctl', 'show', '--value', '-p', 'MainPID', 'unbound'], 10);
    $pid = trim($pidResult['output']);
    if ($pidResult['code'] !== 0 || !ctype_digit($pid) || (int)$pid < 1) throw new RuntimeException('Unbound belum aktif.');
    $processStatus = (string)file_get_contents('/proc/' . $pid . '/status');
    if (!preg_match('/^Uid:\s+\d+\s+(\d+)/m', $processStatus, $match)) throw new RuntimeException('User layanan Unbound tidak dapat dibaca.');
    $account = posix_getpwuid((int)$match[1]);
    if ($account === false) throw new RuntimeException('Akun layanan Unbound tidak ditemukan.');
    sync_log('Memvalidasi konfigurasi dan akses file Unbound.');
    $check = CommandRunner::run(['/usr/sbin/runuser', '-u', $account['name'], '--', '/usr/sbin/unbound-checkconf', '/etc/unbound/unbound.conf'], 300);
    if ($check['code'] !== 0) throw new RuntimeException('Konfigurasi atau akses file Unbound tidak valid: ' . trim($check['output']));
    sync_log('Validasi berhasil. Memuat ulang Unbound.');
    $reloadAttempted = true;
    $reload = CommandRunner::run(['/usr/bin/systemctl', 'reload', 'unbound'], 60);
    if ($reload['code'] !== 0) throw new RuntimeException('Unbound gagal reload: ' . trim($reload['output']));
    usleep(250000);
    $active = CommandRunner::run(['/usr/bin/systemctl', 'is-active', '--quiet', 'unbound'], 10);
    if ($active['code'] !== 0) throw new RuntimeException('Unbound tidak aktif setelah reload.');
    $update = $pdo->prepare('UPDATE blocklists SET domain_count=?,last_updated=NOW(),last_error=NULL WHERE id=?');
    foreach ($counts as $id => $count) $update->execute([$count, $id]);
    foreach (['domain-blocklist.txt','last-temp-final.txt','last-verified-whitelist.txt'] as $name) {
        sync_install($stage . '/' . $name, FILTER_STATE . '/' . $name, 0640, 'unbound-web');
    }
    $summary = ['updated_at' => date('Y-m-d H:i:s'), 'raw' => $rawCount, 'normalized' => $normalizedCount,
        'remote_whitelist' => $remoteCount, 'manual_whitelist' => $manualCount, 'whitelist' => $whitelistCount,
        'removed' => $normalizedCount - $finalCount, 'final' => $finalCount, 'redirect_ip' => $options['redirect_ip'], 'output' => FILTER_OUTPUT];
    file_put_contents($stage . '/status.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    sync_install($stage . '/status.json', FILTER_STATE . '/status.json', 0640, 'unbound-web');
    sync_log('Selesai. File: ' . FILTER_OUTPUT);
    $changed = false;
} catch (Throwable $error) {
    $failed = true;
    $message = $error->getMessage();
    if ($changed) {
        try {
            foreach ($backups as $path => $backup) {
                if ($backup === null) { if (is_file($path)) unlink($path); }
                else sync_install($backup[0], $path, $backup[1], $backup[2], $backup[3]);
            }
            if ($reloadAttempted) {
                $restore = CommandRunner::run(['/usr/bin/systemctl', 'reload', 'unbound'], 60);
                if ($restore['code'] !== 0) throw new RuntimeException('reload konfigurasi sebelumnya gagal');
            }
            $message .= ' File filter sebelumnya dipulihkan.';
        } catch (Throwable $restoreError) { $message .= ' Pemulihan gagal: ' . $restoreError->getMessage(); }
    }
    fwrite(STDERR, '[ERROR] ' . $message . "\n");
    try { sync_log('[ERROR] ' . $message); } catch (Throwable) { }
    if (isset($pdo, $sources)) {
        $update = $pdo->prepare('UPDATE blocklists SET last_error=? WHERE id=?');
        foreach ($sources as $source) { try { $update->execute([substr($message, 0, 500), $source['id']]); } catch (Throwable) { } }
    }
} finally {
    if ($stage !== null && is_dir($stage)) {
        foreach (glob($stage . '/*') ?: [] as $file) { if (is_file($file)) unlink($file); }
        rmdir($stage);
    }
}
exit($failed ? 1 : 0);
