<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
require_admin();
page_start('Live Log Unbound', true);
?>
<section class="panel p-5 space-y-4">
  <p class="text-sm text-slate-400">Menampilkan 40 entri terakhir dan log baru dari journal layanan Unbound.</p>
  <form class="flex flex-wrap gap-2" data-stream="unbound-live-log" data-output="#unbound-log">
    <button class="btn-primary" type="submit">Mulai Live Log</button>
    <button class="btn-secondary" type="button" data-stream-stop disabled>Stop</button>
  </form>
  <p role="status" id="unbound-log-status">Belum berjalan.</p>
  <pre id="unbound-log" class="live-log" tabindex="0" aria-label="Log Unbound"></pre>
</section>
<?php page_end(); ?>

<?php // System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
