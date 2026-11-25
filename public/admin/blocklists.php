<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
require_once __DIR__ . '/../../app/filters.php';
require_admin();
$options = filter_options(db());
$summary = is_readable(FILTER_STATE . '/status.json') ? json_decode((string)file_get_contents(FILTER_STATE . '/status.json'), true) : null;
$sources=db()->query('SELECT id,name,url,is_active,domain_count,last_updated,last_error FROM blocklists ORDER BY id DESC')->fetchAll();
$whitelist=db()->query('SELECT id,domain,note,created_at FROM whitelists ORDER BY domain')->fetchAll();
$state = manage(['filters-state']);
$flags = $state['code'] === 0 ? json_decode($state['output'], true) : null;
page_start('Blocklist & whitelist',true);
?>
<section class="panel mb-5 p-5 sm:p-6">
  <h2 class="font-semibold">Autoupdate filter DNS</h2>
  <p class="mt-2 text-sm text-slate-400">Sebelum mengaktifkan filter, tambahkan sendiri baris include untuk filter yang dipakai di dalam bagian <code>server:</code> pada <code>/etc/unbound/unbound.conf</code>.</p>
  <pre class="mt-2 text-sm">    include: "/etc/unbound/blocklist/blocklist.conf"
    include: "/etc/unbound/blocklist/whitelist.conf"</pre>
  <p class="mt-2 text-sm text-slate-400">Migrasi dari versi lama: matikan kedua filter sebelum menambahkan include baru. Jika konfigurasi meng-include file lama <code>90-unbound-web-blocklist.conf</code> secara langsung, hapus baris lama tersebut terlebih dahulu.</p>
  <p class="mt-1 text-sm leading-6 text-slate-400">Sumber digabung, domain duplikat dibuang, lalu whitelist URL dan manual diterapkan. Domain yang diblokir diarahkan ke IPv4 di bawah.</p>
  <form class="mt-5 filter-options" data-api="filter-options-save">
    <label><span class="mb-2 block text-sm">URL whitelist otomatis</span><input class="form-input" name="whitelist_url" type="url" maxlength="2048" value="<?=e($options['whitelist_url'])?>" placeholder="https://yourdomain.com/whitelist.txt"><span class="mt-1 block text-xs text-slate-500">Kosongkan untuk memakai whitelist manual saja.</span></label>
    <label><span class="mb-2 block text-sm">IP redirect</span><input class="form-input" name="redirect_ip" value="<?=e($options['redirect_ip'])?>" required></label>
    <button class="btn-secondary" type="submit">Simpan pengaturan</button><p data-result class="text-sm text-cyan-200"></p>
  </form>
  <?php if (is_array($summary)): ?>
  <p class="mt-5 text-xs text-slate-400">Sinkronisasi berhasil terakhir: <?=e($summary['updated_at']??'—')?></p>
  <dl class="mt-3 filter-summary text-sm">
    <?php foreach (['raw'=>'Domain diproses','normalized'=>'Domain unik','whitelist'=>'Whitelist gabungan','removed'=>'Dikecualikan','final'=>'Domain diblokir'] as $key=>$label): ?>
    <div class="rounded-xl border border-slate-800 p-3"><dt class="text-slate-400"><?=e($label)?></dt><dd class="mt-1 text-lg font-semibold"><?=number_format((int)($summary[$key]??0),0,',','.')?></dd></div>
    <?php endforeach; ?>
  </dl>
  <?php endif; ?>
