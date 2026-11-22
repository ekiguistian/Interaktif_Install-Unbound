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
    $line = @file_get_contents('/proc/stat');
    if ($line === false || !preg_match('/^cpu\s+([0-9 ]+)/m', $line, $match)) {
        return [];
    }
    $values = array_map('intval', preg_split('/\s+/', trim($match[1])) ?: []);
    $total = array_sum(array_slice($values, 0, 8));
    $idle = ($values[3] ?? 0) + ($values[4] ?? 0);
    return [$total, $idle];
}

function cpuPercent(): ?float
{
    $path = '/var/lib/unbound-web/cpu.sample';
    $current = readCpuSample();
    if (!$current) return null;
    [$total, $idle] = $current;
    $previous = is_readable($path) ? json_decode((string)file_get_contents($path), true) : null;
    $temp = $path . '.' . getmypid() . '.tmp';
    file_put_contents($temp, json_encode([$total, $idle]), LOCK_EX);
    rename($temp, $path);
    if (!is_array($previous) || count($previous) !== 2) {
        return null;
    }
    $totalDelta = $total - (int)$previous[0];
    $idleDelta = $idle - (int)$previous[1];
    return $totalDelta > 0 ? max(0, min(100, (($totalDelta - $idleDelta) / $totalDelta) * 100)) : null;
}

function systemMetrics(): array
{
    $memory = [];
    foreach (@file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)/', $line, $match)) {
            $memory[$match[1]] = (int)$match[2];
        }
    }
    $memoryTotal = $memory['MemTotal'] ?? null;
    $memoryUsed = isset($memory['MemTotal'], $memory['MemAvailable']) ? $memoryTotal - $memory['MemAvailable'] : null;
    $load = sys_getloadavg() ?: [];
    $diskTotal = disk_total_space('/');
    $diskFree = disk_free_space('/');
    $uptime = @file_get_contents('/proc/uptime');
    return [
        'cpu_percent' => cpuPercent(),
        'load_1m' => $load[0] ?? null,
        'load_5m' => $load[1] ?? null,
        'load_15m' => $load[2] ?? null,
        'memory_used_mb' => $memoryUsed === null ? null : (int)round($memoryUsed / 1024),
        'memory_total_mb' => $memoryTotal === null ? null : (int)round($memoryTotal / 1024),
        'disk_used_gb' => $diskTotal === false || $diskFree === false ? null : round(max(0, $diskTotal - $diskFree) / 1073741824, 3),
        'disk_total_gb' => $diskTotal === false ? null : round($diskTotal / 1073741824, 3),
        'uptime_seconds' => $uptime === false ? null : (int)(float)$uptime,
    ];
}

try {
    $lock = fopen('/var/lib/unbound-web/worker.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        exit(0);
    }
    $services = read_service_status();
    $statusPath = '/var/lib/unbound-web/system-status.json';
    $statusTemp = $statusPath . '.' . getmypid() . '.tmp';
    file_put_contents($statusTemp, json_encode($services, JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($statusTemp, $statusPath);
    $stats = '';
    if ($services['unbound'] === 'active') {
        $statsResult = CommandRunner::run(['/usr/bin/sudo', '-n', '/usr/local/sbin/unbound-web-manage', 'stats'], 8);
        if ($statsResult['code'] !== 0) {
            error_log('Statistik Unbound gagal: ' . trim($statsResult['output']));
        }
        $stats = $statsResult['code'] === 0 ? $statsResult['output'] : '';
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

    $recursionTotal = 0.0;
    $recursionWeight = 0;
    if (preg_match_all('/^thread([0-9]+)\.recursion\.time\.avg=([0-9]+(?:\.[0-9]+)?)$/m', $stats, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $weight = (int)readStat($stats, 'thread' . $match[1] . '.num.recursivereplies');
            $recursionTotal += (float)$match[2] * $weight;
            $recursionWeight += $weight;
        }
    }
    $recursionMs = $recursionWeight > 0 ? ($recursionTotal / $recursionWeight) * 1000 : 0;

    $interfaces = [];
    foreach (glob('/sys/class/net/*/statistics') ?: [] as $dir) {
        $rx = @file_get_contents($dir . '/rx_bytes');
        $tx = @file_get_contents($dir . '/tx_bytes');
        if ($rx !== false && $tx !== false) {
            $interfaces[basename(dirname($dir))] = ['rx' => (int)$rx, 'tx' => (int)$tx];
        }
    }
    $system = systemMetrics();
    $sample = [
        'interfaces' => $interfaces,
        'dns' => preg_match('/^total\.num\.queries=\d+$/m', $stats) === 1,
        'dns_uptime' => readStat($stats, 'time.up'),
        'system' => $system,
        'boot' => trim((string)file_get_contents('/proc/sys/kernel/random/boot_id')),
    ];
    $pdo = db();
    $previous = $pdo->query('SELECT UNIX_TIMESTAMP(recorded_at) AS epoch,total_queries,sample_json FROM metrics ORDER BY id DESC LIMIT 1')->fetch();
    $last = json_decode((string)($previous['sample_json'] ?? ''), true);
    $elapsed = $previous ? time() - (int)$previous['epoch'] : 0;
    $sample['qps'] = null;
    if ($elapsed > 0 && $sample['dns'] && ($last['dns'] ?? false)
        && $sample['boot'] === $last['boot'] && $sample['dns_uptime'] >= $last['dns_uptime']
        && $queries >= (int)$previous['total_queries']) {
        $sample['qps'] = ($queries - (int)$previous['total_queries']) / $elapsed;
    }
    $qps = $sample['qps'] ?? 0;
    $primary = $interfaces[app_env('NET_INTERFACE')] ?? ['rx' => 0, 'tx' => 0];
    $system = array_map(static fn($value) => $value ?? 0, $system);
    $insert = $pdo->prepare('INSERT INTO metrics (recorded_at,total_queries,total_replies,cache_hits,cache_hit_ratio,qps,rx_bytes,tx_bytes,query_types_json,response_codes_json,recursion_avg_ms,cpu_percent,load_1m,load_5m,load_15m,memory_used_mb,memory_total_mb,disk_used_gb,disk_total_gb,uptime_seconds,sample_json) VALUES (NOW(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $insert->execute([
        $queries, $replies, $hits, round($cacheRatio, 4), round($qps, 4), $primary['rx'], $primary['tx'],
        json_encode($queryTypes, JSON_UNESCAPED_SLASHES), json_encode($responseCodes, JSON_UNESCAPED_SLASHES),
        round($recursionMs, 4), $system['cpu_percent'] ?? 0, $system['load_1m'], $system['load_5m'], $system['load_15m'],
        $system['memory_used_mb'], $system['memory_total_mb'], $system['disk_used_gb'], $system['disk_total_gb'], $system['uptime_seconds'], json_encode($sample, JSON_THROW_ON_ERROR),
    ]);
    $pdo->exec('DELETE FROM metrics WHERE recorded_at < NOW() - INTERVAL 30 DAY');
    printf("Metrik disimpan. Query %d, reply %d, cache %.2f%%, %.2f QPS, CPU %.1f%%.\n", $queries, $replies, $cacheRatio, $qps, $system['cpu_percent']);
} catch (Throwable $error) {
    error_log('Unbound Web worker: ' . $error->getMessage());
    fwrite(STDERR, 'Worker gagal. Periksa journal dan konfigurasi aplikasi. ' . $error->getMessage() . "\n");
    exit(1);
}
