<?php
/**
 * MätarKollen – typer.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Typlistor: storheter, mätartyper, fabrikat, mätarmodeller, kommunikation och nyckeltyper. Läsning öppen för alla.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/mat.php';

$lista = kc_get('lista', (string)array_key_first(MAT_TYPLISTOR));
$def = mat_typlista($lista);
$inloggad = mat_inloggad() !== null;

$kolumner = ['namn' => 'Namn'];
$format = ['is_active' => fn($v) => (int)$v === 1 ? 'Aktiv' : '<span class="kc-dampad">Inaktiv</span>'];
// Referenser (t.ex. mätarmodellens fabrikat) visas med namn via join, så att man kan filtrera och sortera på dem
$t = kc_tabell($def['tabell']);
$select = ['t.*'];
$join = '';
$extra_kol = ['uuid'];
$i = 0;
foreach ($def['ref'] as $k => $r) {
    $i++;
    $tr = kc_tabell(MAT_TYPLISTOR[$r['lista']]['tabell']);
    $select[] = "COALESCE(r$i.namn, '') AS `ref_$k`";
    $join .= " LEFT JOIN `$tr` r$i ON r$i.uuid = t.`$k` AND r$i.is_deleted = 0";
    $kolumner['ref_' . $k] = $r['etikett'];
    $extra_kol[] = $k;
}
foreach ($def['extra'] as $k => $e) {
    $kolumner[$k] = $e;
}
$kolumner += ['beskrivning' => 'Beskrivning', 'sortering' => 'Sortering', 'is_active' => 'Status'];

kc_sidhuvud($def['rubrik']);
?>
<?php if (count(MAT_TYPLISTOR) > 1): ?>
<p>
  <?php $mat_delar = [];
  foreach (MAT_TYPLISTOR as $nyckel => $d) {
      $mat_delar[] = $nyckel === $lista
          ? '<strong>' . kc_h($d['rubrik']) . '</strong>'
          : '<a href="' . kc_h(kc_url('typer.php?lista=' . $nyckel)) . '">' . kc_h($d['rubrik']) . '</a>';
  }
  echo implode(' · ', $mat_delar); ?>
</p>
<?php endif; ?>

<?php if ($inloggad): ?>
<p><a class="kc-knapp kc-knapp-primar" href="<?= kc_h(kc_url('typ_redigera.php?lista=' . rawurlencode($lista))) ?>">+ Ny <?= kc_h($def['etikett']) ?></a></p>
<?php endif; ?>

<?= kc_lista([
    'kalla'             => 'SELECT ' . implode(', ', $select) . " FROM `$t` t$join WHERE t.is_deleted = 0",
    'kolumner'          => $kolumner,
    'extra_kolumner'    => $extra_kol,
    'standardsortering' => 'sortering',
    'format'            => $format,
    'radlank'           => $inloggad
        ? fn($rad) => kc_url('typ_redigera.php?lista=' . rawurlencode($lista) . '&uuid=' . rawurlencode($rad['uuid']))
        : null,
    'export_namn'       => 'mat_' . $lista,
    'tom_text'          => 'Inga typer.',
]) ?>
<p class="kc-hjalp">Inaktiva typer visas inte i val men finns kvar på poster som redan använder dem.
   Många typer på en gång: <a href="<?= kc_h(kc_url('import.php?tabell=' . rawurlencode($lista))) ?>">importera <?= kc_h(mb_strtolower($def['rubrik'])) ?></a>.</p>
<?php kc_sidfot();
