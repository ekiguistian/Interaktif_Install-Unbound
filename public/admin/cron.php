<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
require_admin();
page_start('Jadwal worker', true);
$result = manage(['cron-view']);
?>
<section class="panel p-5"><h2 class="font-semibold">Monitoring &amp; jadwal filter</h2>
<p class="mt-2 text-slate-400">Layanan monitoring mengambil sampel setiap detik. Cron filter berjalan pukul 03.15 saat blocklist atau whitelist aktif.</p>
<pre class="mt-4"><?=e($result['output'])?></pre></section>
<?php page_end(); ?>

<?php // System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
