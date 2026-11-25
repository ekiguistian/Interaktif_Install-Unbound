<?php
declare(strict_types=1);

$metricsResult = manage(['zabbix-metrics-state'], null, 8);
$zabbixMetrics = $metricsResult['code'] === 0 ? json_decode($metricsResult['output'], true) : null;
?>
<section class="panel p-5 mb-5 space-y-4">
  <h2 class="font-semibold">Statistik Unbound untuk Zabbix</h2>
  <?php if (!is_array($zabbixMetrics)): ?>
    <p class="text-rose-300">Status ekspor metrik belum bisa dibaca.</p>
  <?php else: ?>
    <p class="text-sm text-slate-400">Item <code>unbound.web.metrics</code> menyediakan total query, total reply, QPS, dan metrik Linux.</p>
    <div class="flex flex-wrap items-center gap-3">
      <form data-api="<?=$zabbixMetrics['enabled'] ? 'zabbix-metrics-disable' : 'zabbix-metrics-enable'?>" data-output="#zabbix-metrics-result">
        <button class="btn-secondary" type="submit"><?=$zabbixMetrics['enabled'] ? 'Nonaktifkan statistik Unbound' : 'Aktifkan statistik Unbound'?></button>
      </form>
      <span class="state-label" data-active="<?=$zabbixMetrics['enabled'] ? 'true' : 'false'?>"><?=$zabbixMetrics['enabled'] ? 'Aktif' : 'Nonaktif'?></span>
    </div>
    <p id="zabbix-metrics-result" role="status"></p>
  <?php endif; ?>
  <a class="inline-block text-cyan-300" href="https://github.com/ekiguistian/Unbound_DNS_Web-Manager/blob/main/docs/zabbix.md">Panduan item dan grafik Zabbix</a>
</section>
<?php // System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
