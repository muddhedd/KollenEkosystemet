<?php
/**
 * FastighetsKollen – objekt_redigera.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Skapa, ändra, flytta och radera ett objekt. Kräver inloggning.
 * Adressen väljs bland byggnadens adresser. Flyttas objektet till en annan
 * byggnad nollställs adressen och en ny måste väljas.
 * Radering är mjuk och tar med objektets kopplingar till objektgrupper och egenskapsvärden.
 * Parametrar: uuid (befintligt) eller byggnad (nytt objekt i den byggnaden).
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/fas.php';

fas_krav_inloggning();
$to = kc_tabell('md_objekt');

$uuid = kc_get('uuid');
$ny   = $uuid === '';
$rad  = null;
if (!$ny) {
    $rad = fas_objekt_hamta($uuid);
    if ($rad === null) {
        throw new KcFel('Objektet finns inte eller har raderats.', 404);
    }
}
$falt = ['byggnad_uuid', 'objektnummer', 'objekttyp_uuid', 'adress_uuid', 'kvadratmeter', 'antal_rum', 'koktyp_uuid', 'vaningsplan', 'beskrivning'];
$varden = $ny
    ? array_fill_keys($falt, '') + ['byggnad_uuid' => kc_get('byggnad')]
    : array_map(static fn($v) => $v === null ? '' : (string)$v, array_intersect_key($rad, array_flip($falt)));
if ($ny) {
    $varden['byggnad_uuid'] = kc_get('byggnad');
}
$fel = [];
$info = null;

/* ---------- Radera ---------- */

if (kc_ar_post() && kc_post('atgard') === 'radera' && !$ny) {
    kc_transaktion(function () use ($to, $uuid): void {
        $nu = kc_nu();
        kc_kor("UPDATE `$to` SET is_deleted = 1, last_changed = ?, source_tool = 'fas' WHERE uuid = ? AND is_deleted = 0", [$nu, $uuid]);
        fas_kopplingar_radera('objekt', $uuid, $nu);
        fas_egenskapsvarden_radera($uuid, $nu);
    });
    kc_flash('ok', 'Objektet ' . $rad['objektnummer'] . ' är raderat.');
    kc_omdirigera('byggnad.php?uuid=' . rawurlencode($rad['byggnad_uuid']));
}

/* ---------- Spara ---------- */

