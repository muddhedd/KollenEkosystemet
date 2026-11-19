<?php
/**
 * FastighetsKollen – byggnad_redigera.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Skapa, ändra, flytta och radera en byggnad (även utomhusområde). Kräver inloggning.
 * Flytt till annan fastighet: adresser och objekt följer med.
 * Radering är mjuk, tar med byggnadens adresser, objektgruppskopplingar och egenskapsvärden och blockeras om byggnaden har aktiva objekt.
 * Parametrar: uuid (befintlig) eller fastighet (ny byggnad på den fastigheten).
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/fas.php';

fas_krav_inloggning();
$tb = kc_tabell('md_byggnad');
$ta = kc_tabell('md_adress');

$uuid = kc_get('uuid');
$ny   = $uuid === '';
$rad  = null;
if (!$ny) {
    $rad = fas_byggnad_hamta($uuid);
    if ($rad === null) {
        throw new KcFel('Byggnaden finns inte eller har raderats.', 404);
    }
}
$falt = ['fastighet_uuid', 'byggnadsnummer', 'namn', 'byggnadstyp_uuid'];
$varden = $ny
    ? ['fastighet_uuid' => kc_get('fastighet'), 'byggnadsnummer' => '', 'namn' => '', 'byggnadstyp_uuid' => '']
    : array_intersect_key($rad, array_flip($falt));
$fel = [];

/* ---------- Radera ---------- */

if (kc_ar_post() && kc_post('atgard') === 'radera' && !$ny) {
    $objekt = fas_byggnad_antal_objekt($uuid);
    if ($objekt > 0) {
        kc_flash('fel', "Byggnaden har $objekt aktiva objekt och kan inte raderas. Ta bort eller flytta dem först.");
        kc_omdirigera('byggnad_redigera.php?uuid=' . rawurlencode($uuid));
    }
    kc_transaktion(function () use ($tb, $ta, $uuid): void {
        $nu = kc_nu();
        kc_kor("UPDATE `$tb` SET is_deleted = 1, last_changed = ?, source_tool = 'fas' WHERE uuid = ? AND is_deleted = 0", [$nu, $uuid]);
        kc_kor("UPDATE `$ta` SET is_deleted = 1, last_changed = ?, source_tool = 'fas' WHERE byggnad_uuid = ? AND is_deleted = 0", [$nu, $uuid]);
        fas_kopplingar_radera('byggnad', $uuid, $nu);
        fas_egenskapsvarden_radera($uuid, $nu);
    });
    kc_flash('ok', 'Byggnaden ' . trim($rad['byggnadsnummer'] . ' ' . $rad['namn']) . ' är raderad.');
    kc_omdirigera('fastighet.php?uuid=' . rawurlencode($rad['fastighet_uuid']));
}

/* ---------- Spara ---------- */

if (kc_ar_post() && kc_post('atgard') === 'spara') {
    foreach ($falt as $k) {
        $varden[$k] = kc_post($k);
    }
    if (fas_fastighet_hamta($varden['fastighet_uuid']) === null) {
        $fel[] = 'Välj vilken fastighet byggnaden tillhör.';
    }
    if ($varden['byggnadsnummer'] === '' || mb_strlen($varden['byggnadsnummer']) > 50) {
        $fel[] = 'Byggnadsnummer måste fyllas i (max 50 tecken).';
    } elseif (!fas_ar_unik('md_byggnad', 'byggnadsnummer', $varden['byggnadsnummer'], $ny ? null : $uuid)) {
        $fel[] = 'Byggnadsnumret används redan av en annan byggnad.';
    }
    if (mb_strlen($varden['namn']) > 150) {
        $fel[] = 'Namnet får vara max 150 tecken.';
    }
    if (!array_key_exists($varden['byggnadstyp_uuid'], fas_typ_val('byggnadstyp', (string)($rad['byggnadstyp_uuid'] ?? '')))) {
        $fel[] = 'Välj en byggnadstyp.';
    }

    if ($fel === []) {
        if ($ny) {
            $uuid = kc_uuid();
            kc_kor(
                "INSERT INTO `$tb` (uuid, last_changed, is_deleted, source_tool, fastighet_uuid, byggnadsnummer, namn, byggnadstyp_uuid)
                 VALUES (?, ?, 0, 'fas', ?, ?, ?, ?)",
                [$uuid, kc_nu(), $varden['fastighet_uuid'], $varden['byggnadsnummer'], $varden['namn'], $varden['byggnadstyp_uuid']]
            );
            kc_flash('ok', 'Byggnaden är skapad. Lägg gärna till adresser och objekt.');
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
                    "UPDATE `$tb` SET fastighet_uuid = ?, byggnadsnummer = ?, namn = ?, byggnadstyp_uuid = ?, last_changed = ?, source_tool = 'fas'
                      WHERE uuid = ? AND is_deleted = 0",
                    [$varden['fastighet_uuid'], $varden['byggnadsnummer'], $varden['namn'], $varden['byggnadstyp_uuid'], kc_nu(), $uuid]
                );
            }
            $flyttad = $varden['fastighet_uuid'] !== $rad['fastighet_uuid'];
            kc_flash('ok', !$andrat ? 'Inget var ändrat.'
                : ($flyttad ? 'Byggnaden är flyttad. Dess adresser och objekt följde med.' : 'Ändringarna är sparade.'));
        }
        kc_omdirigera('byggnad.php?uuid=' . rawurlencode($uuid));
    }
}

