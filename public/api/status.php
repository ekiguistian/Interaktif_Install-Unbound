<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/functions.php';
require_admin(true);
header('Cache-Control: no-store');
session_write_close();

try {
    // Keep one-second detail for the latest two minutes and 10-second buckets before that.
    $rows = db()->query('SELECT m.*, UNIX_TIMESTAMP(m.recorded_at) AS epoch FROM metrics m
        INNER JOIN (SELECT MAX(id) AS id FROM metrics WHERE recorded_at >= NOW() - INTERVAL 10 MINUTE
            GROUP BY CASE WHEN recorded_at >= NOW() - INTERVAL 2 MINUTE THEN UNIX_TIMESTAMP(recorded_at)
                ELSE FLOOR(UNIX_TIMESTAMP(recorded_at)/10)*10 END
        ) points ON points.id=m.id ORDER BY m.id DESC LIMIT 181')->fetchAll();
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
        $sampleTime = (float)($sample['collected_at'] ?? $row['epoch']);
        $elapsed = $sampleTime - ($previous['epoch'] ?? 0);
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
        $previous = $networkSample + ['epoch' => $sampleTime, 'uptime' => (int)$row['uptime_seconds']];
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
        'ok' => true, 'history' => $history, 'services' => system_status(), 'interval_seconds' => 1,
        'host' => gethostname(), 'kernel' => php_uname('r'), 'os' => $os['PRETTY_NAME'] ?? 'Linux',
        'interface' => app_env('NET_INTERFACE'), 'now' => time(),
    ]);
} catch (Throwable $error) {
    error_log('Unbound Web metrics: ' . $error->getMessage());
    json_response(['ok'=>false, 'message'=>'Riwayat metrik belum dapat dibaca.'], 503);
}

// System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
