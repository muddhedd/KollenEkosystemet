<?php
/**
 * FastighetsKollen – import.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Import av fastigheter, byggnader, objekt, adresser, objektgrupper, kopplingar och typlistor (CSV).
 * Utan parameter: översikt över importerna. Med tabell=<namn>: kärnpaketets importsida.
 * Mall och "Exportera för import" är öppna för alla; att importera kräver inloggning.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/fas.php';
require __DIR__ . '/fas_import.php';

$tabell = kc_get('tabell');
if ($tabell !== '') {
    kc_import_kor(fas_import_def($tabell));
}

kc_sidhuvud('Import');
?>
<div class="kc-ruta" style="max-width:52rem">
  <h2>Så fungerar importen</h2>
  <ol>
    <li>Välj vad du vill importera och ladda ner mallen (eller exportera befintliga data för import).</li>
    <li>Fyll i filen i Excel och spara som <strong>CSV (semikolonavgränsad)</strong>.</li>
    <li>Ladda upp filen, välj mjuk eller hård import och granska förhandsvisningen.</li>
    <li>Genomför – och skicka sedan ändringarna till CentralKollen under <a href="<?= kc_h(kc_url('synk.php')) ?>">Synk</a>.</li>
  </ol>
  <p class="kc-hjalp">Importera i ordningen typer → fastigheter → byggnader → adresser → objekt → objektgrupper → kopplingar,
     eftersom varje steg pekar på det föregående. Max <?= kc_h(KC_IMPORT_MAX_RADER) ?> rader per fil. Ingen import raderar något.</p>
</div>

<div class="kc-ruta" style="max-width:52rem">
  <h2>Importer</h2>
  <div class="kc-tabell-wrap"><table class="kc-tabell">
    <thead><tr><th>Data</th><th>Nyckel</th><th></th></tr></thead>
    <tbody>
    <?php foreach ([
        'fastighet'   => 'fastighetsnummer',
        'byggnad'     => 'byggnadsnummer',
        'adress'      => 'ägare + gatuadress',
        'objekt'      => 'objektnummer',
        'objektgrupp' => 'objektgruppsnummer',
        'koppling'    => 'objektgrupp + mål + kopplingstyp',
        'byggnadstyp'     => 'namn',
        'objekttyp'       => 'namn',
        'objektgruppstyp' => 'namn',
        'koktyp'          => 'namn',
    ] as $t => $nyckel): ?>
      <tr>
        <td><a href="<?= kc_h(kc_url('import.php?tabell=' . $t)) ?>"><?= kc_h(FAS_IMPORT_TABELLER[$t]['rubrik']) ?></a></td>
        <td><code><?= kc_h($nyckel) ?></code> (eller uuid)</td>
        <td><a href="<?= kc_h(kc_url('import.php?tabell=' . $t . '&hamta=mall')) ?>">mall</a> ·
            <a href="<?= kc_h(kc_url('import.php?tabell=' . $t . '&hamta=data')) ?>">exportera för import</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php kc_sidfot();
