<?php
/**
 * FastighetsKollen – typer.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Typlistor (byggnadstyper, objekttyper, objektgruppstyper, kök). Läsning öppen för alla.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/fas.php';

$lista = kc_get('lista', 'byggnadstyp');
$def = fas_typlista($lista);
$inloggad = fas_inloggad() !== null;

kc_sidhuvud($def['rubrik']);
?>
<p>
  <?php $delar = [];
  foreach (FAS_TYPLISTOR as $nyckel => $d) {
      $delar[] = $nyckel === $lista
          ? '<strong>' . kc_h($d['rubrik']) . '</strong>'
          : '<a href="' . kc_h(kc_url('typer.php?lista=' . $nyckel)) . '">' . kc_h($d['rubrik']) . '</a>';
  }
  echo implode(' · ', $delar); ?>
</p>

<?php if ($inloggad): ?>
<p><a class="kc-knapp kc-knapp-primar" href="<?= kc_h(kc_url('typ_redigera.php?lista=' . rawurlencode($lista))) ?>">+ Ny <?= kc_h($def['etikett']) ?></a></p>
<?php endif; ?>

<?= kc_lista([
    'tabell'            => kc_tabell($def['tabell']),
    'kolumner'          => ['namn' => 'Namn', 'beskrivning' => 'Beskrivning', 'sortering' => 'Sortering', 'is_active' => 'Status'],
    'extra_kolumner'    => ['uuid'],
    'villkor'           => 'is_deleted = 0',
    'standardsortering' => 'sortering',
    'format'            => [
        'is_active' => fn($v) => (int)$v === 1 ? 'Aktiv' : '<span class="kc-dampad">Inaktiv</span>',
    ],
    'radlank'           => $inloggad
        ? fn($rad) => kc_url('typ_redigera.php?lista=' . rawurlencode($lista) . '&uuid=' . rawurlencode($rad['uuid']))
        : null,
    'export_namn'       => 'fas_' . $lista,
    'tom_text'          => 'Inga typer.',
]) ?>
<p class="kc-hjalp">Inaktiva typer visas inte i rullistor men finns kvar på poster som redan använder dem.
   Många typer på en gång: <a href="<?= kc_h(kc_url('import.php?tabell=' . rawurlencode($lista))) ?>">importera <?= kc_h(mb_strtolower($def['rubrik'])) ?></a>.</p>
<?php kc_sidfot();
