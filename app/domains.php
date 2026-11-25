<?php
declare(strict_types=1);

function valid_domain(string $domain): bool
{
    $domain = rtrim(strtolower(trim($domain)), '.');
    if (strlen($domain) < 1 || strlen($domain) > 253 || filter_var($domain, FILTER_VALIDATE_IP)) return false;
    $labels = explode('.', $domain);
    if (count($labels) < 2) return false;
    foreach ($labels as $label) {
        if (strlen($label) < 1 || strlen($label) > 63 || !preg_match('/^(?!-)[a-z0-9-]+(?<!-)$/', $label)) return false;
    }
    return true;
}

// System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
