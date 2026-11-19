<?php
/**
 * CentralKollen – synkstatus.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Senaste synk per verktyg, tabell och riktning. Endast administratörer.
 * Tips: filtrera på ett prefix (t.ex. nyk) för att se ett verktyg.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/cen.php';

cen_krav_admin();

$ts = kc_tabell('sys_synkstatus');
$tv = kc_tabell('md_verktyg');

// Sammanfattning ovanför listan
$summa = kc_hamta_en(
    "SELECT COUNT(*) AS totalt,
            SUM(status = 200) AS ok,
            SUM(status <> 200) AS fel,
            MAX(senaste_tid) AS senast
       FROM `$ts`"
) ?? ['totalt' => 0, 'ok' => 0, 'fel' => 0, 'senast' => null];

kc_sidhuvud('Synkstatus');
?>
<div class="kc-ruta">
  <p>Flöden: <strong><?= kc_h((int)$summa['totalt']) ?></strong> ·
     OK: <strong><?= kc_h((int)$summa['ok']) ?></strong> ·
     Fel: <strong<?= (int)$summa['fel'] > 0 ? ' style="color:var(--kc-fel)"' : '' ?>><?= kc_h((int)$summa['fel']) ?></strong> ·
     Senaste synk: <?= $summa['senast'] ? kc_h(kc_tid($summa['senast'], 'Y-m-d H:i:s')) : '<em class="kc-dampad">ingen än</em>' ?></p>
  <p class="kc-hjalp">Ett flöde är ett verktyg + en tabell + en riktning (push = verktyget lämnar data,
     pull = verktyget hämtar data). Detaljer per anrop finns i <a href="<?= kc_h(kc_url('synklogg.php')) ?>">synkloggen</a>.</p>
</div>

<?= kc_lista([
    'kalla' => "SELECT s.id, s.verktyg_prefix, v.namn AS verktyg_namn, s.tabell, s.riktning,
                       s.senaste_tid, s.senaste_ok_tid, s.senaste_seq, s.antal, s.status, s.meddelande
                  FROM `$ts` s
                  LEFT JOIN `$tv` v ON v.prefix = s.verktyg_prefix AND v.is_deleted = 0",
    'kolumner'          => [
        'verktyg_prefix' => 'Verktyg',
        'tabell'         => 'Tabell',
        'riktning'       => 'Riktning',
        'status'         => 'Status',
        'senaste_tid'    => 'Senaste anrop',
        'senaste_ok_tid' => 'Senast OK',
        'antal'          => 'Rader',
        'senaste_seq'    => 'Seq',
        'meddelande'     => 'Meddelande',
    ],
    'extra_kolumner'    => ['verktyg_namn'],
    'standardsortering' => 'senaste_tid',
    'standardriktning'  => 'desc',
    'format'            => [
        'verktyg_prefix' => fn($v, $rad) => '<code>' . kc_h($v) . '</code> ' . kc_h($rad['verktyg_namn'] ?? ''),
        'riktning'       => fn($v) => $v === 'push' ? 'push ↑' : 'pull ↓',
        'status'         => fn($v) => (int)$v === 200
            ? '<strong style="color:var(--kc-ok)">OK</strong>'
            : '<strong style="color:var(--kc-fel)">Fel ' . kc_h($v) . '</strong>',
        'senaste_tid'    => fn($v) => kc_h(kc_tid($v, 'Y-m-d H:i:s')),
        'senaste_ok_tid' => fn($v) => $v === null ? '<em class="kc-dampad">aldrig</em>' : kc_h(kc_tid($v, 'Y-m-d H:i:s')),
        'senaste_seq'    => fn($v, $rad) => $rad['riktning'] === 'pull' ? kc_h($v) : '<span class="kc-dampad">–</span>',
        'meddelande'     => fn($v) => $v === 'ok' ? '' : kc_h($v),
    ],
    'tom_text'          => 'Inga synkanrop har gjorts ännu.',
]) ?>
<?php kc_sidfot();
