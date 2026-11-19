<?php
/**
 * FastighetsKollen – adress_redigera.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Skapa, ändra och radera en adress på en fastighet (byggnad från etapp 3).
 * Parametrar: agare (fastighet), agare_uuid, uuid (tom = ny). Kräver inloggning.
 * Ägaren har alltid exakt en huvudadress när den har adresser.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/fas.php';

fas_krav_inloggning();
$ta = kc_tabell('md_adress');

$agare      = kc_get('agare', 'fastighet');
$agdef      = fas_adress_agartyp($agare);
$agare_uuid = kc_get('agare_uuid');
$agarrad    = fas_adress_agare_hamta($agare, $agare_uuid);
if ($agarrad === null) {
    throw new KcFel('Det som adressen ska tillhöra finns inte eller har raderats.', 404);
}
$kol     = $agdef['kolumn'];
$tillbaka = $agdef['sida'] . '?uuid=' . rawurlencode($agare_uuid);
$rubrik  = trim(($agarrad['fastighetsnummer'] ?? $agarrad['byggnadsnummer'] ?? '') . ' ' . ($agarrad['namn'] ?? ''));

$uuid = kc_get('uuid');
$ny   = $uuid === '';
$rad  = null;
if (!$ny) {
    $rad = kc_ar_uuid($uuid)
        ? kc_hamta_en("SELECT * FROM `$ta` WHERE uuid = ? AND `$kol` = ? AND is_deleted = 0 LIMIT 1", [$uuid, $agare_uuid])
        : null;
    if ($rad === null) {
        throw new KcFel('Adressen finns inte eller har raderats.', 404);
    }
}
$varden = $ny
    ? ['gatuadress' => '', 'postnummer' => '', 'ort' => '', 'beskrivning' => '', 'ar_huvudadress' => 0]
    : $rad;
$fel = [];

/* ---------- Radera ---------- */

if (kc_ar_post() && kc_post('atgard') === 'radera' && !$ny) {
    $anvand = fas_adress_anvand($uuid);
    if ($anvand > 0) {
        kc_flash('fel', "Adressen används av $anvand objekt och kan inte raderas. Byt adress på objekten först.");
        kc_omdirigera('adress_redigera.php?agare=' . $agare . '&agare_uuid=' . rawurlencode($agare_uuid) . '&uuid=' . rawurlencode($uuid));
    }
    kc_transaktion(function () use ($ta, $uuid, $agare, $agare_uuid): void {
        kc_kor("UPDATE `$ta` SET is_deleted = 1, last_changed = ?, source_tool = 'fas' WHERE uuid = ? AND is_deleted = 0", [kc_nu(), $uuid]);
        fas_huvudadress_satt($agare, $agare_uuid, null);   // ny huvudadress om den borttagna var det
    });
    kc_flash('ok', 'Adressen ' . fas_adress_text($rad) . ' är borttagen.');
    kc_omdirigera($tillbaka);
}

/* ---------- Spara ---------- */

if (kc_ar_post() && kc_post('atgard') === 'spara') {
    $varden = [
        'gatuadress'     => kc_post('gatuadress'),
        'postnummer'     => kc_post('postnummer'),
        'ort'            => kc_post('ort'),
        'beskrivning'    => kc_post('beskrivning'),
        'ar_huvudadress' => isset($_POST['ar_huvudadress']) ? 1 : (int)($rad['ar_huvudadress'] ?? 0),
    ];
    if ($varden['gatuadress'] === '' || mb_strlen($varden['gatuadress']) > 150) {
        $fel[] = 'Gatuadress måste fyllas i (max 150 tecken).';
    }
    $pnr = fas_postnummer($varden['postnummer']);
    if ($pnr === null) {
        $fel[] = 'Postnummer ska vara fem siffror, t.ex. 852 30.';
    } else {
        $varden['postnummer'] = $pnr;
    }
    if ($varden['ort'] === '' || mb_strlen($varden['ort']) > 100) {
        $fel[] = 'Ort måste fyllas i (max 100 tecken).';
    }
    if (mb_strlen($varden['beskrivning']) > 255) {
        $fel[] = 'Beskrivningen är för lång (max 255 tecken).';
    }
    if ($fel === []) {
        $params = [$agare_uuid, $varden['gatuadress'], $varden['postnummer']];
        $undantag = '';
        if (!$ny) {
            $undantag = ' AND uuid <> ?';
            $params[] = $uuid;
        }
        $dubblett = (int)kc_hamta_varde(
            "SELECT COUNT(*) FROM `$ta` WHERE `$kol` = ? AND is_deleted = 0 AND gatuadress = ? AND postnummer = ?$undantag",
            $params
        ) > 0;
        if ($dubblett) {
            $fel[] = 'Adressen finns redan på ' . $agdef['etikett'] . '.';
        }
    }

    if ($fel === []) {
        kc_transaktion(function () use ($ta, $kol, &$uuid, $ny, $rad, $varden, $agare, $agare_uuid): void {
            if ($ny) {
                $uuid = kc_uuid();
                kc_kor(
                    "INSERT INTO `$ta` (uuid, last_changed, is_deleted, source_tool, `$kol`, gatuadress, postnummer, ort, beskrivning, ar_huvudadress)
                     VALUES (?, ?, 0, 'fas', ?, ?, ?, ?, ?, 0)",
                    [$uuid, kc_nu(), $agare_uuid, $varden['gatuadress'], $varden['postnummer'], $varden['ort'], $varden['beskrivning']]
                );
            } else {
                $andrat = $varden['gatuadress'] !== $rad['gatuadress'] || $varden['postnummer'] !== $rad['postnummer']
                       || $varden['ort'] !== $rad['ort'] || $varden['beskrivning'] !== $rad['beskrivning'];
                if ($andrat) {
                    kc_kor(
                        "UPDATE `$ta` SET gatuadress = ?, postnummer = ?, ort = ?, beskrivning = ?, last_changed = ?, source_tool = 'fas'
                          WHERE uuid = ? AND is_deleted = 0",
                        [$varden['gatuadress'], $varden['postnummer'], $varden['ort'], $varden['beskrivning'], kc_nu(), $uuid]
                    );
                }
            }
            // Ikryssad = blir huvudadress; annars behålls nuvarande (eller den första om ingen finns)
            fas_huvudadress_satt($agare, $agare_uuid, $varden['ar_huvudadress'] === 1 ? $uuid : null);
        });
        kc_flash('ok', $ny ? 'Adressen är tillagd.' : 'Adressen är sparad.');
        kc_omdirigera($tillbaka);
    }
}

