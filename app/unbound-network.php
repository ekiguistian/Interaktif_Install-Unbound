<?php
declare(strict_types=1);
?>
<section class="panel p-5 space-y-4" aria-labelledby="unbound-network">
  <h2 id="unbound-network" class="font-semibold">Interface &amp; Reverse DNS</h2>
  <?php if (!empty($config['network_sync_failed'])): ?>
    <p role="status">Snapshot jaringan belum tersimpan ke database. Muat ulang halaman untuk mencoba lagi.</p>
  <?php endif; ?>
  <?php foreach (['interface' => 'Interface DNS', 'local-zone' => 'Zona reverse', 'local-data' => 'Record PTR'] as $kind => $label): ?>
    <div class="space-y-2">
      <h3 class="font-medium"><?=e($label)?></h3>
      <?php if (!$config['network'][$kind]): ?>
        <p class="text-sm">Belum ada di file konfigurasi utama.</p>
      <?php endif; ?>
      <?php foreach ($config['network'][$kind] as $row): ?>
        <?php if (!$row['editable']): ?>
          <p class="text-sm"><code><?=e($row['value'])?></code> · <?= $row['loopback'] ? 'Loopback, hanya baca' : 'Nama interface, hanya baca' ?></p>
          <?php continue; ?>
        <?php endif; ?>
        <form class="space-y-2" data-api="unbound-network-save"
          data-confirm="<?= $kind === 'interface' ? 'Simpan IP dan restart Unbound jika layanan aktif?' : 'Simpan perubahan dan muat ulang Unbound jika layanan aktif?' ?>">
          <input type="hidden" name="kind" value="<?=e($kind)?>">
          <input type="hidden" name="line" value="<?=e((string)$row['line'])?>">
          <input type="hidden" name="revision" value="<?=e($config['revision'])?>">
          <label class="block text-sm">
            <?= $kind === 'interface' ? 'Alamat IP' : ($kind === 'local-zone' ? 'Nama zona' : 'Nama reverse PTR') ?>
            <input class="form-input font-mono" name="value" value="<?=e($kind === 'interface' ? $row['address'] : $row['name'])?>" maxlength="254" required spellcheck="false">
          </label>
          <?php if ($kind === 'interface' && $row['port'] !== ''): ?>
            <p class="text-sm">Port <?=e($row['port'])?></p>
          <?php elseif ($kind === 'local-zone'): ?>
            <p class="text-sm">Tipe zona <code><?=e($row['mode'])?></code></p>
          <?php elseif ($kind === 'local-data'): ?>
            <label class="block text-sm">Hostname tujuan PTR
              <input class="form-input font-mono" name="target" value="<?=e($row['target'])?>" maxlength="254" required spellcheck="false">
            </label>
          <?php endif; ?>
          <button class="btn-secondary" type="submit">Simpan <?=e($label)?></button>
          <p data-result role="status"></p>
        </form>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</section>

<?php // System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
