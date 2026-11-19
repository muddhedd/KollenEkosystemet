<?php
/**
 * MätarKollen – matarlista.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Lista över mätare med typ, fabrikat, modell, kommunikation och var mätaren sitter nu.
 * Läsning öppen för alla. Nycklar visas aldrig här och exporteras aldrig.
 * Valfria parametrar (en i taget):
 *   ej_monterade=1  bara mätare som inte sitter i någon mätpunkt
 *   samma_id=1      bara mätare vars mätarId finns på fler än en mätare (oavsett gateway),
 *                   sorterat på mätarId – för att hitta fel i dokumentationen
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/mat.php';

$inloggad = mat_inloggad() !== null;
$tm = kc_tabell('md_matare');
$tg = kc_tabell('md_montering');
$tp = kc_tabell('md_matpunkt');
$typ = static fn(string $lista): string => kc_tabell(MAT_TYPLISTOR[$lista]['tabell']);
$bara_lediga = kc_get('ej_monterade') === '1';
$bara_samma  = !$bara_lediga && kc_get('samma_id') === '1';

$kolumner = [
    'matarnummer'    => 'Mätarnr',
    'matar_id'       => 'MätarId',
    'matartyp'       => 'Typ',
    'fabrikat'       => 'Fabrikat',
    'modell'         => 'Modell',
    'kommunikation'  => 'Kommunikation',
    'gateway'        => 'Gateway',
    'matpunkt'       => 'Monterad i',
    'monterad_sedan' => 'Sedan',
];
if ($bara_samma) {
    $kolumner = ['matar_id' => 'MätarId', 'antal_samma_id' => 'Antal med samma id'] + $kolumner;
}

kc_sidhuvud(match (true) {
    $bara_lediga => 'Mätare som inte är monterade',
    $bara_samma  => 'Mätare med samma mätarId',
    default      => 'Mätare',
});
?>
<p>
  <?php if ($inloggad): ?><a class="kc-knapp kc-knapp-primar" href="<?= kc_h(kc_url('matare_redigera.php')) ?>">+ Ny mätare</a> &nbsp;<?php endif; ?>
  <?php if ($bara_lediga || $bara_samma): ?>
    <a href="<?= kc_h(kc_url('matarlista.php')) ?>">« Alla mätare</a>
  <?php else: ?>
    <a href="<?= kc_h(kc_url('matarlista.php?ej_monterade=1')) ?>">Visa bara ej monterade »</a> &nbsp;
    <a href="<?= kc_h(kc_url('matarlista.php?samma_id=1')) ?>">Visa mätare med samma mätarId »</a>
  <?php endif; ?>
</p>
<?php if ($bara_samma): ?>
  <p class="kc-hjalp">Mätare vars mätarId finns på fler än en mätare, oavsett gateway. Samma mätarId på olika
     gateways kan vara rätt, men kontrollera mot installationsdokumentationen.</p>
<?php endif; ?>

<?= kc_lista([
    'kalla' => "SELECT m.id, m.uuid, m.matarnummer, m.matar_id, m.gateway, m.kommentar,
                       COALESCE(ty.namn, '') AS matartyp, COALESCE(fa.namn, '') AS fabrikat,
                       COALESCE(mo.namn, '') AS modell, COALESCE(ko.namn, '') AS kommunikation,
                       COALESCE(p.matpunktsnamn, '') AS matpunkt,
                       g.startdatum AS monterad_sedan,
                       COALESCE(d.antal, 1) AS antal_samma_id
                  FROM `$tm` m
                  LEFT JOIN `{$typ('matartyp')}` ty ON ty.uuid = m.matartyp_uuid AND ty.is_deleted = 0
                  LEFT JOIN `{$typ('fabrikat')}` fa ON fa.uuid = m.fabrikat_uuid AND fa.is_deleted = 0
                  LEFT JOIN `{$typ('matarmodell')}` mo ON mo.uuid = m.matarmodell_uuid AND mo.is_deleted = 0
                  LEFT JOIN `{$typ('kommunikation')}` ko ON ko.uuid = m.kommunikation_uuid AND ko.is_deleted = 0
                  LEFT JOIN `$tg` g ON g.matare_uuid = m.uuid AND g.is_deleted = 0 AND g.slutdatum IS NULL
                  LEFT JOIN `$tp` p ON p.uuid = g.matpunkt_uuid AND p.is_deleted = 0
                  LEFT JOIN (SELECT matar_id, COUNT(*) AS antal FROM `$tm`
                              WHERE is_deleted = 0 AND matar_id <> '' GROUP BY matar_id) d ON d.matar_id = m.matar_id
                 WHERE m.is_deleted = 0",
    'villkor'           => match (true) {
        $bara_lediga => "matpunkt = ''",
        $bara_samma  => 'antal_samma_id > 1',
        default      => '',
    },
    'kolumner'          => $kolumner,
    'extra_kolumner'    => ['uuid', 'kommentar'],
    'standardsortering' => $bara_samma ? 'matar_id' : 'matarnummer',
    'format'            => [
        'matpunkt' => fn($v) => $v === '' ? '<span class="kc-dampad">ej monterad</span>' : kc_h($v),
    ],
    'radlank'           => fn($rad) => kc_url('matare.php?uuid=' . rawurlencode($rad['uuid'])),
    'export_namn'       => $bara_samma ? 'mat_matare_samma_id' : 'mat_matare',
    'tom_text'          => match (true) {
        $bara_lediga => 'Alla mätare är monterade.',
        $bara_samma  => 'Inga mätare delar mätarId.',
        default      => 'Inga mätare registrerade.',
    },
]) ?>
<?php kc_sidfot();
