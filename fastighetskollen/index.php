<?php
/**
 * FastighetsKollen – index.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Startsida: översikt, öppen för alla.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/fas.php';

$anvandare = fas_inloggad();

$antal_typer = [];
foreach (FAS_TYPLISTOR as $nyckel => $def) {
    $t = kc_tabell($def['tabell']);
    $antal_typer[$nyckel] = (int)kc_hamta_varde("SELECT COUNT(*) FROM `$t` WHERE is_deleted = 0 AND is_active = 1");
}
$anv_synk = kc_synk_status('cen_anvandare', 'pull');
$ej_skickade = 0;
foreach (fas_delade_tabeller() as $namn => $def) {
    $ej_skickade += kc_push_osynkade($namn, $def['tabell']);
}
$antal = [];
foreach (['fastighet' => 'md_fastighet', 'byggnad' => 'md_byggnad', 'objekt' => 'md_objekt', 'objektgrupp' => 'md_objektgrupp'] as $nyckel => $tabell) {
    $antal[$nyckel] = (int)kc_hamta_varde('SELECT COUNT(*) FROM `' . kc_tabell($tabell) . '` WHERE is_deleted = 0');
}

kc_sidhuvud('FastighetsKollen');
?>
<div class="kc-ruta">
  <h2>Om FastighetsKollen</h2>
  <p>FastighetsKollen äger fastighetsstrukturen i ekosystemet Kollen:
     fastigheter, byggnader (även utomhusområden), objekt, adresser och objektgrupper.
     Andra verktyg – t.ex. MätarKollen – hämtar strukturen härifrån via CentralKollen.</p>
  <p>All information kan läsas av alla. För att ändra krävs inloggning med behörighet
     till FastighetsKollen, som ges i CentralKollen.</p>
</div>

<div class="kc-ruta">
  <h2>Läget just nu</h2>
  <p><a href="<?= kc_h(kc_url('fastigheter.php')) ?>">Fastigheter</a>: <strong><?= kc_h($antal['fastighet']) ?></strong>
     · <a href="<?= kc_h(kc_url('byggnader.php')) ?>">Byggnader</a>: <strong><?= kc_h($antal['byggnad']) ?></strong>
     · <a href="<?= kc_h(kc_url('objektlista.php')) ?>">Objekt</a>: <strong><?= kc_h($antal['objekt']) ?></strong>
     · <a href="<?= kc_h(kc_url('objektgrupper.php')) ?>">Objektgrupper</a>: <strong><?= kc_h($antal['objektgrupp']) ?></strong></p>
  <p>Typer:
    <?php $delar = [];
    foreach (FAS_TYPLISTOR as $nyckel => $def) {
        $delar[] = '<a href="' . kc_h(kc_url('typer.php?lista=' . $nyckel)) . '">' . kc_h($def['rubrik']) . '</a> ('
                 . kc_h($antal_typer[$nyckel]) . ')';
    }
    echo implode(' · ', $delar); ?>
  </p>
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
