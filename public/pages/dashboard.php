<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
page_start('Monitoring resolver');
?>
<section class="panel mb-6 overflow-hidden p-6 sm:p-8">
  <div class="grid items-center gap-8 lg:grid-cols-[1.35fr_.65fr]">
    <div>
      <span class="inline-flex items-center gap-2 rounded-full border border-cyan-400/20 bg-cyan-400/10 px-3 py-1 text-xs font-medium text-cyan-200"><span class="h-2 w-2 animate-pulse rounded-full bg-cyan-300"></span>Unbound DNS Operations</span>
      <h2 class="mt-5 max-w-2xl text-3xl font-semibold tracking-tight sm:text-4xl">Resolver berjalan, metrik terbaca.</h2>
      <p class="mt-4 max-w-2xl leading-7 text-slate-400">Pantau layanan DNS, cache, kueri, dan trafik interface server. Data metrik diperbarui oleh worker setiap menit.</p>
      <div class="mt-6 flex flex-wrap gap-3">
        <a id="main-action" href="/wizard" class="btn-primary"><i class="fa-solid fa-wand-magic-sparkles"></i><span id="main-action-text">Setup Unbound</span></a>
        <a href="/login" class="btn-secondary"><i class="fa-solid fa-lock"></i>Login admin</a>
      </div>
    </div>
    <div class="hidden justify-end lg:flex"><div class="grid h-44 w-44 place-items-center rounded-full border border-cyan-300/15 bg-cyan-300/[.04]"><div class="grid h-32 w-32 place-items-center rounded-full border border-cyan-300/25 bg-slate-950 text-5xl text-cyan-300 shadow-[0_0_60px_rgba(34,211,238,.12)]"><i class="fa-solid fa-network-wired"></i></div></div></div>
  </div>
</section>

<section data-dashboard>
  <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
    <?php foreach ([['unbound','Unbound DNS','fa-server'],['zabbix','Zabbix Agent','fa-chart-line']] as [$key,$label,$icon]): ?>
    <article class="panel kpi p-5"><div class="flex items-center justify-between"><span class="text-sm text-slate-400"><?= e($label) ?></span><i class="fa-solid <?= e($icon) ?> text-cyan-300"></i></div><p id="status-<?= e($key) ?>" class="mt-3 text-xl font-semibold text-slate-500">Memeriksa</p><p id="unbound-version" class="mt-1 text-xs text-slate-500"><?= $key === 'unbound' ? 'Versi belum tersedia' : 'Status layanan terakhir' ?></p></article>
    <?php endforeach; ?>
    <?php foreach ([['metric-queries','Total query','fa-arrow-right-arrow-left'],['metric-replies','Total jawaban','fa-reply'],['metric-cache','Cache hit ratio','fa-bolt'],['metric-qps','Query per second','fa-gauge-high'],['metric-recursion','Rata-rata waktu rekursi','fa-stopwatch'],['metric-rx','Interface RX','fa-download'],['metric-tx','Interface TX','fa-upload'],['metric-cpu','CPU','fa-microchip'],['metric-load','Load 1 / 5 / 15 menit','fa-chart-line'],['metric-memory','RAM terpakai / total','fa-memory'],['metric-disk','Disk terpakai / total','fa-hard-drive'],['metric-uptime','Uptime','fa-clock']] as [$id,$label,$icon]): ?>
    <article class="panel kpi p-5"><div class="flex items-center justify-between"><span class="text-sm text-slate-400"><?= e($label) ?></span><i class="fa-solid <?= e($icon) ?> text-cyan-300"></i></div><p id="<?= e($id) ?>" class="mt-4 text-2xl font-semibold tracking-tight">—</p><p class="mt-1 text-xs text-slate-500">Data worker Unbound</p></article>
    <?php endforeach; ?>
  </div>

  <div class="mt-5 grid gap-5 xl:grid-cols-3">
    <section class="panel p-5"><div class="mb-4 flex items-center justify-between"><div><h3 class="font-semibold">QPS</h3><p class="mt-1 text-xs text-slate-500">Riwayat satu jam terakhir</p></div><i class="fa-solid fa-wave-square text-cyan-300"></i></div><div class="h-64"><canvas id="qps-chart"></canvas></div></section>
    <section class="panel p-5"><div class="mb-4 flex items-center justify-between"><div><h3 class="font-semibold">Trafik interface</h3><p class="mt-1 text-xs text-slate-500">Kecepatan RX dan TX</p></div><i class="fa-solid fa-chart-area text-cyan-300"></i></div><div class="h-64"><canvas id="traffic-chart"></canvas></div></section>
    <section class="panel p-5"><div class="mb-4 flex items-center justify-between"><div><h3 class="font-semibold">Cache hit</h3><p class="mt-1 text-xs text-slate-500">Rasio jawaban dari cache per menit</p></div><i class="fa-solid fa-bolt text-amber-300"></i></div><div class="h-64"><canvas id="cache-chart"></canvas></div></section>
  </div>
  <div class="mt-5 grid gap-5 lg:grid-cols-2">
    <section class="panel p-5"><h3 class="font-semibold">Jenis kueri DNS</h3><p id="query-types" class="mt-3 text-sm leading-6 text-slate-400">Belum ada kueri</p></section>
    <section class="panel p-5"><h3 class="font-semibold">Kode respons DNS</h3><p id="response-codes" class="mt-3 text-sm leading-6 text-slate-400">Belum ada respons</p></section>
  </div>
  <p class="mt-4 text-right text-xs text-slate-500">Pembaruan terakhir: <span id="updated-at">Menghubungkan ke worker...</span></p>
</section>
<?php page_end(); ?>
