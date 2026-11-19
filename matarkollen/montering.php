<?php
/**
 * MätarKollen – montering.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Montera, demontera eller byta mätare i en mätpunkt. Kräver inloggning.
 * Parametrar: matpunkt (uuid), atgard = montera | demontera | byt
 * - En mätpunkt har högst en monterad mätare åt gången och en mätare sitter i högst en mätpunkt.
 * - Virtuella mätpunkter har inga mätare. Avvecklade mätpunkter kan inte få nya mätare.
 * - Byte: den gamla monteringen avslutas och den nya startar samma datum, i en transaktion.
 * - Ställningar (valfria) registreras per förväntad storhet vid montering och demontering.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/mat.php';

mat_krav_inloggning();
$tg = kc_tabell('md_montering');

$mp = mat_matpunkt_hamta(kc_get('matpunkt'));
if ($mp === null) {
    throw new KcFel('Mätpunkten finns inte.', 404);
}
$atgard = kc_get('atgard');
$rubriker = ['montera' => 'Montera mätare', 'demontera' => 'Demontera mätare', 'byt' => 'Byt mätare'];
if (!isset($rubriker[$atgard])) {
    throw new KcFel('Okänd åtgärd.', 400);
}
$aktiv = mat_aktiv_montering('matpunkt_uuid', $mp['uuid']);
$tillbaka = 'matpunkt.php?uuid=' . rawurlencode($mp['uuid']);

// Förutsättningar
if ($mp['typ'] === 'virtuell') {
    kc_flash('fel', 'En virtuell mätpunkt har inga mätare.');
    kc_omdirigera($tillbaka);
}
if ($atgard === 'montera' && $aktiv !== null) {
    kc_flash('fel', 'Mätpunkten har redan en monterad mätare. Använd Byt mätare.');
    kc_omdirigera($tillbaka);
}
if (in_array($atgard, ['demontera', 'byt'], true) && $aktiv === null) {
    kc_flash('fel', 'Mätpunkten har ingen monterad mätare.');
    kc_omdirigera($tillbaka);
}
if (in_array($atgard, ['montera', 'byt'], true) && $mp['status'] === 'avvecklad') {
    kc_flash('fel', 'Mätpunkten är avvecklad och kan inte få en ny mätare.');
    kc_omdirigera($tillbaka);
}

$storheter = mat_stallning_storheter($mp['uuid']);
$datum  = mat_idag();
$matare_text = kc_get('matare');
$st_ut  = [];   // ställningar vid demontering (gamla mätaren)
$st_in  = [];   // ställningar vid montering (nya mätaren)
$fel = [];

if (kc_ar_post() && kc_post('atgard') === 'spara') {
    $datum = kc_post('datum');
    $matare_text = kc_post('matare');
    if (!mat_datum_ok($datum)) {
        $fel[] = 'Ange ett giltigt datum (ÅÅÅÅ-MM-DD).';
    }
    $ny_matare = null;
    if ($atgard !== 'demontera') {
        [$ny_matare, $f] = mat_matare_sok($matare_text);
        if ($f !== null) {
            $fel[] = $f;
        } elseif ($aktiv !== null && $ny_matare['uuid'] === $aktiv['matare_uuid']) {
            $fel[] = 'Det är samma mätare som redan sitter här.';
        }
        [$st_in, $f] = mat_stallningar_las('st_in', $storheter);
        $fel = array_merge($fel, $f);
    }
    if ($atgard !== 'montera') {
        [$st_ut, $f] = mat_stallningar_las('st_ut', $storheter);
        $fel = array_merge($fel, $f);
        if (mat_datum_ok($datum) && $datum < (string)$aktiv['startdatum']) {
            $fel[] = 'Datumet kan inte vara före monteringen av nuvarande mätare (' . $aktiv['startdatum'] . ').';
        }
    }
    if ($fel === [] && $ny_matare !== null) {
        // Den gamla monteringen räknas som avslutad på datumet vid byte
        $fel = mat_montering_kontroll($ny_matare['uuid'], $mp['uuid'], $datum, '', $aktiv['uuid'] ?? '');
        if ($fel === [] && $aktiv !== null) {
            $fel = mat_montering_kontroll($aktiv['matare_uuid'], $mp['uuid'], (string)$aktiv['startdatum'], $datum, $aktiv['uuid']);
        }
    }

    if ($fel === []) {
        kc_transaktion(function () use ($atgard, $aktiv, $ny_matare, $mp, $datum, $st_ut, $st_in, $tg): void {
            $nu = kc_nu();
            if ($aktiv !== null) {
                kc_kor("UPDATE `$tg` SET slutdatum = ?, last_changed = ?, source_tool = 'mat' WHERE uuid = ? AND is_deleted = 0",
                       [$datum, $nu, $aktiv['uuid']]);
                mat_stallningar_spara($aktiv['uuid'], 'demontering', $st_ut);
            }
            if ($ny_matare !== null) {
                $g = kc_uuid();
                kc_kor("INSERT INTO `$tg` (uuid, last_changed, is_deleted, source_tool, matare_uuid, matpunkt_uuid, startdatum, slutdatum)
                        VALUES (?, ?, 0, 'mat', ?, ?, ?, NULL)", [$g, $nu, $ny_matare['uuid'], $mp['uuid'], $datum]);
                mat_stallningar_spara($g, 'montering', $st_in);
            }
        });
        kc_flash('ok', match ($atgard) {
            'montera'   => 'Mätaren ' . $ny_matare['matarnummer'] . ' är monterad.',
            'demontera' => 'Mätaren ' . $aktiv['matarnummer'] . ' är demonterad.',
            'byt'       => 'Mätaren ' . $aktiv['matarnummer'] . ' är bytt mot ' . $ny_matare['matarnummer'] . '.',
        });
        if ($mp['status'] === 'projekt' && $ny_matare !== null) {
            kc_flash('info', 'Mätpunkten har fortfarande status Projekt. Ändra till Drifttagen när den är i drift.');
        }
        kc_omdirigera($tillbaka);
    }
}

kc_sidhuvud($rubriker[$atgard] . ': ' . $mp['matpunktsnamn']);
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
    <div class="kc-falt">
      <label for="datum">Datum *</label>
      <input type="date" id="datum" name="datum" required value="<?= kc_h($datum) ?>">
      <?php if ($atgard === 'byt'): ?><div class="kc-hjalp">Den gamla mätaren demonteras och den nya monteras detta datum.</div><?php endif; ?>
    </div>
  </div>

  <?php if ($aktiv !== null): ?>
  <div class="kc-ruta" style="max-width:44rem">
    <h2>Demontera <?= kc_h($aktiv['matarnummer']) ?></h2>
    <p class="kc-dampad">Monterad sedan <?= kc_h($aktiv['startdatum']) ?>. Ställning vid demontering:</p>
    <?= mat_stallning_falt('st_ut', $storheter, $st_ut) ?>
  </div>
  <?php endif; ?>

  <?php if ($atgard !== 'demontera'): ?>
  <div class="kc-ruta" style="max-width:44rem">
    <h2><?= $atgard === 'byt' ? 'Ny mätare' : 'Mätare' ?></h2>
    <div class="kc-falt">
      <label for="matare">Mätarnummer eller mätarId *</label>
      <input type="text" id="matare" name="matare" maxlength="100" style="width:20rem" value="<?= kc_h($matare_text) ?>">
      <div class="kc-hjalp">Mätaren måste finnas och får inte sitta i en annan mätpunkt.
        <a href="<?= kc_h(kc_url('matarlista.php?ej_monterade=1')) ?>" target="_blank" rel="noopener">Ej monterade mätare »</a> ·
        <a href="<?= kc_h(kc_url('matare_redigera.php')) ?>" target="_blank" rel="noopener">Ny mätare »</a></div>
    </div>
    <p class="kc-dampad">Ställning vid montering:</p>
    <?= mat_stallning_falt('st_in', $storheter, $st_in) ?>
  </div>
  <?php endif; ?>

  <div class="kc-ruta" style="max-width:44rem">
    <button type="submit" class="kc-knapp-primar"><?= kc_h($rubriker[$atgard]) ?></button>
  </div>
</form>
<?php kc_sidfot();
