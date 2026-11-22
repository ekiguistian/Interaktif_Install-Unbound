<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/functions.php';
header('Cache-Control: no-store, max-age=0');
if (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    $revisionFile = APP_ROOT . '/revision';
    $revision = is_readable($revisionFile) ? trim((string)file_get_contents($revisionFile)) : '';
    if (preg_match('/^[a-f0-9]{40}$/D', $revision)) {
        header('X-Unbound-Web-Revision: ' . $revision);
    }
}

try {
    $rows = db()->query('SELECT recorded_at,total_queries,total_replies,cache_hits,cache_hit_ratio,qps,rx_bytes,tx_bytes,query_types_json,response_codes_json,recursion_avg_ms,cpu_percent,load_1m,load_5m,load_15m,memory_used_mb,memory_total_mb,disk_used_gb,disk_total_gb,uptime_seconds FROM metrics WHERE recorded_at >= NOW() - INTERVAL 1 HOUR ORDER BY recorded_at DESC LIMIT 60')->fetchAll();
    $rows = array_reverse($rows);
    $history = [];
    $previous = null;
    foreach ($rows as $row) {
        $time = strtotime((string)$row['recorded_at']);
        $seconds = $previous === null ? 60 : max(1, $time - $previous['time']);
        $rx = $previous === null ? 0 : max(0, ((int)$row['rx_bytes'] - (int)$previous['rx']) / $seconds);
        $tx = $previous === null ? 0 : max(0, ((int)$row['tx_bytes'] - (int)$previous['tx']) / $seconds);
        $history[] = [
            'recorded_at' => $row['recorded_at'],
            'total_queries' => (int)$row['total_queries'],
            'total_replies' => (int)$row['total_replies'],
            'cache_hits' => (int)$row['cache_hits'],
            'cache_hit_ratio' => (float)$row['cache_hit_ratio'],
            'qps' => (float)$row['qps'],
            'query_types' => json_decode((string)($row['query_types_json'] ?? '{}'), true) ?: [],
            'response_codes' => json_decode((string)($row['response_codes_json'] ?? '{}'), true) ?: [],
            'recursion_avg_ms' => (float)$row['recursion_avg_ms'],
            'cpu_percent' => (float)$row['cpu_percent'],
            'load_1m' => (float)$row['load_1m'],
            'load_5m' => (float)$row['load_5m'],
            'load_15m' => (float)$row['load_15m'],
            'memory_used_mb' => (int)$row['memory_used_mb'],
            'memory_total_mb' => (int)$row['memory_total_mb'],
            'disk_used_gb' => (float)$row['disk_used_gb'],
            'disk_total_gb' => (float)$row['disk_total_gb'],
            'uptime_seconds' => (int)$row['uptime_seconds'],
            'rx_bps' => round($rx, 2),
            'tx_bps' => round($tx, 2),
        ];
        $previous = ['time' => $time, 'rx' => (int)$row['rx_bytes'], 'tx' => (int)$row['tx_bytes']];
    }
    json_response(['ok' => true, 'services' => system_status(), 'latest' => $history ? end($history) : null, 'history' => $history]);
} catch (Throwable $error) {
    error_log('Unbound Web status API: ' . $error->getMessage());
    json_response(['ok' => false, 'message' => 'Database atau worker metrik belum siap.'], 503);
}
