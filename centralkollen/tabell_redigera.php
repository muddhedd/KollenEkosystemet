<?php
/**
 * CentralKollen – tabell_redigera.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Redigera en registrerad mastertabell: beskrivning, status, radering.
 * Visar också innehållet i den centrala kopian (antal rader och de senast ändrade).
 * Tabellnamnet är låst efter att tabellen skapats. Endast administratörer.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/cen.php';

cen_krav_admin();
$tt = kc_tabell('md_tabell');
$tv = kc_tabell('md_verktyg');
$tp = kc_tabell('md_prenumeration');

$uuid = kc_get('uuid');
$rad  = kc_ar_uuid($uuid)
    ? kc_hamta_en(
        "SELECT t.*, v.prefix AS master, v.namn AS master_namn, v.uuid AS master_uuid
           FROM `$tt` t JOIN `$tv` v ON v.uuid = t.verktyg_uuid AND v.is_deleted = 0
          WHERE t.uuid = ? AND t.is_deleted = 0 LIMIT 1",
        [$uuid])
    : null;
if ($rad === null) {
    throw new KcFel('Tabellen finns inte.', 404);
}
$har_sida   = 'tabell_redigera.php?uuid=' . rawurlencode($uuid);
$master_sida = 'verktyg_redigera.php?uuid=' . rawurlencode($rad['master_uuid']);

/* ---------- Spara ---------- */

if (kc_ar_post() && kc_post('atgard') === 'spara') {
    $beskrivning = kc_post('beskrivning');
    $aktiv       = isset($_POST['is_active']) ? 1 : 0;
    if (mb_strlen($beskrivning) > 255) {
        kc_flash('fel', 'Beskrivningen är för lång (max 255 tecken).');
        kc_omdirigera($har_sida);
    }
    $andrat = $beskrivning !== $rad['beskrivning'] || $aktiv !== (int)$rad['is_active'];
    if ($andrat) {
        kc_kor(
            "UPDATE `$tt` SET beskrivning = ?, is_active = ?, last_changed = ?, source_tool = 'cen'
              WHERE uuid = ? AND is_deleted = 0",
            [$beskrivning, $aktiv, kc_nu(), $uuid]
        );
    }
    kc_flash('ok', $andrat ? 'Ändringarna är sparade.' : 'Inget var ändrat.');
    kc_omdirigera($har_sida);
}

/* ---------- Radera ---------- */

if (kc_ar_post() && kc_post('atgard') === 'radera') {
    kc_transaktion(function () use ($tt, $tp, $uuid): void {
        $nu = kc_nu();
        kc_kor(
            "UPDATE `$tt` SET is_deleted = 1, last_changed = ?, source_tool = 'cen' WHERE uuid = ? AND is_deleted = 0",
            [$nu, $uuid]
        );
        // Kaskad: prenumerationerna på tabellen tas också bort
        kc_kor(
            "UPDATE `$tp` SET is_deleted = 1, last_changed = ?, source_tool = 'cen' WHERE tabell_uuid = ? AND is_deleted = 0",
            [$nu, $uuid]
        );
    });
    kc_flash('ok', 'Tabellen ' . $rad['tabellnamn'] . ' och dess prenumerationer är borttagna ur registret.');
    kc_omdirigera($master_sida);
}

/* ---------- Visning ---------- */

$prenumeranter = kc_hamta_alla(
    "SELECT v.uuid, v.prefix, v.namn, v.is_active
       FROM `$tp` p JOIN `$tv` v ON v.uuid = p.verktyg_uuid AND v.is_deleted = 0
      WHERE p.tabell_uuid = ? AND p.is_deleted = 0
      ORDER BY v.namn",
    [$uuid]
);
$demo = KOLL_VARIANT === 'demo' ? 'demo_' : '';

// Den centrala kopian (skapas vid första push)
$ck = kc_ck_tabell($rad['master'], $rad['tabellnamn']);
$ck_finns = (int)kc_hamta_varde(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$ck]
) === 1;
$ck_info = null;
$ck_senaste = [];
if ($ck_finns) {
    $ck_info = kc_hamta_en(
        "SELECT COUNT(*) AS totalt, COALESCE(SUM(is_deleted), 0) AS raderade,
                MAX(last_changed) AS senast_andrad, MAX(sync_seq) AS max_seq
           FROM `$ck`"
    );
    $ck_senaste = kc_hamta_alla("SELECT uuid, last_changed, is_deleted, sync_seq, data FROM `$ck` ORDER BY last_changed DESC, id DESC LIMIT 10");
}

kc_sidhuvud('Tabell: ' . $rad['master'] . ' / ' . $rad['tabellnamn']);
?>
<p><a href="<?= kc_h(kc_url($master_sida)) ?>">« Tillbaka till <?= kc_h($rad['master_namn']) ?></a> ·
   <a href="<?= kc_h(kc_url('dataflode.php')) ?>">Dataflöde</a></p>

