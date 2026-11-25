<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
require_admin();
$stateResult = manage(['unbound-logging-state'], null, 8);
$logging = $stateResult['code'] === 0 ? json_decode($stateResult['output'], true) : null;
page_start('Live Log Unbound', true);
?>
<section class="panel p-5 mb-5 space-y-4">
  <h2 class="font-semibold">Logging Unbound</h2>
  <?php if (!is_array($logging)): ?>
    <p class="text-rose-300">Status logging belum bisa dibaca.</p>
  <?php else: ?>
    <?php if (!$logging['running']): ?><p class="text-sm text-slate-400">Aktifkan Unbound untuk mengubah logging.</p><?php endif; ?>
    <div class="flex flex-wrap gap-3">
      <?php foreach (['log-queries' => 'log query', 'log-replies' => 'log reply'] as $option => $label): ?>
        <?php $enabled = $logging[$option]; ?>
        <form class="flex items-center gap-2" data-api="unbound-logging-set" data-reload="false" data-log-toggle="<?=e($label)?>">
          <input type="hidden" name="option" value="<?=e($option)?>">
          <input type="hidden" name="value" value="<?=$enabled ? 'no' : 'yes'?>">
          <button class="btn-secondary" type="submit" <?=$logging['running'] ? '' : 'disabled'?>><?=e(($enabled ? 'Nonaktifkan ' : 'Aktifkan ') . $label)?></button>
          <span class="state-label" data-log-state data-active="<?=$enabled ? 'true' : 'false'?>"><?=$enabled ? 'Aktif' : 'Nonaktif'?></span>
        </form>
      <?php endforeach; ?>
    </div>
    <p role="status" data-page-result></p>
  <?php endif; ?>
</section>
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
