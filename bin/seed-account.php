<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/accounts.php';

if (PHP_SAPI !== 'cli') exit(1);
try {
    seed_panel_account(db(), app_env('ADMIN_USER'), app_env('ADMIN_PASS'), app_env('ADMIN_RECOVERY_EMAIL'));
} catch (DomainException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

// System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
