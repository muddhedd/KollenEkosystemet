<?php
/**
 * FastighetsKollen – typ_redigera.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Skapa, redigera, inaktivera och radera en typ. Kräver inloggning.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/fas.php';

fas_krav_inloggning();

$lista = kc_get('lista');
$def   = fas_typlista($lista);
$t     = kc_tabell($def['tabell']);
$uuid  = kc_get('uuid');
$ny    = $uuid === '';
$rad   = null;
if (!$ny) {
    $rad = kc_ar_uuid($uuid) ? kc_hamta_en("SELECT * FROM `$t` WHERE uuid = ? AND is_deleted = 0 LIMIT 1", [$uuid]) : null;
    if ($rad === null) {
        throw new KcFel('Typen finns inte eller har raderats.', 404);
    }
}
$listsida = 'typer.php?lista=' . rawurlencode($lista);
$varden = $ny ? ['namn' => '', 'beskrivning' => '', 'sortering' => 0, 'is_active' => 1] : $rad;
$fel = [];

/* ---------- Radera ---------- */

if (kc_ar_post() && kc_post('atgard') === 'radera' && !$ny) {
    $anvand = fas_typ_anvand($lista, $uuid);
    if ($anvand > 0) {
        kc_flash('fel', "Typen används av $anvand poster och kan inte raderas. Gör den inaktiv i stället.");
        kc_omdirigera('typ_redigera.php?lista=' . rawurlencode($lista) . '&uuid=' . rawurlencode($uuid));
    }
    kc_kor("UPDATE `$t` SET is_deleted = 1, last_changed = ?, source_tool = 'fas' WHERE uuid = ? AND is_deleted = 0", [kc_nu(), $uuid]);
    kc_flash('ok', 'Typen ' . $rad['namn'] . ' är raderad.');
    kc_omdirigera($listsida);
}

/* ---------- Spara ---------- */

if (kc_ar_post() && kc_post('atgard') === 'spara') {
    $varden = [
        'namn'        => kc_post('namn'),
        'beskrivning' => kc_post('beskrivning'),
        'sortering'   => kc_post('sortering', '0'),
        'is_active'   => isset($_POST['is_active']) ? 1 : 0,
    ];
    if ($varden['namn'] === '' || mb_strlen($varden['namn']) > 100) {
        $fel[] = 'Namnet måste vara 1–100 tecken.';
    }
    if (mb_strlen($varden['beskrivning']) > 255) {
        $fel[] = 'Beskrivningen är för lång (max 255 tecken).';
    }
    if (!preg_match('/^-?\d{1,6}$/', (string)$varden['sortering'])) {
        $fel[] = 'Sortering måste vara ett heltal.';
    }
    if ($fel === []) {
        $params = [$varden['namn']];
        $undantag = '';
        if (!$ny) {
            $undantag = ' AND uuid <> ?';
            $params[] = $uuid;
        }
        if ((int)kc_hamta_varde("SELECT COUNT(*) FROM `$t` WHERE is_deleted = 0 AND namn = ?$undantag", $params) > 0) {
            $fel[] = 'Det finns redan en ' . $def['etikett'] . ' med det namnet.';
        }
    }

    if ($fel === []) {
        $varden['sortering'] = (int)$varden['sortering'];
        if ($ny) {
            $uuid = kc_uuid();
            kc_kor(
                "INSERT INTO `$t` (uuid, last_changed, is_deleted, source_tool, namn, beskrivning, sortering, is_active)
                 VALUES (?, ?, 0, 'fas', ?, ?, ?, ?)",
                [$uuid, kc_nu(), $varden['namn'], $varden['beskrivning'], $varden['sortering'], $varden['is_active']]
            );
            kc_flash('ok', 'Typen ' . $varden['namn'] . ' är skapad.');
        } else {
            $andrat = $varden['namn'] !== $rad['namn'] || $varden['beskrivning'] !== $rad['beskrivning']
                   || $varden['sortering'] !== (int)$rad['sortering'] || $varden['is_active'] !== (int)$rad['is_active'];
            if ($andrat) {
                kc_kor(
                    "UPDATE `$t` SET namn = ?, beskrivning = ?, sortering = ?, is_active = ?, last_changed = ?, source_tool = 'fas'
                      WHERE uuid = ? AND is_deleted = 0",
                    [$varden['namn'], $varden['beskrivning'], $varden['sortering'], $varden['is_active'], kc_nu(), $uuid]
                );
            }
            kc_flash('ok', $andrat ? 'Ändringarna är sparade.' : 'Inget var ändrat.');
        }
        kc_omdirigera($listsida);
    }
}

/* ---------- Visning ---------- */

$anvand = $ny ? 0 : fas_typ_anvand($lista, $uuid);
kc_sidhuvud($ny ? 'Ny ' . $def['etikett'] : ucfirst($def['etikett']) . ': ' . $rad['namn']);
?>
<p><a href="<?= kc_h(kc_url($listsida)) ?>">« Tillbaka till <?= kc_h(mb_strtolower($def['rubrik'])) ?></a></p>

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
      <label for="namn">Namn *</label>
      <input type="text" id="namn" name="namn" maxlength="100" required value="<?= kc_h($varden['namn']) ?>">
    </div>
    <div class="kc-falt">
      <label for="beskrivning">Beskrivning</label>
      <input type="text" id="beskrivning" name="beskrivning" maxlength="255" style="width:100%" value="<?= kc_h($varden['beskrivning']) ?>">
    </div>
    <div class="kc-falt">
      <label for="sortering">Sortering</label>
      <input type="number" id="sortering" name="sortering" step="1" style="width:8rem" value="<?= kc_h($varden['sortering']) ?>">
      <div class="kc-hjalp">Lägre tal visas först i rullistor.</div>
    </div>
    <div class="kc-falt">
      <label><input type="checkbox" name="is_active" value="1" <?= (int)$varden['is_active'] === 1 ? 'checked' : '' ?>> Aktiv (visas i rullistor)</label>
    </div>
    <button type="submit" class="kc-knapp-primar"><?= $ny ? 'Skapa' : 'Spara' ?></button>
  </div>
</form>

<?php if (!$ny): ?>
<div class="kc-ruta" style="max-width:40rem">
  <h2>Radera</h2>
  <?php if ($anvand > 0): ?>
    <p>Typen används av <?= kc_h($anvand) ?> poster och kan inte raderas. Gör den inaktiv i stället.</p>
  <?php else: ?>
    <p>Typen används inte och kan raderas.</p>
    <form method="post" onsubmit="return confirm('Radera typen <?= kc_h(addslashes($rad['namn'])) ?>?');">
      <?= kc_csrf_falt() ?>
      <input type="hidden" name="atgard" value="radera">
      <button type="submit" class="kc-knapp-fara">Radera typen</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php kc_sidfot();
