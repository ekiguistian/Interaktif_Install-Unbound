<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
require_admin();
$logging = CommandRunner::run(['/usr/bin/sudo', '-n', '/usr/local/sbin/unbound-web-manage', 'unbound-logging-status'], 20);
$loggingReady = $logging['code'] === 0 && preg_match('/^(yes|no) (yes|no)$/D', trim($logging['output']), $logValues);
$dnsStatus = CommandRunner::run(['/usr/bin/sudo', '-n', '/usr/local/sbin/unbound-web-manage', 'system-dns-status'], 15);
$dnsLines = $dnsStatus['code'] === 0 ? preg_split('/\R/', trim($dnsStatus['output'])) : [];
$dnsMode = array_shift($dnsLines);
$nameservers = implode("\n", $dnsLines);
$resolver = CommandRunner::run(['/usr/bin/sudo', '-n', '/usr/local/sbin/unbound-web-manage', 'resolver-settings-status'], 15);
$resolverValues = ['mode' => 'root', 'dnssec' => 'yes', 'root_hints' => 'no'];
$configValues = [
    'interfaces' => '127.0.0.1,::1', 'port' => '53',
    'access_control' => '127.0.0.0/8,::1/128', 'num_threads' => '2',
    'msg_cache' => '128m', 'rrset_cache' => '256m', 'cache_slabs' => '2',
    'cache_min_ttl' => '60', 'cache_max_ttl' => '86400', 'prefetch' => 'yes',
];
$forwarders = [];
if ($resolver['code'] === 0) {
    foreach (preg_split('/\R/', trim($resolver['output'])) as $line) {
        if (preg_match('/^mode=(root|forward|both)$/D', $line, $match)) {
            $resolverValues['mode'] = $match[1];
        } elseif (preg_match('/^(dnssec|root_hints)=(yes|no)$/D', $line, $match)) {
            $resolverValues[$match[1]] = $match[2];
        } elseif (preg_match('/^forwarder=(.+)$/D', $line, $match)) {
            $forwarders[] = $match[1];
        } elseif (preg_match('/^(interfaces|access_control)=([0-9A-Fa-f:.,\/]+)$/D', $line, $match)) {
            $configValues[$match[1]] = $match[2];
        } elseif (preg_match('/^(port|num_threads|cache_slabs|cache_min_ttl|cache_max_ttl)=([0-9]+)$/D', $line, $match)) {
            $configValues[$match[1]] = $match[2];
        } elseif (preg_match('/^(msg_cache|rrset_cache)=([0-9]+[kKmMgG]?)$/D', $line, $match)) {
            $configValues[$match[1]] = $match[2];
        } elseif (preg_match('/^prefetch=(yes|no)$/D', $line, $match)) {
            $configValues['prefetch'] = $match[1];
        }
    }
}
page_start('Konfigurasi Unbound', true);
?>
<section class="grid gap-5 xl:grid-cols-[1fr_.85fr]">
  <div class="panel p-5 sm:p-6">
    <div class="mb-5"><h2 class="font-semibold">Parameter resolver</h2><p class="mt-1 text-sm leading-6 text-slate-400">Form ini mengelola konfigurasi dasar. Perubahan divalidasi dengan <code>unbound-checkconf</code> sebelum service di-reload.</p></div>
    <div class="mb-5 rounded-xl border border-amber-400/20 bg-amber-400/10 p-3 text-sm leading-6 text-amber-100">Batasi access-control ke subnet klien yang dipercaya. Jangan gunakan <code>0.0.0.0/0</code> pada resolver yang dapat diakses Internet.</div>
    <form class="space-y-4" data-api="save-config">
      <div class="grid gap-4 sm:grid-cols-2">
        <?php field('IP interface (pisahkan koma)','interfaces',$configValues['interfaces'],'text','Contoh LAN: 192.168.1.2'); ?>
        <?php field('Port DNS','port',$configValues['port'],'number'); ?>
        <?php field('Access-control IP/CIDR (pisahkan koma)','access_control',$configValues['access_control'],'text','Contoh LAN: 127.0.0.0/8,192.168.1.0/24'); ?>
        <?php field('Jumlah thread','num_threads',$configValues['num_threads'],'number','Nilai maksimum 256.'); ?>
        <?php field('msg-cache-size','msg_cache',$configValues['msg_cache'],'text','Contoh: 128m'); ?>
        <?php field('rrset-cache-size','rrset_cache',$configValues['rrset_cache'],'text','Contoh: 256m'); ?>
        <?php field('Cache slabs','cache_slabs',$configValues['cache_slabs'],'number','Pangkat dua, maksimal 256.'); ?>
        <?php field('cache-min-ttl (detik)','cache_min_ttl',$configValues['cache_min_ttl'],'number','Minimum 1.'); ?>
        <?php field('cache-max-ttl (detik)','cache_max_ttl',$configValues['cache_max_ttl'],'number','Maksimum 604800.'); ?>
      </div>
      <div class="grid gap-4 rounded-xl border border-slate-700 bg-slate-900/60 p-4 sm:grid-cols-2">
        <label class="block"><span class="mb-2 block text-sm font-medium">Mode resolver</span><select class="form-select" name="upstream_mode">
          <?php foreach (['root' => 'Root langsung', 'forward' => 'Forwarder saja', 'both' => 'Forwarder lalu Root'] as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= $resolverValues['mode'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select><span class="mt-1.5 block text-xs text-slate-500">Mode Gabungan memakai forwarder lebih dahulu dan kembali ke root saat forwarder gagal.</span></label>
        <label class="block"><span class="mb-2 block text-sm font-medium">Alamat upstream</span><textarea class="form-input" name="forwarders" rows="3" maxlength="360" placeholder="1.1.1.1&#10;2606:4700:4700::1111"><?= e(implode("\n", $forwarders)) ?></textarea><span class="mt-1.5 block text-xs text-slate-500">Wajib untuk mode Forwarder dan Gabungan, satu IP per baris, maksimal delapan.</span></label>
        <label class="block"><span class="mb-2 block text-sm font-medium">DNSSEC</span><select class="form-select" name="dnssec"><option value="no" <?= $resolverValues['dnssec'] === 'no' ? 'selected' : '' ?>>Nonaktif</option><option value="yes" <?= $resolverValues['dnssec'] === 'yes' ? 'selected' : '' ?>>Aktif</option></select></label>
        <label class="block"><span class="mb-2 block text-sm font-medium">Root hints eksternal</span><select class="form-select" name="root_hints"><option value="no" <?= $resolverValues['root_hints'] === 'no' ? 'selected' : '' ?>>Gunakan bawaan Unbound</option><option value="yes" <?= $resolverValues['root_hints'] === 'yes' ? 'selected' : '' ?>>Gunakan file InterNIC</option></select><span class="mt-1.5 block text-xs text-slate-500">Tersedia untuk mode Root dan Gabungan.</span></label>
      </div>
      <label class="block"><span class="mb-2 block text-sm font-medium">Prefetch</span><select class="form-select" name="prefetch"><option value="yes" <?= $configValues['prefetch'] === 'yes' ? 'selected' : '' ?>>Aktif</option><option value="no" <?= $configValues['prefetch'] === 'no' ? 'selected' : '' ?>>Nonaktif</option></select></label>
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
  <h2 class="font-semibold">Root hints dan pemeriksaan resolver</h2>
  <p class="mt-2 text-sm leading-6 text-slate-400">Jalankan pemeriksaan setelah menyimpan mode resolver. Pemeriksaan memvalidasi konfigurasi, status service, resolusi DNS, dan flag AD bila DNSSEC aktif.</p>
  <div class="mt-4 flex flex-wrap gap-3">
    <form data-api="resolver-verify" data-reload="false"><button class="btn-primary" type="submit"><i class="fa-solid fa-stethoscope"></i>Verifikasi DNS & resolver</button><p data-result role="status" class="mt-2 text-sm text-cyan-200"></p></form>
    <?php if ($resolverValues['root_hints'] === 'yes'): ?>
      <form data-api="root-hints-update" data-reload="false"><button class="btn-secondary" type="submit"><i class="fa-solid fa-rotate"></i>Perbarui root hints</button><p data-result role="status" class="mt-2 text-sm text-cyan-200"></p></form>
    <?php endif; ?>
  </div>
</section>
<section class="panel mt-5 p-5 sm:p-6">
  <h2 class="font-semibold">DNS sistem</h2>
  <p class="mt-2 text-sm text-slate-400">Alamat di sini dipakai Ubuntu untuk mencari domain. Gunakan satu alamat IPv4 atau IPv6 per baris, maksimal tiga.</p>
  <?php if ($dnsMode === 'ready'): ?>
    <form class="mt-4 space-y-3" data-api="system-dns-set" data-confirm="Terapkan daftar ini sebagai DNS sistem Ubuntu?">
      <label for="system-nameservers" class="block text-sm">IP DNS sistem</label>
      <textarea id="system-nameservers" name="nameservers" class="form-input" rows="3" maxlength="180" required><?= e($nameservers) ?></textarea>
      <button class="btn-primary" type="submit">Simpan DNS sistem</button>
      <p data-result role="status" class="text-sm text-cyan-200"></p>
    </form>
  <?php else: ?>
    <?php if ($nameservers !== ''): ?>
      <pre class="mt-3 rounded-xl bg-black/50 p-3 text-sm"><?= e($nameservers) ?></pre>
    <?php endif; ?>
    <p class="mt-3 text-sm text-slate-400"><?= e(match ($dnsMode) {
        'not-ready' => 'Selesaikan wizard Unbound untuk membuka pengaturan DNS sistem.',
        'read-only' => 'DNS sistem dikelola host atau bersifat read-only. Atur alamatnya dari Proxmox atau pengelola host.',
        default => 'Status DNS sistem belum dapat dibaca.',
    }) ?></p>
  <?php endif; ?>
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
