<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/layout.php';
page_start('Wizard instalasi Unbound');
$loggedIn = admin_logged_in();
?>
<section class="mx-auto max-w-4xl">
  <div class="panel p-6 sm:p-8">
    <div class="flex flex-wrap items-start justify-between gap-5"><div><span class="text-xs font-semibold uppercase tracking-[.2em] text-cyan-300">Setup Wizard</span><h2 class="mt-2 text-xl font-semibold">Siapkan resolver secara bertahap</h2><p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">Setiap langkah menampilkan output dari server. Konfigurasi dasar hanya menerima query dari localhost sampai subnet diizinkan dari Settings.</p></div><span class="rounded-lg border border-amber-400/25 bg-amber-400/10 px-3 py-2 text-xs text-amber-200">Akses admin</span></div>
    <?php if (!$loggedIn): ?>
      <div class="mt-6 rounded-xl border border-amber-400/25 bg-amber-400/10 p-4 text-sm text-amber-100">Login diperlukan sebelum source Unbound dikompilasi atau konfigurasi DNS diubah. <a class="font-semibold underline" href="/login?next=%2Fwizard">Login admin</a></div>
    <?php else: ?>
      <ol id="wizard-steps" class="mt-7 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <?php foreach (['Cek sistem dan DNS','Unduh source resmi','Kompilasi & install','Setup root hints, DNSSEC & service','Verifikasi DNS dan resolver'] as $index => $label): ?>
        <li class="rounded-xl border border-slate-700 bg-slate-900/70 p-3 text-sm"><span class="mb-2 grid h-7 w-7 place-items-center rounded-full bg-slate-800 text-cyan-200"><?= $index + 1 ?></span><?= e($label) ?></li>
        <?php endforeach; ?>
      </ol>
      <form class="mt-6" data-stream="wizard" data-output="#wizard-output">
        <input type="hidden" name="step" value="1" data-wizard-step>
        <button class="btn-primary" type="submit"><i class="fa-solid fa-play"></i><span data-wizard-button>Jalankan tahap 1</span></button>
      </form>
      <pre id="wizard-output" class="mt-5 min-h-48 max-h-[28rem] overflow-auto rounded-xl border border-slate-700 bg-black/50 p-4 font-mono text-xs leading-6 text-emerald-200">Menunggu perintah admin…</pre>
    <?php endif; ?>
  </div>
</section>
<?php page_end(); ?>
