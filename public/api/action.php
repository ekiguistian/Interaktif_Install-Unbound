<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/functions.php';
require_once __DIR__ . '/../../app/filters.php';
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
try {
    $pdo = db();
    switch ($action) {
        case 'config-save':
            $service = $payload['service'] ?? '';
            $content = $payload['content'] ?? null;
            $revision = $payload['revision'] ?? '';
            if (!in_array($service, ['unbound', 'zabbix'], true) || !is_string($content)
                || !is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D', $revision)
                || strlen($content) > 1048576 || trim($content) === '') {
                fail_validation('File atau revisi tidak valid (maksimum 1 MiB).');
            }
            session_write_close();
            set_time_limit(0);
            $result = manage(['config-save', $service, $revision], $content, 600);
            json_response(['ok' => $result['code'] === 0, 'message' => trim($result['output'])], $result['code'] === 0 ? 200 : 422);

        case 'unbound-restart':
        case 'unbound-reload':
        case 'stats':
        case 'firewall-reload':
            $timeout = in_array($action, ['unbound-restart','unbound-reload'], true) ? 600 : 45;
            if ($timeout === 600) { session_write_close(); set_time_limit(0); }
            $result = manage([$action], null, $timeout);
            json_response(['ok' => $result['code'] === 0, 'message' => trim($result['output']) ?: 'Selesai.'], $result['code'] === 0 ? 200 : 422);

        case 'filters-save':
            session_write_close();
            set_time_limit(0);
            $block = isset($payload['block']) ? '1' : '0';
            $white = isset($payload['white']) ? '1' : '0';
            $result = manage(['filters-save', $block, $white], null, 1200);
            json_response(['ok' => $result['code'] === 0, 'message' => trim($result['output'])], $result['code'] === 0 ? 200 : 422);

        case 'filter-options-save':
            $url = trim((string)($payload['whitelist_url'] ?? ''));
            $redirect = trim((string)($payload['redirect_ip'] ?? ''));
            if ($url !== '' && !valid_public_https_url($url)) fail_validation('URL whitelist harus HTTPS publik. Kosongkan jika hanya memakai whitelist manual.');
            if (filter_var($redirect, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) fail_validation('Masukkan IPv4 redirect yang valid.');
            $pdo->beginTransaction();
            $save = $pdo->prepare('INSERT INTO app_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
            $save->execute(['whitelist_url', $url]);
            $save->execute(['blocklist_redirect_ip', $redirect]);
            $pdo->commit();
            json_response(['ok'=>true, 'message'=>'Pengaturan disimpan. Jalankan Update & Apply Now untuk menerapkan.']);

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
                $port = (string)($payload['port'] ?? '');
                $peer = $payload['peer'] ?? '';
                if (!in_array($ruleAction, ['allow', 'deny'], true) || !in_array($direction, ['in', 'out'], true)
                    || !in_array($protocol, FIREWALL_PROTOCOLS, true)) {
                    fail_validation('Aksi, arah, atau protokol tidak valid.');
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
