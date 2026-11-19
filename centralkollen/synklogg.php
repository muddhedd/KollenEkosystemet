<?php
/**
 * CentralKollen – synklogg.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Historik över API-anrop (sparas 90 dagar). Endast administratörer.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/cen.php';

cen_krav_admin();

kc_sidhuvud('Synklogg');
?>
<p class="kc-dampad">Alla anrop till CentralKollens API, senaste först. Tider i <?= kc_h(KOLL_TIDSZON) ?>.</p>

<?= kc_lista([
    'tabell'            => kc_tabell('sys_synklogg'),
    'kolumner'          => [
        'tid'            => 'Tid',
        'verktyg_prefix' => 'Verktyg',
        'endpoint'       => 'Endpoint',
        'tabell'         => 'Tabell',
        'antal'          => 'Rader',
        'status'         => 'Status',
        'meddelande'     => 'Meddelande',
        'ms'             => 'ms',
    ],
    'standardsortering' => 'tid',
    'standardriktning'  => 'desc',
    'format'            => [
        'tid'    => fn($v) => kc_h(kc_tid($v, 'Y-m-d H:i:s')),
        'status' => fn($v) => (int)$v === 200 ? '200' : '<strong>' . kc_h($v) . '</strong>',
    ],
    'tom_text'          => 'Inga anrop loggade ännu.',
]) ?>
<?php kc_sidfot();
