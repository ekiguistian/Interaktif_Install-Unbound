<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
require_admin();
page_start('Monitoring Unbound', true);
$charts = [
    'qps'=>'Laju kueri', 'cache_hit_ratio'=>'Cache hit', 'recursion_avg_ms'=>'Latensi rekursi',
    'cpu_percent'=>'CPU', 'memory_used_mb'=>'Memori terpakai', 'disk_used_gb'=>'Disk terpakai',
    'network'=>'Trafik jaringan',
];
?>
<div data-dashboard>
  <section class="panel host-strip"><div><strong id="host">Memuat server…</strong><p id="system-info"></p></div>
    <div><span>Unbound</span><strong id="service">—</strong></div>
    <div><span>Sampel terakhir · interval 1 menit</span><strong id="sample" role="status">Menunggu worker</strong></div>
  </section>
  <div class="monitor-grid">
  <?php foreach ($charts as $key=>$label): ?>
    <section class="panel telemetry <?= $key === 'network' ? 'wide-chart' : '' ?>">
      <div class="chart-heading"><h2><?=e($label)?></h2><?php if ($key === 'network'): ?>
        <select class="form-select" id="interface" aria-label="Interface jaringan"></select><?php endif; ?></div>
      <strong id="value-<?=e($key)?>">—</strong>
      <div class="plot"><canvas id="chart-<?=e($key)?>" aria-label="<?=e($label)?> selama satu jam" role="img"></canvas></div>
    </section>
  <?php endforeach; ?></div>
  <section class="panel p-5 mt-5"><h2 class="font-semibold">Statistik server</h2><dl class="detail-grid">
  <?php foreach (['queries'=>'Total kueri','replies'=>'Total jawaban','load'=>'Load 1 / 5 / 15 menit','uptime'=>'Uptime Linux','types'=>'Tipe kueri','codes'=>'Kode respons'] as $id=>$label): ?>
    <div><dt><?=e($label)?></dt><dd id="detail-<?=e($id)?>">—</dd></div>
  <?php endforeach; ?></dl></section>
</div>
<?php page_end(); ?>
