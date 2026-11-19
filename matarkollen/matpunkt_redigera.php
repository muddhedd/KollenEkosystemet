<?php
/**
 * MätarKollen – matpunkt_redigera.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Skapa och ändra en mätpunkt. Kräver inloggning.
 * Mätpunkter raderas aldrig i MätarKollen – de avvecklas (status). Historik finns i andra system.
 * Placering: nivå + nummer (i lokala kopian av FastighetsKollen). Flytt är tillåten.
 * Betjäning: vad mätpunkten mäter för (nivå + nummer), förvalt "samma som placering".
 * En oförändrad placering/betjäning som inte längre finns i fastighetsdatan får ligga kvar.
 * Föregående mätpunkt anges med namn; slingor stoppas. Förväntade storheter väljs med kryssrutor
 * (borttagen storhet återaktiveras med samma uuid om den väljs igen).
 * Parameter: uuid (befintlig), annars ny.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/mat.php';

mat_krav_inloggning();
$tm = kc_tabell('md_matpunkt');
$tk = kc_tabell('md_matpunkt_storhet');

$uuid = kc_get('uuid');
$ny   = $uuid === '';
$rad  = null;
if (!$ny) {
    $rad = mat_matpunkt_hamta($uuid);
    if ($rad === null) {
        throw new KcFel('Mätpunkten finns inte.', 404);
    }
}

// Formulärvärden (placering och föregående visas som nummer/namn)
$nuvarande_storheter = $ny ? [] : array_column(mat_matpunkt_storheter($uuid), 'uuid');
$varden = $ny
    ? ['matpunktsnamn' => '', 'typ' => 'fysisk', 'status' => 'projekt', 'niva' => 'objekt', 'placering_nummer' => '',
       'betjaning_samma' => '1', 'betjaning_niva' => 'objekt', 'betjaning_nummer' => '',
       'platsbeskrivning' => '', 'foregaende_namn' => '']
    : [
        'matpunktsnamn'    => $rad['matpunktsnamn'],
        'typ'              => $rad['typ'],
        'status'           => $rad['status'],
        'niva'             => $rad['niva'],
        'placering_nummer' => mat_placering_info($rad['niva'], $rad['placering_uuid'])['nummer'],
        'betjaning_samma'  => $rad['betjaning_niva'] === $rad['niva'] && $rad['betjaning_uuid'] === $rad['placering_uuid'] ? '1' : '',
        'betjaning_niva'   => $rad['betjaning_niva'] !== '' ? $rad['betjaning_niva'] : $rad['niva'],
        'betjaning_nummer' => mat_placering_info($rad['betjaning_niva'], $rad['betjaning_uuid'])['nummer'] ?: $rad['betjaning_nummer'],
        'platsbeskrivning' => $rad['platsbeskrivning'],
        'foregaende_namn'  => $rad['foregaende_uuid'] !== '' ? (string)(mat_matpunkt_hamta($rad['foregaende_uuid'])['matpunktsnamn'] ?? '') : '',
    ];
$valda_storheter = $nuvarande_storheter;
$fel = [];

/* ---------- Spara ---------- */

