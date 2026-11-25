<?php
declare(strict_types=1);
$agentResult = manage(['zabbix-state'], null, 6);
$agent = $agentResult['code'] === 0 ? json_decode($agentResult['output'], true) : null;
if (!is_array($agent) || $agent['installed']) return;
$docs = 'https://www.zabbix.com/download?zabbix=7.0&os_distribution=ubuntu&os_version=22.04&components=agent_2';
$repositories = ['amd64' => 'ubuntu', 'arm64' => 'ubuntu-arm64'];
$repository = $repositories[$agent['architecture']] ?? null;
?>
<section class="panel p-5 mb-5">
  <h2 class="font-semibold">Zabbix Agent belum terpasang</h2>
  <?php if ($agent['jammy'] && $repository !== null): ?>
  <p class="mt-2 text-sm text-slate-400">Jalankan di terminal server untuk memasang Zabbix Agent 2 7.0 LTS di Ubuntu 22.04.</p>
  <pre class="command-block">curl -fLO 'https://repo.zabbix.com/zabbix/7.0/<?=e($repository)?>/pool/main/z/zabbix-release/zabbix-release_latest_7.0+ubuntu22.04_all.deb'
sudo dpkg -i 'zabbix-release_latest_7.0+ubuntu22.04_all.deb'
sudo apt update
sudo apt install -y zabbix-agent2
sudo systemctl enable --now zabbix-agent2
sudo systemctl restart php8.4-fpm</pre>
  <p class="text-sm text-slate-400">Restart PHP-FPM memberi panel akses ke direktori konfigurasi yang baru dibuat. Muat ulang halaman ini, lalu isi alamat server Zabbix lewat editor.</p>
  <?php else: ?>
  <p class="mt-2 text-sm text-slate-400">Pilih paket Zabbix Agent sesuai sistem operasi dan arsitektur server di panduan resmi.</p>
  <?php endif; ?>
  <a class="mt-3 inline-block text-cyan-300" href="<?=e($docs)?>">Panduan resmi Zabbix</a>
</section>

<?php // System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
