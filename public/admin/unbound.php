<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
require_admin();
$logging = CommandRunner::run(['/usr/bin/sudo', '-n', '/usr/local/sbin/unbound-web-manage', 'unbound-logging-status'], 20);
$loggingReady = $logging['code'] === 0 && preg_match('/^(yes|no) (yes|no)$/D', trim($logging['output']), $logValues);
page_start('Konfigurasi Unbound', true);
?>
<section class="grid gap-5 xl:grid-cols-[1fr_.85fr]">
  <div class="panel p-5 sm:p-6">
    <div class="mb-5"><h2 class="font-semibold">Parameter resolver</h2><p class="mt-1 text-sm leading-6 text-slate-400">Form ini mengelola konfigurasi dasar. Perubahan divalidasi dengan <code>unbound-checkconf</code> sebelum service di-reload.</p></div>
    <div class="mb-5 rounded-xl border border-amber-400/20 bg-amber-400/10 p-3 text-sm leading-6 text-amber-100">Batasi access-control ke subnet klien yang dipercaya. Jangan gunakan <code>0.0.0.0/0</code> pada resolver yang dapat diakses Internet.</div>
    <form class="space-y-4" data-api="save-config">
      <div class="grid gap-4 sm:grid-cols-2">
        <?php field('IP interface (pisahkan koma)','interfaces','127.0.0.1,::1','text','Contoh LAN: 192.168.1.2'); ?>
        <?php field('Port DNS','port','53','number'); ?>
        <?php field('Access-control IP/CIDR (pisahkan koma)','access_control','127.0.0.0/8,::1/128','text','Contoh LAN: 127.0.0.0/8,192.168.1.0/24'); ?>
        <?php field('Jumlah thread','num_threads','2','number','Nilai maksimum 256.'); ?>
        <?php field('msg-cache-size','msg_cache','128m','text','Contoh: 128m'); ?>
        <?php field('rrset-cache-size','rrset_cache','256m','text','Contoh: 256m'); ?>
        <?php field('Cache slabs','cache_slabs','2','number','Pangkat dua, maksimal 256.'); ?>
        <?php field('cache-min-ttl (detik)','cache_min_ttl','60','number','Minimum 1.'); ?>
        <?php field('cache-max-ttl (detik)','cache_max_ttl','86400','number','Maksimum 604800.'); ?>
      </div>
      <label class="block"><span class="mb-2 block text-sm font-medium">Prefetch</span><select class="form-select" name="prefetch"><option value="yes">Aktif</option><option value="no">Nonaktif</option></select></label>
      <label class="block"><span class="mb-2 block text-sm font-medium">harden-dnssec-stripped</span><select class="form-select" name="harden_dnssec"><option value="yes">Aktif</option><option value="no">Nonaktif</option></select></label>
      <button class="btn-primary" type="submit"><i class="fa-solid fa-check"></i>Validasi & terapkan</button>
      <p data-result class="text-sm text-cyan-200"></p>
    </form>
  </div>
  <div class="panel p-5 sm:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="font-semibold">unbound.conf aktif</h2><p class="mt-1 text-sm text-slate-400">Tampilan read-only dari file sistem.</p></div>
      <form data-api="config-view" data-reload="false"><button class="btn-secondary" type="submit"><i class="fa-solid fa-rotate"></i>Muat file</button></form>
    </div>
    <pre data-page-result class="mt-5 max-h-[38rem] min-h-64 overflow-auto rounded-xl border border-slate-700 bg-black/50 p-4 font-mono text-xs leading-6 text-emerald-200">Klik “Muat file” untuk membaca konfigurasi aktif.</pre>
  </div>
</section>
<section class="panel mt-5 p-5 sm:p-6">
  <h2 class="font-semibold">Log query dan reply</h2>
  <?php if ($loggingReady): ?>
    <form class="mt-4 space-y-4" data-api="unbound-logging-set">
      <div class="grid gap-4 sm:grid-cols-2">
        <?php foreach (['log_queries' => ['Log query', $logValues[1]], 'log_replies' => ['Log reply', $logValues[2]]] as $name => [$label, $value]): ?>
          <label>
            <span class="mb-2 block text-sm"><?= e($label) ?></span>
            <select class="form-select" name="<?= e($name) ?>">
              <option value="no" <?= $value === 'no' ? 'selected' : '' ?>>Nonaktif</option>
              <option value="yes" <?= $value === 'yes' ? 'selected' : '' ?>>Aktif</option>
            </select>
          </label>
        <?php endforeach; ?>
      </div>
      <button class="btn-primary" type="submit">Simpan pengaturan log</button>
      <p data-result role="status" class="text-sm text-cyan-200"></p>
    </form>
  <?php else: ?>
    <p class="mt-3 text-sm text-slate-400">Status log belum dapat dibaca.</p>
  <?php endif; ?>
</section>
<?php page_end(); ?>
