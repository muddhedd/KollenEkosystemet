<?php
/**
 * FastighetsKollen – byggnader.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Lista över alla byggnader (inkl. utomhusområden). Läsning öppen för alla.
 * Valfri parameter: fastighet=<uuid> visar bara den fastighetens byggnader.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/fas.php';

$tb = kc_tabell('md_byggnad');
$tf = kc_tabell('md_fastighet');
$ta = kc_tabell('md_adress');
$to = kc_tabell('md_objekt');
$tt = kc_tabell('md_byggnadstyp');

$fastighet = fas_fastighet_hamta(kc_get('fastighet'));

kc_sidhuvud($fastighet !== null ? 'Byggnader på ' . trim($fastighet['fastighetsnummer'] . ' ' . $fastighet['namn']) : 'Byggnader');
?>
<p class="kc-hjalp" style="text-align:right">Import: <a href="<?= kc_h(kc_url('import.php?tabell=byggnad')) ?>">byggnader</a> · <a href="<?= kc_h(kc_url('import.php?tabell=adress')) ?>">adresser</a></p>
<?php if ($fastighet !== null): ?>
  <p><a href="<?= kc_h(kc_url('fastighet.php?uuid=' . rawurlencode($fastighet['uuid']))) ?>">« Till fastigheten</a> ·
     <a href="<?= kc_h(kc_url('byggnader.php')) ?>">Visa alla byggnader</a></p>
<?php else: ?>
  <p class="kc-dampad">Nya byggnader läggs till från fastighetens sida.</p>
<?php endif; ?>

<?= kc_lista([
    'kalla' => "SELECT b.id, b.uuid, b.byggnadsnummer, b.namn, b.fastighet_uuid,
                       t.namn AS typ,
                       CONCAT_WS(' ', f.fastighetsnummer, f.namn) AS fastighet,
                       (SELECT CONCAT_WS(', ', NULLIF(a.gatuadress, ''), NULLIF(CONCAT_WS(' ', a.postnummer, a.ort), ''))
                          FROM `$ta` a WHERE a.byggnad_uuid = b.uuid AND a.is_deleted = 0
                         ORDER BY a.ar_huvudadress DESC, a.id LIMIT 1) AS huvudadress,
                       (SELECT COUNT(*) FROM `$to` o WHERE o.byggnad_uuid = b.uuid AND o.is_deleted = 0) AS antal_objekt
                  FROM `$tb` b
                  LEFT JOIN `$tf` f ON f.uuid = b.fastighet_uuid
                  LEFT JOIN `$tt` t ON t.uuid = b.byggnadstyp_uuid
                 WHERE b.is_deleted = 0",
    'villkor'           => $fastighet !== null ? 'fastighet_uuid = ?' : '',
    'villkor_params'    => $fastighet !== null ? [$fastighet['uuid']] : [],
    'kolumner'          => [
        'byggnadsnummer' => 'Byggnadsnr',
        'namn'           => 'Namn',
        'typ'            => 'Typ',
        'huvudadress'    => 'Huvudadress',
        'fastighet'      => 'Fastighet',
        'antal_objekt'   => 'Objekt',
    ],
    'extra_kolumner'    => ['uuid', 'fastighet_uuid'],
    'standardsortering' => 'byggnadsnummer',
    'radlank'           => fn($rad) => kc_url('byggnad.php?uuid=' . rawurlencode($rad['uuid'])),
    'export_namn'       => 'fas_byggnad',
    'tom_text'          => 'Inga byggnader registrerade.',
]) ?>
<?php kc_sidfot();
