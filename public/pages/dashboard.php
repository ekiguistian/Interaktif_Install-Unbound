<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
require_admin();
page_start('Pemantauan Unbound', true, 'dashboard');
$groups = [
    'Statistik DNS' => ['qps'=>'Laju kueri', 'cache_hit_ratio'=>'Rasio cache hit', 'recursion_avg_ms'=>'Latensi rekursi'],
    'Sumber daya server' => ['cpu_percent'=>'CPU', 'memory_used_mb'=>'Memori terpakai', 'disk_used_gb'=>'Ruang disk terpakai'],
    'Jaringan' => ['network'=>'Trafik jaringan'],
];
?>
<div class="mb-4 flex justify-end">
  <a class="btn-secondary" href="https://app.midtrans.com/payment-links/b45ec2c9-80f7-4dd8-b2e9-e18d430d192e-7l5NTR4t"
    target="_blank" rel="noopener noreferrer">Support me</a>
</div>
<div data-dashboard>
  <section class="panel host-strip"><div><strong id="host">Memuat informasi server…</strong><p id="system-info"></p></div>
    <div><span>Unbound</span><strong id="service">—</strong></div>
    <div><span>Sampel terakhir · interval 1 detik</span><strong id="sample" role="status">Menunggu data</strong></div>
  </section>
  <?php foreach ($groups as $group => $charts): ?>
  <section class="monitor-section" aria-label="<?=e($group)?>">
  <h2 class="monitor-section-title"><?=e($group)?></h2>
  <div class="monitor-grid">
  <?php foreach ($charts as $key=>$label): ?>
    <section class="panel telemetry <?= $key === 'network' ? 'wide-chart' : '' ?>">
      <div class="chart-heading"><h3><?=e($label)?></h3><?php if ($key === 'network'): ?>
        <select class="form-select" id="interface" aria-label="Interface jaringan"></select><?php endif; ?></div>
      <strong id="value-<?=e($key)?>">—</strong>
      <div class="plot"><canvas id="chart-<?=e($key)?>" aria-label="<?=e($label)?> selama 10 menit terakhir" role="img"></canvas></div>
    </section>
  <?php endforeach; ?></div>
  </section>
  <?php endforeach; ?>
  <section class="panel p-5 mt-5"><h2 class="font-semibold">Statistik server</h2><dl class="detail-grid">
  <?php foreach (['queries'=>'Total kueri','replies'=>'Total jawaban','load'=>'Beban rata-rata (1 / 5 / 15 menit)','uptime'=>'Waktu aktif Linux','types'=>'Tipe kueri','codes'=>'Kode respons'] as $id=>$label): ?>
    <div><dt><?=e($label)?></dt><dd id="detail-<?=e($id)?>">—</dd></div>
  <?php endforeach; ?></dl></section>
</div>
<?php page_end(); ?>

<?php // System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
