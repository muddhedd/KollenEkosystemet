<?php
/**
 * ProjektKollen – index.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Startsida: översikt, öppen för alla.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/prj.php';

$anvandare = prj_inloggad();
$pren = prj_prenumerationer();
$aldsta = kc_pull_aldsta($pren);
$anv_synk = kc_synk_status('cen_anvandare', 'pull');
$antal = [];
foreach (['fas_fastighet', 'fas_byggnad', 'fas_objekt', 'fas_objektgrupp', 'mat_matpunkt', 'mat_matare'] as $n) {
    $antal[$n] = prj_ld_antal($pren[$n]['ld']);
}
$tp = kc_tabell('md_projekt');
$per_status = [];
foreach (kc_hamta_alla("SELECT status, COUNT(*) AS n FROM `$tp` WHERE is_deleted = 0 GROUP BY status") as $r) {
    $per_status[$r['status']] = (int)$r['n'];
}
$ej_skickade = 0;
foreach (prj_delade_tabeller() as $namn => $def) {
    $ej_skickade += kc_push_osynkade($namn, $def['tabell']);
}
$gammal = $aldsta === null || (time() - (new DateTimeImmutable($aldsta, new DateTimeZone('UTC')))->getTimestamp()) > 86400;

kc_sidhuvud('ProjektKollen');
?>
<div class="kc-ruta">
  <h2>Om ProjektKollen</h2>
  <p>ProjektKollen driver projekt i ekosystemet Kollen. Första projekttypen är installation av
     temperaturmätare: från urval av fastigheter, byggnader och objekt till mätpunktslista, etiketter,
     provisionering och export till MätarKollen och LoRaWAN-plattformen.</p>
  <p>Planerade mätpunkter och inköpta mätare ägs av projektet tills de importeras i MätarKollen.
     All information kan läsas av alla. För att ändra krävs inloggning med behörighet till ProjektKollen,
     som ges i CentralKollen.</p>
</div>

<div class="kc-ruta">
  <h2>Läget just nu</h2>
  <p>Projekt:
    <?php foreach (PRJ_STATUS as $k => $namn): ?>
      <?= kc_h($namn) ?> <?= kc_h($per_status[$k] ?? 0) ?> ·
    <?php endforeach; ?>
    <a href="<?= kc_h(kc_url('projektlista.php')) ?>">Visa »</a></p>
  <p>Fastighetsdata: <?= kc_h($antal['fas_fastighet']) ?> fastigheter · <?= kc_h($antal['fas_byggnad']) ?> byggnader ·
     <?= kc_h($antal['fas_objekt']) ?> objekt · <?= kc_h($antal['fas_objektgrupp']) ?> objektgrupper
     · <a href="<?= kc_h(kc_url('fastighetsdata.php')) ?>">Sök »</a></p>
  <p>Från MätarKollen: <?= kc_h($antal['mat_matpunkt']) ?> mätpunkter · <?= kc_h($antal['mat_matare']) ?> mätare</p>
  <p>Senast hämtad (äldsta tabellen):
    <?= $aldsta !== null ? kc_h(kc_tid($aldsta, 'Y-m-d H:i')) : '<em class="kc-dampad">aldrig (eller inte alla tabeller)</em>' ?>
    <?php if ($gammal): ?> · <strong><a href="<?= kc_h(kc_url('synk.php')) ?>">Hämta från CentralKollen »</a></strong><?php endif; ?></p>
  <p>Användare senast hämtade från CentralKollen:
    <?= $anv_synk && $anv_synk['senaste_ok_tid']
        ? kc_h(kc_tid($anv_synk['senaste_ok_tid'], 'Y-m-d H:i'))
        : '<em class="kc-dampad">aldrig</em>' ?>
    · <a href="<?= kc_h(kc_url('synk.php')) ?>">Synk »</a></p>
  <p>Ändringar som inte skickats till CentralKollen:
    <?= $ej_skickade > 0
        ? '<strong>' . kc_h($ej_skickade) . '</strong> · <a href="' . kc_h(kc_url('synk.php')) . '">Skicka »</a>'
        : '<span class="kc-dampad">inga</span>' ?></p>
</div>

<?php if ($anvandare === null): ?>
<p><a class="kc-knapp" href="<?= kc_h(kc_url('login.php')) ?>">Logga in för att ändra</a></p>
<?php endif; ?>
<?php kc_sidfot();
