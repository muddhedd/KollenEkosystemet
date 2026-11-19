<?php
/**
 * FastighetsKollen – fastighet_redigera.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Skapa, ändra och radera en fastighet. Kräver inloggning.
 * Radering är mjuk och tar med fastighetens adresser, objektgruppskopplingar och egenskapsvärden. Blockeras om
 * fastigheten har aktiva byggnader.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/fas.php';

fas_krav_inloggning();
$tf = kc_tabell('md_fastighet');
$ta = kc_tabell('md_adress');

$uuid = kc_get('uuid');
$ny   = $uuid === '';
$rad  = null;
if (!$ny) {
    $rad = fas_fastighet_hamta($uuid);
    if ($rad === null) {
        throw new KcFel('Fastigheten finns inte eller har raderats.', 404);
    }
}
$falt = ['namn', 'fastighetsnummer', 'team', 'bolag', 'kostnadsstalle'];
$varden = $ny ? array_fill_keys($falt, '') : array_intersect_key($rad, array_flip($falt));
$fel = [];

/* ---------- Radera ---------- */

if (kc_ar_post() && kc_post('atgard') === 'radera' && !$ny) {
    $byggnader = fas_fastighet_antal_byggnader($uuid);
    if ($byggnader > 0) {
        kc_flash('fel', "Fastigheten har $byggnader aktiva byggnader och kan inte raderas. Ta bort eller flytta dem först.");
        kc_omdirigera('fastighet_redigera.php?uuid=' . rawurlencode($uuid));
    }
    kc_transaktion(function () use ($tf, $ta, $uuid): void {
        $nu = kc_nu();
        kc_kor("UPDATE `$tf` SET is_deleted = 1, last_changed = ?, source_tool = 'fas' WHERE uuid = ? AND is_deleted = 0", [$nu, $uuid]);
        kc_kor("UPDATE `$ta` SET is_deleted = 1, last_changed = ?, source_tool = 'fas' WHERE fastighet_uuid = ? AND is_deleted = 0", [$nu, $uuid]);
        fas_kopplingar_radera('fastighet', $uuid, $nu);
        fas_egenskapsvarden_radera($uuid, $nu);
    });
    kc_flash('ok', 'Fastigheten ' . $rad['fastighetsnummer'] . ' ' . $rad['namn'] . ' är raderad.');
    kc_omdirigera('fastigheter.php');
}

/* ---------- Spara ---------- */

if (kc_ar_post() && kc_post('atgard') === 'spara') {
    foreach ($falt as $k) {
        $varden[$k] = kc_post($k);
    }
    $fel = fas_fastighet_validera($varden, $ny ? null : $uuid);

    if ($fel === []) {
        if ($ny) {
            $uuid = kc_uuid();
            kc_kor(
                "INSERT INTO `$tf` (uuid, last_changed, is_deleted, source_tool, namn, fastighetsnummer, team, bolag, kostnadsstalle)
                 VALUES (?, ?, 0, 'fas', ?, ?, ?, ?, ?)",
                [$uuid, kc_nu(), $varden['namn'], $varden['fastighetsnummer'], $varden['team'], $varden['bolag'], $varden['kostnadsstalle']]
            );
            kc_flash('ok', 'Fastigheten är skapad. Lägg gärna till en adress.');
        } else {
            $andrat = false;
            foreach ($falt as $k) {
                if ((string)$rad[$k] !== $varden[$k]) {
                    $andrat = true;
                    break;
                }
            }
            if ($andrat) {
                kc_kor(
                    "UPDATE `$tf` SET namn = ?, fastighetsnummer = ?, team = ?, bolag = ?, kostnadsstalle = ?,
                                     last_changed = ?, source_tool = 'fas'
                      WHERE uuid = ? AND is_deleted = 0",
                    [$varden['namn'], $varden['fastighetsnummer'], $varden['team'], $varden['bolag'], $varden['kostnadsstalle'], kc_nu(), $uuid]
                );
            }
            kc_flash('ok', $andrat ? 'Ändringarna är sparade.' : 'Inget var ändrat.');
        }
        kc_omdirigera('fastighet.php?uuid=' . rawurlencode($uuid));
    }
}

/* ---------- Visning ---------- */

kc_sidhuvud($ny ? 'Ny fastighet' : 'Ändra fastighet: ' . $rad['fastighetsnummer'] . ' ' . $rad['namn']);
?>
<p><a href="<?= kc_h(kc_url($ny ? 'fastigheter.php' : 'fastighet.php?uuid=' . rawurlencode($uuid))) ?>">« Tillbaka</a></p>

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
      <label for="fastighetsnummer">Fastighetsnummer *</label>
      <input type="text" id="fastighetsnummer" name="fastighetsnummer" maxlength="50" required value="<?= kc_h($varden['fastighetsnummer']) ?>">
      <div class="kc-hjalp">Internt nummer, unikt. Officiell fastighetsbeteckning läggs som egenskap (se Egenskaper).</div>
    </div>
    <div class="kc-falt">
      <label for="namn">Namn *</label>
      <input type="text" id="namn" name="namn" maxlength="150" style="width:100%" required value="<?= kc_h($varden['namn']) ?>">
    </div>
    <div class="kc-falt">
      <label for="team">Team</label>
      <input type="text" id="team" name="team" maxlength="100" value="<?= kc_h($varden['team']) ?>">
    </div>
    <div class="kc-falt">
      <label for="bolag">Bolag</label>
      <input type="text" id="bolag" name="bolag" maxlength="100" value="<?= kc_h($varden['bolag']) ?>">
    </div>
    <div class="kc-falt">
      <label for="kostnadsstalle">Kostnadsställe</label>
      <input type="text" id="kostnadsstalle" name="kostnadsstalle" maxlength="50" value="<?= kc_h($varden['kostnadsstalle']) ?>">
      <div class="kc-hjalp">Team, bolag och kostnadsställe är fritext tills OrganisationsKollen och BudgetKollen finns.</div>
    </div>
    <button type="submit" class="kc-knapp-primar"><?= $ny ? 'Skapa fastighet' : 'Spara' ?></button>
  </div>
</form>

<?php if (!$ny):
    $byggnader = fas_fastighet_antal_byggnader($uuid); ?>
<div class="kc-ruta" style="max-width:40rem">
  <h2>Radera</h2>
  <?php if ($byggnader > 0): ?>
    <p>Fastigheten har <?= kc_h($byggnader) ?> aktiva byggnader och kan inte raderas förrän de är borttagna eller flyttade.</p>
  <?php else: ?>
    <p>Fastigheten, dess adresser, egenskapsvärden och kopplingar till objektgrupper tas bort. Fastighetsnumret blir ledigt igen.</p>
    <form method="post" onsubmit="return confirm('Radera fastigheten <?= kc_h(addslashes($rad['fastighetsnummer'] . ' ' . $rad['namn'])) ?>?');">
      <?= kc_csrf_falt() ?>
      <input type="hidden" name="atgard" value="radera">
      <button type="submit" class="kc-knapp-fara">Radera fastigheten</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php kc_sidfot();
