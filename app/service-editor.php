<?php
declare(strict_types=1);
$name = $service === 'unbound' ? 'Unbound' : 'Zabbix Agent';
$result = manage(['config-read', $service]);
$config = $result['code'] === 0 ? json_decode($result['output'], true) : null;
page_start($name, true);
if ($service === 'unbound') require __DIR__ . '/resolver-controls.php';
if ($service === 'zabbix' && $config === null) require __DIR__ . '/zabbix-install.php';
if ($service === 'zabbix' && $config !== null) require __DIR__ . '/zabbix-metrics.php';
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

<?php // System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
