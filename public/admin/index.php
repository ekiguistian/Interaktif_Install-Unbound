<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
require_admin();
page_start('Ringkasan server', true);
?>
<section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
  <?php
  card('Unbound','fa-server','Konfigurasi resolver, log DNS, blocklist, whitelist, cron, dan uji DNS.','/settings/unbound');
  card('Zabbix Agent','fa-chart-line','Atur server monitoring, hostname, dan status service.','/settings/zabbix');
  card('Firewall','fa-fire','Atur status UFW dan kelola aturan Allow atau Deny untuk trafik IN dan OUT.','/settings/firewall');
  ?>
</section>
<section class="panel mt-5 p-5"><div class="flex items-start gap-3"><i class="fa-solid fa-circle-info mt-1 text-cyan-300"></i><p class="text-sm leading-6 text-slate-400">Perubahan service membutuhkan konfirmasi di halaman modul. Konfigurasi Unbound divalidasi dengan <code>unbound-checkconf</code> sebelum reload dijalankan.</p></div></section>
<section class="panel mt-5 p-5">
  <h2 class="font-semibold">Pembaruan Web Manager</h2>
  <p class="mt-2 text-sm text-slate-400">Periksa branch main di GitHub. Jika ada versi baru, aplikasi akan memasangnya dan memeriksa layanan.</p>
  <form class="mt-4" data-stream="app-update" data-output="#app-update-output" data-confirm="Periksa GitHub dan pasang pembaruan Web Manager jika tersedia?">
    <button class="btn-primary" type="submit"><i class="fa-solid fa-rotate" aria-hidden="true"></i>Check for Update</button>
  </form>
  <pre id="app-update-output" role="status" class="mt-4 max-h-64 overflow-auto rounded-xl border border-slate-700 bg-black/50 p-4 text-sm text-slate-300">Menunggu pemeriksaan.</pre>
</section>
<?php page_end(); ?>
