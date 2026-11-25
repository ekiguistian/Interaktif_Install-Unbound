<?php
declare(strict_types=1);

final class CommandRunner
{
    /** @param list<string> $argv */
    private static function command(array $argv): array
    {
        if ($argv === []) {
            throw new InvalidArgumentException('Perintah kosong.');
        }
        return $argv;
    }

    /** @param list<string> $argv @return array{code:int,output:string} */
    public static function run(array $argv, int $timeout = 10, ?string $input = null): array
    {
        $command = self::command($argv);
        $stdin = tmpfile();
        if ($stdin === false) throw new RuntimeException('Gagal membuka input proses.');
        if ($input !== null) { fwrite($stdin, $input); rewind($stdin); }
        $descriptor = [0 => $stdin, 1 => ['pipe', 'w'], 2 => ['redirect', 1]];
        $process = proc_open($command, $descriptor, $pipes, null, ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin']);
        if (!is_resource($process)) {
            return ['code' => 127, 'output' => 'Perintah sistem tidak dapat dijalankan.'];
        }
        fclose($stdin);
        stream_set_blocking($pipes[1], false);
        $output = '';
        $started = time();
        while (true) {
            $chunk = stream_get_contents($pipes[1]);
            if ($chunk !== false && $chunk !== '') {
                $output .= $chunk;
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (time() - $started > $timeout) {
                proc_terminate($process, 15);
                $output .= "\nPerintah dihentikan setelah batas waktu.\n";
                break;
            }
            usleep(100000);
        }
        $remaining = stream_get_contents($pipes[1]);
        if ($remaining !== false) {
            $output .= $remaining;
        }
        fclose($pipes[1]);
        $closed = proc_close($process);
        $code = $closed >= 0 ? $closed : (int)($status['exitcode'] ?? 1);
        return ['code' => $code, 'output' => $output];
    }

    /** @param list<string> $argv */
    public static function stream(array $argv): int
    {
        $command = self::command($argv);
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]];
        $process = proc_open($command, $descriptor, $pipes, null, ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin']);
        if (!is_resource($process)) {
            echo 'data: ' . json_encode(['output' => "Gagal menjalankan proses sistem.\n"], JSON_UNESCAPED_SLASHES) . "\n\n";
            flush();
            return 127;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        $status = ['exitcode' => 1, 'running' => true];
        while (true) {
            $chunk = stream_get_contents($pipes[1]);
            if ($chunk !== false && $chunk !== '') {
                echo 'data: ' . json_encode(['output' => $chunk], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
                flush();
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(100000);
        }
        $remaining = stream_get_contents($pipes[1]);
        if ($remaining !== false && $remaining !== '') {
            echo 'data: ' . json_encode(['output' => $remaining], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
            flush();
        }
        fclose($pipes[1]);
        $closed = proc_close($process);
        return $closed >= 0 ? $closed : (int)$status['exitcode'];
    }
}

// System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
