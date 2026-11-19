<?php
/**
 * MätarKollen – matpunkt.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * En mätpunkt: uppgifter, placering, förväntade storheter, föregående och efterföljande mätpunkter.
 * Mätare: monterad mätare, knappar för montera/byt/demontera och monteringshistorik med ställningar.
 * Läsning öppen för alla.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/mat.php';

$inloggad = mat_inloggad() !== null;
$uuid = kc_get('uuid');
$m = mat_matpunkt_hamta($uuid);
if ($m === null) {
    throw new KcFel('Mätpunkten finns inte.', 404);
}
$plac = mat_placering_info($m['niva'], $m['placering_uuid']);
$betj = mat_placering_info($m['betjaning_niva'], $m['betjaning_uuid']);
$betj_samma = $m['betjaning_niva'] === $m['niva'] && $m['betjaning_uuid'] === $m['placering_uuid'];
/** Placering/betjäning som text, med "saknas" om den försvunnit ur fastighetsdatan. */
$mat_koppling = static function (array $info, string $niva, string $nummer): string {
    if ($info['finns']) {
        return kc_h($info['text']) . ($info['adress'] !== '' ? '<br><span class="kc-dampad">' . kc_h($info['adress']) . '</span>' : '');
    }
    if ($niva === '') {
        return '<em class="kc-dampad">ej angiven</em>';
    }
    $nr = $info['nummer'] !== '' ? $info['nummer'] : $nummer;
    return '<strong style="color:var(--kc-fel)">saknas</strong> (' . kc_h(MAT_PLACERING[$niva]['etikett'] ?? $niva) . ($nr !== '' ? ' ' . kc_h($nr) : '') . ')';
};
$storheter = mat_matpunkt_storheter($uuid);
$foregaende = $m['foregaende_uuid'] !== '' ? mat_matpunkt_hamta($m['foregaende_uuid']) : null;
$efter = mat_efterfoljande($uuid);
$monteringar = mat_monteringar('matpunkt_uuid', $uuid);
$aktiv = mat_aktiv_montering('matpunkt_uuid', $uuid);
$lank = static fn(array $mp): string =>
    '<a href="' . kc_h(kc_url('matpunkt.php?uuid=' . rawurlencode($mp['uuid']))) . '">' . kc_h($mp['matpunktsnamn']) . '</a>';

kc_sidhuvud('Mätpunkt: ' . $m['matpunktsnamn']);
?>
<p><a href="<?= kc_h(kc_url('matpunkter.php')) ?>">« Alla mätpunkter</a></p>

<?php if (!$plac['finns'] || (!$betj['finns'] && !$betj_samma)): ?>
  <div class="kc-flash kc-flash-fel"><?= !$plac['finns'] ? 'Placeringen' : 'Betjäningen' ?><?= !$plac['finns'] && !$betj['finns'] && !$betj_samma ? ' och betjäningen' : '' ?>
    finns inte längre i FastighetsKollen (enligt senaste hämtningen). Ändra mätpunkten så att den pekar på en giltig post.</div>
<?php endif; ?>

<div class="kc-ruta" style="max-width:52rem">
  <h2>Uppgifter</h2>
  <div class="kc-tabell-wrap"><table class="kc-tabell"><tbody>
    <tr><th>Mätpunktsnamn</th><td><?= kc_h($m['matpunktsnamn']) ?></td></tr>
    <tr><th>Typ</th><td><?= kc_h(MAT_MATPUNKT_TYPER[$m['typ']] ?? $m['typ']) ?></td></tr>
    <tr><th>Status</th><td><strong><?= kc_h(MAT_MATPUNKT_STATUS[$m['status']] ?? $m['status']) ?></strong></td></tr>
    <tr><th>Placering</th><td><?= $mat_koppling($plac, $m['niva'], $m['placering_nummer']) ?></td></tr>
    <tr><th>Betjänar</th><td><?= $betj_samma && $m['betjaning_niva'] !== ''
        ? '<span class="kc-dampad">samma som placering</span>'
        : $mat_koppling($betj, $m['betjaning_niva'], $m['betjaning_nummer']) ?></td></tr>
    <tr><th>Plats</th><td><?= kc_h($m['platsbeskrivning']) ?></td></tr>
    <tr><th>Förväntade storheter</th><td>
      <?= $storheter === [] ? '<em class="kc-dampad">inga angivna</em>'
          : kc_h(implode(', ', array_map(static fn($s) => mat_storhet_text($s) . ((int)$s['is_active'] === 1 ? '' : ' (inaktiv)'), $storheter))) ?>
    </td></tr>
    <tr><th>Senast ändrad</th><td><?= kc_h(kc_tid($m['last_changed'])) ?></td></tr>
  </tbody></table></div>
  <?php if ($inloggad): ?>
    <p style="margin-top:.75rem"><a class="kc-knapp" href="<?= kc_h(kc_url('matpunkt_redigera.php?uuid=' . rawurlencode($uuid))) ?>">Ändra</a></p>
  <?php endif; ?>
  <p class="kc-hjalp">Faktiskt inkomna storheter visas här när MätvärdesKollen finns.</p>
