<?php
/**
 * FastighetsKollen – objektgrupper.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Lista över alla objektgrupper. Läsning öppen för alla.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/fas.php';

$inloggad = fas_inloggad() !== null;
$tg = kc_tabell('md_objektgrupp');
$tk = kc_tabell('md_objektgrupp_koppling');
$tt = kc_tabell('md_objektgruppstyp');

kc_sidhuvud('Objektgrupper');
?>
<p class="kc-hjalp" style="text-align:right">Import: <a href="<?= kc_h(kc_url('import.php?tabell=objektgrupp')) ?>">objektgrupper</a> · <a href="<?= kc_h(kc_url('import.php?tabell=koppling')) ?>">kopplingar</a></p>
<p class="kc-dampad">En objektgrupp (t.ex. ett trapphus, ett soprum eller ett värmesystem) kopplas till fastigheter,
   byggnader och objekt som <strong>resurs</strong> (själva saken) eller <strong>användare</strong> (de som använder den).</p>
<?php if ($inloggad): ?>
  <p><a class="kc-knapp" href="<?= kc_h(kc_url('objektgrupp_redigera.php')) ?>">+ Ny objektgrupp</a></p>
<?php endif; ?>

<?= kc_lista([
    'kalla' => "SELECT g.id, g.uuid, g.objektgruppsnummer, g.namn, g.beskrivning, t.namn AS typ,
                       (SELECT COUNT(*) FROM `$tk` k WHERE k.objektgrupp_uuid = g.uuid AND k.is_deleted = 0
                           AND k.kopplingstyp = 'resurs') AS antal_resurser,
                       (SELECT COUNT(*) FROM `$tk` k WHERE k.objektgrupp_uuid = g.uuid AND k.is_deleted = 0
                           AND k.kopplingstyp = 'anvandare') AS antal_anvandare
                  FROM `$tg` g
                  LEFT JOIN `$tt` t ON t.uuid = g.objektgruppstyp_uuid
                 WHERE g.is_deleted = 0",
    'kolumner'          => [
        'objektgruppsnummer' => 'Nr',
        'namn'               => 'Namn',
        'typ'                => 'Typ',
        'beskrivning'        => 'Beskrivning',
        'antal_resurser'     => 'Resurser',
        'antal_anvandare'    => 'Användare',
    ],
    'extra_kolumner'    => ['uuid'],
    'standardsortering' => 'objektgruppsnummer',
    'radlank'           => fn($rad) => kc_url('objektgrupp.php?uuid=' . rawurlencode($rad['uuid'])),
    'export_namn'       => 'fas_objektgrupp',
    'tom_text'          => 'Inga objektgrupper registrerade.',
]) ?>
<p class="kc-hjalp">Kolumnerna Resurser och Användare räknar direkta kopplingar. Gruppens sida visar alla objekt som omfattas.</p>
<?php kc_sidfot();
