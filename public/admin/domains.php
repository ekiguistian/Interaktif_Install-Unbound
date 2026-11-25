<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/layout.php';
require_admin();
$domains=db()->query('SELECT id,domain,query_type,created_at FROM test_domains ORDER BY domain,query_type')->fetchAll();
page_start('Domain uji',true);
?>
<section class="panel p-5 sm:p-6">
  <div class="flex flex-wrap items-start justify-between gap-4"><div><h2 class="font-semibold">Daftar kueri DNS</h2><p class="mt-1 text-sm leading-6 text-slate-400">Daftar ini dipakai saat uji performa DNS.</p></div><span class="rounded-full bg-slate-800 px-3 py-1.5 text-sm text-slate-300"><?=number_format(count($domains),0,',','.')?> domain</span></div>
  <form class="mt-5 grid gap-3 sm:grid-cols-[1fr_10rem_auto]" data-api="domain-add"><input class="form-input" name="domain" placeholder="contoh.net" aria-label="Nama domain" required><select class="form-select" name="query_type" aria-label="Tipe kueri"><option>A</option><option>AAAA</option><option>MX</option><option>TXT</option><option>NS</option><option>CNAME</option></select><button class="btn-primary" type="submit"><i class="fa-solid fa-plus"></i>Tambah domain</button><p data-result class="text-sm text-cyan-200 sm:col-span-3"></p></form>
  <div class="mt-5 overflow-x-auto"><table class="w-full min-w-[36rem] text-left text-sm"><thead class="text-xs uppercase text-slate-500"><tr><th class="py-3">Domain dan tipe kueri</th><th>Ditambahkan</th><th></th></tr></thead><tbody class="divide-y divide-slate-800">
    <?php foreach($domains as $domain): ?><tr><td class="py-3"><form class="flex min-w-64 gap-2" data-api="domain-update"><input type="hidden" name="id" value="<?=e($domain['id'])?>"><input class="form-input" name="domain" aria-label="Nama domain" value="<?=e($domain['domain'])?>" required><select class="form-select w-28" name="query_type" aria-label="Tipe kueri"><?php foreach(['A','AAAA','MX','TXT','NS','CNAME'] as $type): ?><option value="<?=e($type)?>" <?=$domain['query_type']===$type?'selected':''?>><?=e($type)?></option><?php endforeach; ?></select><button class="btn-secondary" type="submit" aria-label="Simpan perubahan domain" title="Simpan perubahan domain"><i class="fa-solid fa-floppy-disk"></i></button><span data-result class="sr-only"></span></form></td><td class="text-xs text-slate-400"><?=e($domain['created_at'])?></td><td><form data-api="domain-delete"><input type="hidden" name="id" value="<?=e($domain['id'])?>"><button class="btn-danger" type="submit"><i class="fa-solid fa-trash"></i>Hapus</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<?php page_end(); ?>

<?php // System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
