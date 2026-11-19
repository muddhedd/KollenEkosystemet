<?php
/**
 * ProjektKollen – install.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 *
 * Skapar och uppdaterar ProjektKollens tabeller.
 * Idempotent: kan köras hur många gånger som helst.
 * Öppnas bara när config.php innehåller:  define('KOLL_INSTALL', true);
 *
 * Tabellerna definieras i prj.php (PRJ_LD_TABELLER, PRJ_TABELLER, PRJ_TYPLISTOR).
 * Etapp 1: kärnpaketets tabeller, användarkopia, synkstatus, lokala kopior av fastighets- och
 * mätardata, projekt, typlistorna funktion och projekttyp (förifylls) och räknaren för projektnummer.
 * Etapp 2: omfattning, objektlista och planerade mätpunkter (md_omfattning, md_projektobjekt, md_matpunkt).
 * Etapp 3: kolumnen etikett_tid i md_matpunkt (läggs till i befintlig tabell).
 * Etapp 4: mätarlagret (md_matare) och nycklarna (md_matarnyckel, delas aldrig).
 * Etapp 5a: kolumnerna monterad_datum och monterad_av i md_matpunkt (används från etapp 5b).
 * Etapp 6: exportmallar, kolumner, exportrader och exportlogg (prj_export.php) samt den låsta mallen
 *          "MätarKollen – Mätpunkter och mätare".
 * Härdning (2026-11-18): borttagna nycklar töms på värdet.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/prj.php';
require __DIR__ . '/prj_export.php';

$installation_oppen = defined('KOLL_INSTALL') && KOLL_INSTALL === true;

if (kc_ar_post() && kc_post('atgard') === 'installera') {
    if (!$installation_oppen) {
        throw new KcFel('Installationen är låst i config.php.', 403);
    }
    $logg = [];

    // 1. Tabeller (DDL körs utanför transaktion)
    kc_core_tabeller_skapa();
    kc_verktyg_tabeller_skapa($logg);
    foreach (PRJ_LD_TABELLER as $namn => $def) {
        kc_inst_tabell($namn, $def, $logg);
    }
    foreach (PRJ_TABELLER as $namn => $def) {
        kc_inst_tabell($namn, $def, $logg);
    }
    foreach (array_keys(PRJ_TYPLISTOR) as $lista) {
        kc_inst_tabell(PRJ_TYPLISTOR[$lista]['tabell'], prj_typ_tabelldef($lista), $logg);
    }
    kc_inst_sys_tabell('sys_raknare', "
      namn  VARCHAR(40) NOT NULL PRIMARY KEY,
      varde BIGINT      NOT NULL DEFAULT 0", $logg);
    prj_export_installera($logg);

    // 2. Förifyllda typer (bara de som saknas). Projekttypens funktion slås upp på namn.
    kc_transaktion(function () use (&$logg): void {
        foreach (PRJ_TYPLISTOR as $lista => $def) {
            $nya = 0;
            foreach ($def['standard'] as $i => $v) {
                if (isset($v['funktion'])) {
                    $tf = kc_tabell('md_funktion');
                    $v['funktion_uuid'] = (string)(kc_hamta_varde("SELECT uuid FROM `$tf` WHERE namn = ? AND is_deleted = 0 LIMIT 1", [$v['funktion']]) ?? '');
                    unset($v['funktion']);
                }
                $nya += kc_inst_seed($def['tabell'], 'namn', $v + [
                    'beskrivning' => '', 'sortering' => ($i + 1) * 10, 'is_active' => 1,
                ]);
            }
            $logg[] = $nya > 0 ? "{$def['rubrik']}: lade till $nya." : "{$def['rubrik']}: redan kompletta.";
        }
        prj_export_seed($logg);
    });

    // Säkerhet: borttagna nycklar ska inte behålla sina värden (gäller även äldre rader)
    $n = kc_kor('UPDATE `' . kc_tabell('md_matarnyckel') . "` SET varde = '' WHERE is_deleted = 1 AND varde <> ''");
    $logg[] = $n > 0 ? "Nycklar: tömde värdet på $n borttagna nycklar." : 'Nycklar: inga borttagna nycklar med kvarvarande värde.';

    $_SESSION['prj_install_logg'] = $logg;
    kc_flash('ok', 'Installationen är klar.');
    kc_omdirigera('install.php');
}

/* ---------- Visning ---------- */

$logg = $_SESSION['prj_install_logg'] ?? [];
unset($_SESSION['prj_install_logg']);

$namnlista = array_merge(
    ['sys_inloggning', 'sys_synkstatus', 'sys_raknare', 'ld_cen_anvandare'],
    array_keys(PRJ_LD_TABELLER),
    array_keys(PRJ_TABELLER),
    array_column(PRJ_TYPLISTOR, 'tabell')
);
$status = [];
foreach ($namnlista as $namn) {
    $t = kc_tabell($namn);
    $finns = kc_inst_tabell_finns($t);
    $status[] = ['tabell' => $t, 'finns' => $finns, 'rader' => $finns ? (int)kc_hamta_varde("SELECT COUNT(*) FROM `$t`") : null];
}

kc_sidhuvud('Installation');
?>
<?php if (!$installation_oppen): ?>
<div class="kc-ruta">
  <h2>Installationen är låst</h2>
  <p>För att köra installationen, ändra i <code>config.php</code> till:</p>
  <p><code>define('KOLL_INSTALL', true);</code></p>
  <p class="kc-dampad">Sätt tillbaka till <code>false</code> när du är klar.</p>
</div>
<?php else: ?>

<?php if ($logg !== []): ?>
<div class="kc-ruta">
  <h2>Resultat</h2>
  <ul>
    <?php foreach ($logg as $rad): ?><li><?= kc_h($rad) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="kc-ruta">
  <h2>Status</h2>
  <p>Variant: <code><?= kc_h(KOLL_VARIANT) ?></code> · Databas: <code><?= kc_h(DB_NAMN) ?></code> ·
     Kärna: <code><?= kc_h(KC_VERSION) ?></code></p>
  <div class="kc-tabell-wrap">
    <table class="kc-tabell">
      <thead><tr><th>Tabell</th><th>Finns</th><th>Rader</th></tr></thead>
      <tbody>
      <?php foreach ($status as $s): ?>
        <tr>
          <td><code><?= kc_h($s['tabell']) ?></code></td>
          <td><?= $s['finns'] ? 'Ja' : 'Nej' ?></td>
          <td><?= $s['rader'] === null ? '–' : kc_h($s['rader']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="kc-ruta">
  <h2>Installera / uppdatera</h2>
  <p>Skapar saknade tabeller och kolumner och fyller på typlistorna. Befintlig data påverkas inte.
     Användare hämtas från CentralKollen – det finns inget lokalt administratörskonto.</p>
  <form method="post">
    <?= kc_csrf_falt() ?>
    <input type="hidden" name="atgard" value="installera">
    <button type="submit" class="kc-knapp-primar">Kör installationen</button>
  </form>
</div>
<?php endif; ?>
<?php kc_sidfot();
