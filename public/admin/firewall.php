<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/layout.php';
require_admin();

$result = CommandRunner::run(['/usr/bin/sudo', '-n', '/usr/local/sbin/unbound-web-manage', 'firewall-list'], 20);
$firewall = $result['code'] === 0 ? json_decode($result['output'], true) : null;
$direction = $_GET['direction'] ?? 'all';
if (!in_array($direction, ['all', 'in', 'out'], true)) {
    $direction = 'all';
}

$ruleFields = static function (array $rule): void { ?>
  <div class="grid gap-4 sm:grid-cols-2">
    <?php foreach ([
        'rule_action' => ['Aksi', 'action', ['allow' => 'Allow', 'deny' => 'Deny']],
        'direction' => ['Arah', 'direction', ['in' => 'IN', 'out' => 'OUT']],
    ] as $name => [$label, $key, $options]): ?>
      <fieldset>
        <legend class="mb-2 text-sm font-medium"><?= e($label) ?></legend>
        <div class="flex gap-2">
          <?php foreach ($options as $value => $text): ?>
            <label class="cursor-pointer">
              <input class="sr-only peer" type="radio" name="<?= e($name) ?>" value="<?= e($value) ?>" <?= $rule[$key] === $value ? 'checked' : '' ?>>
              <span class="btn-secondary peer-checked:border-cyan-300 peer-checked:bg-cyan-400/15 peer-checked:text-cyan-100 peer-focus-visible:ring-2 peer-focus-visible:ring-cyan-300"><?= e($text) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>
    <?php endforeach; ?>
    <label>
      <span class="mb-2 block text-sm font-medium">Protokol</span>
      <select name="protocol" class="form-select">
        <?php foreach (FIREWALL_PROTOCOLS as $proto): ?>
        <option value="<?=e($proto)?>" <?=$rule['protocol'] === $proto ? 'selected' : ''?>><?=e($proto === 'any' ? 'Semua protokol' : strtoupper($proto))?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>
      <span class="mb-2 block text-sm font-medium">Port tujuan (opsional)</span>
      <input class="form-input" name="port" type="number" min="1" max="65535" value="<?= e($rule['port']) ?>">
    </label>
  </div>
  <div class="mt-4">
    <?php field('IP/CIDR', 'peer', $rule['peer'], 'text', 'Untuk IN, isi IP sumber. Untuk OUT, isi IP tujuan. Kosong berarti semua alamat.', false); ?>
  </div>
<?php };

page_start('Firewall UFW', true);
?>
<section class="panel mb-5 p-5">
  <nav aria-label="Kontrol UFW" class="flex flex-wrap items-center justify-between gap-4">
    <div>
      <h2 class="font-semibold">Status UFW</h2>
      <p class="mt-1 text-sm <?= $firewall && $firewall['active'] ? 'text-emerald-300' : 'text-slate-400' ?>"><?= $firewall ? ($firewall['active'] ? 'Aktif' : 'Nonaktif') : 'Tidak tersedia' ?></p>
    </div>
    <div class="flex flex-wrap gap-2">
      <?php foreach (['enable' => 'Enable UFW', 'disable' => 'Disable UFW'] as $action => $label): ?>
        <form data-api="firewall-<?= e($action) ?>" data-confirm="<?= e($action === 'enable'
            ? 'Pastikan aturan SSH dan port web tersedia sebelum mengaktifkan UFW. Lanjutkan?'
            : 'Nonaktifkan UFW dan hentikan penerapan aturan firewall?') ?>">
          <input type="hidden" name="confirm" value="<?= e(strtoupper($action)) ?>">
          <button class="<?= $action === 'enable' ? 'btn-primary' : 'btn-secondary' ?> disabled:cursor-not-allowed disabled:opacity-40" type="submit"
            <?= !$firewall || $firewall['active'] === ($action === 'enable') ? 'disabled' : '' ?>><?= e($label) ?></button>
        </form>
      <?php endforeach; ?>
      <form data-api="firewall-reload"><button type="submit" class="btn-secondary">Reload UFW</button></form>
    </div>
  </nav>
  <p data-page-result role="status" class="mt-2 text-sm text-cyan-200"></p>
