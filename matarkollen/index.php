<?php
/**
 * MätarKollen – index.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Startsida: översikt, öppen för alla.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/mat.php';

$anvandare = mat_inloggad();
$pren = mat_prenumerationer();
$aldsta = kc_pull_aldsta($pren);
$anv_synk = kc_synk_status('cen_anvandare', 'pull');
$antal = [];
foreach (['fas_fastighet', 'fas_byggnad', 'fas_objekt', 'fas_objektgrupp'] as $n) {
    $antal[$n] = mat_ld_antal($pren[$n]['ld']);
}
$tm = kc_tabell('md_matpunkt');
$per_status = [];
foreach (kc_hamta_alla("SELECT status, COUNT(*) AS n FROM `$tm` WHERE is_deleted = 0 GROUP BY status") as $r) {
    $per_status[$r['status']] = (int)$r['n'];
}
$tma = kc_tabell('md_matare');
$tmo = kc_tabell('md_montering');
$antal_matare = (int)kc_hamta_varde("SELECT COUNT(*) FROM `$tma` WHERE is_deleted = 0");
$antal_monterade = (int)kc_hamta_varde(
    "SELECT COUNT(DISTINCT mo.matare_uuid) FROM `$tmo` mo JOIN `$tma` ma ON ma.uuid = mo.matare_uuid AND ma.is_deleted = 0
      WHERE mo.is_deleted = 0 AND mo.slutdatum IS NULL"
);
$ej_skickade = 0;
foreach (mat_delade_tabeller() as $namn => $def) {
    $ej_skickade += kc_push_osynkade($namn, $def['tabell']);
}
$gammal = $aldsta === null || (time() - (new DateTimeImmutable($aldsta, new DateTimeZone('UTC')))->getTimestamp()) > 86400;

kc_sidhuvud('MätarKollen');
?>
<div class="kc-ruta">
  <h2>Om MätarKollen</h2>
  <p>MätarKollen äger mätpunkter, mätare och monteringen mellan dem i ekosystemet Kollen.
     Mätpunkten är det som mätvärden och förbrukning knyts till; den placeras på ett objekt, en byggnad,
     en fastighet eller en objektgrupp från FastighetsKollen.</p>
  <p>All information kan läsas av alla. För att ändra krävs inloggning med behörighet till MätarKollen,
     som ges i CentralKollen.</p>
</div>

<div class="kc-ruta">
  <h2>Läget just nu</h2>
  <p>Fastighetsdata från FastighetsKollen:
     <?= kc_h($antal['fas_fastighet']) ?> fastigheter · <?= kc_h($antal['fas_byggnad']) ?> byggnader ·
     <?= kc_h($antal['fas_objekt']) ?> objekt · <?= kc_h($antal['fas_objektgrupp']) ?> objektgrupper
     · <a href="<?= kc_h(kc_url('fastighetsdata.php')) ?>">Sök »</a></p>
  <p>Senast hämtad (äldsta tabellen):
    <?= $aldsta !== null ? kc_h(kc_tid($aldsta, 'Y-m-d H:i')) : '<em class="kc-dampad">aldrig (eller inte alla tabeller)</em>' ?>
    <?php if ($gammal): ?> · <strong><a href="<?= kc_h(kc_url('synk.php')) ?>">Hämta från CentralKollen »</a></strong><?php endif; ?></p>
  <p>Användare senast hämtade från CentralKollen:
    <?= $anv_synk && $anv_synk['senaste_ok_tid']
        ? kc_h(kc_tid($anv_synk['senaste_ok_tid'], 'Y-m-d H:i'))
        : '<em class="kc-dampad">aldrig</em>' ?>
    · <a href="<?= kc_h(kc_url('synk.php')) ?>">Synk »</a></p>
  <p>Mätpunkter:
    <?php foreach (MAT_MATPUNKT_STATUS as $k => $namn): ?>
      <?= kc_h($namn) ?> <?= kc_h($per_status[$k] ?? 0) ?> ·
    <?php endforeach; ?>
    <a href="<?= kc_h(kc_url('matpunkter.php')) ?>">Visa »</a></p>
  <p>Mätare: <?= kc_h($antal_matare) ?> · monterade <?= kc_h($antal_monterade) ?> ·
    <a href="<?= kc_h(kc_url('matarlista.php?ej_monterade=1')) ?>">ej monterade <?= kc_h($antal_matare - $antal_monterade) ?></a>
    · <a href="<?= kc_h(kc_url('matarlista.php')) ?>">Visa »</a></p>
  <p>Ändringar som inte skickats till CentralKollen:
    <?= $ej_skickade > 0
        ? '<strong>' . kc_h($ej_skickade) . '</strong> · <a href="' . kc_h(kc_url('synk.php')) . '">Skicka »</a>'
        : '<span class="kc-dampad">inga</span>' ?></p>
</div>

<?php if ($anvandare === null): ?>
<p><a class="kc-knapp" href="<?= kc_h(kc_url('login.php')) ?>">Logga in för att ändra</a></p>
<?php endif; ?>
<?php kc_sidfot();
