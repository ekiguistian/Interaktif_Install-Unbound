<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
require_admin();
page_start('Uji performa dnsperf',true);
?>
<section class="grid gap-5 xl:grid-cols-[.8fr_1.2fr]">
  <div class="panel p-5 sm:p-6"><h2 class="font-semibold">Parameter pengujian</h2><p class="mt-2 text-sm leading-6 text-slate-400">Benchmark memakai domain dari menu Domain Pengujian. Output dnsperf dialirkan langsung ke layar.</p>
    <div class="mt-4 rounded-xl border border-amber-400/25 bg-amber-400/10 p-4 text-sm leading-6 text-amber-100"><i class="fa-solid fa-triangle-exclamation mr-2"></i>Tes tanpa batas QPS dapat memenuhi kapasitas CPU dan membuat jawaban DNS melambat. Jalankan saat beban rendah. Target hanya boleh berupa IP loopback atau private.</div>
    <form class="mt-5 grid gap-4 sm:grid-cols-2" data-stream="dnsperf" data-output="#dnsperf-output">
      <?php field('Target IPv4','target','127.0.0.1','text','Contoh: 192.168.1.2'); ?>
      <?php field('Port','port','53','number'); ?>
      <?php field('Durasi (maksimal 300 detik)','duration','60','number'); ?>
      <?php field('Concurrent clients','clients','4','number'); ?>
      <?php field('Pasangan thread','threads','4','number'); ?>
      <?php field('Batas QPS (0 = tanpa batas)','qps','0','number'); ?>
      <button class="btn-primary sm:col-span-2 sm:w-fit" type="submit" onclick="return confirm('Benchmark akan menghasilkan beban DNS selama durasi yang dipilih. Mulai pengujian?')"><i class="fa-solid fa-play"></i>Jalankan dnsperf</button>
    </form>
  </div>
  <div class="panel p-5 sm:p-6"><div class="flex items-center justify-between"><div><h2 class="font-semibold">Output pengujian</h2><p class="mt-1 text-sm text-slate-400">Hasil lengkap tampil per baris.</p></div><i class="fa-solid fa-terminal text-cyan-300"></i></div><pre id="dnsperf-output" class="mt-5 min-h-[28rem] max-h-[40rem] overflow-auto rounded-xl border border-slate-700 bg-black/60 p-4 font-mono text-xs leading-6 text-emerald-200">Siap menunggu pengujian.</pre></div>
</section>
<?php page_end(); ?>
