<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/filters.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$directory = sys_get_temp_dir() . '/unbound-filter-test-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
try {
    $fixtures = [
        "# ignore.example\n0.0.0.0 WWW.Example.com alias.example # comment.example\n::1 ipv6host.example\n||ad.example^\n@@||allowed.example^\nhttps://www.url.example/path\n*.wild.example\n" =>
            ['example.com','alias.example','ipv6host.example','ad.example','url.example','wild.example'],
        '"www.csv.example","category"' . "\n" . '"other.example",0' => ['csv.example','other.example'],
        '["www.json.example", {"domain":"url.example", "key.example":true}, "127.0.0.1"]' => ['json.example','url.example','key.example'],
    ];
    foreach ($fixtures as $text => $expected) {
        file_put_contents($directory . '/feed', $text);
        expect(iterator_to_array(filter_read_domains($directory . '/feed', true), false) === $expected, 'Parser fixture failed');
    }
    file_put_contents($directory . '/feed', "WWW.Allow.example\nallow.example\n");
    expect(iterator_to_array(filter_read_domains($directory . '/feed', false), false) === ['www.allow.example','allow.example'], 'Whitelist must preserve www');
    foreach (['127.0.0.1', 'localhost', 'bad".example', '-bad.example', "example.com;include:", 'bad..example'] as $value) {
        expect(filter_normalize($value, true) === null, 'Unsafe domain accepted: ' . $value);
    }
    file_put_contents($directory . '/feed', '<html><body>Error</body></html>');
    try { iterator_to_array(filter_read_domains($directory . '/feed', true)); throw new LogicException('HTML accepted'); }
    catch (RuntimeException $expected) { }
    $allowed = ['allow.example'=>true,'child.block.example'=>true,'www.only.example'=>true];
    expect(filter_is_allowed('a.b.allow.example', $allowed), 'Allowed subtree was blocked');
    expect(!filter_is_allowed('notallow.example', $allowed), 'Suffix must match a whole label');
    expect(!filter_is_allowed('only.example', $allowed), 'www exception must not allow its parent');
    $config = implode('', iterator_to_array(filter_config_lines(['block.example'=>true,'only.example'=>true], $allowed, '192.168.1.2')));
    expect(str_contains($config, 'local-data: "block.example. A 192.168.1.2"'), 'Redirect missing');
    expect(str_contains($config, 'local-zone: "child.block.example." transparent'), 'Child whitelist under a blocked parent missing');
    expect(str_contains($config, 'local-zone: "www.only.example." transparent'), 'www exception missing');
    file_put_contents($directory . '/main.conf', '# include: "' . FILTER_OUTPUT . '"' . "\ninclude-toplevel: \"$directory/nested.conf\"\n");
    file_put_contents($directory . '/nested.conf', 'server:' . "\n include: \"" . FILTER_OUTPUT . "\"\n");
    expect(filter_has_include($directory . '/main.conf', FILTER_OUTPUT), 'Nested existing include not detected');
    file_put_contents($directory . '/nested.conf', 'include: "' . $directory . '/main.conf"' . "\n");
    expect(!filter_has_include($directory . '/main.conf', FILTER_OUTPUT), 'Comment/cyclic include falsely detected');
    echo "PASS: feed formats, normalization, domain validation, whitelist precedence, redirect rules, include discovery\n";
} finally {
    foreach (glob($directory . '/*') as $file) unlink($file);
    rmdir($directory);
}
