<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
require_admin();
page_start('Jadwal otomatis', true);
$result = manage(['cron-view']);
?>
<section class="panel p-5"><h2 class="font-semibold">Pemantauan dan filter DNS</h2>
<p class="mt-2 text-slate-400">Panel mengambil sampel setiap detik. Filter aktif disinkronkan setiap hari pukul 03.15 waktu server.</p>
<pre class="mt-4"><?=e($result['output'])?></pre></section>
<?php page_end(); ?>

<?php // System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
