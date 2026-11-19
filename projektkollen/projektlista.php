<?php
/**
 * ProjektKollen – projektlista.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Lista över projekt. Läsning öppen för alla.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/prj.php';

$inloggad = prj_inloggad() !== null;
$tp = kc_tabell('md_projekt');
$tt = kc_tabell('md_projekttyp');

kc_sidhuvud('Projekt');
?>
<?php if ($inloggad): ?>
  <p><a class="kc-knapp kc-knapp-primar" href="<?= kc_h(kc_url('projekt_redigera.php')) ?>">+ Nytt projekt</a></p>
<?php endif; ?>

<?= kc_lista([
    'kalla' => "SELECT p.id, p.uuid, p.projektnummer, p.namn, p.status, p.projektledare, p.startdatum, p.slutdatum,
                       COALESCE(t.namn, '') AS projekttyp
                  FROM `$tp` p
                  LEFT JOIN `$tt` t ON t.uuid = p.projekttyp_uuid AND t.is_deleted = 0
                 WHERE p.is_deleted = 0",
    'kolumner'          => [
        'projektnummer' => 'Nr',
        'namn'          => 'Namn',
        'projekttyp'    => 'Typ',
        'status'        => 'Status',
        'projektledare' => 'Projektledare',
        'startdatum'    => 'Start',
        'slutdatum'     => 'Slut',
    ],
    'extra_kolumner'    => ['uuid'],
    'standardsortering' => 'projektnummer',
    'format'            => [
        'status' => fn($v) => kc_h(PRJ_STATUS[$v] ?? $v),
    ],
    'radlank'           => fn($rad) => kc_url('projekt.php?uuid=' . rawurlencode($rad['uuid'])),
    'export_namn'       => 'prj_projekt',
    'tom_text'          => 'Inga projekt.',
]) ?>
<?php kc_sidfot();