<div class="kc-ruta" style="max-width:48rem">
  <h2>Namn i ekosystemet</h2>
  <p>Master: <code><?= kc_h($rad['master']) ?></code> <?= kc_h($rad['master_namn']) ?></p>
  <p>Tabell hos mastern: <code><?= kc_h($demo . $rad['master'] . '_md_' . $rad['tabellnamn']) ?></code></p>
  <p>Central kopia: <code><?= kc_h(kc_ck_tabell($rad['master'], $rad['tabellnamn'])) ?></code></p>
  <p>Lokal kopia hos prenumerant: <code><?= kc_h($demo) ?>&lt;prefix&gt;_ld_<?= kc_h($rad['master'] . '_' . $rad['tabellnamn']) ?></code></p>
  <p class="kc-hjalp">Tabellnamnet kan inte ändras – det skulle bryta kopiorna hos CentralKollen och prenumeranterna.</p>
</div>

<div class="kc-ruta">
  <h2>Central kopia</h2>
  <?php if (!$ck_finns): ?>
    <p><em class="kc-dampad">Inte skapad än – den skapas vid första push från <?= kc_h($rad['master_namn']) ?>.</em></p>
  <?php else: ?>
    <p><strong><?= kc_h((int)$ck_info['totalt'] - (int)$ck_info['raderade']) ?></strong> aktiva rader
       (+ <?= kc_h($ck_info['raderade']) ?> raderade) ·
       senast ändrad <?= $ck_info['senast_andrad'] ? kc_h(kc_tid($ck_info['senast_andrad'], 'Y-m-d H:i:s')) : '–' ?> ·
       högsta sync_seq <?= kc_h($ck_info['max_seq'] ?? 0) ?></p>
    <?php if ($ck_senaste !== []): ?>
      <details><summary>Visa de 10 senast ändrade raderna</summary>
        <div class="kc-tabell-wrap"><table class="kc-tabell">
          <thead><tr><th>uuid</th><th>Ändrad</th><th>Seq</th><th></th><th>Data</th></tr></thead>
          <tbody>
          <?php foreach ($ck_senaste as $r): ?>
            <tr>
              <td><code><?= kc_h(substr($r['uuid'], 0, 8)) ?>…</code></td>
              <td><?= kc_h(kc_tid($r['last_changed'], 'Y-m-d H:i:s')) ?></td>
              <td><?= kc_h($r['sync_seq']) ?></td>
              <td><?= (int)$r['is_deleted'] === 1 ? '<span class="kc-dampad">raderad</span>' : '' ?></td>
              <td><code style="font-size:.85em;word-break:break-all"><?= kc_h(mb_strimwidth($r['data'], 0, 160, '…')) ?></code></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </details>
    <?php endif; ?>
  <?php endif; ?>
</div>

<form method="post">
  <?= kc_csrf_falt() ?>
  <input type="hidden" name="atgard" value="spara">
  <div class="kc-ruta" style="max-width:48rem">
    <h2>Uppgifter</h2>
    <div class="kc-falt">
      <label for="beskrivning">Beskrivning</label>
      <input type="text" id="beskrivning" name="beskrivning" maxlength="255" style="width:100%"
             value="<?= kc_h($rad['beskrivning']) ?>">
    </div>
    <div class="kc-falt">
      <label>
        <input type="checkbox" name="is_active" value="1" <?= (int)$rad['is_active'] === 1 ? 'checked' : '' ?>>
        Aktiv (synkas)
      </label>
      <div class="kc-hjalp">En inaktiv tabell synkas inte, men prenumerationerna ligger kvar.</div>
    </div>
    <button type="submit" class="kc-knapp-primar">Spara</button>
  </div>
</form>

<div class="kc-ruta" style="max-width:48rem">
  <h2>Prenumeranter</h2>
  <?php if ($prenumeranter === []): ?>
    <p><em class="kc-dampad">Inga verktyg prenumererar på tabellen.</em></p>
  <?php else: ?>
    <ul>
      <?php foreach ($prenumeranter as $p): ?>
        <li><a href="<?= kc_h(kc_url('verktyg_redigera.php?uuid=' . rawurlencode($p['uuid']))) ?>"><?= kc_h($p['namn']) ?></a>
            <code><?= kc_h($p['prefix']) ?></code>
            <?php if ((int)$p['is_active'] !== 1): ?><span class="kc-dampad">– inaktivt</span><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <p class="kc-hjalp">Prenumerationer läggs till och tas bort på prenumerantens verktygssida.</p>
</div>

<div class="kc-ruta" style="max-width:48rem">
  <h2>Ta bort ur registret</h2>
  <p>Tabellen och alla prenumerationer på den tas bort ur registret. Data hos verktygen påverkas inte.</p>
  <form method="post" onsubmit="return confirm('Ta bort tabellen <?= kc_h($rad['tabellnamn']) ?> och dess prenumerationer?');">
    <?= kc_csrf_falt() ?>
    <input type="hidden" name="atgard" value="radera">
    <button type="submit" class="kc-knapp-fara">Ta bort tabellen</button>
  </form>
</div>
<?php kc_sidfot();
