<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
require_admin();
page_start('Ringkasan server', true);
?>
<section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
  <?php
  card('Unbound','fa-server','Kelola layanan, periksa statistik, dan ubah konfigurasi Unbound.','/settings/unbound');
  card('Zabbix Agent','fa-chart-line','Atur konfigurasi agen dan ekspor statistik ke Zabbix.','/settings/zabbix');
  card('Firewall','fa-fire','Kelola UFW serta aturan trafik masuk dan keluar.','/settings/firewall');
  ?>
</section>
<?php page_end(); ?>

<?php // System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
