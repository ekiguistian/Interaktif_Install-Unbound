<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/functions.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function readStat(string $stats, string $name): float
{
    if (preg_match('/^' . preg_quote($name, '/') . '=([0-9]+(?:\.[0-9]+)?)$/m', $stats, $match)) {
        return (float)$match[1];
    }
    return 0.0;
}

function readCpuSample(): array
{
    $line = file_get_contents('/proc/stat');
    if ($line === false || !preg_match('/^cpu\s+([0-9 ]+)/m', $line, $match)) {
        return [0, 0];
    }
    $values = array_map('intval', preg_split('/\s+/', trim($match[1])) ?: []);
    $total = array_sum($values);
    $idle = ($values[3] ?? 0) + ($values[4] ?? 0);
    return [$total, $idle];
}

function cpuPercent(): float
{
    $path = '/var/lib/unbound-web/cpu.sample';
    [$total, $idle] = readCpuSample();
    $previous = is_readable($path) ? json_decode((string)file_get_contents($path), true) : null;
    $temp = $path . '.' . getmypid() . '.tmp';
    file_put_contents($temp, json_encode([$total, $idle]), LOCK_EX);
    rename($temp, $path);
    if (!is_array($previous) || count($previous) !== 2) {
        return 0.0;
    }
    $totalDelta = $total - (int)$previous[0];
    $idleDelta = $idle - (int)$previous[1];
    return $totalDelta > 0 ? max(0, min(100, (($totalDelta - $idleDelta) / $totalDelta) * 100)) : 0.0;
}

function systemMetrics(): array
{
    $memory = [];
    foreach (file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)/', $line, $match)) {
            $memory[$match[1]] = (int)$match[2];
        }
    }
    $memoryTotal = (int)($memory['MemTotal'] ?? 0);
    $memoryUsed = max(0, $memoryTotal - (int)($memory['MemAvailable'] ?? $memoryTotal));
    $load = preg_split('/\s+/', trim((string)file_get_contents('/proc/loadavg'))) ?: [];
    $diskTotal = disk_total_space('/') ?: 0;
    $diskFree = disk_free_space('/') ?: 0;
    $uptime = (float)(explode(' ', trim((string)file_get_contents('/proc/uptime')))[0] ?? 0);
    return [
        'cpu_percent' => round(cpuPercent(), 3),
        'load_1m' => (float)($load[0] ?? 0),
        'load_5m' => (float)($load[1] ?? 0),
        'load_15m' => (float)($load[2] ?? 0),
        'memory_used_mb' => (int)round($memoryUsed / 1024),
        'memory_total_mb' => (int)round($memoryTotal / 1024),
        'disk_used_gb' => round(max(0, $diskTotal - $diskFree) / 1073741824, 3),
        'disk_total_gb' => round($diskTotal / 1073741824, 3),
        'uptime_seconds' => (int)$uptime,
    ];
}

