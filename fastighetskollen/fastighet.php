<?php
/**
 * FastighetsKollen – fastighet.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * En fastighet: uppgifter, egenskaper, adresser, byggnader och objektgrupper.
 * Läsning öppen för alla; länkar för att ändra visas för inloggade.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/fas.php';

$inloggad = fas_inloggad() !== null;
$uuid = kc_get('uuid');
$f = fas_fastighet_hamta($uuid);
if ($f === null) {
    throw new KcFel('Fastigheten finns inte eller har raderats.', 404);
}
$adresser = fas_adresser('fastighet', $uuid);

$tb = kc_tabell('md_byggnad');
$tt = kc_tabell('md_byggnadstyp');
$ta = kc_tabell('md_adress');
$to = kc_tabell('md_objekt');
$byggnader = kc_hamta_alla(
    "SELECT b.uuid, b.byggnadsnummer, b.namn, t.namn AS typ,
            (SELECT CONCAT_WS(', ', NULLIF(a.gatuadress, ''), NULLIF(CONCAT_WS(' ', a.postnummer, a.ort), ''))
               FROM `$ta` a WHERE a.byggnad_uuid = b.uuid AND a.is_deleted = 0
              ORDER BY a.ar_huvudadress DESC, a.id LIMIT 1) AS huvudadress,
            (SELECT COUNT(*) FROM `$to` o WHERE o.byggnad_uuid = b.uuid AND o.is_deleted = 0) AS antal_objekt
       FROM `$tb` b LEFT JOIN `$tt` t ON t.uuid = b.byggnadstyp_uuid
      WHERE b.fastighet_uuid = ? AND b.is_deleted = 0
      ORDER BY b.byggnadsnummer",
    [$uuid]
);

kc_sidhuvud('Fastighet: ' . $f['fastighetsnummer'] . ' ' . $f['namn']);
?>
<p><a href="<?= kc_h(kc_url('fastigheter.php')) ?>">« Alla fastigheter</a></p>

<div class="kc-ruta" style="max-width:48rem">
  <h2>Uppgifter</h2>
  <div class="kc-tabell-wrap"><table class="kc-tabell">
    <tbody>
      <tr><th>Fastighetsnummer</th><td><?= kc_h($f['fastighetsnummer']) ?></td></tr>
      <tr><th>Namn</th><td><?= kc_h($f['namn']) ?></td></tr>
      <tr><th>Team</th><td><?= kc_h($f['team']) ?></td></tr>
      <tr><th>Bolag</th><td><?= kc_h($f['bolag']) ?></td></tr>
      <tr><th>Kostnadsställe</th><td><?= kc_h($f['kostnadsstalle']) ?></td></tr>
      <tr><th>Senast ändrad</th><td><?= kc_h(kc_tid($f['last_changed'])) ?></td></tr>
    </tbody>
  </table></div>
  <?php if ($inloggad): ?>
    <p style="margin-top:.75rem"><a class="kc-knapp" href="<?= kc_h(kc_url('fastighet_redigera.php?uuid=' . rawurlencode($uuid))) ?>">Ändra uppgifter</a></p>
  <?php endif; ?>
  <p class="kc-hjalp">Team, bolag och kostnadsställe lagras tills vidare som text. De kommer att väljas ur
     OrganisationsKollen och BudgetKollen när de finns.</p>
</div>

<?= fas_egenskaper_ruta('fastighet', $uuid, $inloggad) ?>

<div class="kc-ruta" style="max-width:48rem">
  <h2>Adresser</h2>
  <?php if ($adresser === []): ?>
    <p><em class="kc-dampad">Inga adresser registrerade.</em></p>
  <?php else: ?>
    <div class="kc-tabell-wrap"><table class="kc-tabell">
      <thead><tr><th>Adress</th><th>Beskrivning</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($adresser as $a): ?>
        <tr>
          <td>
            <?php if ($inloggad): ?>
              <a href="<?= kc_h(kc_url('adress_redigera.php?agare=fastighet&agare_uuid=' . rawurlencode($uuid) . '&uuid=' . rawurlencode($a['uuid']))) ?>"><?= kc_h(fas_adress_text($a)) ?></a>
            <?php else: ?>
              <?= kc_h(fas_adress_text($a)) ?>
            <?php endif; ?>
          </td>
          <td><?= kc_h($a['beskrivning']) ?></td>
          <td><?= (int)$a['ar_huvudadress'] === 1 ? '<strong>Huvudadress</strong>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <?php if ($inloggad): ?>
    <p style="margin-top:.75rem"><a class="kc-knapp" href="<?= kc_h(kc_url('adress_redigera.php?agare=fastighet&agare_uuid=' . rawurlencode($uuid))) ?>">+ Lägg till adress</a></p>
  <?php endif; ?>
</div>

<div class="kc-ruta">
  <h2>Byggnader</h2>
  <?php if ($byggnader === []): ?>
    <p><em class="kc-dampad">Inga byggnader registrerade.</em></p>
  <?php else: ?>
    <div class="kc-tabell-wrap"><table class="kc-tabell">
      <thead><tr><th>Byggnadsnr</th><th>Namn</th><th>Typ</th><th>Huvudadress</th><th>Objekt</th></tr></thead>
      <tbody>
      <?php foreach ($byggnader as $b): ?>
        <tr>
          <td><a href="<?= kc_h(kc_url('byggnad.php?uuid=' . rawurlencode($b['uuid']))) ?>"><?= kc_h($b['byggnadsnummer']) ?></a></td>
          <td><?= kc_h($b['namn']) ?></td>
          <td><?= kc_h($b['typ']) ?></td>
          <td><?= kc_h($b['huvudadress']) ?></td>
          <td><?= kc_h($b['antal_objekt']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <p style="margin-top:.75rem">
    <?php if ($inloggad): ?>
      <a class="kc-knapp" href="<?= kc_h(kc_url('byggnad_redigera.php?fastighet=' . rawurlencode($uuid))) ?>">+ Ny byggnad</a>
    <?php endif; ?>
    <?php if ($byggnader !== []): ?>
      <a href="<?= kc_h(kc_url('byggnader.php?fastighet=' . rawurlencode($uuid))) ?>">Visa i byggnadslistan (sortera, filtrera, exportera) »</a>
    <?php endif; ?>
  </p>
</div>

<?= fas_objektgrupper_ruta('fastighet', ['fastighet' => $uuid]) ?>
<?php kc_sidfot();
