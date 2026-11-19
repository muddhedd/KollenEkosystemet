<?php
/**
 * ProjektKollen – typ_redigera.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Skapa, redigera, inaktivera och radera en typ. Kräver inloggning.
 * En typ som används kan inte raderas, bara göras inaktiv. Extra kolumner per lista (funktionens storheter)
 * och referenser till andra typlistor (projekttypens funktion, obligatorisk).
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/prj.php';

prj_krav_inloggning();

$lista = kc_get('lista');
$def   = prj_typlista($lista);
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
$extra = $def['extra'];
$ref   = $def['ref'];
$varden = $ny
    ? ['namn' => '', 'beskrivning' => '', 'sortering' => 0, 'is_active' => 1]
      + array_fill_keys(array_keys($extra), '') + array_fill_keys(array_keys($ref), kc_get(array_key_first($ref) ?? '_'))
    : $rad;
$fel = [];

/* ---------- Radera ---------- */

if (kc_ar_post() && kc_post('atgard') === 'radera' && !$ny) {
    $anvand = prj_typ_anvand($lista, $uuid);
    if ($anvand > 0) {
        kc_flash('fel', "Typen används av $anvand poster och kan inte raderas. Gör den inaktiv i stället.");
        kc_omdirigera('typ_redigera.php?lista=' . rawurlencode($lista) . '&uuid=' . rawurlencode($uuid));
    }
    kc_kor("UPDATE `$t` SET is_deleted = 1, last_changed = ?, source_tool = 'prj' WHERE uuid = ? AND is_deleted = 0", [kc_nu(), $uuid]);
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
    foreach (array_keys($extra) as $k) {
        $varden[$k] = kc_post($k);
        $prj_max = $k === 'storheter' ? 255 : 20;
        if (mb_strlen($varden[$k]) > $prj_max) {
            $fel[] = ucfirst($extra[$k]) . " får vara max $prj_max tecken.";
        }
    }
    foreach ($ref as $k => $r) {
        $varden[$k] = kc_post($k);
        $vald = prj_typ_hamta($r['lista'], $varden[$k]);
        if ($vald === null || ((int)$vald['is_active'] !== 1 && ($ny || $varden[$k] !== (string)$rad[$k]))) {
            $fel[] = 'Välj ' . mb_strtolower($r['etikett']) . '.';
        }
    }
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
        foreach (array_keys($ref) as $k) {      // t.ex. modellnamn unikt per fabrikat
            $undantag .= " AND `$k` = ?";
            $params[] = $varden[$k];
        }
        if ((int)kc_hamta_varde("SELECT COUNT(*) FROM `$t` WHERE is_deleted = 0 AND namn = ?$undantag", $params) > 0) {
            $fel[] = 'Det finns redan en ' . $def['etikett'] . ' med det namnet.';
        }
    }

    if ($fel === []) {
        $varden['sortering'] = (int)$varden['sortering'];
        if ($ny) {
            $uuid = kc_uuid();
            $kol = array_merge(['namn', 'beskrivning', 'sortering', 'is_active'], array_keys($extra), array_keys($ref));
            kc_kor(
                "INSERT INTO `$t` (uuid, last_changed, is_deleted, source_tool, `" . implode('`, `', $kol) . "`)
                 VALUES (?, ?, 0, 'prj'" . str_repeat(', ?', count($kol)) . ')',
                array_merge([$uuid, kc_nu()], array_map(static fn($k) => $varden[$k], $kol))
            );
            kc_flash('ok', 'Typen ' . $varden['namn'] . ' är skapad.');
        } else {
            $andrat = $varden['namn'] !== $rad['namn'] || $varden['beskrivning'] !== $rad['beskrivning']
                   || $varden['sortering'] !== (int)$rad['sortering'] || $varden['is_active'] !== (int)$rad['is_active'];
            foreach (array_merge(array_keys($extra), array_keys($ref)) as $k) {
                $andrat = $andrat || $varden[$k] !== (string)$rad[$k];
            }
            if ($andrat) {
                $kol = array_merge(['namn', 'beskrivning', 'sortering', 'is_active'], array_keys($extra), array_keys($ref));
                $set = implode(', ', array_map(static fn($k) => "`$k` = ?", $kol));
                kc_kor(
                    "UPDATE `$t` SET $set, last_changed = ?, source_tool = 'prj' WHERE uuid = ? AND is_deleted = 0",
                    array_merge(array_map(static fn($k) => $varden[$k], $kol), [kc_nu(), $uuid])
                );
            }
            kc_flash('ok', $andrat ? 'Ändringarna är sparade.' : 'Inget var ändrat.');
        }
        kc_omdirigera($listsida);
    }
}

/* ---------- Visning ---------- */

$anvand = $ny ? 0 : prj_typ_anvand($lista, $uuid);
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
    <?php foreach ($ref as $k => $r):
        $prj_val = array_map(static fn($x) => $x['namn'], prj_typer_aktiva($r['lista']));
        if ($varden[$k] !== '' && !isset($prj_val[$varden[$k]]) && ($prj_x = prj_typ_hamta($r['lista'], (string)$varden[$k])) !== null) {
            $prj_val[$varden[$k]] = $prj_x['namn'] . ' (inaktiv)';
        } ?>
    <div class="kc-falt">
      <label for="<?= kc_h($k) ?>"><?= kc_h($r['etikett']) ?> *</label>
      <select id="<?= kc_h($k) ?>" name="<?= kc_h($k) ?>"><?= prj_alternativ($prj_val, (string)$varden[$k]) ?></select>
      <div class="kc-hjalp">Saknas det? Lägg till under <a href="<?= kc_h(kc_url('typer.php?lista=' . rawurlencode($r['lista']))) ?>"><?= kc_h(mb_strtolower(PRJ_TYPLISTOR[$r['lista']]['rubrik'])) ?></a>.</div>
    </div>
    <?php endforeach; ?>
    <?php foreach ($extra as $k => $etikett): ?>
    <div class="kc-falt">
      <label for="<?= kc_h($k) ?>"><?= kc_h($etikett) ?></label>
      <?php if ($k === 'storheter'): ?>
        <input type="text" id="<?= kc_h($k) ?>" name="<?= kc_h($k) ?>" maxlength="255" style="width:100%" value="<?= kc_h($varden[$k]) ?>">
        <div class="kc-hjalp">Kommaseparerade, med samma namn som i MätarKollen (t.ex. Temperatur, Relativ fuktighet).
          Blir mätpunkternas förväntade storheter.</div>
      <?php else: ?>
        <input type="text" id="<?= kc_h($k) ?>" name="<?= kc_h($k) ?>" maxlength="20" style="width:8rem" value="<?= kc_h($varden[$k]) ?>">
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
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