try {
    $lock = fopen('/var/lib/unbound-web/worker.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        exit(0);
    }
    $serviceResult = CommandRunner::run(['/usr/bin/sudo', '-n', '/usr/local/sbin/unbound-web-manage', 'service-status']);
    $services = ['unbound' => 'unknown', 'unbound_version' => '', 'zabbix' => 'unknown'];
    if ($serviceResult['code'] === 0) {
        foreach (preg_split('/\R/', trim($serviceResult['output'])) as $line) {
            if (preg_match('/^(unbound|zabbix)=([a-z-]+)$/', $line, $match)) {
                $services[$match[1]] = $match[2];
            } elseif (preg_match('/^unbound_version=([0-9.]+)$/', $line, $match)) {
                $services['unbound_version'] = $match[1];
            }
        }
    }
    $statusPath = '/var/lib/unbound-web/system-status.json';
    $statusTemp = $statusPath . '.' . getmypid() . '.tmp';
    file_put_contents($statusTemp, json_encode($services, JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($statusTemp, $statusPath);
    $stats = '';
    if ($services['unbound'] === 'active') {
        $statsResult = CommandRunner::run(['/usr/bin/sudo', '-n', '/usr/local/sbin/unbound-web-manage', 'stats'], 8);
        if ($statsResult['code'] !== 0) {
            throw new RuntimeException('unbound-control stats_noreset gagal: ' . trim($statsResult['output']));
        }
        $stats = $statsResult['output'];
    }
    $queries = (int)readStat($stats, 'total.num.queries');
    $hits = (int)readStat($stats, 'total.num.cachehits');
    $misses = (int)readStat($stats, 'total.num.cachemiss');
    $replies = $hits + (int)readStat($stats, 'total.num.recursivereplies');
    $cacheRatio = ($hits + $misses) > 0 ? ($hits / ($hits + $misses)) * 100 : 0;
    $queryTypes = [];
    if (preg_match_all('/^num\.query\.type\.([A-Za-z0-9_-]+)=([0-9]+)$/m', $stats, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $queryTypes[$match[1]] = (int)$match[2];
        }
    }
    $responseCodes = [];
    if (preg_match_all('/^num\.answer\.rcode\.([A-Za-z0-9_-]+)=([0-9]+)$/m', $stats, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $responseCodes[$match[1]] = (int)$match[2];
        }
    }
    foreach (['NOERROR', 'NXDOMAIN', 'SERVFAIL', 'REFUSED'] as $rcode) {
        $responseCodes[$rcode] ??= 0;
    }

    $recursionTotal = 0.0;
    $recursionWeight = 0;
    if (preg_match_all('/^thread([0-9]+)\.recursion\.time\.avg=([0-9]+(?:\.[0-9]+)?)$/m', $stats, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $weight = (int)readStat($stats, 'thread' . $match[1] . '.num.recursivereplies');
            $recursionTotal += (float)$match[2] * max(1, $weight);
            $recursionWeight += max(1, $weight);
        }
    }
    $recursionMs = $recursionWeight > 0 ? ($recursionTotal / $recursionWeight) * 1000 : 0;

    $interface = app_env('NET_INTERFACE', 'eth0');
    if (!preg_match('/^[A-Za-z0-9_.:-]{1,15}$/', $interface)) {
        $interface = 'eth0';
    }
    $base = '/sys/class/net/' . $interface . '/statistics/';
    $rx = is_readable($base . 'rx_bytes') ? (int)file_get_contents($base . 'rx_bytes') : 0;
    $tx = is_readable($base . 'tx_bytes') ? (int)file_get_contents($base . 'tx_bytes') : 0;
    $pdo = db();
    $previous = $pdo->query('SELECT recorded_at,total_queries FROM metrics ORDER BY id DESC LIMIT 1')->fetch();
    $qps = 0.0;
    if ($previous) {
        $elapsed = max(1, time() - strtotime((string)$previous['recorded_at']));
        $delta = $queries >= (int)$previous['total_queries'] ? $queries - (int)$previous['total_queries'] : $queries;
        $qps = $delta / $elapsed;
    }
    $system = systemMetrics();
    $insert = $pdo->prepare('INSERT INTO metrics (recorded_at,total_queries,total_replies,cache_hits,cache_hit_ratio,qps,rx_bytes,tx_bytes,query_types_json,response_codes_json,recursion_avg_ms,cpu_percent,load_1m,load_5m,load_15m,memory_used_mb,memory_total_mb,disk_used_gb,disk_total_gb,uptime_seconds) VALUES (NOW(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $insert->execute([
        $queries, $replies, $hits, round($cacheRatio, 4), round($qps, 4), $rx, $tx,
        json_encode($queryTypes, JSON_UNESCAPED_SLASHES), json_encode($responseCodes, JSON_UNESCAPED_SLASHES),
        round($recursionMs, 4), $system['cpu_percent'], $system['load_1m'], $system['load_5m'], $system['load_15m'],
        $system['memory_used_mb'], $system['memory_total_mb'], $system['disk_used_gb'], $system['disk_total_gb'], $system['uptime_seconds'],
    ]);
    $pdo->exec('DELETE FROM metrics WHERE recorded_at < NOW() - INTERVAL 30 DAY');
    printf("Metrik disimpan. Query %d, reply %d, cache %.2f%%, %.2f QPS, CPU %.1f%%.\n", $queries, $replies, $cacheRatio, $qps, $system['cpu_percent']);
} catch (Throwable $error) {
    error_log('Unbound Web worker: ' . $error->getMessage());
    fwrite(STDERR, 'Worker gagal. Periksa journal dan konfigurasi aplikasi. ' . $error->getMessage() . "\n");
    exit(1);
}
