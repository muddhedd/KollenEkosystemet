<?php
/**
 * MätarKollen – matpunkter.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Lista över mätpunkter. Läsning öppen för alla.
 * Valfri parameter: saknas=1 visar bara mätpunkter vars placering eller betjäning inte längre finns i FastighetsKollen.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/mat.php';

$inloggad = mat_inloggad() !== null;
$tm = kc_tabell('md_matpunkt');
$tk = kc_tabell('md_matpunkt_storhet');
$ts = kc_tabell('md_storhet');
$tf = kc_tabell('ld_fas_fastighet');
$tb = kc_tabell('ld_fas_byggnad');
$to = kc_tabell('ld_fas_objekt');
$tg = kc_tabell('ld_fas_objektgrupp');
$tmo = kc_tabell('md_montering');
$tma = kc_tabell('md_matare');
$bara_saknas = kc_get('saknas') === '1';

// Kopplingar till fastighetsdatan: placering (f, b, o, g) och betjäning (bf, bb, bo, bg)
$mat_join = "LEFT JOIN `$tf` f ON m.niva = 'fastighet' AND f.uuid = m.placering_uuid AND f.is_deleted = 0
             LEFT JOIN `$tb` b ON m.niva = 'byggnad' AND b.uuid = m.placering_uuid AND b.is_deleted = 0
             LEFT JOIN `$to` o ON m.niva = 'objekt' AND o.uuid = m.placering_uuid AND o.is_deleted = 0
             LEFT JOIN `$tg` g ON m.niva = 'objektgrupp' AND g.uuid = m.placering_uuid AND g.is_deleted = 0
             LEFT JOIN `$tf` bf ON m.betjaning_niva = 'fastighet' AND bf.uuid = m.betjaning_uuid AND bf.is_deleted = 0
             LEFT JOIN `$tb` bb ON m.betjaning_niva = 'byggnad' AND bb.uuid = m.betjaning_uuid AND bb.is_deleted = 0
             LEFT JOIN `$to` bo ON m.betjaning_niva = 'objekt' AND bo.uuid = m.betjaning_uuid AND bo.is_deleted = 0
             LEFT JOIN `$tg` bg ON m.betjaning_niva = 'objektgrupp' AND bg.uuid = m.betjaning_uuid AND bg.is_deleted = 0";
$mat_saknas_p = 'COALESCE(f.uuid, b.uuid, o.uuid, g.uuid) IS NULL';
$mat_saknas_b = 'COALESCE(bf.uuid, bb.uuid, bo.uuid, bg.uuid) IS NULL';

$antal_saknas = (int)kc_hamta_varde(
    "SELECT COUNT(*) FROM `$tm` m $mat_join WHERE m.is_deleted = 0 AND ($mat_saknas_p OR $mat_saknas_b)"
);

kc_sidhuvud($bara_saknas ? 'Mätpunkter med saknad placering eller betjäning' : 'Mätpunkter');
?>
<?php if ($antal_saknas > 0 && !$bara_saknas): ?>
  <div class="kc-flash kc-flash-fel"><?= kc_h($antal_saknas) ?> mätpunkter har en placering eller betjäning som inte längre finns i FastighetsKollen.
    <a href="<?= kc_h(kc_url('matpunkter.php?saknas=1')) ?>">Visa dem »</a></div>
<?php elseif ($bara_saknas): ?>
  <p><a href="<?= kc_h(kc_url('matpunkter.php')) ?>">« Alla mätpunkter</a></p>
<?php endif; ?>
<p>
  <?php if ($inloggad): ?><a class="kc-knapp" href="<?= kc_h(kc_url('matpunkt_redigera.php')) ?>">+ Ny mätpunkt</a> &nbsp;<?php endif; ?>
  <span class="kc-hjalp">Import: <a href="<?= kc_h(kc_url('import.php?tabell=matpunkt')) ?>">mätpunkter och mätare</a></span>
</p>

<?= kc_lista([
    'kalla' => "SELECT m.id, m.uuid, m.matpunktsnamn, m.typ, m.status, m.niva, m.platsbeskrivning,
                       COALESCE(f.fastighetsnummer, b.byggnadsnummer, o.objektnummer, g.objektgruppsnummer, '') AS placering,
                       IF($mat_saknas_p, 1, 0) AS saknas_p,
                       m.betjaning_niva,
                       COALESCE(bf.fastighetsnummer, bb.byggnadsnummer, bo.objektnummer, bg.objektgruppsnummer, '') AS betjanar,
                       IF($mat_saknas_b, 1, 0) AS saknas_b,
                       IF($mat_saknas_p OR $mat_saknas_b, 1, 0) AS saknas,
                       COALESCE(p.matpunktsnamn, '') AS foregaende,
                       (SELECT GROUP_CONCAT(s.namn ORDER BY s.sortering, s.namn SEPARATOR ', ')
                          FROM `$tk` k JOIN `$ts` s ON s.uuid = k.storhet_uuid AND s.is_deleted = 0
                         WHERE k.matpunkt_uuid = m.uuid AND k.is_deleted = 0) AS storheter,
                       (SELECT ma.matarnummer FROM `$tmo` mo JOIN `$tma` ma ON ma.uuid = mo.matare_uuid AND ma.is_deleted = 0
                         WHERE mo.matpunkt_uuid = m.uuid AND mo.is_deleted = 0 AND mo.slutdatum IS NULL LIMIT 1) AS matare
                  FROM `$tm` m
                  $mat_join
                  LEFT JOIN `$tm` p ON p.uuid = m.foregaende_uuid AND p.is_deleted = 0
                 WHERE m.is_deleted = 0",
    'villkor'           => $bara_saknas ? 'saknas = 1' : '',
    'kolumner'          => [
        'matpunktsnamn'    => 'Mätpunkt',
        'typ'              => 'Typ',
        'status'           => 'Status',
        'niva'             => 'Nivå',
        'placering'        => 'Placering',
        'platsbeskrivning' => 'Plats',
        'betjaning_niva'   => 'Betjänar nivå',
        'betjanar'         => 'Betjänar',
        'storheter'        => 'Storheter',
        'matare'           => 'Mätare',
        'foregaende'       => 'Föregående',
    ],
    'extra_kolumner'    => ['uuid', 'saknas', 'saknas_p', 'saknas_b'],
    'standardsortering' => 'matpunktsnamn',
    'format'            => [
        'typ'       => fn($v) => kc_h(MAT_MATPUNKT_TYPER[$v] ?? $v),
        'status'    => fn($v) => kc_h(MAT_MATPUNKT_STATUS[$v] ?? $v),
        'niva'      => fn($v) => kc_h(MAT_PLACERING[$v]['etikett'] ?? $v),
        'placering' => fn($v, $rad) => (int)$rad['saknas_p'] === 1
            ? '<strong style="color:var(--kc-fel)">saknas</strong>' : kc_h($v),
        'betjaning_niva' => fn($v) => kc_h(MAT_PLACERING[$v]['etikett'] ?? $v),
        'betjanar'  => fn($v, $rad) => (int)$rad['saknas_b'] === 1
            ? '<strong style="color:var(--kc-fel)">saknas</strong>' : kc_h($v),
    ],
    'radlank'           => fn($rad) => kc_url('matpunkt.php?uuid=' . rawurlencode($rad['uuid'])),
    'export_namn'       => 'mat_matpunkt',
    'tom_text'          => $bara_saknas ? 'Alla mätpunkter har giltig placering och betjäning.' : 'Inga mätpunkter registrerade.',
]) ?>
<?php kc_sidfot();
