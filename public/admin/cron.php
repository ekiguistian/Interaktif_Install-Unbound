<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
require_admin();
page_start('Jadwal worker', true);
$result = manage(['cron-view']);
?>
<section class="panel p-5"><h2 class="font-semibold">Cron aktif milik panel</h2>
<p class="mt-2 text-slate-400">Worker mengambil sampel setiap menit. Sinkronisasi harian mengikuti sakelar di menu Blocklist &amp; Whitelist.</p>
<pre class="mt-4"><?=e($result['output'])?></pre></section>
<?php page_end(); ?>
