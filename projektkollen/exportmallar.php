<?php
/**
 * ProjektKollen – exportmallar.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Lista över exportmallar. Den färdiga mallen för MätarKollen är låst (kan kopieras); egna mallar
 * skapas, ändras och tas bort av inloggade. Läsning öppen för alla.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/prj.php';
require __DIR__ . '/prj_export.php';

$inloggad = prj_inloggad() !== null;
$tm = kc_tabell('md_exportmall');
$tk = kc_tabell('md_exportkolumn');

kc_sidhuvud('Exportmallar');
?>
<p><?php if ($inloggad): ?><a class="kc-knapp kc-knapp-primar" href="<?= kc_h(kc_url('exportmall_redigera.php')) ?>">+ Ny mall</a><?php endif; ?></p>
<p class="kc-hjalp">En mall bestämmer filens kolumner, avgränsare, teckenkodning, urval och hur många rader varje fil får ha.
   Exporter görs per projekt under Projekt → Export. Låsta mallar kan inte ändras – kopiera dem till en egen mall.</p>

<?= kc_lista([
    'kalla' => "SELECT x.id, x.uuid, x.namn, x.beskrivning, x.urval, x.max_rader, x.med_nycklar, x.last,
                       (SELECT COUNT(*) FROM `$tk` k WHERE k.mall_uuid = x.uuid AND k.is_deleted = 0) AS kolumner
                  FROM `$tm` x WHERE x.is_deleted = 0",
    'kolumner' => [
        'namn'        => 'Namn',
        'urval'       => 'Urval',
        'max_rader'   => 'Rader per fil',
        'kolumner'    => 'Kolumner',
        'med_nycklar' => 'Nycklar',
        'last'        => 'Låst',
    ],
    'extra_kolumner'    => ['uuid', 'beskrivning'],
    'standardsortering' => 'namn',
    'format' => [
        'urval'       => fn($v) => kc_h(PRJ_EXPORT_URVAL[$v] ?? $v),
        'max_rader'   => fn($v) => (int)$v === 0 ? '<span class="kc-dampad">ingen delning</span>' : kc_h($v),
        'med_nycklar' => fn($v) => (int)$v === 1 ? '<strong>ja</strong>' : 'nej',
        'last'        => fn($v) => (int)$v === 1 ? 'låst' : '',
    ],
    'radlank'     => fn($rad) => kc_url('exportmall_redigera.php?uuid=' . rawurlencode($rad['uuid'])),
    'export_namn' => 'prj_exportmallar',
    'tom_text'    => 'Inga exportmallar. Kör install.php för att lägga in MätarKollen-mallen.',
]) ?>
<?php kc_sidfot();