/* ---------- Visning ---------- */

$ar_huvud = !$ny && (int)$rad['ar_huvudadress'] === 1;
$anvand   = $ny ? 0 : fas_adress_anvand($uuid);
kc_sidhuvud(($ny ? 'Ny adress' : 'Ändra adress') . ' – ' . $rubrik);
?>
<p><a href="<?= kc_h(kc_url($tillbaka)) ?>">« Tillbaka till <?= kc_h($agdef['etikett']) ?></a></p>

<?php if ($fel !== []): ?>
<div class="kc-flash kc-flash-fel">
  <strong>Kunde inte spara:</strong>
  <ul style="margin:.25rem 0 0"><?php foreach ($fel as $f): ?><li><?= kc_h($f) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="post">
  <?= kc_csrf_falt() ?>
  <input type="hidden" name="atgard" value="spara">
  <div class="kc-ruta" style="max-width:40rem">
    <div class="kc-falt">
      <label for="gatuadress">Gatuadress *</label>
      <input type="text" id="gatuadress" name="gatuadress" maxlength="150" style="width:100%" required
             value="<?= kc_h($varden['gatuadress']) ?>" placeholder="t.ex. Storgatan 10a">
    </div>
    <div class="kc-falt">
      <label for="postnummer">Postnummer *</label>
      <input type="text" id="postnummer" name="postnummer" maxlength="7" style="width:8rem" required
             value="<?= kc_h($varden['postnummer']) ?>" placeholder="123 45">
    </div>
    <div class="kc-falt">
      <label for="ort">Ort *</label>
      <input type="text" id="ort" name="ort" maxlength="100" required value="<?= kc_h($varden['ort']) ?>">
    </div>
    <div class="kc-falt">
      <label for="beskrivning">Beskrivning</label>
      <input type="text" id="beskrivning" name="beskrivning" maxlength="255" style="width:100%"
             value="<?= kc_h($varden['beskrivning']) ?>" placeholder="t.ex. Entré mot gården">
    </div>
    <div class="kc-falt">
      <?php if ($ar_huvud): ?>
        <p><strong>Det här är huvudadressen.</strong> <span class="kc-hjalp">Välj en annan adress som huvudadress för att byta.</span></p>
      <?php else: ?>
        <label><input type="checkbox" name="ar_huvudadress" value="1" <?= (int)$varden['ar_huvudadress'] === 1 ? 'checked' : '' ?>>
          Gör till huvudadress</label>
        <div class="kc-hjalp">Huvudadressen visas i listor. Den första adressen blir huvudadress automatiskt.</div>
      <?php endif; ?>
    </div>
    <button type="submit" class="kc-knapp-primar"><?= $ny ? 'Lägg till' : 'Spara' ?></button>
  </div>
</form>

<?php if (!$ny): ?>
<div class="kc-ruta" style="max-width:40rem">
  <h2>Ta bort</h2>
  <?php if ($anvand > 0): ?>
    <p>Adressen används av <?= kc_h($anvand) ?> objekt och kan inte tas bort.</p>
  <?php else: ?>
    <p>Adressen tas bort<?= $ar_huvud ? ', och nästa adress blir huvudadress' : '' ?>.</p>
    <form method="post" onsubmit="return confirm('Ta bort adressen <?= kc_h(addslashes(fas_adress_text($rad))) ?>?');">
      <?= kc_csrf_falt() ?>
      <input type="hidden" name="atgard" value="radera">
      <button type="submit" class="kc-knapp-fara">Ta bort adressen</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php kc_sidfot();
