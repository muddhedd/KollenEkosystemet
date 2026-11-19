<?php
/**
 * FastighetsKollen – objektgrupp_redigera.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Skapa, ändra och radera en objektgrupp. Kräver inloggning.
 * Radering är mjuk och tar med gruppens kopplingar och egenskapsvärden (efter bekräftelse).
 * Parameter: uuid (befintlig), annars ny.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/fas.php';

fas_krav_inloggning();
$tg = kc_tabell('md_objektgrupp');
$tk = kc_tabell('md_objektgrupp_koppling');

$uuid = kc_get('uuid');
$ny   = $uuid === '';
$rad  = null;
if (!$ny) {
    $rad = fas_objektgrupp_hamta($uuid);
    if ($rad === null) {
        throw new KcFel('Objektgruppen finns inte eller har raderats.', 404);
    }
}
$falt = ['objektgruppsnummer', 'namn', 'objektgruppstyp_uuid', 'beskrivning'];
$varden = $ny ? array_fill_keys($falt, '') : array_intersect_key($rad, array_flip($falt));
$fel = [];

$antal_kopplingar = static fn(string $g): int =>
    (int)kc_hamta_varde("SELECT COUNT(*) FROM `$tk` WHERE objektgrupp_uuid = ? AND is_deleted = 0", [$g]);

/* ---------- Radera ---------- */

if (kc_ar_post() && kc_post('atgard') === 'radera' && !$ny) {
    $n = 0;
    kc_transaktion(function () use ($tg, $tk, $uuid, &$n): void {
        $nu = kc_nu();
        kc_kor("UPDATE `$tg` SET is_deleted = 1, last_changed = ?, source_tool = 'fas' WHERE uuid = ? AND is_deleted = 0", [$nu, $uuid]);
        $n = kc_kor("UPDATE `$tk` SET is_deleted = 1, last_changed = ?, source_tool = 'fas' WHERE objektgrupp_uuid = ? AND is_deleted = 0", [$nu, $uuid]);
        fas_egenskapsvarden_radera($uuid, $nu);
    });
    kc_flash('ok', 'Objektgruppen ' . trim($rad['objektgruppsnummer'] . ' ' . $rad['namn']) . ' är raderad'
        . ($n > 0 ? " tillsammans med $n kopplingar." : '.'));
    kc_omdirigera('objektgrupper.php');
}

/* ---------- Spara ---------- */

if (kc_ar_post() && kc_post('atgard') === 'spara') {
    foreach ($falt as $k) {
        $varden[$k] = kc_post($k);
    }
    if ($varden['objektgruppsnummer'] === '' || mb_strlen($varden['objektgruppsnummer']) > 50) {
        $fel[] = 'Objektgruppsnummer måste fyllas i (max 50 tecken).';
    } elseif (!fas_ar_unik('md_objektgrupp', 'objektgruppsnummer', $varden['objektgruppsnummer'], $ny ? null : $uuid)) {
        $fel[] = 'Objektgruppsnumret används redan av en annan objektgrupp.';
    }
    if ($varden['namn'] === '' || mb_strlen($varden['namn']) > 150) {
        $fel[] = 'Namn måste fyllas i (max 150 tecken).';
    }
    if (!array_key_exists($varden['objektgruppstyp_uuid'], fas_typ_val('objektgruppstyp', (string)($rad['objektgruppstyp_uuid'] ?? '')))) {
        $fel[] = 'Välj en objektgruppstyp.';
    }
    if (mb_strlen($varden['beskrivning']) > 255) {
        $fel[] = 'Beskrivningen är för lång (max 255 tecken).';
    }

    if ($fel === []) {
        if ($ny) {
            $uuid = kc_uuid();
            kc_kor(
                "INSERT INTO `$tg` (uuid, last_changed, is_deleted, source_tool, objektgruppsnummer, namn, objektgruppstyp_uuid, beskrivning)
                 VALUES (?, ?, 0, 'fas', ?, ?, ?, ?)",
                [$uuid, kc_nu(), $varden['objektgruppsnummer'], $varden['namn'], $varden['objektgruppstyp_uuid'], $varden['beskrivning']]
            );
            kc_flash('ok', 'Objektgruppen är skapad. Lägg till resurser och användare nedan.');
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
                    "UPDATE `$tg` SET objektgruppsnummer = ?, namn = ?, objektgruppstyp_uuid = ?, beskrivning = ?, last_changed = ?, source_tool = 'fas'
                      WHERE uuid = ? AND is_deleted = 0",
                    [$varden['objektgruppsnummer'], $varden['namn'], $varden['objektgruppstyp_uuid'], $varden['beskrivning'], kc_nu(), $uuid]
                );
            }
            kc_flash('ok', $andrat ? 'Ändringarna är sparade.' : 'Inget var ändrat.');
        }
        kc_omdirigera('objektgrupp.php?uuid=' . rawurlencode($uuid));
    }
}

