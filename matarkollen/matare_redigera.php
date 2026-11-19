<?php
/**
 * MätarKollen – matare_redigera.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Skapa och ändra en mätare, med nycklar. Kräver inloggning.
 * - Mätarnumret (M000001 …) sätts av systemet och kan inte ändras.
 * - Samma mätarId på samma gateway ger en varning, inte ett fel.
 * - Nycklar: ett fält per nyckeltyp; tomt fält tar bort nyckeln (återaktiveras med samma uuid).
 * - Radering: bara för felregistrerade mätare som aldrig monterats, och bara efter att
 *   mätarnumret skrivits in och en kryssruta bockats i.
 * Parameter: uuid (befintlig), annars ny.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/mat.php';

mat_krav_inloggning();
$tm = kc_tabell('md_matare');
$tn = kc_tabell('md_matarnyckel');

$uuid = kc_get('uuid');
$ny   = $uuid === '';
$rad  = null;
if (!$ny) {
    $rad = mat_matare_hamta($uuid);
    if ($rad === null) {
        throw new KcFel('Mätaren finns inte.', 404);
    }
}
$falt = ['matar_id', 'matartyp_uuid', 'fabrikat_uuid', 'matarmodell_uuid', 'kommunikation_uuid', 'gateway', 'kommentar'];
$varden = $ny ? array_fill_keys($falt, '') : array_intersect_key($rad, array_flip($falt));
$befintliga_nycklar = $ny ? [] : array_map(static fn($n) => $n['varde'], mat_nycklar($uuid));
$nycklar = $befintliga_nycklar;
$antal_monteringar = $ny ? 0 : count(mat_monteringar('matare_uuid', $uuid));
$fel = [];
$radera_fel = '';

/* ---------- Radera (felregistrering) ---------- */

if (kc_ar_post() && kc_post('atgard') === 'radera' && !$ny) {
    if ($antal_monteringar > 0) {
        $radera_fel = 'Mätaren har monteringar och kan inte raderas. Ta först bort monteringarna om de också är felregistrerade.';
    } elseif (kc_post('bekrafta_nummer') !== $rad['matarnummer'] || kc_post('bekrafta') !== '1') {
        $radera_fel = 'Skriv in mätarnumret ' . $rad['matarnummer'] . ' och bocka i rutan för att radera.';
    } else {
        kc_transaktion(function () use ($uuid, $tm, $tn): void {
            $nu = kc_nu();
            kc_kor("UPDATE `$tn` SET varde = '', is_deleted = 1, last_changed = ?, source_tool = 'mat' WHERE matare_uuid = ? AND is_deleted = 0", [$nu, $uuid]);
            kc_egenskapsvarden_radera($uuid, $nu);
            kc_kor("UPDATE `$tm` SET is_deleted = 1, last_changed = ?, source_tool = 'mat' WHERE uuid = ? AND is_deleted = 0", [$nu, $uuid]);
        });
        kc_flash('ok', 'Mätaren ' . $rad['matarnummer'] . ' är raderad.');
        kc_omdirigera('matarlista.php');
    }
}

/* ---------- Spara ---------- */

