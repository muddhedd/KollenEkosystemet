<?php
/**
 * FastighetsKollen – objekt.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Ett objekt med dess egenskaper och objektgrupper. Läsning öppen för alla.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/fas.php';

$inloggad = fas_inloggad() !== null;
$uuid = kc_get('uuid');
$o = fas_objekt_hamta($uuid);
if ($o === null) {
    throw new KcFel('Objektet finns inte eller har raderats.', 404);
}
$b   = fas_byggnad_hamta($o['byggnad_uuid']);
$f   = $b !== null ? fas_fastighet_hamta($b['fastighet_uuid']) : null;
$adr = $o['adress_uuid'] !== '' ? fas_adress_hamta($o['adress_uuid']) : null;
$kok = fas_typ_namn('koktyp', $o['koktyp_uuid']);

kc_sidhuvud('Objekt: ' . $o['objektnummer']);
?>
<p>
  <?php if ($b !== null): ?>
    <a href="<?= kc_h(kc_url('byggnad.php?uuid=' . rawurlencode($b['uuid']))) ?>">« Byggnad <?= kc_h(trim($b['byggnadsnummer'] . ' ' . $b['namn'])) ?></a> ·
  <?php endif; ?>
  <a href="<?= kc_h(kc_url('objektlista.php')) ?>">Alla objekt</a>
</p>

<div class="kc-ruta" style="max-width:48rem">
  <h2>Uppgifter</h2>
  <div class="kc-tabell-wrap"><table class="kc-tabell"><tbody>
    <tr><th>Objektnummer</th><td><?= kc_h($o['objektnummer']) ?></td></tr>
    <tr><th>Objekttyp</th><td><?= kc_h(fas_typ_namn('objekttyp', $o['objekttyp_uuid'])) ?></td></tr>
    <tr><th>Adress</th><td><?= $adr !== null ? kc_h(fas_adress_text($adr)) : '<em class="kc-dampad">ingen vald</em>' ?></td></tr>
    <tr><th>Våningsplan</th><td><?= kc_h(fas_vaning($o['vaningsplan'])) ?></td></tr>
    <tr><th>Storlek</th><td><?= kc_h(fas_storlek($o['antal_rum'], $kok)) ?></td></tr>
    <tr><th>Kvadratmeter</th><td><?= $o['kvadratmeter'] !== null ? kc_h(fas_tal($o['kvadratmeter'])) . ' m²' : '' ?></td></tr>
    <tr><th>Beskrivning</th><td><?= kc_h($o['beskrivning']) ?></td></tr>
    <tr><th>Byggnad</th><td><?= $b !== null ? kc_h(trim($b['byggnadsnummer'] . ' ' . $b['namn'])) : '' ?></td></tr>
    <tr><th>Fastighet</th><td><?= $f !== null ? kc_h(trim($f['fastighetsnummer'] . ' ' . $f['namn'])) : '' ?></td></tr>
    <tr><th>Senast ändrad</th><td><?= kc_h(kc_tid($o['last_changed'])) ?></td></tr>
  </tbody></table></div>
  <?php if ($inloggad): ?>
    <p style="margin-top:.75rem"><a class="kc-knapp" href="<?= kc_h(kc_url('objekt_redigera.php?uuid=' . rawurlencode($uuid))) ?>">Ändra / flytta</a></p>
  <?php endif; ?>
</div>

<?= fas_egenskaper_ruta('objekt', $uuid, $inloggad) ?>

<?= fas_objektgrupper_ruta('objekt', ['objekt' => $uuid, 'byggnad' => $o['byggnad_uuid'], 'fastighet' => $b['fastighet_uuid'] ?? '']) ?>
<?php kc_sidfot();