if (kc_ar_post() && kc_post('atgard') === 'spara') {
    foreach ($falt as $k) {
        $varden[$k] = kc_post($k);
    }
    $byggnad = fas_byggnad_hamta($varden['byggnad_uuid']);
    if ($byggnad === null) {
        $fel[] = 'Välj vilken byggnad objektet tillhör.';
    }
    if ($varden['objektnummer'] === '' || mb_strlen($varden['objektnummer']) > 50) {
        $fel[] = 'Objektnummer måste fyllas i (max 50 tecken).';
    } elseif (!fas_ar_unik('md_objekt', 'objektnummer', $varden['objektnummer'], $ny ? null : $uuid)) {
        $fel[] = 'Objektnumret används redan av ett annat objekt.';
    }
    if (!array_key_exists($varden['objekttyp_uuid'], fas_typ_val('objekttyp', (string)($rad['objekttyp_uuid'] ?? '')))) {
        $fel[] = 'Välj en objekttyp.';
    }
    if ($varden['koktyp_uuid'] !== '' && !array_key_exists($varden['koktyp_uuid'], fas_typ_val('koktyp', (string)($rad['koktyp_uuid'] ?? '')))) {
        $fel[] = 'Ogiltigt val av kök.';
    }

    // Byggnad bytt? Då gäller inte den gamla adressen längre.
    $flyttad = !$ny && $byggnad !== null && $varden['byggnad_uuid'] !== $rad['byggnad_uuid'];
    if ($varden['adress_uuid'] !== '') {
        $adr = fas_adress_hamta($varden['adress_uuid']);
        if ($adr === null || $adr['byggnad_uuid'] !== $varden['byggnad_uuid']) {
            $varden['adress_uuid'] = '';
            if ($flyttad) {
                $info = 'Objektet flyttades till en annan byggnad, så adressen nollställdes. Välj en av den nya byggnadens adresser.';
            } else {
                $fel[] = 'Adressen måste vara en av byggnadens adresser.';
            }
        }
    }

    [$kvm, $f1] = fas_decimal($varden['kvadratmeter'], 0, 99999, 0.1, 'Kvadratmeter');
    [$rum, $f2] = fas_decimal($varden['antal_rum'], 0, 99, 0.5, 'Antal rum');
    foreach ([$f1, $f2] as $f) {
        if ($f !== null) {
            $fel[] = $f;
        }
    }
    $vaning = null;
    if (trim($varden['vaningsplan']) !== '') {
        $v = mb_strtoupper(trim($varden['vaningsplan']));
        if ($v === 'BV') {
            $vaning = 0;
        } elseif (preg_match('/^-?\d{1,3}$/', $v) && (int)$v >= -20 && (int)$v <= 200) {
            $vaning = (int)$v;
        } else {
            $fel[] = 'Våningsplan ska vara ett heltal (0 eller BV = entréplan, -1 = källare).';
        }
    }
    if (mb_strlen($varden['beskrivning']) > 255) {
        $fel[] = 'Beskrivningen är för lång (max 255 tecken).';
    }

    if ($fel === []) {
        $spara = [
            'byggnad_uuid'   => $varden['byggnad_uuid'],
            'objektnummer'   => $varden['objektnummer'],
            'objekttyp_uuid' => $varden['objekttyp_uuid'],
            'adress_uuid'    => $varden['adress_uuid'],
            'kvadratmeter'   => $kvm,
            'antal_rum'      => $rum,
            'koktyp_uuid'    => $varden['koktyp_uuid'],
            'vaningsplan'    => $vaning,
            'beskrivning'    => $varden['beskrivning'],
        ];
        if ($ny) {
            $uuid = kc_uuid();
            kc_kor(
                "INSERT INTO `$to` (uuid, last_changed, is_deleted, source_tool, " . implode(', ', array_keys($spara)) . ")
                 VALUES (?, ?, 0, 'fas', " . implode(', ', array_fill(0, count($spara), '?')) . ')',
                array_merge([$uuid, kc_nu()], array_values($spara))
            );
            kc_flash('ok', 'Objektet ' . $spara['objektnummer'] . ' är skapat.');
        } else {
            $andrat = false;
            foreach ($spara as $k => $v) {
                $gammal = $rad[$k];
                if (in_array($k, ['kvadratmeter', 'antal_rum'], true)) {
                    $lika = ($gammal === null && $v === null) || ($gammal !== null && $v !== null && abs((float)$gammal - $v) < 0.001);
                } elseif ($k === 'vaningsplan') {
                    $lika = ($gammal === null && $v === null) || ($gammal !== null && $v !== null && (int)$gammal === $v);
                } else {
                    $lika = (string)$gammal === (string)$v;
                }
                if (!$lika) {
                    $andrat = true;
                    break;
                }
            }
            if ($andrat) {
                $set = implode(', ', array_map(static fn($k) => "`$k` = ?", array_keys($spara)));
                kc_kor(
                    "UPDATE `$to` SET $set, last_changed = ?, source_tool = 'fas' WHERE uuid = ? AND is_deleted = 0",
                    array_merge(array_values($spara), [kc_nu(), $uuid])
                );
            }
            kc_flash('ok', !$andrat ? 'Inget var ändrat.' : ($flyttad ? 'Objektet är flyttat.' : 'Ändringarna är sparade.'));
        }
        if ($info !== null) {
            kc_flash('info', $info);
            kc_omdirigera('objekt_redigera.php?uuid=' . rawurlencode($uuid));
        }
        kc_omdirigera('objekt.php?uuid=' . rawurlencode($uuid));
    }
}

/* ---------- Visning ---------- */

$byggnad  = fas_byggnad_hamta((string)$varden['byggnad_uuid']);
$adresser = $byggnad !== null ? fas_adresser('byggnad', $byggnad['uuid']) : [];
$adressval = [];
foreach ($adresser as $a) {
    $adressval[$a['uuid']] = fas_adress_text($a) . ($a['beskrivning'] !== '' ? ' (' . $a['beskrivning'] . ')' : '');
}
$tillbaka = $ny
    ? ($byggnad !== null ? 'byggnad.php?uuid=' . rawurlencode($byggnad['uuid']) : 'objektlista.php')
    : 'objekt.php?uuid=' . rawurlencode($uuid);
// Värden från databasen visas snyggt; ogiltig inmatning visas som den skrevs
$visa_tal    = static fn($v) => is_numeric($v) ? fas_tal($v) : (string)$v;
$visa_vaning = static fn($v) => preg_match('/^-?\d+$/', (string)$v) ? fas_vaning($v) : (string)$v;

kc_sidhuvud($ny ? 'Nytt objekt' : 'Ändra objekt: ' . $rad['objektnummer']);
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
    <h2>Placering</h2>
    <div class="kc-falt">
      <label for="byggnad_uuid">Byggnad *</label>
      <select id="byggnad_uuid" name="byggnad_uuid" required>
        <option value="">– välj –</option>
        <?php foreach (fas_byggnad_val() as $fastighet => $byggnader): ?>
          <optgroup label="<?= kc_h($fastighet) ?>">
            <?php foreach ($byggnader as $b_uuid => $b_text): ?>
              <option value="<?= kc_h($b_uuid) ?>" <?= $b_uuid === $varden['byggnad_uuid'] ? 'selected' : '' ?>><?= kc_h($b_text) ?></option>
            <?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>
      <?php if (!$ny): ?><div class="kc-hjalp">Byt byggnad för att flytta objektet. Adressen nollställs då och väljs på nytt.</div><?php endif; ?>
    </div>
    <div class="kc-falt">
      <label for="adress_uuid">Adress</label>
      <select id="adress_uuid" name="adress_uuid"><?= fas_alternativ($adressval, (string)$varden['adress_uuid'], '– ingen adress –') ?></select>
      <div class="kc-hjalp">
        <?php if ($byggnad !== null && $adresser === []): ?>
          Byggnaden har inga adresser än. <a href="<?= kc_h(kc_url('adress_redigera.php?agare=byggnad&agare_uuid=' . rawurlencode($byggnad['uuid']))) ?>">Lägg till en adress på byggnaden</a>.
        <?php else: ?>
          Väljs bland byggnadens adresser.
        <?php endif; ?>
      </div>
    </div>
    <div class="kc-falt">
      <label for="vaningsplan">Våningsplan</label>
      <input type="text" id="vaningsplan" name="vaningsplan" maxlength="4" style="width:6rem"
             value="<?= kc_h($visa_vaning($varden['vaningsplan'])) ?>" placeholder="BV">
      <div class="kc-hjalp">BV (eller 0) = entréplan, 1 = en trappa upp, -1 = källare.</div>
    </div>
  </div>

  <div class="kc-ruta" style="max-width:44rem">
    <h2>Uppgifter</h2>
    <div class="kc-falt">
      <label for="objektnummer">Objektnummer *</label>
      <input type="text" id="objektnummer" name="objektnummer" maxlength="50" required value="<?= kc_h($varden['objektnummer']) ?>">
    </div>
    <div class="kc-falt">
      <label for="objekttyp_uuid">Objekttyp *</label>
      <select id="objekttyp_uuid" name="objekttyp_uuid" required><?= fas_alternativ(fas_typ_val('objekttyp', (string)$varden['objekttyp_uuid']), (string)$varden['objekttyp_uuid']) ?></select>
    </div>
    <div class="kc-falt">
      <label for="kvadratmeter">Kvadratmeter</label>
      <input type="text" id="kvadratmeter" name="kvadratmeter" maxlength="10" style="width:8rem" inputmode="decimal"
             value="<?= kc_h($visa_tal($varden['kvadratmeter'])) ?>" placeholder="72,5">
    </div>
    <div class="kc-falt">
      <label for="antal_rum">Antal rum</label>
      <input type="text" id="antal_rum" name="antal_rum" maxlength="5" style="width:6rem" inputmode="decimal"
             value="<?= kc_h($visa_tal($varden['antal_rum'])) ?>" placeholder="2">
      <label for="koktyp_uuid" style="display:inline;margin-left:1rem">Kök</label>
      <select id="koktyp_uuid" name="koktyp_uuid"><?= fas_alternativ(fas_typ_val('koktyp', (string)$varden['koktyp_uuid']), (string)$varden['koktyp_uuid'], '– ej angivet –') ?></select>
      <div class="kc-hjalp">Halva rum tillåts (t.ex. 2,5). Visas som "2 RoK".</div>
    </div>
    <div class="kc-falt">
      <label for="beskrivning">Beskrivning</label>
      <input type="text" id="beskrivning" name="beskrivning" maxlength="255" style="width:100%" value="<?= kc_h($varden['beskrivning']) ?>">
    </div>
    <button type="submit" class="kc-knapp-primar"><?= $ny ? 'Skapa objekt' : 'Spara' ?></button>
  </div>
</form>

<?php if (!$ny): ?>
<div class="kc-ruta" style="max-width:44rem">
  <h2>Radera</h2>
  <p>Objektet, dess egenskapsvärden och kopplingar till objektgrupper tas bort. Objektnumret blir ledigt igen.</p>
  <form method="post" onsubmit="return confirm('Radera objektet <?= kc_h(addslashes($rad['objektnummer'])) ?>?');">
    <?= kc_csrf_falt() ?>
    <input type="hidden" name="atgard" value="radera">
    <button type="submit" class="kc-knapp-fara">Radera objektet</button>
  </form>
</div>
<?php endif; ?>
<?php kc_sidfot();
