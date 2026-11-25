<?php
declare(strict_types=1);

const UFW_BEFORE = '/etc/ufw/before.rules';

function icmp_rules(): array
{
    $rules = [];
    foreach (file(UFW_BEFORE, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (!str_contains($line, ' -p icmp ')) continue;
        $id = hash('sha256', $line);
        $rule = null;
        if (preg_match('/^-A ufw-before-(input|output) -p icmp(?: (-s|-d) ([0-9.\/]+))? -m comment --comment unbound-web-icmp -j (ACCEPT|DROP)$/D', $line, $m)) {
            $rule = firewall_rule($m[4] === 'ACCEPT' ? 'allow' : 'deny', $m[1] === 'input' ? 'in' : 'out', 'icmp', '', $m[3] ?? '');
        }
        $rules[$id] = ['id'=>$id,'text'=>$line,'rule'=>$rule,'comment'=>''];
    }
    return $rules;
}

function write_before_rules(string $content): void
{
    $temp = tempnam(dirname(UFW_BEFORE), '.unbound-web-');
    try {
        if ($temp === false || file_put_contents($temp, $content) !== strlen($content)
            || !chmod($temp, fileperms(UFW_BEFORE) & 0777) || !rename($temp, UFW_BEFORE)) {
            throw new RuntimeException('Gagal menyimpan before.rules.');
        }
    } finally {
        if ($temp && is_file($temp)) unlink($temp);
    }
}

function apply_rule(array $rule, bool $delete, string $comment = ''): void
{
    if ($rule['protocol'] !== 'icmp') {
        $args = firewall_arguments($rule);
        if ($delete) array_unshift($args, '--force', 'delete');
        elseif ($comment !== '') array_push($args, 'comment', $comment);
        firewall_ufw(...$args);
        return;
    }
    $in = $rule['direction'] === 'in';
    $line = '-A ufw-before-' . ($in ? 'input' : 'output') . ' -p icmp';
    if ($rule['peer'] !== '') $line .= ($in ? ' -s ' : ' -d ') . $rule['peer'];
    $line .= ' -m comment --comment unbound-web-icmp -j ' . ($rule['action'] === 'allow' ? 'ACCEPT' : 'DROP');
    $old = file_get_contents(UFW_BEFORE);
    if ($old === false) throw new RuntimeException('before.rules tidak terbaca.');
    if ($delete) {
        $new = str_replace("\n" . $line . "\n", "\n", $old);
    } else {
        if (!preg_match('/^\*filter\s*$.*?^(?=-A |COMMIT$)/ms', $old, $match, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException('Tabel filter UFW tidak dikenali.');
        }
        $pos = $match[0][1] + strlen($match[0][0]);
        $new = substr_replace($old, $line . "\n", $pos, 0);
    }
    write_before_rules($new);
    try {
        firewall_ufw('reload');
    } catch (Throwable $error) {
        write_before_rules($old);
        firewall_ufw('reload');
        throw $error;
    }
}

// System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
