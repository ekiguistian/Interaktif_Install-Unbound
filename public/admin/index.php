<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
require_admin();
page_start('Ringkasan server', true);
?>
<section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
  <?php
  card('Konfigurasi DNS','fa-sliders','Atur interface, access-control, cache, DNSSEC dan lihat unbound.conf aktif.','/settings/unbound');
  card('Blocklist & whitelist','fa-shield-halved','Kelola sumber blokir, domain pengecualian dan jadwal sinkronisasi.','/settings/blocklists');
  card('Zabbix Agent','fa-chart-line','Pasang Agent, isi server monitoring, atau kendalikan service.','/settings/zabbix');
  card('Firewall','fa-fire','Lihat dan kelola aturan UFW incoming yang aktif.','/settings/firewall');
  card('Jadwal sistem','fa-clock','Atur jadwal worker metrik dan pembaruan blocklist.','/settings/cron');
  card('Uji dnsperf','fa-gauge-high','Jalankan benchmark DNS lokal dan lihat output langsung.','/settings/dnsperf');
  card('Domain pengujian','fa-list','Kelola daftar domain dan tipe query untuk dnsperf.','/settings/domains');
  ?>
</section>
<section class="panel mt-5 p-5"><div class="flex items-start gap-3"><i class="fa-solid fa-circle-info mt-1 text-cyan-300"></i><p class="text-sm leading-6 text-slate-400">Perubahan service membutuhkan konfirmasi di halaman modul. Konfigurasi Unbound divalidasi dengan <code>unbound-checkconf</code> sebelum reload dijalankan.</p></div></section>
<?php page_end(); ?>