</section>

<?php if (!$firewall): ?>
  <?php notice(trim($result['output']) ?: 'Status dan aturan UFW tidak dapat dibaca.', 'error'); ?>
<?php else: ?>
<section class="grid items-start gap-5 xl:grid-cols-[.75fr_1.25fr]">
  <div class="panel p-5">
    <h2 class="font-semibold">Tambah aturan</h2>
    <form class="mt-5" data-api="firewall-add">
      <?php $ruleFields(['action' => 'allow', 'direction' => $direction === 'out' ? 'out' : 'in', 'protocol' => 'udp', 'port' => '53', 'peer' => '']); ?>
      <button class="btn-primary mt-4" type="submit">Tambah aturan</button>
      <p data-result role="status" class="mt-2 text-sm text-cyan-200"></p>
    </form>
  </div>
  <div class="panel p-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 class="font-semibold">Aturan tersimpan</h2>
      <nav class="flex gap-2" aria-label="Filter arah aturan">
        <?php foreach (['all' => 'Semua', 'in' => 'IN', 'out' => 'OUT'] as $value => $label): ?>
          <a class="btn-secondary <?= $value === $direction ? 'nav-active' : '' ?>" href="?direction=<?= e($value) ?>" <?= $value === $direction ? 'aria-current="true"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
      </nav>
    </div>
    <ul class="mt-5 space-y-3">
      <?php $shown = false; ?>
      <?php foreach ($firewall['rules'] as $item):
          $rule = $item['rule'];
          if ($direction !== 'all' && ($rule['direction'] ?? null) !== $direction) {
              continue;
          }
          $shown = true;
          ?>
        <li class="rounded-xl border border-slate-700 p-3">
          <?php if ($rule === null): ?>
            <code class="break-all text-sm text-slate-300"><?= e($item['text']) ?></code>
            <p class="mt-1 text-xs text-slate-400">Aturan lanjutan · baca saja</p>
          <?php else: ?>
            <details>
              <summary class="cursor-pointer break-words text-sm">
                <span class="<?= $rule['action'] === 'allow' ? 'text-emerald-300' : 'text-rose-300' ?>"><?= e(strtoupper($rule['action'])) ?></span>
                <?= e(strtoupper($rule['direction'])) ?> · <?= $rule['port'] !== '' ? e($rule['port']) . '/' : '' ?><?= e(strtoupper($rule['protocol'])) ?>
                <span class="text-slate-400">· <?= e($rule['peer'] ?: 'Semua alamat') ?></span>
                <span class="ml-2 text-cyan-200">Ubah / Hapus</span>
              </summary>
              <?php if ($item['comment'] !== ''): ?><p class="mt-2 text-xs text-slate-400"><?= e($item['comment']) ?></p><?php endif; ?>
              <form class="mt-4" data-api="firewall-update">
                <input type="hidden" name="id" value="<?= e($item['id']) ?>">
                <?php $ruleFields($rule); ?>
                <button class="btn-primary mt-4" type="submit">Simpan perubahan</button>
                <p data-result role="status" class="mt-2 text-sm text-cyan-200"></p>
              </form>
              <form class="mt-2" data-api="firewall-delete" data-confirm="Hapus aturan UFW ini?">
                <input type="hidden" name="id" value="<?= e($item['id']) ?>">
                <button class="btn-danger" type="submit">Hapus aturan</button>
                <p data-result role="status" class="mt-2 text-sm text-cyan-200"></p>
              </form>
            </details>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
      <?php if (!$shown): ?><li class="text-sm text-slate-400">Belum ada aturan pada tampilan ini.</li><?php endif; ?>
    </ul>
    <details class="mt-5 text-sm">
      <summary class="cursor-pointer text-slate-300">Status aktif dari UFW</summary>
      <pre class="mt-3 max-h-64 overflow-auto rounded-xl bg-black/50 p-4 text-xs text-slate-300"><?= e($firewall['status']) ?></pre>
    </details>
  </div>
</section>
<?php endif; ?>
<?php page_end(); ?>

<?php // System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
