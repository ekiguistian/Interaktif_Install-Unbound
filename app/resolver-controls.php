<?php
declare(strict_types=1);

$resultState = manage(['resolver-state'], null, 8);
$resolver = $resultState['code'] === 0 ? json_decode($resultState['output'], true) : null;
if (!is_array($resolver)) {
    notice('Status Unbound belum bisa dibaca. ' . trim($resultState['output']), 'error');
    return;
}
$running = $resolver['service'] === 'active';
$ready = $resolver['configured'];
$button = static function (string $action, string $label, string $output, bool $reload = true, string $confirm = ''): void { ?>
  <form data-api="<?=e($action)?>" data-reload="<?=$reload ? 'true' : 'false'?>" data-output="#<?=e($output)?>" data-confirm="<?=e($confirm)?>">
    <button class="btn-secondary" type="submit"><?=e($label)?></button>
  </form>
<?php };
$badge = static function (bool $enabled, string $label): void { ?>
  <span class="state-label" data-active="<?=$enabled ? 'true' : 'false'?>"><?=e($label)?></span>
<?php }; ?>
<section class="panel p-5 mb-5 space-y-4">
  <div class="flex flex-wrap items-center gap-3">
    <h2 class="font-semibold">Layanan Unbound</h2>
    <?php $badge($running, ['active'=>'Aktif','inactive'=>'Nonaktif','failed'=>'Gagal','activating'=>'Memulai','deactivating'=>'Berhenti'][$resolver['service']] ?? 'Belum diketahui'); ?>
  </div>
  <div class="flex flex-wrap gap-2">
    <?php if ($ready) $button('unbound-check', 'Cek konfigurasi Unbound', 'resolver-result', false); ?>
    <?php if ($running): ?>
      <?php $button('stats', 'Baca statistik', 'resolver-result', false); ?>
      <?php $button('unbound-reload', 'Reload server DNS', 'resolver-result', true, 'Muat ulang server DNS?'); ?>
      <?php $button('unbound-restart', 'Restart Unbound', 'resolver-result', true, 'Mulai ulang server DNS?'); ?>
      <?php $button('unbound-stop', 'Nonaktifkan Unbound', 'resolver-result', true, 'Hentikan Unbound? Permintaan DNS ke resolver ini akan berhenti dilayani.'); ?>
    <?php elseif ($ready && in_array($resolver['service'], ['inactive','failed'], true)): ?>
      <?php $button('unbound-start', 'Aktifkan Unbound', 'resolver-result'); ?>
    <?php endif; ?>
  </div>
  <pre id="resolver-result" role="status" class="max-h-72 overflow-auto"></pre>
</section>
<?php if ($ready): ?>
<section class="panel p-5 mb-5 space-y-4">
  <div class="flex flex-wrap items-center gap-3">
    <h2 class="font-semibold">Keamanan DNS</h2>
    <?php if (!$resolver['live']): ?><span class="state-label">Berdasarkan konfigurasi di disk</span><?php endif; ?>
  </div>
  <div class="resolver-grid">
    <article class="resolver-card">
      <h3>Root hints</h3>
      <?php $badge($resolver['root_hints_active'], $resolver['root_hints_active'] ? 'Aktif' : 'Bawaan Unbound'); ?>
      <p><?=e($resolver['root_hints_ready'] ? 'Daftar root server siap diperbarui.' : 'Siapkan daftar dari InterNIC untuk memakai file root hints.')?></p>
      <div class="flex flex-wrap gap-2">
        <?php if ($running && $resolver['root_hints_active']) $button('root-hints-disable', 'Nonaktifkan root hints', 'unbound-dns-result'); ?>
        <?php if ($running && !$resolver['root_hints_active'] && $resolver['root_hints_ready']) $button('root-hints-enable', 'Aktifkan root hints', 'unbound-dns-result'); ?>
        <?php $button('root-hints-update', $resolver['root_hints_ready'] ? 'Perbarui root hints' : 'Siapkan root hints', 'unbound-dns-result'); ?>
      </div>
    </article>
    <article class="resolver-card">
      <h3>DNSSEC</h3>
      <?php $strict = $resolver['dnssec_active'] && !$resolver['dnssec_permissive']; ?>
      <?php $badge($strict, $strict ? 'Aktif' : ($resolver['dnssec_active'] ? 'Permisif' : 'Nonaktif')); ?>
      <p><?php if (!$resolver['anchor_tool'] && !$resolver['anchor_ready']): ?>Pasang alat dengan <code>sudo apt install unbound-anchor</code> untuk menyiapkan trust anchor.
      <?php else: ?><?=e($resolver['anchor_ready'] ? 'Trust anchor root tersedia.' : 'Siapkan trust anchor sebelum mengaktifkan DNSSEC.')?><?php endif; ?></p>
      <div class="flex flex-wrap gap-2">
        <?php if ($running && $strict) $button('dnssec-disable', 'Nonaktifkan DNSSEC', 'unbound-dns-result', true, 'Nonaktifkan validasi DNSSEC?'); ?>
        <?php if ($running && !$strict && $resolver['anchor_ready']) $button('dnssec-enable', 'Aktifkan DNSSEC', 'unbound-dns-result'); ?>
        <?php if ($resolver['anchor_tool']) $button('dnssec-anchor', $resolver['anchor_ready'] ? 'Perbarui trust anchor' : 'Siapkan trust anchor', 'unbound-dns-result'); ?>
      </div>
    </article>
    <article class="resolver-card">
      <h3>Sertifikat kontrol</h3>
      <?php $badge($resolver['certificates_ready'], $resolver['certificates_ready'] ? 'Tersedia' : ($resolver['unix_control'] ? 'Socket Unix' : 'Belum siap')); ?>
      <?php if ($resolver['certificates_ready']): ?>
        <p><?=e($resolver['tls_enabled'] ? 'TLS diaktifkan dalam konfigurasi remote-control.' : 'File tersedia. Gunakan melalui pengaturan remote-control TCP bila diperlukan.')?></p>
      <?php elseif ($resolver['unix_control']): ?>
        <p>Socket Unix lokal tidak memakai sertifikat TLS.</p>
      <?php elseif ($resolver['certificates_can_prepare']): ?>
        <p>Siapkan sertifikat untuk remote-control melalui TLS.</p>
        <?php $button('control-certificates', 'Siapkan sertifikat', 'unbound-dns-result'); ?>
      <?php else: ?>
        <p>Periksa kelengkapan file pada pengaturan remote-control.</p>
      <?php endif; ?>
    </article>
  </div>
  <pre id="unbound-dns-result" role="status" class="text-sm"></pre>
</section>
<?php endif; ?>

<?php // System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