if (kc_ar_post() && kc_post('atgard') === 'spara') {
    foreach ($falt as $k) {
        $varden[$k] = kc_post($k);
    }
    $in = $_POST['nyckel'] ?? [];
    $in = is_array($in) ? $in : [];

    if (mb_strlen($varden['matar_id']) > 100) {
        $fel[] = 'MätarId får vara max 100 tecken.';
    }
    if (mb_strlen($varden['gateway']) > 100) {
        $fel[] = 'Gateway får vara max 100 tecken.';
    }
    if (mb_strlen($varden['kommentar']) > 255) {
        $fel[] = 'Kommentaren är för lång (max 255 tecken).';
    }
    // Typlistor: tomt är tillåtet; ett valt värde måste finnas och vara aktivt (eller oförändrat)
    foreach (['matartyp' => 'mätartyp', 'fabrikat' => 'fabrikat', 'matarmodell' => 'modell', 'kommunikation' => 'kommunikation'] as $lista => $etikett) {
        $k = $lista . '_uuid';
        if ($varden[$k] === '') {
            continue;
        }
        $t = mat_typ_hamta($lista, $varden[$k]);
        if ($t === null || ((int)$t['is_active'] !== 1 && ($ny || $varden[$k] !== $rad[$k]))) {
            $fel[] = 'Ogiltig ' . $etikett . '.';
        }
    }
    // Modellen bestämmer fabrikatet
    if ($varden['matarmodell_uuid'] !== '' && ($mo = mat_typ_hamta('matarmodell', $varden['matarmodell_uuid'])) !== null) {
        if ($varden['fabrikat_uuid'] === '') {
            $varden['fabrikat_uuid'] = $mo['fabrikat_uuid'];
        } elseif ($varden['fabrikat_uuid'] !== $mo['fabrikat_uuid']) {
            $fel[] = 'Modellen hör inte till det valda fabrikatet.';
        }
    }
    // Nycklar: en per nyckeltyp
    $nycklar = [];
    foreach ($in as $typ_uuid => $v) {
        if (!is_string($typ_uuid) || !is_string($v) || !kc_ar_uuid($typ_uuid)) {
            continue;
        }
        $v = trim($v);
        if ($v === '' && !isset($befintliga_nycklar[$typ_uuid])) {
            continue;
        }
        $nt = mat_typ_hamta('nyckeltyp', $typ_uuid);
        if ($nt === null || ((int)$nt['is_active'] !== 1 && !isset($befintliga_nycklar[$typ_uuid]))) {
            $fel[] = 'Ogiltig nyckeltyp.';
            continue;
        }
        if (mb_strlen($v) > 255) {
            $fel[] = 'Nyckeln för ' . $nt['namn'] . ' är för lång (max 255 tecken).';
        }
        $nycklar[$typ_uuid] = $v;
    }

    if ($fel === []) {
        $andrat = false;
        $nummer = $ny ? '' : $rad['matarnummer'];
        kc_transaktion(function () use ($ny, &$uuid, &$nummer, $rad, $varden, $nycklar, $befintliga_nycklar, $tm, $tn, &$andrat): void {
            $nu = kc_nu();
            if ($ny) {
                $uuid = kc_uuid();
                $nummer = mat_nasta_matarnummer();
                kc_kor(
                    "INSERT INTO `$tm` (uuid, last_changed, is_deleted, source_tool, matarnummer, `" . implode('`, `', array_keys($varden)) . "`)
                     VALUES (?, ?, 0, 'mat', ?" . str_repeat(', ?', count($varden)) . ')',
                    array_merge([$uuid, $nu, $nummer], array_values($varden))
                );
                $andrat = true;
            } else {
                foreach ($varden as $k => $v) {
                    $andrat = $andrat || (string)$rad[$k] !== $v;
                }
                if ($andrat) {
                    $set = implode(', ', array_map(static fn($k) => "`$k` = ?", array_keys($varden)));
                    kc_kor("UPDATE `$tm` SET $set, last_changed = ?, source_tool = 'mat' WHERE uuid = ? AND is_deleted = 0",
                           array_merge(array_values($varden), [$nu, $uuid]));
                }
            }
            foreach ($nycklar as $typ_uuid => $v) {
                if (($befintliga_nycklar[$typ_uuid] ?? null) === $v) {
                    continue;
                }
                $r = kc_hamta_en("SELECT uuid FROM `$tn` WHERE matare_uuid = ? AND nyckeltyp_uuid = ? ORDER BY is_deleted ASC, id ASC LIMIT 1",
                                 [$uuid, $typ_uuid]);
                if ($v === '') {
                    kc_kor("UPDATE `$tn` SET varde = '', is_deleted = 1, last_changed = ?, source_tool = 'mat' WHERE matare_uuid = ? AND nyckeltyp_uuid = ? AND is_deleted = 0",
                           [$nu, $uuid, $typ_uuid]);
                } elseif ($r !== null) {
                    kc_kor("UPDATE `$tn` SET varde = ?, is_deleted = 0, last_changed = ?, source_tool = 'mat' WHERE uuid = ?", [$v, $nu, $r['uuid']]);
                } else {
                    kc_kor("INSERT INTO `$tn` (uuid, last_changed, is_deleted, source_tool, matare_uuid, nyckeltyp_uuid, varde) VALUES (?, ?, 0, 'mat', ?, ?, ?)",
                           [kc_uuid(), $nu, $uuid, $typ_uuid, $v]);
                }
                $andrat = true;
            }
        });
        kc_flash('ok', $ny ? "Mätaren $nummer är skapad." : ($andrat ? 'Ändringarna är sparade.' : 'Inget var ändrat.'));
        if (mat_matar_id_dubbletter($varden['matar_id'], $varden['gateway'], $uuid) !== []) {
            kc_flash('info', 'Obs: en annan mätare har samma mätarId på samma gateway.');
        }
        kc_omdirigera('matare.php?uuid=' . rawurlencode($uuid));
    }
}

/* ---------- Visning ---------- */

/** Val för en typlista: aktiva + nuvarande inaktiva. */
$val = static function (string $lista, string $nuvarande): array {
    $ut = array_map(static fn($r) => $r['namn'], mat_typer_aktiva($lista));
    if ($nuvarande !== '' && !isset($ut[$nuvarande]) && ($r = mat_typ_hamta($lista, $nuvarande)) !== null) {
        $ut[$nuvarande] = $r['namn'] . ' (inaktiv)';
    }
    return $ut;
};
// Modeller visas som "Fabrikat – Modell"
$fabrikatnamn = array_map(static fn($r) => $r['namn'], mat_typer_aktiva('fabrikat'));
$modellval = [];
foreach ($val('matarmodell', (string)$varden['matarmodell_uuid']) as $u => $namn) {
    $mo = mat_typ_hamta('matarmodell', $u);
    $fa = $fabrikatnamn[$mo['fabrikat_uuid'] ?? ''] ?? (mat_typ_hamta('fabrikat', (string)($mo['fabrikat_uuid'] ?? ''))['namn'] ?? '?');
    $modellval[$u] = $fa . ' – ' . $namn;
}
asort($modellval);
// Nyckeltyper: aktiva + de som har en nyckel
$nyckeltyper = array_map(static fn($r) => $r['namn'], mat_typer_aktiva('nyckeltyp'));
foreach (array_keys($befintliga_nycklar) as $u) {
    if (!isset($nyckeltyper[$u]) && ($r = mat_typ_hamta('nyckeltyp', (string)$u)) !== null) {
        $nyckeltyper[$u] = $r['namn'] . ' (inaktiv)';
    }
}
$tillbaka = $ny ? 'matarlista.php' : 'matare.php?uuid=' . rawurlencode($uuid);

kc_sidhuvud($ny ? 'Ny mätare' : 'Ändra mätare ' . $rad['matarnummer']);
?>
<p><a href="<?= kc_h(kc_url($tillbaka)) ?>">« Tillbaka</a></p>

<?php if ($fel !== []): ?>
<div class="kc-flash kc-flash-fel">
  <strong>Kunde inte spara:</strong>
  <ul style="margin:.25rem 0 0"><?php foreach ($fel as $f): ?><li><?= kc_h($f) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="post" autocomplete="off">
  <?= kc_csrf_falt() ?>
  <input type="hidden" name="atgard" value="spara">
  <div class="kc-ruta" style="max-width:44rem">
    <h2>Mätare</h2>
    <p>Mätarnummer: <strong><?= $ny ? '<span class="kc-dampad">sätts automatiskt när mätaren skapas</span>' : kc_h($rad['matarnummer']) ?></strong></p>
    <div class="kc-falt">
      <label for="matar_id">MätarId</label>
      <input type="text" id="matar_id" name="matar_id" maxlength="100" style="width:20rem" value="<?= kc_h($varden['matar_id']) ?>">
      <div class="kc-hjalp">Det id mätaren skickar med sina värden.</div>
    </div>
    <div class="kc-falt">
      <label for="matartyp_uuid">Mätartyp</label>
      <select id="matartyp_uuid" name="matartyp_uuid"><?= mat_alternativ($val('matartyp', (string)$varden['matartyp_uuid']), (string)$varden['matartyp_uuid'], '– ingen –') ?></select>
    </div>
    <div class="kc-falt">
      <label for="fabrikat_uuid">Fabrikat</label>
      <select id="fabrikat_uuid" name="fabrikat_uuid"><?= mat_alternativ($val('fabrikat', (string)$varden['fabrikat_uuid']), (string)$varden['fabrikat_uuid'], '– inget –') ?></select>
      <label for="matarmodell_uuid" style="display:inline;margin-left:1rem">Modell</label>
      <select id="matarmodell_uuid" name="matarmodell_uuid"><?= mat_alternativ($modellval, (string)$varden['matarmodell_uuid'], '– ingen –') ?></select>
      <div class="kc-hjalp">Väljer du bara modell fylls fabrikatet i automatiskt.</div>
    </div>
    <div class="kc-falt">
      <label for="kommunikation_uuid">Kommunikation</label>
      <select id="kommunikation_uuid" name="kommunikation_uuid"><?= mat_alternativ($val('kommunikation', (string)$varden['kommunikation_uuid']), (string)$varden['kommunikation_uuid'], '– ingen –') ?></select>
      <label for="gateway" style="display:inline;margin-left:1rem">Gateway</label>
      <input type="text" id="gateway" name="gateway" maxlength="100" style="width:14rem" value="<?= kc_h($varden['gateway']) ?>">
    </div>
    <div class="kc-falt">
      <label for="kommentar">Kommentar</label>
      <input type="text" id="kommentar" name="kommentar" maxlength="255" style="width:100%" value="<?= kc_h($varden['kommentar']) ?>">
    </div>
  </div>

  <div class="kc-ruta" style="max-width:44rem">
    <h2>Nycklar</h2>
    <?php if ($nyckeltyper === []): ?>
      <p><em class="kc-dampad">Inga nyckeltyper finns. Lägg till dem under Typer.</em></p>
    <?php else: ?>
      <?php foreach ($nyckeltyper as $u => $namn): ?>
        <div class="kc-falt">
          <label for="nyckel_<?= kc_h(substr(md5($u), 0, 8)) ?>"><?= kc_h($namn) ?></label>
          <input type="password" class="mat-nyckelfalt kc-nyckel" id="nyckel_<?= kc_h(substr(md5($u), 0, 8)) ?>" name="nyckel[<?= kc_h($u) ?>]"
                 maxlength="255" style="width:100%" autocomplete="off" value="<?= kc_h($nycklar[$u] ?? '') ?>">
        </div>
      <?php endforeach; ?>
      <label><input type="checkbox" id="mat_visa_nycklar"> Visa nycklarna</label>
      <p class="kc-hjalp">Tomt fält = ingen nyckel. Nycklar delas aldrig med andra verktyg och syns bara för inloggade.</p>
      <script>
        document.getElementById('mat_visa_nycklar').addEventListener('change', function () {
          var typ = this.checked ? 'text' : 'password';
          document.querySelectorAll('.mat-nyckelfalt').forEach(function (f) { f.type = typ; });
        });
      </script>
    <?php endif; ?>
    <button type="submit" class="kc-knapp-primar"><?= $ny ? 'Skapa mätare' : 'Spara' ?></button>
  </div>
</form>

<?php if (!$ny): ?>
<div class="kc-ruta" style="max-width:44rem">
  <h2>Radera felregistrerad mätare</h2>
  <?php if ($radera_fel !== ''): ?><div class="kc-flash kc-flash-fel"><?= kc_h($radera_fel) ?></div><?php endif; ?>
  <?php if ($antal_monteringar > 0): ?>
    <p>Mätaren har <?= kc_h($antal_monteringar) ?> montering(ar) och kan inte raderas. Mätare som har använts finns kvar
       för historiken. Är monteringen också fel kan den tas bort från mätpunktens sida.</p>
  <?php else: ?>
    <p>Mätaren har aldrig monterats. Radera den bara om den är felregistrerad – nycklar och egenskapsvärden raderas också.</p>
    <form method="post">
      <?= kc_csrf_falt() ?>
      <input type="hidden" name="atgard" value="radera">
      <div class="kc-falt">
        <label for="bekrafta_nummer">Skriv mätarnumret <code><?= kc_h($rad['matarnummer']) ?></code> för att bekräfta</label>
        <input type="text" id="bekrafta_nummer" name="bekrafta_nummer" maxlength="20" style="width:10rem" autocomplete="off">
      </div>
      <div class="kc-falt">
        <label><input type="checkbox" name="bekrafta" value="1"> Jag förstår att mätaren raderas</label>
      </div>
      <button type="submit" class="kc-knapp-fara">Radera mätaren</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php kc_sidfot();
