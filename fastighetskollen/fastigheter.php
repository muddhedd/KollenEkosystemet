<?php
/**
 * FastighetsKollen – fastigheter.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Lista över fastigheter med huvudadress. Läsning öppen för alla.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/fas.php';

$inloggad = fas_inloggad() !== null;
$tf = kc_tabell('md_fastighet');
$ta = kc_tabell('md_adress');

kc_sidhuvud('Fastigheter');
?>
<p class="kc-hjalp" style="text-align:right">Import: <a href="<?= kc_h(kc_url('import.php?tabell=fastighet')) ?>">fastigheter</a> · <a href="<?= kc_h(kc_url('import.php?tabell=adress')) ?>">adresser</a></p>
<?php if ($inloggad): ?>
<p><a class="kc-knapp kc-knapp-primar" href="<?= kc_h(kc_url('fastighet_redigera.php')) ?>">+ Ny fastighet</a></p>
<?php endif; ?>

<?= kc_lista([
    'kalla' => "SELECT f.id, f.uuid, f.namn, f.fastighetsnummer, f.team, f.bolag, f.kostnadsstalle,
                       (SELECT CONCAT_WS(', ', NULLIF(a.gatuadress, ''), NULLIF(CONCAT_WS(' ', a.postnummer, a.ort), ''))
                          FROM `$ta` a
                         WHERE a.fastighet_uuid = f.uuid AND a.is_deleted = 0
                         ORDER BY a.ar_huvudadress DESC, a.id LIMIT 1) AS huvudadress
                  FROM `$tf` f
                 WHERE f.is_deleted = 0",
    'kolumner'          => [
        'fastighetsnummer' => 'Fastighetsnr',
        'namn'             => 'Namn',
        'huvudadress'      => 'Huvudadress',
        'team'             => 'Team',
        'bolag'            => 'Bolag',
        'kostnadsstalle'   => 'Kostnadsställe',
    ],
    'extra_kolumner'    => ['uuid'],
    'standardsortering' => 'fastighetsnummer',
    'radlank'           => fn($rad) => kc_url('fastighet.php?uuid=' . rawurlencode($rad['uuid'])),
    'export_namn'       => 'fas_fastighet',
    'tom_text'          => 'Inga fastigheter registrerade.',
]) ?>
<?php kc_sidfot();
