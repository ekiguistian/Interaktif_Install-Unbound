<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
require_admin();
$schedule=setting('blocklist_schedule','daily');
$cron=CommandRunner::run(['/usr/bin/sudo','-n','/usr/local/sbin/unbound-web-manage','cron-view']);
$custom=CommandRunner::run(['/usr/bin/sudo','-n','/usr/local/sbin/unbound-web-manage','cron-custom-list']);
$jobs=[];
foreach (preg_split('/\R/',trim($custom['output'])) as $line) {
    $parts=explode('|',$line);
    if (count($parts)===7 && preg_match('/^[A-Za-z0-9_-]{1,40}$/',$parts[0])) $jobs[]=$parts;
}
page_start('Jadwal sistem',true);
?>
<section class="grid gap-5 xl:grid-cols-[.8fr_1.2fr]">
  <div class="panel p-5 sm:p-6"><h2 class="font-semibold">Pembaruan blocklist terjadwal</h2><p class="mt-2 text-sm leading-6 text-slate-400">Worker metrik tetap berjalan setiap menit. Jadwal sinkronisasi blocklist dikelola terpisah.</p>
    <form class="mt-5 space-y-4" data-api="schedule-save"><label class="block"><span class="mb-2 block text-sm">Pembaruan blocklist</span><select class="form-select" name="schedule"><option value="disabled" <?=$schedule==='disabled'?'selected':''?>>Nonaktif</option><option value="daily" <?=$schedule==='daily'?'selected':''?>>Setiap hari</option><option value="weekly" <?=$schedule==='weekly'?'selected':''?>>Setiap minggu</option><option value="12h" <?=$schedule==='12h'?'selected':''?>>Setiap 12 jam</option><option value="6h" <?=$schedule==='6h'?'selected':''?>>Setiap 6 jam</option></select></label><button class="btn-primary" type="submit"><i class="fa-solid fa-calendar-check"></i>Simpan jadwal</button><p data-result class="text-sm text-cyan-200"></p></form>
  </div>
  <div class="panel p-5 sm:p-6"><h2 class="font-semibold">Tambah atau ubah jadwal</h2><p class="mt-2 text-sm leading-6 text-slate-400">Tugas dibatasi pada sinkronisasi blocklist dan pembaruan root hints. Isi lima kolom waktu cron.</p>
    <form class="mt-5 grid gap-3 sm:grid-cols-5" data-api="cron-custom-save">
      <label class="sm:col-span-5"><span class="mb-1 block text-xs text-slate-500">ID jadwal</span><input class="form-input" name="id" placeholder="root-hints-weekly" pattern="[A-Za-z0-9_-]{1,40}" required></label>
      <?php foreach ([['minute','Menit','0'],['hour','Jam','3'],['day','Hari','*'],['month','Bulan','*'],['weekday','Hari minggu','0']] as [$key,$label,$value]): ?><label><span class="mb-1 block text-xs text-slate-500"><?=e($label)?></span><input class="form-input" name="<?=e($key)?>" value="<?=e($value)?>" required></label><?php endforeach; ?>
      <label class="sm:col-span-3"><span class="mb-1 block text-xs text-slate-500">Tugas</span><select class="form-select" name="task"><option value="update-root-hints">Perbarui root hints</option><option value="update-blocklists">Sinkronisasi blocklist</option></select></label>
      <button class="btn-primary self-end sm:col-span-2" type="submit"><i class="fa-solid fa-floppy-disk"></i>Simpan jadwal</button><p data-result class="text-sm text-cyan-200 sm:col-span-5"></p>
    </form>
    <div class="mt-5 space-y-3">
      <?php foreach($jobs as [$id,$minute,$hour,$day,$month,$weekday,$task]): ?>
      <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-800 bg-slate-900/60 p-3"><div><p class="font-medium"><?=e($id)?></p><p class="font-mono text-xs text-slate-400"><?=e(implode(' ',[$minute,$hour,$day,$month,$weekday]))?> · <?=e($task)?></p></div><form data-api="cron-custom-delete"><input type="hidden" name="id" value="<?=e($id)?>"><button class="btn-danger" type="submit"><i class="fa-solid fa-trash"></i>Hapus</button></form></div>
      <?php endforeach; ?>
      <?php if(!$jobs): ?><p class="py-4 text-sm text-slate-500">Belum ada jadwal tambahan.</p><?php endif; ?>
    </div>
  </div>
</section>
<section class="panel mt-5 p-5 sm:p-6"><div class="flex items-center justify-between gap-3"><div><h2 class="font-semibold">Crontab aplikasi</h2><p class="mt-1 text-sm text-slate-400">Job worker, sinkronisasi blocklist, dan jadwal custom.</p></div><form data-api="cron-view" data-reload="false"><button class="btn-secondary" type="submit"><i class="fa-solid fa-rotate"></i>Muat ulang</button></form></div><pre data-page-result class="mt-5 min-h-52 overflow-auto rounded-xl border border-slate-700 bg-black/50 p-4 font-mono text-xs leading-6 text-emerald-200"><?=e($cron['output']?:'File cron belum tersedia.')?></pre></section>
<?php page_end(); ?>
