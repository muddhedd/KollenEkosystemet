<?php
/**
 * CentralKollen – verktyg.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Verktygsregistret. Endast administratörer.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/cen.php';

cen_krav_admin();

kc_sidhuvud('Verktyg');
?>
<p class="kc-dampad">Alla roller i ekosystemet. Klicka på ett verktyg för att ange bas-URL,
   aktivera det och generera API-nycklar.</p>

<?= kc_lista([
    'tabell'            => kc_tabell('md_verktyg'),
    'kolumner'          => [
        'namn'      => 'Verktyg',
        'prefix'    => 'Prefix',
        'bas_url'   => 'Bas-URL',
        'is_active' => 'Status',
    ],
    'extra_kolumner'    => ['uuid'],
    'villkor'           => 'is_deleted = 0',
    'standardsortering' => 'namn',
    'format'            => [
        'prefix'    => fn($v) => '<code>' . kc_h($v) . '</code>',
        'bas_url'   => fn($v) => $v === '' ? '<em class="kc-dampad">ej angiven</em>' : kc_h($v),
        'is_active' => fn($v) => (int)$v === 1 ? '<strong>Aktivt</strong>' : '<span class="kc-dampad">Inaktivt</span>',
    ],
    'radlank'           => fn($rad) => kc_url('verktyg_redigera.php?uuid=' . rawurlencode($rad['uuid'])),
    'tom_text'          => 'Inga verktyg hittades.',
]) ?>
<?php kc_sidfot();
