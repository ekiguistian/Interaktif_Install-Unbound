<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
require_admin();
page_start('Pembaruan panel', true);
?>
<section class="panel p-5 space-y-4" data-update-panel>
  <p class="text-slate-400">Cek branch <code>main</code> di GitHub. Saat dipasang, installer dapat memulai ulang layanan web dan memutus akses panel sebentar.</p>
  <dl class="detail-grid">
    <div><dt>Commit terpasang</dt><dd><code data-installed>—</code></dd></div>
    <div><dt>Commit yang dicek</dt><dd><code data-update-commit>—</code></dd></div>
  </dl>
  <div class="flex flex-wrap gap-2">
    <button class="btn-secondary" type="button" data-update-check>Check for update</button>
    <button class="btn-primary" type="button" data-update-run hidden>Pasang pembaruan</button>
  </div>
  <p class="text-cyan-300" role="status" data-update-message>Memuat status pembaruan…</p>
</section>
<?php page_end(); ?>

<?php // System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
