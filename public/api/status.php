<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/functions.php';
require_admin(true);
header('Cache-Control: no-store');
session_write_close();

try {
    $rows = db()->query('SELECT *, UNIX_TIMESTAMP(recorded_at) AS epoch FROM metrics WHERE recorded_at >= NOW() - INTERVAL 1 HOUR ORDER BY id DESC LIMIT 61')->fetchAll();
    $history = [];
    $previous = null;
    foreach (array_reverse($rows) as $row) {
        $sample = json_decode((string)($row['sample_json'] ?? ''), true);
        // Historical rows contain counters for the installer-selected interface only.
        $networkSample = $sample ?? [
            'interfaces' => [app_env('NET_INTERFACE', 'eth0') => [
                'rx' => (int)$row['rx_bytes'], 'tx' => (int)$row['tx_bytes'],
            ]],
            'boot' => null,
        ];
        $elapsed = (int)$row['epoch'] - ($previous['epoch'] ?? 0);
        $network = [];
        foreach ($networkSample['interfaces'] as $name => $counters) {
            foreach (['rx', 'tx'] as $direction) {
                $last = $previous['interfaces'][$name][$direction] ?? null;
                $network[$name][$direction] = $last !== null && $elapsed > 0
                    && $networkSample['boot'] === $previous['boot'] && (int)$row['uptime_seconds'] >= $previous['uptime']
                    && $counters[$direction] >= $last
                    ? ($counters[$direction] - $last) * 8 / $elapsed : null;
            }
        }
        $previous = $networkSample + ['epoch' => (int)$row['epoch'], 'uptime' => (int)$row['uptime_seconds']];
        unset($row['sample_json']);
        $row['network'] = $network;
        $row['qps'] = $sample === null ? $row['qps'] : $sample['qps'];
        $row = ($sample['system'] ?? []) + $row;
        if ($sample !== null && !$sample['dns']) {
            foreach (['total_queries','total_replies','cache_hits','cache_hit_ratio','recursion_avg_ms'] as $key) $row[$key] = null;
        }
        $row['query_types'] = json_decode((string)$row['query_types_json'], true);
        $row['response_codes'] = json_decode((string)$row['response_codes_json'], true);
        $history[] = $row;
    }
    $os = is_readable('/etc/os-release') ? parse_ini_file('/etc/os-release', false, INI_SCANNER_RAW) : [];
    json_response([
        'ok' => true, 'history' => array_slice($history, -60), 'services' => system_status(),
        'host' => gethostname(), 'kernel' => php_uname('r'), 'os' => $os['PRETTY_NAME'] ?? 'Linux',
        'interface' => app_env('NET_INTERFACE'), 'now' => time(),
    ]);
} catch (Throwable $error) {
    error_log('Unbound Web metrics: ' . $error->getMessage());
    json_response(['ok'=>false, 'message'=>'Riwayat metrik belum dapat dibaca.'], 503);
}
