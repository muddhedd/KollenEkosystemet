<?php
/**
 * MätarKollen – install.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 *
 * Skapar och uppdaterar MätarKollens tabeller.
 * Idempotent: kan köras hur många gånger som helst.
 * Öppnas bara när config.php innehåller:  define('KOLL_INSTALL', true);
 *
 * Tabellerna definieras i mat.php (MAT_LD_TABELLER och, från etapp 2, MAT_TABELLER).
 * Etapp 1: kärnpaketets tabeller, användarkopia, synkstatus och lokala kopior av fastighetsdata.
 * Etapp 2: mätpunkter, förväntade storheter och typlistan storhet (förifylls).
 * Etapp 3: mätare, nycklar, montering, ställningar, typlistor för mätare och räknaren för mätarnummer.
 * Etapp 4: egenskapstabellerna (md_egenskap, md_egenskapsval, md_egenskapsvarde) via MAT_TABELLER.
 * Etapp 5: kolumnen placering_nummer på mätpunkter fylls i från fastighetsdatan.
 * Etapp 6a: betjäning på mätpunkter; befintliga får betjäning = placering.
 * Härdning (2026-11-18): borttagna nycklar töms på värdet.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/mat.php';

$installation_oppen = defined('KOLL_INSTALL') && KOLL_INSTALL === true;

if (kc_ar_post() && kc_post('atgard') === 'installera') {
    if (!$installation_oppen) {
        throw new KcFel('Installationen är låst i config.php.', 403);
    }
    $logg = [];

    // 1. Tabeller (DDL körs utanför transaktion)
    kc_core_tabeller_skapa();
    kc_verktyg_tabeller_skapa($logg);
    foreach (MAT_LD_TABELLER as $namn => $def) {
        kc_inst_tabell($namn, $def, $logg);
    }
    foreach (MAT_TABELLER as $namn => $def) {
        kc_inst_tabell($namn, $def, $logg);
    }
    foreach (array_keys(MAT_TYPLISTOR) as $lista) {
        kc_inst_tabell(MAT_TYPLISTOR[$lista]['tabell'], mat_typ_tabelldef($lista), $logg);
    }
    kc_inst_sys_tabell('sys_raknare', "
      namn  VARCHAR(40) NOT NULL PRIMARY KEY,
      varde BIGINT      NOT NULL DEFAULT 0", $logg);

    // 2. Förifyllda typer (bara de som saknas)
    kc_transaktion(function () use (&$logg): void {
        foreach (MAT_TYPLISTOR as $def) {
            $nya = 0;
            foreach ($def['standard'] as $i => $v) {
                $nya += kc_inst_seed($def['tabell'], 'namn', $v + [
                    'beskrivning' => '', 'sortering' => ($i + 1) * 10, 'is_active' => 1,
                ]);
            }
            if ($def['standard'] !== []) {
                $logg[] = $nya > 0 ? "{$def['rubrik']}: lade till $nya." : "{$def['rubrik']}: redan kompletta.";
            }
        }
    });

    // 3. Betjäning (etapp 6a) och nummer för placering/betjäning (etapp 5)
    $n = mat_betjaning_fyll();
    $logg[] = $n > 0 ? "Mätpunkter: satte betjäning = placering på $n." : 'Mätpunkter: alla har betjäning.';
    $n = mat_placering_nummer_uppdatera();
    $logg[] = $n > 0 ? "Mätpunkter: fyllde i $n nummer för placering/betjäning." : 'Mätpunkter: nummer för placering/betjäning redan aktuella.';

    // Säkerhet: borttagna nycklar ska inte behålla sina värden (gäller även äldre rader)
    $n = kc_kor('UPDATE `' . kc_tabell('md_matarnyckel') . "` SET varde = '' WHERE is_deleted = 1 AND varde <> ''");
    $logg[] = $n > 0 ? "Nycklar: tömde värdet på $n borttagna nycklar." : 'Nycklar: inga borttagna nycklar med kvarvarande värde.';

    $_SESSION['mat_install_logg'] = $logg;
    kc_flash('ok', 'Installationen är klar.');
    kc_omdirigera('install.php');
}

/* ---------- Visning ---------- */

$logg = $_SESSION['mat_install_logg'] ?? [];
unset($_SESSION['mat_install_logg']);

$namnlista = array_merge(
    ['sys_inloggning', 'sys_synkstatus', 'sys_raknare', 'ld_cen_anvandare'],
    array_keys(MAT_LD_TABELLER),
    array_keys(MAT_TABELLER),
    array_column(MAT_TYPLISTOR, 'tabell')
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