/* ---------- Visning ---------- */

$typer = fas_typ_val('objektgruppstyp', (string)$varden['objektgruppstyp_uuid']);
$tillbaka = $ny ? 'objektgrupper.php' : 'objektgrupp.php?uuid=' . rawurlencode($uuid);

kc_sidhuvud($ny ? 'Ny objektgrupp' : 'Ändra objektgrupp: ' . trim($rad['objektgruppsnummer'] . ' ' . $rad['namn']));
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
  <div class="kc-ruta" style="max-width:40rem">
    <div class="kc-falt">
      <label for="objektgruppsnummer">Objektgruppsnummer *</label>
      <input type="text" id="objektgruppsnummer" name="objektgruppsnummer" maxlength="50" required value="<?= kc_h($varden['objektgruppsnummer']) ?>">
      <div class="kc-hjalp">Unikt i hela ekosystemet, så att andra verktyg kan peka ut gruppen.</div>
    </div>
    <div class="kc-falt">
      <label for="namn">Namn *</label>
      <input type="text" id="namn" name="namn" maxlength="150" required style="width:100%" value="<?= kc_h($varden['namn']) ?>" placeholder="t.ex. Soprum Storgatan">
    </div>
    <div class="kc-falt">
      <label for="objektgruppstyp_uuid">Typ *</label>
      <select id="objektgruppstyp_uuid" name="objektgruppstyp_uuid" required><?= fas_alternativ($typer, (string)$varden['objektgruppstyp_uuid']) ?></select>
    </div>
    <div class="kc-falt">
      <label for="beskrivning">Beskrivning</label>
      <input type="text" id="beskrivning" name="beskrivning" maxlength="255" style="width:100%" value="<?= kc_h($varden['beskrivning']) ?>">
    </div>
    <button type="submit" class="kc-knapp-primar"><?= $ny ? 'Skapa objektgrupp' : 'Spara' ?></button>
  </div>
</form>

<?php if (!$ny):
    $n = $antal_kopplingar($uuid);
    $fraga = 'Radera objektgruppen ' . trim($rad['objektgruppsnummer'] . ' ' . $rad['namn'])
           . ($n > 0 ? " och dess $n kopplingar" : '') . '?'; ?>
<div class="kc-ruta" style="max-width:40rem">
  <h2>Radera</h2>
  <p>Objektgruppen tas bort<?= $n > 0 ? ' tillsammans med <strong>' . kc_h($n) . ' kopplingar</strong>' : '' ?>.
     Gruppens egenskapsvärden tas också bort. Fastigheter, byggnader och objekt påverkas inte. Objektgruppsnumret blir ledigt igen.</p>
  <form method="post" onsubmit="return confirm('<?= kc_h(addslashes($fraga)) ?>');">
    <?= kc_csrf_falt() ?>
    <input type="hidden" name="atgard" value="radera">
    <button type="submit" class="kc-knapp-fara">Radera objektgruppen</button>
  </form>
</div>
<?php endif; ?>
<?php kc_sidfot();
