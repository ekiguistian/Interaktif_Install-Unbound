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
    case 'wizard':
        $step=filter_var($payload['step']??null,FILTER_VALIDATE_INT);
        if ($step===false || $step<1 || $step>5) json_response(['ok'=>false,'message'=>'Tahap wizard tidak valid.'],422);
        $args[]='wizard-step-' . $step;
        break;
    case 'blocklist-sync':
        $args[]='update-blocklists';
        break;
    case 'app-update':
        $args[] = 'app-update';
        break;
    case 'zabbix-install':
        $server=trim((string)($payload['server']??''));
        $active=trim((string)($payload['server_active']??''));
        $hostname=trim((string)($payload['hostname']??''));
        if (!preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/',$server)||str_contains($server,'..')||
            !preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/',$active)||str_contains($active,'..')||
            !preg_match('/^[A-Za-z0-9._-]{1,128}$/',$hostname)) json_response(['ok'=>false,'message'=>'Parameter Zabbix tidak valid.'],422);
        array_push($args,'zabbix-install',$server,$active,$hostname);
        break;
    case 'dnsperf':
        $target=trim((string)($payload['target']??'')); $port=filter_var($payload['port']??null,FILTER_VALIDATE_INT);
        $duration=filter_var($payload['duration']??null,FILTER_VALIDATE_INT); $clients=(string)($payload['clients']??'');
        $threads=(string)($payload['threads']??''); $qps=(string)($payload['qps']??'0');
        if (!valid_private_ipv4($target)) json_response(['ok'=>false,'message'=>'Target dibatasi ke alamat IPv4 private atau loopback.'],422);
        if ($port===false||$port<1||$port>65535||$duration===false||$duration<1||$duration>300||
            !preg_match('/^[1-9][0-9]{0,8}$/',$clients)||!preg_match('/^[1-9][0-9]{0,8}$/',$threads)||
            ($qps!=='0'&&!preg_match('/^[1-9][0-9]{0,8}$/',$qps))) json_response(['ok'=>false,'message'=>'Parameter dnsperf tidak valid; durasi maksimum 300 detik.'],422);
        array_push($args,'dnsperf',$target,(string)$port,(string)$duration,$clients,$threads,$qps);
        break;
    default:
        json_response(['ok'=>false,'message'=>'Proses streaming tidak dikenal.'],400);
}

while (ob_get_level() > 0) ob_end_flush();
set_time_limit(0);
ignore_user_abort(true);
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
flush();
$exitCode=CommandRunner::stream($args);
if ($task==='wizard' && $exitCode!==0) {
    echo 'data: ' . json_encode(['output' => "\nMemulihkan resolver sistem setelah kegagalan...\n"], JSON_UNESCAPED_SLASHES) . "\n\n";
    flush();
    CommandRunner::stream(['/usr/bin/sudo','-n','/usr/local/sbin/unbound-web-manage','wizard-rollback']);
}
if ($task==='zabbix-install' && $exitCode===0) {
    $statement=db()->prepare('INSERT INTO app_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    foreach (['zabbix_server'=>$server,'zabbix_server_active'=>$active,'zabbix_hostname'=>$hostname] as $key=>$value) $statement->execute([$key,$value]);
}
if ($exitCode===0 && in_array($task,['wizard','zabbix-install'],true)) refresh_system_status_cache();
echo 'data: ' . json_encode(['done' => true, 'exitCode' => $exitCode], JSON_UNESCAPED_SLASHES) . "\n\n";
flush();