</div>

<?= kc_egenskaper_ruta('matpunkt', $uuid, $inloggad) ?>

<div class="kc-ruta" style="max-width:52rem">
  <h2>Flöde</h2>
  <p>Föregående: <?= $foregaende !== null ? $lank($foregaende) : '<em class="kc-dampad">ingen</em>' ?></p>
  <p>Efterföljande (<?= kc_h(count($efter)) ?>):
    <?= $efter === [] ? '<em class="kc-dampad">inga</em>' : implode(', ', array_map($lank, $efter)) ?></p>
  <?php if ($m['typ'] === 'virtuell'): ?>
    <p class="kc-hjalp">Virtuell mätpunkt: värdena räknas fram (t.ex. föregående minus summan av efterföljande) i
       MätvärdesKollen/FörbrukningsKollen. MätarKollen äger bara strukturen.</p>
  <?php endif; ?>
</div>

<div class="kc-ruta" style="max-width:52rem">
  <h2>Mätare</h2>
  <?php if ($m['typ'] === 'virtuell'): ?>
    <p><em class="kc-dampad">Virtuell mätpunkt – inga mätare.</em></p>
  <?php else: ?>
    <?php if ($aktiv !== null): ?>
      <p>Monterad: <a href="<?= kc_h(kc_url('matare.php?uuid=' . rawurlencode($aktiv['matare_uuid']))) ?>"><strong><?= kc_h($aktiv['matarnummer']) ?></strong></a>
         <?= $aktiv['matar_id'] !== '' ? '(mätarId ' . kc_h($aktiv['matar_id']) . ')' : '' ?> sedan <?= kc_h($aktiv['startdatum']) ?></p>
      <?php if ($m['status'] === 'avvecklad'): ?>
        <div class="kc-flash kc-flash-info">Mätpunkten är avvecklad men har fortfarande en monterad mätare. Demontera den.</div>
      <?php endif; ?>
    <?php else: ?>
      <p><em class="kc-dampad">Ingen mätare monterad.</em></p>
    <?php endif; ?>
    <?php if ($inloggad): $mat_bas = 'montering.php?matpunkt=' . rawurlencode($uuid) . '&atgard='; ?>
      <p>
        <?php if ($aktiv === null && $m['status'] !== 'avvecklad'): ?>
          <a class="kc-knapp" href="<?= kc_h(kc_url($mat_bas . 'montera')) ?>">Montera mätare</a>
        <?php elseif ($aktiv !== null): ?>
          <?php if ($m['status'] !== 'avvecklad'): ?><a class="kc-knapp" href="<?= kc_h(kc_url($mat_bas . 'byt')) ?>">Byt mätare</a><?php endif; ?>
          <a class="kc-knapp" href="<?= kc_h(kc_url($mat_bas . 'demontera')) ?>">Demontera</a>
        <?php endif; ?>
      </p>
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($monteringar !== []): ?>
    <h3>Historik</h3>
    <div class="kc-tabell-wrap"><table class="kc-tabell">
      <thead><tr><th>Mätare</th><th>Från</th><th>Till</th><th>Ställningar</th><?= $inloggad ? '<th></th>' : '' ?></tr></thead>
      <tbody>
      <?php foreach ($monteringar as $g): $st = mat_stallningar($g['uuid']); ?>
        <tr>
          <td><?= $g['matarnummer'] !== ''
                ? '<a href="' . kc_h(kc_url('matare.php?uuid=' . rawurlencode($g['matare_uuid']))) . '">' . kc_h($g['matarnummer']) . '</a>'
                : '<span class="kc-dampad">(raderad)</span>' ?></td>
          <td><?= kc_h($g['startdatum']) ?></td>
          <td><?= ($g['slutdatum'] ?? '') === '' ? '<strong>monterad nu</strong>' : kc_h($g['slutdatum']) ?></td>
          <td>
            <?php foreach (MAT_HANDELSER as $h => $etikett): if ($st[$h] !== []): ?>
              <div><span class="kc-dampad"><?= kc_h($etikett) ?>:</span> <?= kc_h(mat_stallning_text($st[$h])) ?></div>
            <?php endif; endforeach; ?>
          </td>
          <?php if ($inloggad): ?><td><a href="<?= kc_h(kc_url('montering_redigera.php?uuid=' . rawurlencode($g['uuid']))) ?>">Rätta</a></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<?php kc_sidfot();
