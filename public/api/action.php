<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/functions.php';
require_admin(true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}
$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) {
    json_response(['ok' => false, 'message' => 'Format data tidak valid.'], 400);
}
verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
$action = (string)($payload['action'] ?? '');
$pdo = db();

try {
    switch ($action) {
        case 'save-config':
            $interfaces = array_values(array_unique(array_map('trim', explode(',', (string)($payload['interfaces'] ?? '')))));
            $acls = array_values(array_unique(array_map('trim', explode(',', (string)($payload['access_control'] ?? '')))));
            $port = filter_var($payload['port'] ?? null, FILTER_VALIDATE_INT);
            $threads = filter_var($payload['num_threads'] ?? null, FILTER_VALIDATE_INT);
            $msg = (string)($payload['msg_cache'] ?? '');
            $rrset = (string)($payload['rrset_cache'] ?? '');
            $prefetch = (string)($payload['prefetch'] ?? '');
            $minTtl = filter_var($payload['cache_min_ttl'] ?? null, FILTER_VALIDATE_INT);
            $maxTtl = filter_var($payload['cache_max_ttl'] ?? null, FILTER_VALIDATE_INT);
            $slabs = filter_var($payload['cache_slabs'] ?? null, FILTER_VALIDATE_INT);
            $harden = (string)($payload['harden_dnssec'] ?? '');
            if (!$interfaces || count($interfaces) > 8) fail_validation('Masukkan 1 sampai 8 alamat interface.');
            foreach ($interfaces as $ip) if (filter_var($ip, FILTER_VALIDATE_IP) === false) fail_validation('Alamat interface tidak valid.');
            if (!$acls || count($acls) > 32) fail_validation('Masukkan 1 sampai 32 jaringan access-control.');
            foreach ($acls as $cidr) if (!valid_ip_or_cidr($cidr)) fail_validation('Subnet access-control tidak valid.');
            if ($port === false || $port < 1 || $port > 65535) fail_validation('Port harus 1 sampai 65535.');
            if ($threads === false || $threads < 1 || $threads > 256) fail_validation('num-threads harus 1 sampai 256.');
            if (!preg_match('/^[0-9]{1,6}[kKmMgG]?$/', $msg) || !preg_match('/^[0-9]{1,6}[kKmMgG]?$/', $rrset)) fail_validation('Ukuran cache harus berupa angka dengan satuan k, m, atau g opsional.');
            if (!in_array($prefetch, ['yes','no'], true)) fail_validation('Nilai prefetch hanya yes atau no.');
            if ($minTtl === false || $minTtl < 1 || $minTtl > 86400 || $maxTtl === false || $maxTtl < $minTtl || $maxTtl > 604800) fail_validation('Rentang cache TTL tidak valid.');
            if ($slabs === false || $slabs < 1 || $slabs > 256 || ($slabs & ($slabs - 1)) !== 0) fail_validation('Cache slabs harus bernilai pangkat dua sampai 256.');
            if (!in_array($harden, ['yes','no'], true)) fail_validation('Nilai harden-dnssec-stripped hanya yes atau no.');
            $result = CommandRunner::run(['/usr/bin/sudo','-n','/usr/local/sbin/unbound-web-manage','apply-config',implode(',', $interfaces),(string)$port,implode(',', $acls),(string)$threads,$msg,$rrset,$prefetch,(string)$minTtl,(string)$maxTtl,(string)$slabs,$harden], 60);
            json_response(['ok' => $result['code'] === 0, 'message' => trim($result['output']) ?: 'Konfigurasi diproses.', 'code' => $result['code']], $result['code'] === 0 ? 200 : 422);

        case 'config-view':
            $result = CommandRunner::run(['/usr/bin/sudo','-n','/usr/local/sbin/unbound-web-manage','view-config'], 10);
            json_response(['ok' => $result['code'] === 0, 'config' => $result['output'], 'message' => $result['code'] === 0 ? '' : trim($result['output'])], $result['code'] === 0 ? 200 : 422);

        case 'unbound-logging-set':
            $queries = $payload['log_queries'] ?? '';
            $replies = $payload['log_replies'] ?? '';
            if (!in_array($queries, ['yes', 'no'], true) || !in_array($replies, ['yes', 'no'], true)) {
                fail_validation('Pilih status log query dan reply.');
            }
            $result = CommandRunner::run(['/usr/bin/sudo', '-n', '/usr/local/sbin/unbound-web-manage', 'unbound-logging-set', $queries, $replies], 45);
            json_response(['ok' => $result['code'] === 0, 'message' => trim($result['output'])], $result['code'] === 0 ? 200 : 422);

        case 'blocklist-add':
            $name = trim((string)($payload['name'] ?? ''));
            $url = trim((string)($payload['url'] ?? ''));
            if ($name === '' || strlen($name) > 120) fail_validation('Nama sumber wajib diisi (maksimal 120 karakter).');
            if (!valid_public_https_url($url)) fail_validation('Masukkan URL HTTPS dengan hostname publik yang dapat di-resolve.');
            $stmt = $pdo->prepare('INSERT INTO blocklists (name,url,is_active) VALUES (?,?,1)');
            $stmt->execute([$name,$url]);
            json_response(['ok' => true, 'message' => 'Sumber blocklist ditambahkan. Jalankan Update & Apply Now untuk memuatnya.']);

        case 'blocklist-delete':
            $id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) fail_validation('ID sumber tidak valid.');
            $pdo->prepare('DELETE FROM blocklists WHERE id=?')->execute([$id]);
            json_response(['ok' => true, 'message' => 'Sumber blocklist dihapus dari daftar. Jalankan sinkronisasi untuk menerapkan perubahan.']);

        case 'blocklist-update':
            $id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
            $name = trim((string)($payload['name'] ?? ''));
            $url = trim((string)($payload['url'] ?? ''));
            $active = (string)($payload['is_active'] ?? '');
            if ($id === false || $id < 1 || $name === '' || strlen($name) > 120 || !in_array($active, ['0','1'], true)) fail_validation('Data sumber blocklist tidak valid.');
            if (!valid_public_https_url($url)) fail_validation('Masukkan URL HTTPS dengan hostname publik yang dapat di-resolve.');
            $pdo->prepare('UPDATE blocklists SET name=?,url=?,is_active=? WHERE id=?')->execute([$name,$url,(int)$active,$id]);
            json_response(['ok'=>true,'message'=>'Sumber blocklist diperbarui. Jalankan sinkronisasi untuk menerapkan perubahan.']);

        case 'whitelist-add':
            $domain = strtolower(rtrim(trim((string)($payload['domain'] ?? '')), '.'));
            $note = trim((string)($payload['note'] ?? ''));
            if (!valid_domain($domain)) fail_validation('Nama domain whitelist tidak valid.');
            if (strlen($note) > 255) fail_validation('Catatan maksimal 255 karakter.');
            $pdo->prepare('INSERT INTO whitelists (domain,note) VALUES (?,?)')->execute([$domain,$note]);
            json_response(['ok' => true, 'message' => 'Domain whitelist ditambahkan. Jalankan sinkronisasi blocklist untuk menerapkan perubahan.']);

        case 'whitelist-delete':
            $id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) fail_validation('ID whitelist tidak valid.');
            $pdo->prepare('DELETE FROM whitelists WHERE id=?')->execute([$id]);
            json_response(['ok' => true, 'message' => 'Domain whitelist dihapus. Jalankan sinkronisasi untuk menerapkan perubahan.']);

        case 'whitelist-update':
            $id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
            $domain = strtolower(rtrim(trim((string)($payload['domain'] ?? '')), '.'));
            $note = trim((string)($payload['note'] ?? ''));
            if ($id === false || $id < 1 || !valid_domain($domain) || strlen($note) > 255) fail_validation('Data whitelist tidak valid.');
            $pdo->prepare('UPDATE whitelists SET domain=?,note=? WHERE id=?')->execute([$domain,$note,$id]);
            json_response(['ok'=>true,'message'=>'Whitelist diperbarui. Jalankan sinkronisasi untuk menerapkan perubahan.']);

        case 'schedule-save':
            $schedule = (string)($payload['schedule'] ?? '');
            if (!in_array($schedule, ['disabled','daily','weekly','6h','12h'], true)) fail_validation('Pilihan jadwal tidak valid.');
            $result = CommandRunner::run(['/usr/bin/sudo','-n','/usr/local/sbin/unbound-web-manage','blocklist-schedule',$schedule]);
            if ($result['code'] !== 0) json_response(['ok' => false, 'message' => trim($result['output'])], 422);
            $pdo->prepare('INSERT INTO app_settings (setting_key,setting_value) VALUES (\'blocklist_schedule\',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$schedule]);
            json_response(['ok' => true, 'message' => trim($result['output'])]);

        case 'zabbix-config':
            $server = trim((string)($payload['server'] ?? ''));
            $active = trim((string)($payload['server_active'] ?? ''));
            $hostname = trim((string)($payload['hostname'] ?? ''));
            if (!preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/', $server) || str_contains($server, '..')) fail_validation('Alamat Zabbix Server tidak valid.');
            if (!preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/', $active) || str_contains($active, '..')) fail_validation('Alamat Zabbix ServerActive tidak valid.');
            if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/', $hostname)) fail_validation('Hostname hanya boleh berisi huruf, angka, titik, garis bawah, dan strip.');
            $result = CommandRunner::run(['/usr/bin/sudo','-n','/usr/local/sbin/unbound-web-manage','zabbix-config',$server,$active,$hostname], 180);
            if ($result['code'] !== 0) json_response(['ok' => false, 'message' => trim($result['output'])], 422);
            save_zabbix_settings($pdo,$server,$active,$hostname);
            refresh_system_status_cache();
            json_response(['ok' => true, 'message' => trim($result['output'])]);

        case 'zabbix-service':
            $serviceAction = (string)($payload['service_action'] ?? '');
            if (!in_array($serviceAction,['start','stop','restart','enable','disable'],true)) fail_validation('Aksi service tidak valid.');
            $result = CommandRunner::run(['/usr/bin/sudo','-n','/usr/local/sbin/unbound-web-manage','zabbix-service',$serviceAction], 30);
            refresh_system_status_cache();
            json_response(['ok' => $result['code'] === 0, 'message' => trim($result['output']) ?: 'Aksi service selesai.'], $result['code'] === 0 ? 200 : 422);

        case 'firewall-add':
        case 'firewall-update':
        case 'firewall-delete':
            $args = ['/usr/bin/sudo', '-n', '/usr/local/sbin/unbound-web-manage', $action];
            if ($action !== 'firewall-add') {
                $id = $payload['id'] ?? '';
                if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D', $id)) {
                    fail_validation('ID aturan tidak valid. Muat ulang halaman.');
                }
                $args[] = $id;
            }
            if ($action !== 'firewall-delete') {
                $ruleAction = $payload['rule_action'] ?? '';
                $direction = $payload['direction'] ?? '';
                $protocol = $payload['protocol'] ?? '';
                $port = filter_var($payload['port'] ?? null, FILTER_VALIDATE_INT);
                $peer = $payload['peer'] ?? '';
                if (!in_array($ruleAction, ['allow', 'deny'], true) || !in_array($direction, ['in', 'out'], true)
                    || !in_array($protocol, ['tcp', 'udp'], true) || $port === false || $port < 1 || $port > 65535) {
                    fail_validation('Pilih Allow/Deny, IN/OUT, TCP/UDP, dan port 1–65535.');
                }
                if (!is_string($peer) || (trim($peer) !== '' && !valid_ip_or_cidr(trim($peer)))) {
                    fail_validation('Masukkan IP atau CIDR IPv4/IPv6 yang valid.');
                }
                array_push($args, $ruleAction, $direction, $protocol, (string)$port, trim($peer));
            }
            $result = CommandRunner::run($args, 45);
            json_response(['ok' => $result['code'] === 0, 'message' => trim($result['output'])], $result['code'] === 0 ? 200 : 422);

        case 'firewall-enable':
        case 'firewall-disable':
            $confirmation = $action === 'firewall-enable' ? 'ENABLE' : 'DISABLE';
            if (($payload['confirm'] ?? '') !== $confirmation) {
                fail_validation('Konfirmasi perubahan status UFW diperlukan.');
            }
            $result=CommandRunner::run(['/usr/bin/sudo','-n','/usr/local/sbin/unbound-web-manage',$action],30);
            json_response(['ok'=>$result['code']===0,'message'=>trim($result['output'])],$result['code']===0?200:422);

        case 'cron-view':
            $result=CommandRunner::run(['/usr/bin/sudo','-n','/usr/local/sbin/unbound-web-manage','cron-view']);
            json_response(['ok'=>$result['code']===0,'output'=>$result['output']],$result['code']===0?200:422);

        case 'cron-custom-save':
            $id = trim((string)($payload['id'] ?? ''));
            $fields = array_map(static fn(string $key): string => trim((string)($payload[$key] ?? '')), ['minute','hour','day','month','weekday']);
            $task = (string)($payload['task'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) || !in_array($task, ['update-blocklists','update-root-hints'], true)) fail_validation('ID atau tugas cron tidak valid.');
            foreach ($fields as $fieldValue) if (!preg_match('/^[0-9*/,\\-]{1,32}$/', $fieldValue)) fail_validation('Format waktu cron tidak valid.');
            $command = array_merge(['/usr/bin/sudo','-n','/usr/local/sbin/unbound-web-manage','cron-custom-save',$id], $fields, [$task]);
            $result = CommandRunner::run($command);
            json_response(['ok'=>$result['code']===0,'message'=>trim($result['output'])],$result['code']===0?200:422);

        case 'cron-custom-delete':
            $id = trim((string)($payload['id'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id)) fail_validation('ID cron tidak valid.');
            $result = CommandRunner::run(['/usr/bin/sudo','-n','/usr/local/sbin/unbound-web-manage','cron-custom-delete',$id]);
            json_response(['ok'=>$result['code']===0,'message'=>trim($result['output'])],$result['code']===0?200:422);

        case 'domain-add':
            $domain=strtolower(rtrim(trim((string)($payload['domain']??'')),'.'));
            $type=(string)($payload['query_type']??'A');
            if (!valid_domain($domain)) fail_validation('Domain target tidak valid.');
            if (!in_array($type,['A','AAAA','MX','TXT','NS','CNAME'],true)) fail_validation('Tipe query tidak didukung.');
            $pdo->prepare('INSERT INTO test_domains (domain,query_type) VALUES (?,?)')->execute([$domain,$type]);
            json_response(['ok'=>true,'message'=>'Domain pengujian ditambahkan.']);

        case 'domain-delete':
            $id=filter_var($payload['id']??null,FILTER_VALIDATE_INT);
            if ($id===false || $id<1) fail_validation('ID domain tidak valid.');
            $pdo->prepare('DELETE FROM test_domains WHERE id=?')->execute([$id]);
            json_response(['ok'=>true,'message'=>'Domain pengujian dihapus.']);

        case 'domain-update':
            $id=filter_var($payload['id']??null,FILTER_VALIDATE_INT);
            $domain=strtolower(rtrim(trim((string)($payload['domain']??'')),'.'));
            $type=(string)($payload['query_type']??'');
            if ($id===false || $id<1 || !valid_domain($domain) || !in_array($type,['A','AAAA','MX','TXT','NS','CNAME'],true)) fail_validation('Data domain pengujian tidak valid.');
            $pdo->prepare('UPDATE test_domains SET domain=?,query_type=? WHERE id=?')->execute([$domain,$type,$id]);
            json_response(['ok'=>true,'message'=>'Domain pengujian diperbarui.']);

        default:
            json_response(['ok'=>false,'message'=>'Aksi tidak dikenal.'],400);
    }
} catch (PDOException $error) {
    error_log('Unbound Web action DB: ' . $error->getMessage());
    $message = $error->getCode() === '23000' ? 'Data dengan nilai yang sama sudah terdaftar.' : 'Database gagal memproses perubahan.';
    json_response(['ok'=>false,'message'=>$message],422);
} catch (Throwable $error) {
    error_log('Unbound Web action: ' . $error->getMessage());
    json_response(['ok'=>false,'message'=>'Aksi gagal. Periksa log aplikasi dan status service.'],500);
}

function save_zabbix_settings(PDO $pdo, string $server, string $active, string $hostname): void
{
    $statement=$pdo->prepare('INSERT INTO app_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    foreach (['zabbix_server'=>$server,'zabbix_server_active'=>$active,'zabbix_hostname'=>$hostname] as $key=>$value) $statement->execute([$key,$value]);
}
