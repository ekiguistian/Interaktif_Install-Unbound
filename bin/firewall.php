<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/CommandRunner.php';

/** @return array{action:string,direction:string,protocol:string,port:string,peer:string} */
function firewall_rule(string $action, string $direction, string $protocol, string $port, string $peer): array
{
    if (!in_array($action, ['allow', 'deny'], true) || !in_array($direction, ['in', 'out'], true)
        || !in_array($protocol, ['tcp', 'udp'], true)
        || !preg_match('/^[1-9][0-9]{0,4}$/D', $port) || (int)$port > 65535) {
        throw new InvalidArgumentException('Pilih Allow/Deny, IN/OUT, TCP/UDP, dan port 1–65535.');
    }

    if ($peer !== '' && $peer !== 'any') {
        [$address, $prefix] = array_pad(explode('/', $peer, 2), 2, null);
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('Masukkan IP atau CIDR IPv4/IPv6 yang valid.');
        }
        $packed = inet_pton($address);
        $max = strlen($packed) * 8;
        if ($prefix !== null && (!preg_match('/^[0-9]{1,3}$/D', $prefix) || (int)$prefix > $max)) {
            throw new InvalidArgumentException('Panjang prefix CIDR tidak valid.');
        }
        $bits = $prefix === null ? $max : (int)$prefix;
        if ($bits === 0) {
            throw new InvalidArgumentException('Untuk semua alamat, kosongkan kolom IP/CIDR.');
        }
        for ($i = 0; $i < strlen($packed); $i++) {
            $keep = max(0, min(8, $bits - 8 * $i));
            $packed[$i] = chr(ord($packed[$i]) & (0xff << (8 - $keep)));
        }
        $peer = inet_ntop($packed) . ($bits === $max ? '' : "/$bits");
    } else {
        $peer = '';
    }

    return compact('action', 'direction', 'protocol', 'port', 'peer');
}

function firewall_ufw(string ...$args): string
{
    $result = CommandRunner::run(['/usr/bin/env', 'LC_ALL=C', '/usr/sbin/ufw', ...$args], 8);
    if ($result['code'] !== 0 || str_contains($result['output'], 'Perintah dihentikan setelah batas waktu.')) {
        throw new RuntimeException(trim($result['output']) ?: 'Perintah UFW gagal.');
    }
    return trim($result['output']);
}

function firewall_rules(): array
{
    $rules = [];
    foreach (explode("\n", firewall_ufw('show', 'added')) as $line) {
        $line = trim($line);
        if (!str_starts_with($line, 'ufw ')) {
            continue;
        }
        $rule = null;
        $comment = '';
        $command = substr($line, 4);
        if (preg_match("/^(.*?) comment '([\x20-\x26\x28-\x7e]*)'$/D", $command, $parts)) {
            [, $command, $comment] = $parts;
        }
        try {
            if (preg_match('~^(allow|deny)(?: (in|out))? ([0-9]+)/([a-z]+)$~D', $command, $m)) {
                $rule = firewall_rule($m[1], $m[2] ?: 'in', $m[4], $m[3], '');
            } elseif (preg_match('~^(allow|deny)(?: (in|out))?(?: from (\S+))? to (\S+) port ([0-9]+) proto ([a-z]+)$~D', $command, $m)) {
                $direction = $m[2] ?: 'in';
                if (($direction === 'in' && $m[4] === 'any') || ($direction === 'out' && in_array($m[3], ['', 'any'], true))) {
                    $rule = firewall_rule($m[1], $direction, $m[6], $m[5], ($direction === 'in' ? $m[3] : $m[4]));
                }
            }
        } catch (InvalidArgumentException) {
            $rule = null;
        }
        $id = hash('sha256', $line);
        $rules[$id] = ['id' => $id, 'text' => $line, 'rule' => $rule, 'comment' => $comment];
    }
    return $rules;
}

function firewall_arguments(array $rule): array
{
    $peer = $rule['peer'] ?: 'any';
    $from = $rule['direction'] === 'in' ? $peer : 'any';
    $to = $rule['direction'] === 'out' ? $peer : 'any';
    return [$rule['action'], $rule['direction'], 'from', $from, 'to', $to, 'port', $rule['port'], 'proto', $rule['protocol']];
}

function firewall_change(string $operation, array $input): void
{
    $id = $operation === 'add' ? null : array_shift($input);
    $new = $operation === 'delete' ? null : firewall_rule(...$input);
    $rules = firewall_rules();
    $old = $id === null ? null : ($rules[$id] ?? null);
    if ($id !== null && ($old === null || $old['rule'] === null)) {
        throw new RuntimeException('Aturan sudah berubah atau tidak dapat diedit. Muat ulang halaman.');
    }
    if ($new !== null && $old !== null && $old['rule'] === $new) {
        echo "Aturan tetap sama.\n";
        return;
    }
    $selector = static fn(array $rule): array => array_diff_key($rule, ['action' => true]);
    $sameSelector = $new !== null && $old !== null && $selector($new) === $selector($old['rule']);
    foreach ($rules as $item) {
        if ($new !== null && $item['id'] !== $id && $item['rule'] !== null
            && $selector($item['rule']) === $selector($new)) {
            throw new RuntimeException('Port, protokol, arah, dan alamat ini sudah dipakai. Ubah aturan yang ada.');
        }
    }
    if ($new !== null) {
        $args = firewall_arguments($new);
        if (($old['comment'] ?? '') !== '') {
            array_push($args, 'comment', $old['comment']);
        }
        firewall_ufw(...$args);
        $savedRules = array_column(firewall_rules(), 'rule');
        if (!in_array($new, $savedRules, true)) {
            throw new RuntimeException('UFW belum menyimpan aturan yang diminta. Muat ulang daftar aturan.');
        }
    }
    if ($old !== null && !$sameSelector) {
        try {
            firewall_ufw('--force', 'delete', ...firewall_arguments($old['rule']));
            if (isset(firewall_rules()[$id])) {
                throw new RuntimeException('UFW masih menampilkan aturan lama.');
            }
        } catch (RuntimeException $error) {
            if ($new !== null) {
                try {
                    firewall_ufw('--force', 'delete', ...firewall_arguments($new));
                } catch (RuntimeException $rollbackError) {
                    throw new RuntimeException('Penghapusan aturan lama dan pemulihan gagal. Periksa daftar UFW. ' . $rollbackError->getMessage());
                }
            }
            throw $error;
        }
    }
    echo match ($operation) {
        'add' => "Aturan ditambahkan.\n",
        'update' => "Aturan diperbarui.\n",
        'delete' => "Aturan dihapus.\n",
    };
}

try {
    $operation = $argv[1] ?? '';
    $input = array_slice($argv, 2);
    $expected = ['list' => 0, 'enable' => 0, 'disable' => 0, 'add' => 5, 'update' => 6, 'delete' => 1];
    if (!isset($expected[$operation]) || count($input) !== $expected[$operation]) {
        throw new InvalidArgumentException('Operasi firewall tidak valid.');
    }
    $lock = fopen('/run/unbound-web-ufw.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Firewall sedang diproses. Coba lagi.');
    }

    if ($operation === 'list') {
        $status = firewall_ufw('status', 'numbered');
        echo json_encode(
            ['active' => str_starts_with($status, 'Status: active'), 'rules' => array_values(firewall_rules()), 'status' => $status],
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        );
    } elseif (in_array($operation, ['enable', 'disable'], true)) {
        echo firewall_ufw('--force', $operation), PHP_EOL;
    } else {
        firewall_change($operation, $input);
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