</section>
<div class="grid gap-5 xl:grid-cols-2">
  <section class="panel p-5 sm:p-6">
    <h2 class="font-semibold">Sumber blocklist</h2><p class="mt-1 text-sm leading-6 text-slate-400">Tambahkan URL HTTPS publik. Ekstensi file bebas selama isinya daftar domain, hosts, URL, Adblock <code>||domain^</code>, CSV, atau JSON.</p>
    <form class="mt-5 grid gap-3 sm:grid-cols-2" data-api="blocklist-add"><input class="form-input" name="name" maxlength="120" placeholder="Nama sumber" required><input class="form-input sm:col-span-2" name="url" type="url" placeholder="https://yourdomain.com/blocklist.txt" required><button class="btn-primary sm:col-span-2 sm:w-fit" type="submit"><i class="fa-solid fa-plus"></i>Tambah sumber</button><p data-result class="text-sm text-cyan-200 sm:col-span-2"></p></form>
    <div class="mt-5 overflow-x-auto"><table class="w-full min-w-[42rem] text-left text-sm"><thead class="text-xs uppercase text-slate-500"><tr><th class="py-3">Sumber</th><th>Domain</th><th>Terakhir update</th><th></th></tr></thead><tbody class="divide-y divide-slate-800">
      <?php foreach($sources as $source): ?><tr><td class="py-3 pr-3"><form class="min-w-72 space-y-2" data-api="blocklist-update"><input type="hidden" name="id" value="<?=e($source['id'])?>"><input class="form-input" name="name" maxlength="120" value="<?=e($source['name'])?>" required><input class="form-input" name="url" type="url" value="<?=e($source['url'])?>" required><select class="form-select" name="is_active"><option value="1" <?=$source['is_active']?'selected':''?>>Aktif</option><option value="0" <?=!$source['is_active']?'selected':''?>>Nonaktif</option></select><button class="btn-secondary" type="submit"><i class="fa-solid fa-floppy-disk"></i>Simpan sumber</button><p data-result class="text-xs text-cyan-200"></p></form><?php if($source['last_error']): ?><div class="mt-1 text-xs text-rose-300"><?=e($source['last_error'])?></div><?php endif; ?></td><td><?=number_format((int)$source['domain_count'],0,',','.')?></td><td class="text-xs text-slate-400"><?=e($source['last_updated']??'Belum pernah')?></td><td><form data-api="blocklist-delete"><input type="hidden" name="id" value="<?=e($source['id'])?>"><button class="btn-danger" type="submit"><i class="fa-solid fa-trash"></i>Hapus</button></form></td></tr><?php endforeach; ?>
      <?php if(!$sources): ?><tr><td colspan="4" class="py-6 text-center text-slate-500">Belum ada sumber blocklist.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>

  <section class="panel p-5 sm:p-6">
    <h2 class="font-semibold">Whitelist manual</h2><p class="mt-1 text-sm leading-6 text-slate-400">Tetap tersimpan saat whitelist URL diperbarui. Keduanya digabung untuk mengecualikan domain beserta subdomainnya dari blocklist.</p>
    <form class="mt-5 grid gap-3 sm:grid-cols-2" data-api="whitelist-add"><input class="form-input" name="domain" placeholder="yourdomain.com" required><input class="form-input" name="note" maxlength="255" placeholder="Catatan (opsional)"><button class="btn-primary sm:w-fit" type="submit"><i class="fa-solid fa-plus"></i>Tambah domain</button><p data-result class="text-sm text-cyan-200 sm:col-span-2"></p></form>
    <div class="mt-5 space-y-2">
      <?php foreach($whitelist as $item): ?><div class="rounded-xl border border-slate-800 bg-slate-900/60 p-3"><form class="flex flex-wrap items-end gap-2" data-api="whitelist-update"><input type="hidden" name="id" value="<?=e($item['id'])?>"><label class="min-w-44 flex-1"><span class="mb-1 block text-xs text-slate-500">Domain</span><input class="form-input" name="domain" value="<?=e($item['domain'])?>" required></label><label class="min-w-36 flex-1"><span class="mb-1 block text-xs text-slate-500">Catatan</span><input class="form-input" name="note" value="<?=e($item['note'])?>"></label><button class="btn-secondary" type="submit"><i class="fa-solid fa-floppy-disk"></i>Simpan</button></form><form class="mt-2" data-api="whitelist-delete"><input type="hidden" name="id" value="<?=e($item['id'])?>"><button class="btn-danger" type="submit"><i class="fa-solid fa-trash"></i>Hapus</button><span data-result class="ml-2 text-xs text-cyan-200"></span></form></div><?php endforeach; ?>
      <?php if(!$whitelist): ?><p class="py-5 text-center text-sm text-slate-500">Belum ada domain whitelist.</p><?php endif; ?>
    </div>
  </section>
</div>
<section class="panel mt-5 p-5 sm:p-6">
  <div class="grid gap-5 lg:grid-cols-[1fr_auto] lg:items-center"><div><h2 class="font-semibold">Sinkronisasi & jadwal otomatis</h2><p class="mt-1 text-sm leading-6 text-slate-400">Update otomatis pukul 03.15 waktu server selama filter aktif. Hasil divalidasi sebelum Unbound di-reload; jika gagal, filter sebelumnya tetap digunakan.</p></div>
    <form data-stream="blocklist-sync" data-output="#blocklist-output"><button class="btn-primary" type="submit"><i class="fa-solid fa-cloud-arrow-down"></i>Update & Apply Now</button></form>
  </div>
  <pre id="blocklist-output" class="mt-4 max-h-72 overflow-auto rounded-xl border border-slate-700 bg-black/50 p-4 font-mono text-xs leading-6 text-emerald-200">Siap menjalankan sinkronisasi.</pre>
  <?php if ($flags === null) { notice($state['output'], 'error'); } else { ?>
  <form class="mt-5 flex flex-wrap items-center gap-4" data-api="filters-save">
    <label><input type="checkbox" name="block" <?= $flags['block'] ? 'checked' : '' ?>> Aktifkan blocklist</label>
    <label><input type="checkbox" name="white" <?= $flags['white'] ? 'checked' : '' ?>> Aktifkan whitelist</label>
    <button class="btn-secondary" type="submit">Terapkan &amp; perbarui cron</button><p data-result role="status"></p>
  </form><?php } ?>
</section>
<?php page_end(); ?>

<?php // System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
