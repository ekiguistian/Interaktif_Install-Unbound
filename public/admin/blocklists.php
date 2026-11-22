<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
require_admin();
$sources=db()->query('SELECT id,name,url,is_active,domain_count,last_updated,last_error FROM blocklists ORDER BY id DESC')->fetchAll();
$whitelist=db()->query('SELECT id,domain,note,created_at FROM whitelists ORDER BY domain')->fetchAll();
$schedule=setting('blocklist_schedule','daily');
page_start('Blocklist & whitelist',true);
?>
<div class="grid gap-5 xl:grid-cols-2">
  <section class="panel p-5 sm:p-6">
    <h2 class="font-semibold">Sumber blocklist</h2><p class="mt-1 text-sm leading-6 text-slate-400">URL HTTPS publik. Format Hosts, Adblock <code>||domain^</code>, dan domain biasa diproses otomatis.</p>
    <form class="mt-5 grid gap-3 sm:grid-cols-2" data-api="blocklist-add"><input class="form-input" name="name" maxlength="120" placeholder="Nama sumber" required><input class="form-input sm:col-span-2" name="url" type="url" placeholder="https://yourdomain.com/blocklist.txt" required><button class="btn-primary sm:col-span-2 sm:w-fit" type="submit"><i class="fa-solid fa-plus"></i>Tambah sumber</button><p data-result class="text-sm text-cyan-200 sm:col-span-2"></p></form>
    <div class="mt-5 overflow-x-auto"><table class="w-full min-w-[42rem] text-left text-sm"><thead class="text-xs uppercase text-slate-500"><tr><th class="py-3">Sumber</th><th>Domain</th><th>Terakhir update</th><th></th></tr></thead><tbody class="divide-y divide-slate-800">
      <?php foreach($sources as $source): ?><tr><td class="py-3 pr-3"><form class="min-w-72 space-y-2" data-api="blocklist-update"><input type="hidden" name="id" value="<?=e($source['id'])?>"><input class="form-input" name="name" maxlength="120" value="<?=e($source['name'])?>" required><input class="form-input" name="url" type="url" value="<?=e($source['url'])?>" required><select class="form-select" name="is_active"><option value="1" <?=$source['is_active']?'selected':''?>>Aktif</option><option value="0" <?=!$source['is_active']?'selected':''?>>Nonaktif</option></select><button class="btn-secondary" type="submit"><i class="fa-solid fa-floppy-disk"></i>Simpan sumber</button><p data-result class="text-xs text-cyan-200"></p></form><?php if($source['last_error']): ?><div class="mt-1 text-xs text-rose-300"><?=e($source['last_error'])?></div><?php endif; ?></td><td><?=number_format((int)$source['domain_count'],0,',','.')?></td><td class="text-xs text-slate-400"><?=e($source['last_updated']??'Belum pernah')?></td><td><form data-api="blocklist-delete"><input type="hidden" name="id" value="<?=e($source['id'])?>"><button class="btn-danger" type="submit"><i class="fa-solid fa-trash"></i>Hapus</button></form></td></tr><?php endforeach; ?>
      <?php if(!$sources): ?><tr><td colspan="4" class="py-6 text-center text-slate-500">Belum ada sumber blocklist.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>

  <section class="panel p-5 sm:p-6">
    <h2 class="font-semibold">Whitelist</h2><p class="mt-1 text-sm leading-6 text-slate-400">Domain whitelist mengecualikan domain tersebut beserta subdomainnya dari hasil compile blocklist.</p>
    <form class="mt-5 grid gap-3 sm:grid-cols-2" data-api="whitelist-add"><input class="form-input" name="domain" placeholder="yourdomain.com" required><input class="form-input" name="note" maxlength="255" placeholder="Catatan (opsional)"><button class="btn-primary sm:w-fit" type="submit"><i class="fa-solid fa-plus"></i>Tambah domain</button><p data-result class="text-sm text-cyan-200 sm:col-span-2"></p></form>
    <div class="mt-5 space-y-2">
      <?php foreach($whitelist as $item): ?><div class="rounded-xl border border-slate-800 bg-slate-900/60 p-3"><form class="flex flex-wrap items-end gap-2" data-api="whitelist-update"><input type="hidden" name="id" value="<?=e($item['id'])?>"><label class="min-w-44 flex-1"><span class="mb-1 block text-xs text-slate-500">Domain</span><input class="form-input" name="domain" value="<?=e($item['domain'])?>" required></label><label class="min-w-36 flex-1"><span class="mb-1 block text-xs text-slate-500">Catatan</span><input class="form-input" name="note" value="<?=e($item['note'])?>"></label><button class="btn-secondary" type="submit"><i class="fa-solid fa-floppy-disk"></i>Simpan</button></form><form class="mt-2" data-api="whitelist-delete"><input type="hidden" name="id" value="<?=e($item['id'])?>"><button class="btn-danger" type="submit"><i class="fa-solid fa-trash"></i>Hapus</button><span data-result class="ml-2 text-xs text-cyan-200"></span></form></div><?php endforeach; ?>
      <?php if(!$whitelist): ?><p class="py-5 text-center text-sm text-slate-500">Belum ada domain whitelist.</p><?php endif; ?>
    </div>
  </section>
</div>
<section class="panel mt-5 p-5 sm:p-6">
  <div class="grid gap-5 lg:grid-cols-[1fr_auto] lg:items-center"><div><h2 class="font-semibold">Sinkronisasi & jadwal otomatis</h2><p class="mt-1 text-sm leading-6 text-slate-400">Unduh sumber, filter whitelist, validasi konfigurasi, lalu reload Unbound. Hasil langkah tampil langsung di terminal web.</p></div>
    <form data-stream="blocklist-sync" data-output="#blocklist-output"><button class="btn-primary" type="submit"><i class="fa-solid fa-cloud-arrow-down"></i>Update & Apply Now</button></form>
  </div>
  <pre id="blocklist-output" class="mt-4 max-h-72 overflow-auto rounded-xl border border-slate-700 bg-black/50 p-4 font-mono text-xs leading-6 text-emerald-200">Siap menjalankan sinkronisasi.</pre>
  <form class="mt-5 flex flex-wrap items-end gap-3" data-api="schedule-save"><label class="block min-w-56"><span class="mb-2 block text-sm">Jadwal blocklist</span><select class="form-select" name="schedule"><option value="disabled" <?=$schedule==='disabled'?'selected':''?>>Nonaktif</option><option value="daily" <?=$schedule==='daily'?'selected':''?>>Harian</option><option value="weekly" <?=$schedule==='weekly'?'selected':''?>>Mingguan</option><option value="12h" <?=$schedule==='12h'?'selected':''?>>Setiap 12 jam</option><option value="6h" <?=$schedule==='6h'?'selected':''?>>Setiap 6 jam</option></select></label><button class="btn-secondary" type="submit"><i class="fa-solid fa-calendar-check"></i>Simpan jadwal</button><p data-result class="text-sm text-cyan-200"></p></form>
</section>
<?php page_end(); ?>
