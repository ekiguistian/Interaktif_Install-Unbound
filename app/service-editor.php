<?php
declare(strict_types=1);
$name = $service === 'unbound' ? 'Unbound' : 'Zabbix Agent';
$result = manage(['config-read', $service]);
$config = $result['code'] === 0 ? json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR) : null;
page_start($name, true);
if ($service === 'unbound'): ?>
<section class="panel p-5 mb-5">
  <div class="flex flex-wrap items-center gap-3"><h2 class="font-semibold mr-auto">Kontrol resolver</h2>
  <?php foreach (['stats'=>'Baca statistik','unbound-check'=>'Cek konfigurasi Unbound','unbound-reload'=>'Reload server DNS','unbound-restart'=>'Restart Unbound'] as $action=>$label): ?>
    <form data-api="<?=e($action)?>" data-reload="false" <?= $action !== 'stats' ? 'data-confirm="Terapkan aksi ini ke Unbound?"' : '' ?>>
      <button class="btn-secondary" type="submit"><?=e($label)?></button></form>
  <?php endforeach; ?></div><pre data-page-result role="status" class="mt-3 max-h-72 overflow-auto"></pre>
</section><?php endif;
if ($service === 'unbound'): ?>
<section class="panel p-5 mb-5 space-y-4">
  <h2 class="font-semibold">Root hints, DNSSEC, dan sertifikat kontrol</h2>
  <p class="text-sm text-slate-400">Unduh root hints sebelum mengaktifkannya. DNSSEC memakai trust anchor root. Sertifikat kontrol dipakai untuk TLS unbound-control.</p>
  <div class="flex flex-wrap gap-3">
  <?php foreach (['root-hints-update'=>'Unduh / perbarui root hints','root-hints-enable'=>'Aktifkan root hints','root-hints-disable'=>'Nonaktifkan root hints','dnssec-anchor'=>'Siapkan / perbarui trust anchor','dnssec-enable'=>'Aktifkan DNSSEC','dnssec-disable'=>'Nonaktifkan DNSSEC','control-certificates'=>'Buat sertifikat kontrol'] as $action => $label): ?>
    <form data-api="<?=e($action)?>" data-reload="false" data-output="#unbound-dns-result" data-confirm="Terapkan aksi ini ke Unbound?">
      <button class="btn-secondary" type="submit"><?=e($label)?></button>
    </form>
  <?php endforeach; ?>
  </div>
  <pre id="unbound-dns-result" role="status" class="text-sm"></pre>
</section><?php endif;
if ($config === null) { notice(trim($result['output']), 'error'); } else { ?>
<form class="panel p-5 space-y-4" data-api="config-save" data-confirm="Simpan file dan muat ulang layanan?">
  <label for="config" class="block font-mono"><?=e($config['path'])?></label>
  <input type="hidden" name="service" value="<?=e($service)?>">
  <input type="hidden" name="revision" value="<?=e($config['revision'])?>">
  <textarea id="config" class="form-textarea font-mono text-sm" rows="22" name="content" spellcheck="false" wrap="off" required>
<?=e($config['content'])?></textarea>
  <button class="btn-primary" type="submit">Simpan &amp; muat ulang</button><p data-result role="status"></p>
</form>
<?php } page_end(); ?>
