<?php
/**
 * FastighetsKollen – install.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 *
 * Skapar och uppdaterar FastighetsKollens tabeller och förifyller typlistorna.
 * Idempotent: kan köras hur många gånger som helst.
 * Öppnas bara när config.php innehåller:  define('KOLL_INSTALL', true);
 *
 * Tabellerna definieras i fas.php (FAS_TYPLISTOR och FAS_TABELLER).
 * Etapp 1: kärnpaketets tabeller, användarkopia, synkstatus och typlistor.
 * Etapp 2: fastigheter och adresser.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/fas.php';

$installation_oppen = defined('KOLL_INSTALL') && KOLL_INSTALL === true;

if (kc_ar_post() && kc_post('atgard') === 'installera') {
    if (!$installation_oppen) {
        throw new KcFel('Installationen är låst i config.php.', 403);
    }
    $logg = [];

    // 1. Tabeller (DDL körs utanför transaktion)
    kc_core_tabeller_skapa();
    kc_verktyg_tabeller_skapa($logg);
    foreach (FAS_TYPLISTOR as $def) {
        kc_inst_tabell($def['tabell'], ['kolumner' => FAS_TYP_KOLUMNER, 'index' => ['namn']], $logg);
    }
    foreach (FAS_TABELLER as $namn => $def) {
        kc_inst_tabell($namn, $def, $logg);
    }

    // 2. Förifyllda typer (bara de som saknas)
    kc_transaktion(function () use (&$logg): void {
        foreach (FAS_TYPLISTOR as $def) {
            $nya = 0;
            foreach ($def['standard'] as $i => $namn) {
                $nya += kc_inst_seed($def['tabell'], 'namn', [
                    'namn' => $namn, 'beskrivning' => '', 'sortering' => ($i + 1) * 10, 'is_active' => 1,
                ]);
            }
            $logg[] = $nya > 0 ? "{$def['rubrik']}: lade till $nya." : "{$def['rubrik']}: redan kompletta.";
        }
    });

    $_SESSION['fas_install_logg'] = $logg;
    kc_flash('ok', 'Installationen är klar.');
    kc_omdirigera('install.php');
}

/* ---------- Visning ---------- */

$logg = $_SESSION['fas_install_logg'] ?? [];
unset($_SESSION['fas_install_logg']);

$namnlista = array_merge(
    ['sys_inloggning', 'sys_synkstatus', 'ld_cen_anvandare'],
    array_column(FAS_TYPLISTOR, 'tabell'),
    array_keys(FAS_TABELLER)
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