if (kc_ar_post() && kc_post('atgard') === 'spara') {
    foreach (array_keys($varden) as $k) {
        $varden[$k] = kc_post($k);
    }
    $in = $_POST['storheter'] ?? [];
    $valda_storheter = is_array($in) ? array_values(array_unique(array_filter($in, static fn($v) => is_string($v) && kc_ar_uuid($v)))) : [];

    if (!mat_matpunktsnamn_ok($varden['matpunktsnamn'])) {
        $fel[] = 'Mätpunktsnamnet måste vara 1–100 tecken: bokstäver, siffror och - _ . / (inga mellanslag).';
    } else {
        $annan = mat_matpunkt_via_namn($varden['matpunktsnamn']);
        if ($annan !== null && $annan['uuid'] !== $uuid) {
            $fel[] = 'Mätpunktsnamnet används redan av en annan mätpunkt.';
        }
    }
    if (!array_key_exists($varden['typ'], MAT_MATPUNKT_TYPER)) {
        $fel[] = 'Välj typ.';
    }
    if (!array_key_exists($varden['status'], MAT_MATPUNKT_STATUS)) {
        $fel[] = 'Välj status.';
    }
    // Placering och betjäning (oförändrad koppling som saknas i fastighetsdatan får ligga kvar)
    [$placering_uuid, $f] = mat_fas_koppling_tolka($varden['niva'], $varden['placering_nummer'], 'placeringen',
                                                   $ny ? '' : $rad['niva'], $ny ? '' : $rad['placering_uuid']);
    if ($f !== null) {
        $fel[] = $f;
    }
    if ($varden['betjaning_samma'] === '1') {
        $varden['betjaning_niva'] = $varden['niva'];
        $varden['betjaning_nummer'] = $varden['placering_nummer'];
        $betjaning_uuid = $placering_uuid;
    } else {
        [$betjaning_uuid, $f] = mat_fas_koppling_tolka($varden['betjaning_niva'], $varden['betjaning_nummer'], 'betjäningen',
                                                       $ny ? '' : $rad['betjaning_niva'], $ny ? '' : $rad['betjaning_uuid']);
        if ($f !== null) {
            $fel[] = $f;
        }
    }
    if (mb_strlen($varden['platsbeskrivning']) > 255) {
        $fel[] = 'Platsbeskrivningen är för lång (max 255 tecken).';
    }
    $foregaende_uuid = '';
    if ($varden['foregaende_namn'] !== '') {
        $f = mat_matpunkt_via_namn($varden['foregaende_namn']);
        if ($f === null) {
            $fel[] = 'Föregående mätpunkt ' . $varden['foregaende_namn'] . ' finns inte.';
        } elseif (!$ny && $f['uuid'] === $uuid) {
            $fel[] = 'En mätpunkt kan inte vara sin egen föregående.';
        } elseif (!$ny && mat_ger_slinga($uuid, $f['uuid'])) {
            $fel[] = 'Föregående mätpunkt skulle ge en slinga (den ligger redan efter den här mätpunkten i flödet).';
        } else {
            $foregaende_uuid = $f['uuid'];
        }
    }
    if (!$ny && $varden['typ'] === 'virtuell' && mat_aktiv_montering('matpunkt_uuid', $uuid) !== null) {
        $fel[] = 'Mätpunkten har en monterad mätare och kan inte vara virtuell. Demontera mätaren först.';
    }
    $tillgangliga = mat_typer_aktiva('storhet');
    foreach ($valda_storheter as $s) {
        if (!isset($tillgangliga[$s]) && !in_array($s, $nuvarande_storheter, true)) {
            $fel[] = 'Ogiltig storhet vald.';
            break;
        }
    }

    if ($fel === []) {
        $spara = [
            'matpunktsnamn' => $varden['matpunktsnamn'], 'typ' => $varden['typ'], 'status' => $varden['status'],
            'niva' => $varden['niva'], 'placering_uuid' => $placering_uuid,
            // Numren följer med till andra verktyg; saknas kopplingen behålls det senast kända
            'placering_nummer' => mat_fas_koppling_nummer($varden['niva'], $placering_uuid, $varden['placering_nummer'],
                                                          $ny ? '' : $rad['placering_uuid'], $ny ? '' : (string)$rad['placering_nummer']),
            'betjaning_niva'   => $varden['betjaning_niva'], 'betjaning_uuid' => $betjaning_uuid,
            'betjaning_nummer' => mat_fas_koppling_nummer($varden['betjaning_niva'], $betjaning_uuid, $varden['betjaning_nummer'],
                                                          $ny ? '' : $rad['betjaning_uuid'], $ny ? '' : (string)$rad['betjaning_nummer']),
            'platsbeskrivning' => $varden['platsbeskrivning'], 'foregaende_uuid' => $foregaende_uuid,
        ];
        $andrat = false;
        kc_transaktion(function () use ($ny, &$uuid, $rad, $spara, $tm, $tk, $valda_storheter, $nuvarande_storheter, &$andrat): void {
            $nu = kc_nu();
            if ($ny) {
                $uuid = kc_uuid();
                kc_kor(
                    "INSERT INTO `$tm` (uuid, last_changed, is_deleted, source_tool, `" . implode('`, `', array_keys($spara)) . "`)
                     VALUES (?, ?, 0, 'mat'" . str_repeat(', ?', count($spara)) . ')',
                    array_merge([$uuid, $nu], array_values($spara))
                );
                $andrat = true;
            } else {
                foreach ($spara as $k => $v) {
                    if ((string)$rad[$k] !== (string)$v) {
                        $andrat = true;
                    }
                }
                if ($andrat) {
                    $set = implode(', ', array_map(static fn($k) => "`$k` = ?", array_keys($spara)));
                    kc_kor("UPDATE `$tm` SET $set, last_changed = ?, source_tool = 'mat' WHERE uuid = ? AND is_deleted = 0",
                           array_merge(array_values($spara), [$nu, $uuid]));
                }
            }
            // Förväntade storheter: lägg till / återaktivera valda, ta bort (mjukt) bortvalda
            foreach (array_diff($valda_storheter, $nuvarande_storheter) as $s) {
                $finns = kc_hamta_en("SELECT uuid FROM `$tk` WHERE matpunkt_uuid = ? AND storhet_uuid = ? ORDER BY is_deleted ASC, id ASC LIMIT 1",
                                     [$uuid, $s]);
                if ($finns !== null) {
                    kc_kor("UPDATE `$tk` SET is_deleted = 0, last_changed = ?, source_tool = 'mat' WHERE uuid = ?", [$nu, $finns['uuid']]);
                } else {
                    kc_kor("INSERT INTO `$tk` (uuid, last_changed, is_deleted, source_tool, matpunkt_uuid, storhet_uuid) VALUES (?, ?, 0, 'mat', ?, ?)",
                           [kc_uuid(), $nu, $uuid, $s]);
                }
                $andrat = true;
            }
            foreach (array_diff($nuvarande_storheter, $valda_storheter) as $s) {
                kc_kor("UPDATE `$tk` SET is_deleted = 1, last_changed = ?, source_tool = 'mat'
                         WHERE matpunkt_uuid = ? AND storhet_uuid = ? AND is_deleted = 0", [$nu, $uuid, $s]);
                $andrat = true;
            }
        });
        kc_flash('ok', $ny ? 'Mätpunkten är skapad.' : ($andrat ? 'Ändringarna är sparade.' : 'Inget var ändrat.'));
        kc_omdirigera('matpunkt.php?uuid=' . rawurlencode($uuid));
    }
}

/* ---------- Visning ---------- */

$storhetsval = [];
foreach (mat_typer_aktiva('storhet') as $u => $s) {
    $storhetsval[$u] = mat_storhet_text($s);
}
foreach ($nuvarande_storheter as $u) {   // inaktiva som redan är valda
    if (!isset($storhetsval[$u]) && ($s = mat_typ_hamta('storhet', $u)) !== null) {
        $storhetsval[$u] = mat_storhet_text($s) . ' (inaktiv)';
    }
}
$nivaval = array_map(static fn($d) => $d['etikett'], MAT_PLACERING);
$tillbaka = $ny ? 'matpunkter.php' : 'matpunkt.php?uuid=' . rawurlencode($uuid);

kc_sidhuvud($ny ? 'Ny mätpunkt' : 'Ändra mätpunkt: ' . $rad['matpunktsnamn']);
?>
<p><a href="<?= kc_h(kc_url($tillbaka)) ?>">« Tillbaka</a></p>

<?php if ($fel !== []): ?>
<div class="kc-flash kc-flash-fel">
  <strong>Kunde inte spara:</strong>
  <ul style="margin:.25rem 0 0"><?php foreach ($fel as $f): ?><li><?= kc_h($f) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="post">
  <?= kc_csrf_falt() ?>
  <input type="hidden" name="atgard" value="spara">
  <div class="kc-ruta" style="max-width:44rem">
    <h2>Mätpunkt</h2>
    <div class="kc-falt">
      <label for="matpunktsnamn">Mätpunktsnamn *</label>
      <input type="text" id="matpunktsnamn" name="matpunktsnamn" maxlength="100" required style="width:100%"
             value="<?= kc_h($varden['matpunktsnamn']) ?>" placeholder="t.ex. 1301-01-0001_Temp_22">
      <div class="kc-hjalp">Unikt i hela ekosystemet. Bokstäver, siffror och - _ . / – inga mellanslag.</div>
    </div>
    <div class="kc-falt">
      <label for="typ">Typ</label>
      <select id="typ" name="typ"><?= mat_alternativ(MAT_MATPUNKT_TYPER, $varden['typ']) ?></select>
      <label for="status" style="display:inline;margin-left:1rem">Status</label>
      <select id="status" name="status"><?= mat_alternativ(MAT_MATPUNKT_STATUS, $varden['status']) ?></select>
      <div class="kc-hjalp">Virtuell = inga fysiska mätare, värdena räknas fram. Mätpunkter raderas inte – sätt status Avvecklad.</div>
    </div>
  </div>

  <div class="kc-ruta" style="max-width:44rem">
    <h2>Placering</h2>
    <div class="kc-falt">
      <label for="niva">Nivå *</label>
      <select id="niva" name="niva"><?= mat_alternativ($nivaval, $varden['niva']) ?></select>
      <label for="placering_nummer" style="display:inline;margin-left:1rem">Nummer *</label>
      <input type="text" id="placering_nummer" name="placering_nummer" maxlength="50" style="width:12rem"
             value="<?= kc_h($varden['placering_nummer']) ?>">
      <div class="kc-hjalp">Objekt-, byggnads-, fastighets- eller objektgruppsnummer från FastighetsKollen.
        <a href="<?= kc_h(kc_url('fastighetsdata.php')) ?>" target="_blank" rel="noopener">Sök i fastighetsdata »</a></div>
    </div>
    <div class="kc-falt">
      <label for="platsbeskrivning">Plats</label>
      <input type="text" id="platsbeskrivning" name="platsbeskrivning" maxlength="255" style="width:100%"
             value="<?= kc_h($varden['platsbeskrivning']) ?>" placeholder="t.ex. vardagsrum, innervägg, 1,5 m">
      <div class="kc-hjalp">Placeringen är var mätaren sitter fysiskt.</div>
    </div>
  </div>

  <div class="kc-ruta" style="max-width:44rem">
    <h2>Betjäning</h2>
    <div class="kc-falt">
      <label><input type="checkbox" id="betjaning_samma" name="betjaning_samma" value="1"
             <?= $varden['betjaning_samma'] === '1' ? 'checked' : '' ?>> Samma som placering</label>
      <div class="kc-hjalp">Betjäningen är det mätpunkten mäter för – det som mätvärden och förbrukning knyts till.
        T.ex. sitter huvudmätaren i undercentralen men betjänar hela byggnaden.</div>
    </div>
    <div class="kc-falt" id="mat_betjaning_falt">
      <label for="betjaning_niva">Nivå *</label>
      <select id="betjaning_niva" name="betjaning_niva"><?= mat_alternativ($nivaval, $varden['betjaning_niva']) ?></select>
      <label for="betjaning_nummer" style="display:inline;margin-left:1rem">Nummer *</label>
      <input type="text" id="betjaning_nummer" name="betjaning_nummer" maxlength="50" style="width:12rem"
             value="<?= kc_h($varden['betjaning_nummer']) ?>">
    </div>
    <script>
      (function () {
        var k = document.getElementById('betjaning_samma'), f = document.getElementById('mat_betjaning_falt');
        var visa = function () { f.style.display = k.checked ? 'none' : ''; };
        k.addEventListener('change', visa); visa();
      })();
    </script>
  </div>

  <div class="kc-ruta" style="max-width:44rem">
    <h2>Förväntade storheter</h2>
    <?php if ($storhetsval === []): ?>
      <p><em class="kc-dampad">Inga storheter finns. Lägg till dem under Typer.</em></p>
    <?php else: ?>
      <div class="kc-falt">
        <?php foreach ($storhetsval as $u => $text): ?>
          <label style="display:inline-block;min-width:14rem"><input type="checkbox" name="storheter[]" value="<?= kc_h($u) ?>"
                 <?= in_array($u, $valda_storheter, true) ? 'checked' : '' ?>> <?= kc_h($text) ?></label>
        <?php endforeach; ?>
      </div>
      <p class="kc-hjalp">Det vi avser att mäta här. Vad som faktiskt kommer in visas när MätvärdesKollen finns.</p>
    <?php endif; ?>
  </div>

  <div class="kc-ruta" style="max-width:44rem">
    <h2>Flöde</h2>
    <div class="kc-falt">
      <label for="foregaende_namn">Föregående mätpunkt</label>
      <input type="text" id="foregaende_namn" name="foregaende_namn" maxlength="100" style="width:100%"
             value="<?= kc_h($varden['foregaende_namn']) ?>" placeholder="mätpunktsnamn, lämna tomt om ingen">
      <div class="kc-hjalp">T.ex. huvudmätaren som vattnet passerar innan det når den här mätpunkten.
        Efterföljande mätpunkter räknas fram automatiskt.</div>
    </div>
    <button type="submit" class="kc-knapp-primar"><?= $ny ? 'Skapa mätpunkt' : 'Spara' ?></button>
  </div>
</form>
<?php kc_sidfot();
