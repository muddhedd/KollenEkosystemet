<?php
/**
 * MätarKollen – matare.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * En mätare: uppgifter, varning för samma mätarId på samma gateway, nycklar (bara för inloggade,
 * maskerade med kopieraknapp), egenskaper och monteringshistorik med ställningar. Läsning öppen för alla.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/mat.php';

$inloggad = mat_inloggad() !== null;
$uuid = kc_get('uuid');
$m = mat_matare_hamta($uuid);
if ($m === null) {
    throw new KcFel('Mätaren finns inte.', 404);
}
$typnamn = static function (string $lista, string $u): string {
    $r = mat_typ_hamta($lista, $u);
    return $r === null ? '' : $r['namn'] . ((int)$r['is_active'] === 1 ? '' : ' (inaktiv)');
};
$dubbletter = mat_matar_id_dubbletter($m['matar_id'], $m['gateway'], $uuid);
$monteringar = mat_monteringar('matare_uuid', $uuid);
$nycklar = $inloggad ? mat_nycklar($uuid) : [];

kc_sidhuvud('Mätare ' . $m['matarnummer']);
?>
<p><a href="<?= kc_h(kc_url('matarlista.php')) ?>">« Alla mätare</a></p>

<?php if ($dubbletter !== []): ?>
  <div class="kc-flash kc-flash-info"><strong>Obs:</strong> samma mätarId och gateway finns även på
    <?= implode(', ', array_map(static fn($d) => '<a href="' . kc_h(kc_url('matare.php?uuid=' . rawurlencode($d['uuid']))) . '">'
        . kc_h($d['matarnummer']) . '</a>', $dubbletter)) ?>. Kontrollera att det inte är en dubblett.</div>
<?php endif; ?>

<div class="kc-ruta" style="max-width:52rem">
  <h2>Uppgifter</h2>
  <div class="kc-tabell-wrap"><table class="kc-tabell"><tbody>
    <tr><th>Mätarnummer</th><td><strong><?= kc_h($m['matarnummer']) ?></strong></td></tr>
    <tr><th>MätarId</th><td><?= kc_h($m['matar_id']) ?></td></tr>
    <tr><th>Mätartyp</th><td><?= kc_h($typnamn('matartyp', $m['matartyp_uuid'])) ?></td></tr>
    <tr><th>Fabrikat</th><td><?= kc_h($typnamn('fabrikat', $m['fabrikat_uuid'])) ?></td></tr>
    <tr><th>Modell</th><td><?= kc_h($typnamn('matarmodell', $m['matarmodell_uuid'])) ?></td></tr>
    <tr><th>Kommunikation</th><td><?= kc_h($typnamn('kommunikation', $m['kommunikation_uuid'])) ?></td></tr>
    <tr><th>Gateway</th><td><?= kc_h($m['gateway']) ?></td></tr>
    <tr><th>Kommentar</th><td><?= kc_h($m['kommentar']) ?></td></tr>
    <tr><th>Senast ändrad</th><td><?= kc_h(kc_tid($m['last_changed'])) ?></td></tr>
  </tbody></table></div>
  <?php if ($inloggad): ?>
    <p style="margin-top:.75rem"><a class="kc-knapp" href="<?= kc_h(kc_url('matare_redigera.php?uuid=' . rawurlencode($uuid))) ?>">Ändra</a></p>
  <?php endif; ?>
</div>

<?= kc_egenskaper_ruta('matare', $uuid, $inloggad) ?>

<?php if ($inloggad): ?>
<div class="kc-ruta" style="max-width:52rem">
  <h2>Nycklar</h2>
  <?php if ($nycklar === []): ?>
    <p><em class="kc-dampad">Inga nycklar registrerade.</em></p>
  <?php else: ?>
    <div class="kc-tabell-wrap"><table class="kc-tabell"><tbody>
    <?php foreach ($nycklar as $typ_uuid => $n): ?>
      <tr><th><?= kc_h($typnamn('nyckeltyp', $typ_uuid)) ?></th>
          <td><code class="kc-nyckel"><?= kc_h(mat_maskera($n['varde'])) ?></code></td>
          <td><button type="button" class="kc-lank-knapp" data-mat-kopiera="<?= kc_h($n['varde']) ?>">Kopiera</button></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <p class="kc-hjalp">Nycklarna visas bara för inloggade och ingår aldrig i listor, exporter eller API.</p>
    <script>
      document.querySelectorAll('[data-mat-kopiera]').forEach(function (b) {
        b.addEventListener('click', function () {
          navigator.clipboard.writeText(b.getAttribute('data-mat-kopiera')).then(function () {
            b.textContent = 'Kopierad!';
            setTimeout(function () { b.textContent = 'Kopiera'; }, 1500);
          });
        });
      });
    </script>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="kc-ruta" style="max-width:52rem">
  <h2>Monteringar</h2>
  <?php if ($monteringar === []): ?>
    <p><em class="kc-dampad">Mätaren har aldrig varit monterad.</em> Montera den från mätpunktens sida.</p>
  <?php else: ?>
    <div class="kc-tabell-wrap"><table class="kc-tabell">
      <thead><tr><th>Mätpunkt</th><th>Från</th><th>Till</th><th>Ställningar</th></tr></thead>
      <tbody>
      <?php foreach ($monteringar as $g): $st = mat_stallningar($g['uuid']); ?>
        <tr>
          <td><a href="<?= kc_h(kc_url('matpunkt.php?uuid=' . rawurlencode($g['matpunkt_uuid']))) ?>"><?= kc_h($g['matpunktsnamn']) ?></a></td>
          <td><?= kc_h($g['startdatum']) ?></td>
          <td><?= ($g['slutdatum'] ?? '') === '' ? '<strong>monterad nu</strong>' : kc_h($g['slutdatum']) ?></td>
          <td>
            <?php foreach (MAT_HANDELSER as $h => $etikett): if ($st[$h] !== []): ?>
              <div><span class="kc-dampad"><?= kc_h($etikett) ?>:</span> <?= kc_h(mat_stallning_text($st[$h])) ?></div>
            <?php endif; endforeach; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<?php kc_sidfot();
