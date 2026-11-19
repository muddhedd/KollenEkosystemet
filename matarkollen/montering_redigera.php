<?php
/**
 * MätarKollen – montering_redigera.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Rätta en montering: start- och slutdatum och ställningar. Ta bort en felregistrerad montering
 * (kräver bekräftelse; ställningarna tas bort också). Kräver inloggning.
 * Perioder får inte krocka med andra monteringar för samma mätare eller mätpunkt.
 * Parameter: uuid (monteringens).
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/mat.php';

mat_krav_inloggning();
$tg = kc_tabell('md_montering');

$g = mat_montering_hamta(kc_get('uuid'));
if ($g === null) {
    throw new KcFel('Monteringen finns inte.', 404);
}
$m  = mat_matare_hamta($g['matare_uuid']);
$mp = mat_matpunkt_hamta($g['matpunkt_uuid']);
$tillbaka = 'matpunkt.php?uuid=' . rawurlencode($g['matpunkt_uuid']);
$befintliga = mat_stallningar($g['uuid']);
$storheter = mat_stallning_storheter($g['matpunkt_uuid'], array_merge(array_keys($befintliga['montering']), array_keys($befintliga['demontering'])));

$start = (string)$g['startdatum'];
$slut  = (string)($g['slutdatum'] ?? '');
$st_in = $befintliga['montering'];
$st_ut = $befintliga['demontering'];
$fel = [];
$radera_fel = '';

/* ---------- Ta bort ---------- */

if (kc_ar_post() && kc_post('atgard') === 'radera') {
    if (kc_post('bekrafta') !== '1') {
        $radera_fel = 'Bocka i rutan för att ta bort monteringen.';
    } else {
        kc_transaktion(fn() => mat_montering_radera($g['uuid']));
        kc_flash('ok', 'Monteringen är borttagen.');
        kc_omdirigera($tillbaka);
    }
}

/* ---------- Spara ---------- */

if (kc_ar_post() && kc_post('atgard') === 'spara') {
    $start = kc_post('startdatum');
    $slut  = kc_post('slutdatum');
    [$st_in, $f1] = mat_stallningar_las('st_in', $storheter);
    [$st_ut, $f2] = $slut !== '' ? mat_stallningar_las('st_ut', $storheter) : [array_fill_keys(array_keys($storheter), ''), []];
    $fel = array_merge(mat_montering_kontroll($g['matare_uuid'], $g['matpunkt_uuid'], $start, $slut, $g['uuid']), $f1, $f2);
    if ($slut === '' && $mp !== null && $mp['typ'] === 'virtuell') {
        $fel[] = 'Mätpunkten är virtuell och kan inte ha en monterad mätare.';
    }

    if ($fel === []) {
        $andrat = false;
        kc_transaktion(function () use ($g, $start, $slut, $st_in, $st_ut, $tg, &$andrat): void {
            if ($start !== (string)$g['startdatum'] || $slut !== (string)($g['slutdatum'] ?? '')) {
                kc_kor("UPDATE `$tg` SET startdatum = ?, slutdatum = ?, last_changed = ?, source_tool = 'mat' WHERE uuid = ? AND is_deleted = 0",
                       [$start, $slut === '' ? null : $slut, kc_nu(), $g['uuid']]);
                $andrat = true;
            }
            $andrat = mat_stallningar_spara($g['uuid'], 'montering', $st_in) || $andrat;
            $andrat = mat_stallningar_spara($g['uuid'], 'demontering', $st_ut) || $andrat;
        });
        kc_flash('ok', $andrat ? 'Monteringen är sparad.' : 'Inget var ändrat.');
        kc_omdirigera($tillbaka);
    }
}

kc_sidhuvud('Ändra montering');
?>
<p><a href="<?= kc_h(kc_url($tillbaka)) ?>">« Tillbaka till mätpunkten</a></p>

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
    <p>Mätare <strong><?= kc_h($m !== null ? mat_matare_text($m) : '(raderad)') ?></strong>
       i mätpunkt <strong><?= kc_h($mp['matpunktsnamn'] ?? '(saknas)') ?></strong></p>
    <div class="kc-falt">
      <label for="startdatum">Monterad *</label>
      <input type="date" id="startdatum" name="startdatum" required value="<?= kc_h($start) ?>">
      <label for="slutdatum" style="display:inline;margin-left:1rem">Demonterad</label>
      <input type="date" id="slutdatum" name="slutdatum" value="<?= kc_h($slut) ?>">
      <div class="kc-hjalp">Tomt demonteringsdatum = mätaren sitter kvar. För vanliga byten, använd knapparna på mätpunktens sida.</div>
    </div>
  </div>
  <div class="kc-ruta" style="max-width:44rem">
    <h2>Ställning vid montering</h2>
    <?= mat_stallning_falt('st_in', $storheter, $st_in) ?>
  </div>
  <?php if ($slut !== ''): ?>
  <div class="kc-ruta" style="max-width:44rem">
    <h2>Ställning vid demontering</h2>
    <?= mat_stallning_falt('st_ut', $storheter, $st_ut) ?>
  </div>
  <?php endif; ?>
  <div class="kc-ruta" style="max-width:44rem">
    <button type="submit" class="kc-knapp-primar">Spara</button>
  </div>
</form>

<div class="kc-ruta" style="max-width:44rem">
  <h2>Ta bort felregistrerad montering</h2>
  <?php if ($radera_fel !== ''): ?><div class="kc-flash kc-flash-fel"><?= kc_h($radera_fel) ?></div><?php endif; ?>
  <p>Använd bara om monteringen aldrig har skett. Ett vanligt mätarbyte registreras med Byt mätare,
     så att historiken finns kvar.</p>
  <form method="post">
    <?= kc_csrf_falt() ?>
    <input type="hidden" name="atgard" value="radera">
    <div class="kc-falt">
      <label><input type="checkbox" name="bekrafta" value="1"> Jag förstår att monteringen och dess ställningar tas bort</label>
    </div>
    <button type="submit" class="kc-knapp-fara">Ta bort monteringen</button>
  </form>
</div>
<?php kc_sidfot();
