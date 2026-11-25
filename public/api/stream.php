<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/functions.php';
require_admin(true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok'=>false,'message'=>'Metode tidak diizinkan.'],405);
$payload=json_decode((string)file_get_contents('php://input'),true);
if (!is_array($payload)) json_response(['ok'=>false,'message'=>'Format data tidak valid.'],400);
verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN']??null);
$task=(string)($payload['task']??'');
$args=['/usr/bin/sudo','-n','/usr/local/sbin/unbound-web-manage'];
switch ($task) {
    case 'blocklist-sync':
        $args[]='update-blocklists';
        break;
    case 'dnsperf':
        $target=trim((string)($payload['target']??'')); $port=filter_var($payload['port']??null,FILTER_VALIDATE_INT);
        $duration=filter_var($payload['duration']??null,FILTER_VALIDATE_INT); $clients=(string)($payload['clients']??'');
        $threads=(string)($payload['threads']??''); $qps=(string)($payload['qps']??'0');
        if (!valid_private_ipv4($target)) json_response(['ok'=>false,'message'=>'Target dibatasi ke alamat IPv4 private atau loopback.'],422);
        if ($port===false||$port<1||$port>65535||$duration===false||$duration<1||$duration>300||
            !preg_match('/^[1-9][0-9]{0,8}$/',$clients)||!preg_match('/^[1-9][0-9]{0,8}$/',$threads)||
            ($qps!=='0'&&!preg_match('/^[1-9][0-9]{0,8}$/',$qps))) json_response(['ok'=>false,'message'=>'Parameter dnsperf tidak valid; durasi maksimum 300 detik.'],422);
        $args = ['/usr/bin/php8.4', APP_ROOT . '/bin/dnsperf-run.php'];
        array_push($args,$target,(string)$port,(string)$duration,$clients,$threads,$qps);
        break;
    default:
        json_response(['ok'=>false,'message'=>'Proses streaming tidak dikenal.'],400);
}

session_write_close();
while (ob_get_level() > 0) ob_end_flush();
set_time_limit(0);
ignore_user_abort(true);
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
flush();
$exitCode=CommandRunner::stream($args);
echo 'data: ' . json_encode(['done' => true, 'exitCode' => $exitCode], JSON_UNESCAPED_SLASHES) . "\n\n";
flush();

// System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
