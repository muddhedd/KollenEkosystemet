<?php
/**
 * CentralKollen – dataflode.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Översikt över alla registrerade tabeller: vem som är master och vilka
 * som prenumererar. Endast administratörer. Kräver kärnpaket 1.1.0.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/cen.php';

cen_krav_admin();

$tt = kc_tabell('md_tabell');
$tv = kc_tabell('md_verktyg');
$tp = kc_tabell('md_prenumeration');

kc_sidhuvud('Dataflöde');
?>
<p class="kc-dampad">Alla tabeller som registrerats som masterdata. Tabeller läggs till och
   prenumerationer sätts på respektive <a href="<?= kc_h(kc_url('verktyg.php')) ?>">verktygs sida</a>.</p>

<?= kc_lista([
    'kalla' => "SELECT t.id, t.uuid, t.tabellnamn, t.beskrivning, t.is_active,
                       v.prefix AS master, v.namn AS master_namn,
                       CONCAT(?, v.prefix, '_', t.tabellnamn) AS ck_namn,
                       (SELECT GROUP_CONCAT(v2.prefix ORDER BY v2.prefix SEPARATOR ', ')
                          FROM `$tp` p JOIN `$tv` v2 ON v2.uuid = p.verktyg_uuid AND v2.is_deleted = 0
                         WHERE p.tabell_uuid = t.uuid AND p.is_deleted = 0) AS prenumeranter
                  FROM `$tt` t
                  JOIN `$tv` v ON v.uuid = t.verktyg_uuid AND v.is_deleted = 0
                 WHERE t.is_deleted = 0",
    'kalla_params'      => [(KOLL_VARIANT === 'demo' ? 'demo_' : '') . 'ck_'],
    'kolumner'          => [
        'tabellnamn'    => 'Tabell',
        'master'        => 'Master',
        'ck_namn'       => 'Central kopia',
        'prenumeranter' => 'Prenumeranter',
        'beskrivning'   => 'Beskrivning',
        'is_active'     => 'Status',
    ],
    'extra_kolumner'    => ['uuid', 'master_namn'],
    'standardsortering' => 'master',
    'format'            => [
        'master'        => fn($v, $rad) => '<code>' . kc_h($v) . '</code> ' . kc_h($rad['master_namn']),
        'ck_namn'       => fn($v) => '<code>' . kc_h($v) . '</code>',
        'prenumeranter' => fn($v) => ($v ?? '') === '' ? '<em class="kc-dampad">inga</em>' : kc_h($v),
        'is_active'     => fn($v) => (int)$v === 1 ? 'Aktiv' : '<span class="kc-dampad">Inaktiv</span>',
    ],
    'radlank'           => fn($rad) => kc_url('tabell_redigera.php?uuid=' . rawurlencode($rad['uuid'])),
    'tom_text'          => 'Inga tabeller registrerade ännu.',
]) ?>
<?php kc_sidfot();
