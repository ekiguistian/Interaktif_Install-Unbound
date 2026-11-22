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
<?php page_end(); ?>
