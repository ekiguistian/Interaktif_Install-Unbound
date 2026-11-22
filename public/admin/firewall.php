<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
require_admin();
$result=CommandRunner::run(['/usr/bin/sudo','-n','/usr/local/sbin/unbound-web-manage','firewall-status']);
page_start('Firewall UFW',true);
?>
<section class="grid gap-5 xl:grid-cols-[1fr_1fr]">
  <div class="panel p-5 sm:p-6"><h2 class="font-semibold">Aturan incoming</h2><p class="mt-1 text-sm leading-6 text-slate-400">Aturan baru menggunakan protokol TCP atau UDP dan dapat dibatasi ke sumber IP/CIDR. UFW tidak diaktifkan otomatis oleh installer.</p>
    <form class="mt-5 grid gap-4 sm:grid-cols-2" data-api="firewall-add">
      <label><span class="mb-2 block text-sm">Aksi</span><select class="form-select" name="rule_action"><option value="allow">Allow</option><option value="deny">Deny</option></select></label>
      <label><span class="mb-2 block text-sm">Protokol</span><select class="form-select" name="protocol"><option value="udp">UDP</option><option value="tcp">TCP</option></select></label>
      <?php field('Port','port','53','number'); ?>
      <?php field('Sumber IP/CIDR','source','','text','Kosong berarti sumber mana saja.',false); ?>
      <button class="btn-primary sm:col-span-2 sm:w-fit" type="submit"><i class="fa-solid fa-plus"></i>Tambah aturan</button><p data-result class="text-sm text-cyan-200 sm:col-span-2"></p>
    </form>
    <form class="mt-5 flex flex-wrap items-end gap-3" data-api="firewall-delete"><label class="block"><span class="mb-2 block text-sm">Nomor rule aktif</span><input class="form-input w-40" type="number" min="1" name="number" required></label><button class="btn-danger" type="submit"><i class="fa-solid fa-trash"></i>Hapus rule</button><p data-result class="text-sm text-cyan-200"></p></form>
    <form class="mt-5" data-api="firewall-enable"><input type="hidden" name="confirm" value="ENABLE"><button class="btn-secondary" type="submit" onclick="return confirm('Mengaktifkan UFW dapat memutus koneksi SSH jika aturan SSH belum dibuat. Lanjutkan?')"><i class="fa-solid fa-fire"></i>Aktifkan UFW</button><p data-result class="mt-2 text-sm text-cyan-200"></p></form>
  </div>
  <div class="panel p-5 sm:p-6"><div class="flex items-center justify-between"><div><h2 class="font-semibold">Rule UFW aktif</h2><p class="mt-1 text-sm text-slate-400">Nomor rule dapat digunakan pada form hapus.</p></div><form data-api="firewall-status" data-reload="false"><button class="btn-secondary" type="submit"><i class="fa-solid fa-rotate"></i>Muat ulang</button></form></div><pre data-page-result class="mt-5 max-h-[34rem] min-h-64 overflow-auto rounded-xl border border-slate-700 bg-black/50 p-4 font-mono text-xs leading-6 text-emerald-200"><?=e($result['output']?:'Tidak dapat membaca status UFW.')?></pre></div>
</section>
<?php page_end(); ?>
