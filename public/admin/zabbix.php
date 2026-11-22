<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
require_admin();
$status=system_status();
$installed = in_array($status['zabbix'], ['active', 'inactive'], true);
$boot = $installed ? CommandRunner::run(['/usr/bin/sudo', '-n', '/usr/local/sbin/unbound-web-manage', 'zabbix-boot-status']) : null;
$bootState = $boot && $boot['code'] === 0 ? trim($boot['output']) : 'Tidak tersedia';
$server=setting('zabbix_server'); $active=setting('zabbix_server_active'); $hostname=setting('zabbix_hostname');
page_start('Zabbix Agent',true);
?>
<section class="grid gap-5 xl:grid-cols-[.75fr_1.25fr]">
  <div class="panel p-5 sm:p-6"><div class="flex items-center justify-between"><h2 class="font-semibold">Status service</h2><i class="fa-solid fa-chart-line text-cyan-300"></i></div><p class="mt-4 text-2xl font-semibold <?=$status['zabbix']==='active'?'text-emerald-300':'text-amber-300'?>"><?=e($status['zabbix'])?></p><p class="mt-2 text-sm leading-6 text-slate-400">Agent dipasang atau dikonfigurasi dari form ini. Nilai Server, ServerActive, dan Hostname berasal dari pengaturan aplikasi.</p>
    <?php if ($installed): ?>
      <p class="mt-3 text-sm text-slate-400">Status saat boot <span class="font-medium text-slate-200"><?= e($bootState) ?></span></p>
      <div class="mt-5 flex flex-wrap gap-2">
        <?php foreach (['enable' => 'Enable', 'disable' => 'Disable', 'restart' => 'Restart'] as $key => $label): ?>
          <form data-api="zabbix-service" <?= $key === 'disable' ? 'data-confirm="Nonaktifkan dan hentikan Zabbix Agent?"' : '' ?>>
            <input type="hidden" name="service_action" value="<?= e($key) ?>">
            <button class="btn-secondary" type="submit"><?= e($label) ?></button>
            <p data-result role="status" class="mt-2 text-sm text-cyan-200"></p>
          </form>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="panel p-5 sm:p-6"><h2 class="font-semibold">Konfigurasi Agent</h2><p class="mt-1 text-sm leading-6 text-slate-400">Isikan alamat server Zabbix dan hostname yang terdaftar di monitoring.</p>
    <form class="mt-5 grid gap-4 sm:grid-cols-2" data-stream="zabbix-install" data-output="#zabbix-output">
      <?php field('Zabbix Server IP atau hostname','server',$server,'text','Contoh: 192.168.1.20'); ?>
      <?php field('Zabbix ServerActive IP atau hostname','server_active',$active,'text','Contoh: 192.168.1.20'); ?>
      <?php field('Hostname agent','hostname',$hostname?:'dns-resolver-01','text','Huruf, angka, titik, garis bawah, strip.'); ?>
      <div class="flex items-end"><button class="btn-primary" type="submit"><i class="fa-solid fa-download"></i><?=$status['zabbix']==='not-installed'?'Install & Setup Zabbix Agent':'Simpan & terapkan konfigurasi'?></button></div>
    </form>
    <pre id="zabbix-output" class="mt-5 max-h-72 overflow-auto rounded-xl border border-slate-700 bg-black/50 p-4 font-mono text-xs leading-6 text-emerald-200">Output proses Agent akan muncul di sini.</pre>
    <a class="btn-secondary mt-3" href="/settings/zabbix">Muat ulang status</a>
  </div>
</section>
<?php page_end(); ?>
