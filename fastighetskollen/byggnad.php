<?php
/**
 * FastighetsKollen – byggnad.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * En byggnad: uppgifter, egenskaper, adresser, objekt och objektgrupper. Läsning öppen för alla.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/fas.php';

$inloggad = fas_inloggad() !== null;
$uuid = kc_get('uuid');
$b = fas_byggnad_hamta($uuid);
if ($b === null) {
    throw new KcFel('Byggnaden finns inte eller har raderats.', 404);
}
$f = fas_fastighet_hamta($b['fastighet_uuid']);
$adresser = fas_adresser('byggnad', $uuid);

$to = kc_tabell('md_objekt');
$ta = kc_tabell('md_adress');
$ot = kc_tabell('md_objekttyp');
$kt = kc_tabell('md_koktyp');
$objekt = kc_hamta_alla(
    "SELECT o.*, t.namn AS typ, k.namn AS kok, a.gatuadress
       FROM `$to` o
       LEFT JOIN `$ot` t ON t.uuid = o.objekttyp_uuid
       LEFT JOIN `$kt` k ON k.uuid = o.koktyp_uuid
       LEFT JOIN `$ta` a ON a.uuid = o.adress_uuid AND a.is_deleted = 0
      WHERE o.byggnad_uuid = ? AND o.is_deleted = 0
      ORDER BY o.objektnummer",
    [$uuid]
);
$summa_kvm = array_sum(array_map(static fn($o) => (float)($o['kvadratmeter'] ?? 0), $objekt));
$visa_max = 100;

kc_sidhuvud('Byggnad: ' . trim($b['byggnadsnummer'] . ' ' . $b['namn']));
?>
<p>
  <?php if ($f !== null): ?>
    <a href="<?= kc_h(kc_url('fastighet.php?uuid=' . rawurlencode($f['uuid']))) ?>">« Fastighet <?= kc_h(trim($f['fastighetsnummer'] . ' ' . $f['namn'])) ?></a> ·
  <?php endif; ?>
  <a href="<?= kc_h(kc_url('byggnader.php')) ?>">Alla byggnader</a>
</p>

<div class="kc-ruta" style="max-width:52rem">
  <h2>Uppgifter</h2>
  <div class="kc-tabell-wrap"><table class="kc-tabell"><tbody>
    <tr><th>Byggnadsnummer</th><td><?= kc_h($b['byggnadsnummer']) ?></td></tr>
    <tr><th>Namn</th><td><?= kc_h($b['namn']) ?></td></tr>
    <tr><th>Byggnadstyp</th><td><?= kc_h(fas_typ_namn('byggnadstyp', $b['byggnadstyp_uuid'])) ?></td></tr>
    <tr><th>Fastighet</th><td><?= $f !== null ? kc_h(trim($f['fastighetsnummer'] . ' ' . $f['namn'])) : '' ?></td></tr>
    <tr><th>Objekt</th><td><?= kc_h(count($objekt)) ?> st<?= $summa_kvm > 0 ? ', ' . kc_h(fas_tal($summa_kvm)) . ' m² totalt' : '' ?></td></tr>
    <tr><th>Senast ändrad</th><td><?= kc_h(kc_tid($b['last_changed'])) ?></td></tr>
  </tbody></table></div>
  <?php if ($inloggad): ?>
    <p style="margin-top:.75rem"><a class="kc-knapp" href="<?= kc_h(kc_url('byggnad_redigera.php?uuid=' . rawurlencode($uuid))) ?>">Ändra / flytta</a></p>
  <?php endif; ?>
</div>

<?= fas_egenskaper_ruta('byggnad', $uuid, $inloggad) ?>

<div class="kc-ruta" style="max-width:52rem">
  <h2>Adresser</h2>
  <?php if ($adresser === []): ?>
    <p><em class="kc-dampad">Inga adresser registrerade.</em></p>
  <?php else: ?>
    <div class="kc-tabell-wrap"><table class="kc-tabell">
      <thead><tr><th>Adress</th><th>Beskrivning</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($adresser as $a): ?>
        <tr>
          <td><?php if ($inloggad): ?>
                <a href="<?= kc_h(kc_url('adress_redigera.php?agare=byggnad&agare_uuid=' . rawurlencode($uuid) . '&uuid=' . rawurlencode($a['uuid']))) ?>"><?= kc_h(fas_adress_text($a)) ?></a>
              <?php else: ?><?= kc_h(fas_adress_text($a)) ?><?php endif; ?></td>
          <td><?= kc_h($a['beskrivning']) ?></td>
          <td><?= (int)$a['ar_huvudadress'] === 1 ? '<strong>Huvudadress</strong>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <?php if ($inloggad): ?>
    <p style="margin-top:.75rem"><a class="kc-knapp" href="<?= kc_h(kc_url('adress_redigera.php?agare=byggnad&agare_uuid=' . rawurlencode($uuid))) ?>">+ Lägg till adress</a></p>
  <?php endif; ?>
</div>

<div class="kc-ruta">
  <h2>Objekt</h2>
  <?php if ($objekt === []): ?>
    <p><em class="kc-dampad">Inga objekt registrerade.</em></p>
  <?php else: ?>
    <div class="kc-tabell-wrap"><table class="kc-tabell">
      <thead><tr><th>Objektnr</th><th>Typ</th><th>Adress</th><th>Våning</th><th>Storlek</th><th>m²</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($objekt, 0, $visa_max) as $o): ?>
        <tr>
          <td><a href="<?= kc_h(kc_url('objekt.php?uuid=' . rawurlencode($o['uuid']))) ?>"><?= kc_h($o['objektnummer']) ?></a></td>
          <td><?= kc_h($o['typ']) ?></td>
          <td><?= kc_h($o['gatuadress']) ?></td>
          <td><?= kc_h(fas_vaning($o['vaningsplan'])) ?></td>
          <td><?= kc_h(fas_storlek($o['antal_rum'], (string)$o['kok'])) ?></td>
          <td><?= kc_h(fas_tal($o['kvadratmeter'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if (count($objekt) > $visa_max): ?>
      <p class="kc-hjalp">Visar <?= $visa_max ?> av <?= count($objekt) ?>.</p>
    <?php endif; ?>
  <?php endif; ?>
  <p style="margin-top:.75rem">
    <?php if ($inloggad): ?>
      <a class="kc-knapp" href="<?= kc_h(kc_url('objekt_redigera.php?byggnad=' . rawurlencode($uuid))) ?>">+ Nytt objekt</a>
    <?php endif; ?>
    <?php if ($objekt !== []): ?>
      <a href="<?= kc_h(kc_url('objektlista.php?byggnad=' . rawurlencode($uuid))) ?>">Visa i objektlistan (sortera, filtrera, exportera) »</a>
    <?php endif; ?>
  </p>
</div>

<?= fas_objektgrupper_ruta('byggnad', ['byggnad' => $uuid, 'fastighet' => $b['fastighet_uuid']]) ?>
<?php kc_sidfot();
