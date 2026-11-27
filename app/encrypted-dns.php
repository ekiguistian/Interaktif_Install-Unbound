<?php
declare(strict_types=1);

$protocol = ($encryptedDnsProtocol ?? '') === 'doh' ? 'doh' : 'dot';
$label = $protocol === 'doh' ? 'DoH' : 'DoT';
$defaultPort = $protocol === 'doh' ? '443' : '853';
$guideUrl = $protocol === 'doh'
    ? 'https://unbound.docs.nlnetlabs.nl/en/latest/topics/privacy/dns-over-https.html'
    : 'https://unbound.docs.nlnetlabs.nl/en/latest/manpages/unbound.conf.html';
$guideLabel = $protocol === 'doh' ? 'Panduan resmi DoH Unbound' : 'Referensi resmi opsi TLS Unbound';

$result = manage(['encrypted-dns-state'], null, 12);
$state = $result['code'] === 0 ? json_decode($result['output'], true) : null;
page_start($label, true);
if (!is_array($state)) {
    notice('Status ' . $label . ' belum tersedia. ' . trim($result['output']), 'error');
} else {
    $interfaces = $state['interfaces'] ?? [];
    $certificate = $state['certificate'] ?? [];
    $listener = $state[$protocol] ?? ['enabled' => false, 'listeners' => []];
    $certOk = !empty($certificate['valid']);
    $serviceActive = ($state['service'] ?? '') === 'active';
    $badge = static function (bool $ok, string $yes, string $no): void { ?>
      <span class="state-label" data-active="<?=$ok ? 'true' : 'false'?>"><?=e($ok ? $yes : $no)?></span>
    <?php };
    ?>
    <section class="panel p-5 mb-5 space-y-3">
      <div class="flex flex-wrap items-center gap-3">
        <h2 class="font-semibold">Status <?=e($label)?></h2>
        <?php $badge($serviceActive, 'Unbound aktif', 'Unbound tidak aktif'); ?>
        <?php $badge(!empty($listener['enabled']), 'Aktif di konfigurasi', 'Nonaktif'); ?>
        <?php $badge($certOk, 'Sertifikat siap', 'Sertifikat belum aktif'); ?>
      </div>
      <p class="text-sm">Port <?=e((string)($listener['port'] ?? $defaultPort))?> · <?= $protocol === 'dot' ? 'DNS-over-TLS melalui TCP.' : 'DNS-over-HTTPS melalui HTTP/2; endpoint bawaan /dns-query.' ?></p>
      <?php if (!empty($listener['listeners'])): ?>
        <p class="text-sm">Interface terkonfigurasi: <?php foreach ($listener['listeners'] as $i => $address): ?><?= $i ? ' · ' : '' ?><code><?=e($address)?></code><?php endforeach; ?></p>
      <?php else: ?><p class="text-sm">Belum ada interface untuk layanan ini.</p><?php endif; ?>
      <?php if (!empty($listener['enabled']) && !$serviceActive): ?><p class="text-sm">Listener tersimpan di konfigurasi dan akan aktif saat Unbound dijalankan.</p><?php endif; ?>
      <p class="text-sm">Sertifikat diperiksa kepercayaan CA sistem, tanggal berlaku, dan kecocokan private key.</p>
      <?php if ($certificate['message'] ?? ''): ?><p class="text-sm" role="status"><?=e($certificate['message'])?></p><?php endif; ?>
      <?php if (!empty($certificate['expires'])): ?><p class="text-sm">Berlaku sampai <code><?=e($certificate['expires'])?></code></p><?php endif; ?>
      <?php if (empty($state['tls_supported'])): ?><p class="text-sm text-amber-300">Binary Unbound di sistem tidak melaporkan dukungan TLS. DoT dan DoH tidak dapat diaktifkan.</p>
      <?php elseif ($protocol === 'doh' && empty($state['doh_supported'])): ?><p class="text-sm text-amber-300">Binary Unbound tidak melaporkan dukungan libnghttp2. DoH memerlukan library tersebut dan binary Unbound yang dibangun dengan dukungan nghttp2.</p><?php endif; ?>
      <p class="text-sm">Interface IP aktif: <?php if (!$interfaces): ?>tidak ada IP aktif selain loopback.<?php else: ?>
        <?php foreach ($interfaces as $i => $item): ?><?= $i ? ' · ' : '' ?><code><?=e($item['name'])?> (<?=e($item['ip'])?>)</code><?php endforeach; ?>
      <?php endif; ?></p>
    </section>

    <details class="panel p-5 mb-5 space-y-3" open>
      <summary class="cursor-pointer font-semibold">Panduan singkat sebelum mengaktifkan <?=e($label)?></summary>
      <ol class="ml-5 list-decimal space-y-2 pt-3 text-sm">
        <li>Gunakan nama DNS untuk resolver yang mengarah ke alamat IP server. Sertifikat harus dipercaya klien, masih berlaku, memiliki SAN untuk nama tersebut, dan cocok dengan private key.</li>
        <li>Pilih interface IP yang dapat dijangkau klien. Pastikan port <?=e($defaultPort)?> tersedia, dibuka di firewall/router, dan aturan <code>access-control</code> Unbound mengizinkan subnet klien yang dimaksud.</li>
        <?php if ($protocol === 'dot'): ?>
          <li>DoT memakai TCP, port bawaan 853. Pastikan klien menggunakan nama DNS sertifikat dan port yang sama.</li>
        <?php else: ?>
          <li>DoH memerlukan TLS, HTTP/2, serta binary Unbound yang dibangun dengan libnghttp2. Port bawaan 443 mungkin sudah dipakai Nginx pada IP yang sama; pilih port lain jika perlu.</li>
        <?php endif; ?>
        <li>Aktivasi me-restart Unbound jika layanan sedang berjalan. Setelah itu, uji koneksi dari klien dan periksa status/log Unbound.</li>
      </ol>
      <p class="pt-2 text-sm"><a class="text-cyan-300 underline" href="<?=e($guideUrl)?>" target="_blank" rel="noopener noreferrer"><?=e($guideLabel)?></a>
        <?php if ($protocol === 'dot'): ?> · <a class="text-cyan-300 underline" href="https://unbound.docs.nlnetlabs.nl/en/latest/topics/privacy/dns-over-https.html" target="_blank" rel="noopener noreferrer">Penjelasan DoT dan DoH dari Unbound</a><?php endif; ?>
      </p>
    </details>

    <section class="panel p-5 mb-5 space-y-4">
      <h2 class="font-semibold">Toggle <?=e($label)?></h2>
      <form class="space-y-3" data-api="encrypted-dns-toggle" data-confirm="<?=!empty($listener['enabled']) ? 'Nonaktifkan ' . $label . ' dan mulai ulang Unbound jika sedang aktif?' : 'Aktifkan ' . $label . ' pada interface dan sertifikat yang dipilih? Unbound akan dimulai ulang jika sedang aktif.'?>">
        <input type="hidden" name="protocol" value="<?=e($protocol)?>">
        <input type="hidden" name="enabled" value="<?=!empty($listener['enabled']) ? '0' : '1'?>">
        <label class="block text-sm">Interface IP
          <select class="form-input font-mono" name="interface" <?=!$interfaces && empty($listener['enabled']) ? 'disabled' : ''?>>
            <?php if (!$interfaces): ?><option value="">Tidak ada interface IP aktif</option><?php endif; ?>
            <?php foreach ($interfaces as $item): ?><option value="<?=e($item['ip'])?>" <?=($listener['interface'] ?? '') === $item['ip'] ? 'selected' : ''?>><?=e($item['name'])?> · <?=e($item['ip'])?></option><?php endforeach; ?>
            <?php if (($listener['interface'] ?? '') !== '' && !in_array($listener['interface'], array_column($interfaces, 'ip'), true)): ?><option value="<?=e($listener['interface'])?>" selected><?=e($listener['interface'])?> · interface konfigurasi</option><?php endif; ?>
          </select>
        </label>
        <label class="block text-sm">Port
          <input class="form-input font-mono" name="port" type="number" min="1" max="65535" value="<?=e((string)($listener['port'] ?? $defaultPort))?>" required>
        </label>
        <label class="block text-sm">Path sertifikat PEM / fullchain
          <input class="form-input font-mono" name="certificate" value="<?=e((string)($certificate['certificate'] ?? ''))?>" maxlength="512" placeholder="/etc/letsencrypt/live/dns.example.com/fullchain.pem" spellcheck="false">
        </label>
        <label class="block text-sm">Path private key
          <input class="form-input font-mono" name="key" value="<?=e((string)($certificate['key'] ?? ''))?>" maxlength="512" placeholder="/etc/letsencrypt/live/dns.example.com/privkey.pem" spellcheck="false">
        </label>
        <p class="text-xs text-slate-400">Gunakan sertifikat fullchain DNS yang dipercaya klien. Path harus berada di /etc/unbound, /etc/letsencrypt, atau /etc/ssl dan file dikelola root. Sertifikat dan key yang sama dipakai bersama jika DoT dan DoH sama-sama aktif.</p>
        <?php $buttonDisabled = empty($listener['enabled']) && (!$interfaces || empty($state['tls_supported']) || ($protocol === 'doh' && empty($state['doh_supported']))); ?>
        <button class="btn-primary" type="submit" <?=$buttonDisabled ? 'disabled' : ''?>><?=!empty($listener['enabled']) ? 'Nonaktifkan ' : 'Aktifkan '?><?=e($label)?></button>
        <p data-result role="status"></p>
      </form>
    </section>
<?php }
page_end();

// System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian.
