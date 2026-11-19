<?php
/**
 * ProjektKollen – matarlista.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Det gemensamma mätarlagret: inköpta mätare med fabrikat, modell, inköp och var de används
 * (projekt och mätpunkt sätts vid provisionering). Läsning öppen för alla.
 * Nycklar visas aldrig här och exporteras aldrig.
 * Valfri parameter: status=ledig | provisionerad.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/prj.php';

$inloggad = prj_inloggad() !== null;
$tm = kc_tabell('md_matare');
$tp = kc_tabell('md_projekt');
$tmp = kc_tabell('md_matpunkt');
$tlm = kc_tabell('ld_mat_matare');
$status = in_array(kc_get('status'), ['ledig', 'provisionerad'], true) ? kc_get('status') : '';
[$totalt, $lediga] = prj_matare_antal();

kc_sidhuvud(match ($status) {
    'ledig'         => 'Mätare i lager (lediga)',
    'provisionerad' => 'Provisionerade mätare',
    default         => 'Mätare',
});
?>
<p>
  <?php if ($inloggad): ?><a class="kc-knapp kc-knapp-primar" href="<?= kc_h(kc_url('import.php?tabell=matare')) ?>">Importera mätare</a> &nbsp;<?php endif; ?>
  <?php if ($status !== ''): ?>
    <a href="<?= kc_h(kc_url('matarlista.php')) ?>">« Alla mätare</a>
  <?php else: ?>
    <a href="<?= kc_h(kc_url('matarlista.php?status=ledig')) ?>">Visa bara lediga »</a> &nbsp;
    <a href="<?= kc_h(kc_url('matarlista.php?status=provisionerad')) ?>">Visa bara provisionerade »</a>
  <?php endif; ?>
</p>
<p><?= kc_h($totalt) ?> mätare i lagret, varav <?= kc_h($lediga) ?> lediga.</p>

<?= kc_lista([
    'kalla' => "SELECT m.id, m.uuid, m.matar_id, m.fabrikat, m.modell, m.matartyp, m.kommunikation, m.storheter,
                       m.gateway, m.inkop, m.kommentar,
                       CASE WHEN m.matpunkt_uuid <> '' THEN 'provisionerad' ELSE 'ledig' END AS status,
                       COALESCE(p.projektnummer, '') AS projekt, COALESCE(mp.matpunktsnamn, '') AS matpunkt,
                       CASE WHEN EXISTS (SELECT 1 FROM `$tlm` lm WHERE lm.matar_id = m.matar_id AND lm.is_deleted = 0)
                            THEN 'ja' ELSE '' END AS i_mat
                  FROM `$tm` m
                  LEFT JOIN `$tp` p ON p.uuid = m.projekt_uuid AND p.is_deleted = 0
                  LEFT JOIN `$tmp` mp ON mp.uuid = m.matpunkt_uuid AND mp.is_deleted = 0
                 WHERE m.is_deleted = 0",
    'villkor'           => $status !== '' ? 'status = ?' : '',
    'villkor_params'    => $status !== '' ? [$status] : [],
    'kolumner'          => [
        'matar_id' => 'MätarId',
        'fabrikat' => 'Fabrikat',
        'modell'   => 'Modell',
        'matartyp' => 'Typ',
        'inkop'    => 'Inköp',
        'status'   => 'Status',
        'projekt'  => 'Projekt',
        'matpunkt' => 'Mätpunkt',
        'i_mat'    => 'I MätarKollen',
    ],
    'extra_kolumner'    => ['uuid', 'kommunikation', 'storheter', 'gateway', 'kommentar'],
    'standardsortering' => 'matar_id',
    'format'            => [
        'status' => fn($v) => $v === 'ledig' ? '<span class="kc-dampad">ledig</span>' : '<strong>provisionerad</strong>',
        'i_mat'  => fn($v) => $v === 'ja' ? '<strong>finns redan</strong>' : '',
    ],
    'radlank'           => fn($rad) => kc_url('matare.php?uuid=' . rawurlencode($rad['uuid'])),
    'export_namn'       => 'prj_matare' . ($status !== '' ? '_' . $status : ''),
    'tom_text'          => $status === '' ? 'Inga mätare i lagret. Importera inköpta mätare.' : 'Inga mätare.',
]) ?>
<?php kc_sidfot();
