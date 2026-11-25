<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
require_admin();
page_start('Uji performa DNS',true);
?>
<section class="grid gap-5 xl:grid-cols-[.8fr_1.2fr]">
  <div class="panel p-5 sm:p-6"><h2 class="font-semibold">Pengaturan tes</h2><p class="mt-2 text-sm leading-6 text-slate-400">Tes ini memakai daftar dari menu Domain uji.</p>
    <p class="mt-3 text-sm text-slate-400">Gunakan IPv4 privat atau loopback. Jalankan satu tes dalam satu waktu.</p>
    <form class="mt-5 grid gap-4 sm:grid-cols-2" data-confirm="Mulai uji beban DNS?" data-stream="dnsperf" data-output="#dnsperf-output">
      <?php field('Target IPv4','target','127.0.0.1','text','Contoh 192.168.1.2'); ?>
      <?php field('Port','port','53','number'); ?>
      <?php field('Durasi (maksimal 300 detik)','duration','60','number'); ?>
      <?php field('Klien serentak (maks. 16)','clients','4','number'); ?>
      <?php field('Thread (maks. 4)','threads','4','number'); ?>
      <?php field('Batas QPS (maksimal 1.000)','qps','100','number'); ?>
      <button class="btn-primary sm:col-span-2 sm:w-fit" type="submit"><i class="fa-solid fa-play"></i>Mulai tes</button>
    </form>
  </div>
  <div class="panel p-5 sm:p-6"><div class="flex items-center justify-between"><div><h2 class="font-semibold">Hasil pengujian</h2></div><i class="fa-solid fa-terminal text-cyan-300"></i></div><pre id="dnsperf-output" class="mt-5 min-h-[28rem] max-h-[40rem] overflow-auto rounded-xl border border-slate-700 bg-black/60 p-4 font-mono text-xs leading-6 text-emerald-200">Hasil akan muncul setelah tes dimulai.</pre></div>
</section>
<?php page_end(); ?>

<?php // System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
