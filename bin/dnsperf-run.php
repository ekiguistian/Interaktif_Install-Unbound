<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/functions.php';

if (PHP_SAPI !== 'cli' || $argc !== 7) {
    fwrite(STDERR, "Usage: dnsperf-run.php TARGET PORT SECONDS CLIENTS THREADS QPS\n");
    exit(2);
}
$target = $argv[1];
$port = $argv[2];
$duration = $argv[3];
$clients = $argv[4];
$threads = $argv[5];
$qps = $argv[6];
if (!valid_private_ipv4($target) || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535 ||
    !ctype_digit($duration) || (int)$duration < 1 || (int)$duration > 300 ||
    !preg_match('/^[1-9][0-9]{0,8}$/', $clients) || !preg_match('/^[1-9][0-9]{0,8}$/', $threads) ||
    ($qps !== '0' && !preg_match('/^[1-9][0-9]{0,8}$/', $qps))) {
    fwrite(STDERR, "Parameter dnsperf tidak valid. Target harus IP private/loopback dan durasi maksimum 300 detik.\n");
    exit(2);
}

$file = null;
function streamDnsperf(array $argv): int
{
    $pipes = [];
    $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin']);
    if (!is_resource($process)) {
        fwrite(STDERR, "dnsperf tidak dapat dijalankan.\n");
        return 127;
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    $status = ['exitcode' => 1, 'running' => true];
    while (true) {
        $chunk = stream_get_contents($pipes[1]);
        if ($chunk !== false && $chunk !== '') {
            echo $chunk;
            flush();
        }
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        usleep(100000);
    }
    $remaining = stream_get_contents($pipes[1]);
    if ($remaining !== false && $remaining !== '') {
        echo $remaining;
        flush();
    }
    fclose($pipes[1]);
    $closed = proc_close($process);
    return $closed >= 0 ? $closed : (int)$status['exitcode'];
}

try {
    $rows = db()->query('SELECT domain,query_type FROM test_domains ORDER BY id')->fetchAll();
    if (!$rows) {
        throw new RuntimeException('Daftar domain pengujian masih kosong. Tambahkan domain di menu Domain Pengujian.');
    }
    $file = tempnam('/var/lib/unbound-web', 'dnsperf-');
    if ($file === false) {
        throw new RuntimeException('File input dnsperf tidak dapat dibuat.');
    }
    chmod($file, 0640);
    $handle = fopen($file, 'wb');
    if ($handle === false) {
        throw new RuntimeException('File input dnsperf tidak dapat dibuka.');
    }
    foreach ($rows as $row) {
        $domain = strtolower(rtrim((string)$row['domain'], '.'));
        $type = (string)$row['query_type'];
        if (valid_domain($domain) && in_array($type, ['A','AAAA','MX','TXT','NS','CNAME'], true)) {
            fwrite($handle, $domain . ' ' . $type . "\n");
        }
    }
    fclose($handle);
    $size = filesize($file);
    if (!$size) {
        throw new RuntimeException('Tidak ada domain pengujian yang valid.');
    }
    echo "Target: $target:$port | Durasi: $duration detik | Client: $clients | Thread: $threads | QPS limit: " . ($qps === '0' ? 'tanpa batas' : $qps) . "\n";
    echo 'Jumlah query domain: ' . count($rows) . "\n\n";
    flush();
    $args = ['/usr/bin/dnsperf', '-f', 'inet', '-s', $target, '-p', $port, '-d', $file, '-l', $duration, '-c', $clients, '-T', $threads, '-S', '2'];
    if ($qps !== '0') {
        $args[] = '-Q';
        $args[] = $qps;
    }
    $exitCode = streamDnsperf($args);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if (is_string($file) && is_file($file)) {
        unlink($file);
    }
}
exit($exitCode);