/* ---------- Visning ---------- */

$fastigheter = fas_fastighet_val();
$typer = fas_typ_val('byggnadstyp', (string)$varden['byggnadstyp_uuid']);
$tillbaka = $ny
    ? ($varden['fastighet_uuid'] !== '' ? 'fastighet.php?uuid=' . rawurlencode($varden['fastighet_uuid']) : 'byggnader.php')
    : 'byggnad.php?uuid=' . rawurlencode($uuid);

kc_sidhuvud($ny ? 'Ny byggnad' : 'Ändra byggnad: ' . trim($rad['byggnadsnummer'] . ' ' . $rad['namn']));
?>
<p><a href="<?= kc_h(kc_url($tillbaka)) ?>">« Tillbaka</a></p>

<?php if ($fastigheter === []): ?>
<div class="kc-flash kc-flash-info">Det finns inga fastigheter ännu. <a href="<?= kc_h(kc_url('fastighet_redigera.php')) ?>">Skapa en fastighet</a> först.</div>
<?php endif; ?>

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
      <label for="fastighet_uuid">Fastighet *</label>
      <select id="fastighet_uuid" name="fastighet_uuid" required><?= fas_alternativ($fastigheter, (string)$varden['fastighet_uuid']) ?></select>
      <?php if (!$ny): ?><div class="kc-hjalp">Byt fastighet för att flytta byggnaden. Adresser och objekt följer med.</div><?php endif; ?>
    </div>
    <div class="kc-falt">
      <label for="byggnadsnummer">Byggnadsnummer *</label>
      <input type="text" id="byggnadsnummer" name="byggnadsnummer" maxlength="50" required value="<?= kc_h($varden['byggnadsnummer']) ?>">
    </div>
    <div class="kc-falt">
      <label for="namn">Namn</label>
      <input type="text" id="namn" name="namn" maxlength="150" style="width:100%" value="<?= kc_h($varden['namn']) ?>" placeholder="t.ex. Hus A eller Gården">
    </div>
    <div class="kc-falt">
      <label for="byggnadstyp_uuid">Byggnadstyp *</label>
      <select id="byggnadstyp_uuid" name="byggnadstyp_uuid" required><?= fas_alternativ($typer, (string)$varden['byggnadstyp_uuid']) ?></select>
      <div class="kc-hjalp">Utomhusområden (parkering, gård, lekplats) registreras som byggnadstypen Utomhusområde.</div>
    </div>
    <button type="submit" class="kc-knapp-primar"><?= $ny ? 'Skapa byggnad' : 'Spara' ?></button>
  </div>
</form>

<?php if (!$ny):
    $objekt = fas_byggnad_antal_objekt($uuid); ?>
<div class="kc-ruta" style="max-width:40rem">
  <h2>Radera</h2>
  <?php if ($objekt > 0): ?>
    <p>Byggnaden har <?= kc_h($objekt) ?> aktiva objekt och kan inte raderas förrän de är borttagna eller flyttade.</p>
  <?php else: ?>
    <p>Byggnaden, dess adresser, egenskapsvärden och kopplingar till objektgrupper tas bort. Byggnadsnumret blir ledigt igen.</p>
    <form method="post" onsubmit="return confirm('Radera byggnaden <?= kc_h(addslashes(trim($rad['byggnadsnummer'] . ' ' . $rad['namn']))) ?>?');">
      <?= kc_csrf_falt() ?>
      <input type="hidden" name="atgard" value="radera">
      <button type="submit" class="kc-knapp-fara">Radera byggnaden</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php kc_sidfot();
