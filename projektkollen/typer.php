<?php
/**
 * ProjektKollen – typer.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Typlistor: funktioner (koden i mätpunktsnamnet, med storheter) och projekttyper. Läsning öppen för alla.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/prj.php';

$lista = kc_get('lista', (string)array_key_first(PRJ_TYPLISTOR));
$def = prj_typlista($lista);
$inloggad = prj_inloggad() !== null;

$kolumner = ['namn' => 'Namn'];
$format = ['is_active' => fn($v) => (int)$v === 1 ? 'Aktiv' : '<span class="kc-dampad">Inaktiv</span>'];
// Referenser (t.ex. projekttypens funktion) visas med namn via join, så att man kan filtrera och sortera på dem
$t = kc_tabell($def['tabell']);
$select = ['t.*'];
$join = '';
$extra_kol = ['uuid'];
$i = 0;
foreach ($def['ref'] as $k => $r) {
    $i++;
    $tr = kc_tabell(PRJ_TYPLISTOR[$r['lista']]['tabell']);
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
<?php if (count(PRJ_TYPLISTOR) > 1): ?>
<p>
  <?php $prj_delar = [];
  foreach (PRJ_TYPLISTOR as $nyckel => $d) {
      $prj_delar[] = $nyckel === $lista
          ? '<strong>' . kc_h($d['rubrik']) . '</strong>'
          : '<a href="' . kc_h(kc_url('typer.php?lista=' . $nyckel)) . '">' . kc_h($d['rubrik']) . '</a>';
  }
  echo implode(' · ', $prj_delar); ?>
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
    'export_namn'       => 'prj_' . $lista,
    'tom_text'          => 'Inga typer.',
]) ?>
<p class="kc-hjalp">Inaktiva typer visas inte i val men finns kvar på poster som redan använder dem.
   <?php if ($lista === 'funktion'): ?>Funktionens namn är koden i mätpunktsnamnet (t.ex. Temp i 1301-01-0001_Temp_11).<?php endif; ?></p>
<?php kc_sidfot();
