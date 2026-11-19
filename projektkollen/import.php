<?php
/**
 * ProjektKollen – import.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Import av inköpta mätare med nycklar till det gemensamma mätarlagret (CSV, max 1 000 rader).
 * Utan parameter: översikt. Med tabell=<namn>: kärnpaketets importsida.
 * Mall och "Exportera för import" är öppna för alla (utan nycklar); att importera kräver inloggning.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/prj.php';
require __DIR__ . '/prj_import.php';

$tabell = kc_get('tabell');
if ($tabell !== '') {
    prj_inloggad();
    kc_import_kor(prj_import_def($tabell));
}

kc_sidhuvud('Import');
?>
<div class="kc-ruta" style="max-width:52rem">
  <h2>Så fungerar importen</h2>
  <ol>
    <li>Ladda ner mallen (eller exportera befintliga mätare för import).</li>
    <li>Fyll i filen i Excel och spara som <strong>CSV (semikolonavgränsad)</strong>.</li>
    <li>Ladda upp filen, välj mjuk eller hård import och granska förhandsvisningen.</li>
    <li>Genomför – och skicka sedan ändringarna till CentralKollen under <a href="<?= kc_h(kc_url('synk.php')) ?>">Synk</a>.</li>
  </ol>
  <p class="kc-hjalp">Mätarna hamnar i det gemensamma lagret och får projekt och mätpunkt först vid provisioneringen.
     Nycklar kan importeras men exporteras aldrig härifrån. Ingen import raderar något.</p>
</div>

<div class="kc-ruta" style="max-width:52rem">
  <h2>Importer</h2>
  <div class="kc-tabell-wrap"><table class="kc-tabell">
    <thead><tr><th>Data</th><th>Nyckel</th><th></th></tr></thead>
    <tbody>
    <?php foreach (['matare' => 'mätarId'] as $t => $nyckel): ?>
      <tr>
        <td><a href="<?= kc_h(kc_url('import.php?tabell=' . $t)) ?>"><?= kc_h(PRJ_IMPORT_TABELLER[$t]['rubrik']) ?></a></td>
        <td><code><?= kc_h($nyckel) ?></code></td>
        <td><a href="<?= kc_h(kc_url('import.php?tabell=' . $t . '&hamta=mall')) ?>">mall</a> ·
            <a href="<?= kc_h(kc_url('import.php?tabell=' . $t . '&hamta=data')) ?>">exportera för import</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php kc_sidfot();
