<?php
/**
 * ProjektKollen – demo_data.php
 * Variant: DEMO och GITHUB (nekas i LABB)
 * Fyller verktyget med påhittade exempeldata som hör ihop med FastighetsKollens och MätarKollens
 * exempeldata. Allt går via verktygets egna funktioner och importer:
 *  - mätarlager: 30 klimatgivare från två inköp, med nycklar;
 *  - P1 "Klimatgivare Lärkhuset" (Pågående): omfattning Lärkhuset, objektlista med 0–2 givare per
 *    objekt, låsta namn (etiketter utskrivna), provisionerade och monterade mätare och en export
 *    med MätarKollen-mallen;
 *  - P2 "Klimatgivare Hjorten Hus B" (Planering): preliminära mätpunkter, där de fyra första
 *    lägenheterna får infotext om befintliga mätpunkter i MätarKollen;
 *  - en egen exportmall för en LoRaWAN-plattform.
 * Kräver att fastighets- och mätardata har hämtats (Synk → Hämta). Öppen bara när KOLL_INSTALL = true.
 * Körs en gång: finns det redan projekt görs inget. Efteråt: Synk → Skicka.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/prj.php';
require __DIR__ . '/prj_import.php';
require __DIR__ . '/prj_export.php';

$oppen = defined('KOLL_INSTALL') && KOLL_INSTALL === true && KOLL_VARIANT !== 'labb';

const PRJ_DEMO_ANVANDARE = 'demo';

function prj_demo_hex(string $fro, int $langd): string
{
    return strtoupper(substr(hash('sha256', 'kollen-demo-prj-' . $fro), 0, $langd));
}

/** Skapar ett projekt och returnerar raden. */
function prj_demo_projekt(string $namn, string $status, string $ledare, ?string $start, ?string $slut, string $beskrivning): array
{
    $typ = (string)(array_key_first(prj_typer_aktiva('projekttyp')) ?? '');
    if ($typ === '') {
        throw new KcFel('Projekttyp saknas – kör install.php först.', 409);
    }
    $u = kc_uuid();
    kc_kor('INSERT INTO `' . kc_tabell('md_projekt') . "` (uuid, last_changed, is_deleted, source_tool, projektnummer, namn, projekttyp_uuid,
                status, projektledare, startdatum, slutdatum, beskrivning) VALUES (?, ?, 0, 'prj', ?, ?, ?, ?, ?, ?, ?, ?)",
        [$u, kc_nu(), prj_nasta_projektnummer(), $namn, $typ, $status, $ledare, $start, $slut, $beskrivning]);
    return prj_projekt_hamta($u);
}

/** Lägger in inköpta mätare via importen (mjuk). */
function prj_demo_lager(array $rader): int
{
    $rubriker = ['matar_id', 'fabrikat', 'modell', 'matartyp', 'kommunikation', 'storheter', 'gateway', 'inkop', 'kommentar',
                 'nyckel:DevEUI', 'nyckel:JoinEUI/AppEUI', 'nyckel:AppKey'];
    $fh = fopen('php://memory', 'w+b');
    fputcsv($fh, $rubriker, ';', '"', '');
    foreach ($rader as $r) {
        fputcsv($fh, array_map(static fn($k) => (string)($r[$k] ?? ''), $rubriker), ';', '"', '');
    }
    rewind($fh);
    $csv = (string)stream_get_contents($fh);
    fclose($fh);
    $def = prj_import_def('matare');
    $plan = kc_import_planera($def, kc_import_las($csv, kc_import_max_rader($def)), 'mjuk');
    $fel = array_merge($plan['fel'], array_map(static fn($p) => "rad {$p['rad']}: {$p['text']}",
        array_filter($plan['plan'], static fn($p) => $p['status'] === 'fel')));
    if ($fel !== []) {
        throw new KcFel('Import av mätare: ' . implode(' | ', array_slice($fel, 0, 5)), 500);
    }
    foreach ($plan['plan'] as $p) {
        if ($p['status'] !== 'oforandrad' && $p['utfor'] !== null) {
            ($p['utfor'])();
        }
    }
    return kc_import_summera($plan['plan'])['ny'];
}

function prj_demo_kor(): array
{
    $logg = [];
    if ((int)kc_hamta_varde('SELECT COUNT(*) FROM `' . kc_tabell('md_projekt') . '` WHERE is_deleted = 0') > 0) {
        return ['Det finns redan projekt – inga exempeldata lades in.'];
    }
    if (prj_post_hitta('byggnad', '1303-02') === null || prj_post_hitta('byggnad', '1301-02') === null) {
        throw new KcFel('Fastighetsdatan saknas. Lägg in exempeldata i FastighetsKollen och MätarKollen, skicka och hämta här under Synk först.', 409);
    }
    $mat_mall = kc_hamta_en('SELECT * FROM `' . kc_tabell('md_exportmall') . "` WHERE systemnyckel = 'mat' AND is_deleted = 0");
    if ($mat_mall === null) {
        throw new KcFel('MätarKollen-mallen saknas – kör install.php först.', 409);
    }

    // Mätarlager: två inköp
    $join = '70B3D57ED0001A2B';
    $lager = [];
    for ($i = 1; $i <= 30; $i++) {
        $dev = sprintf('A81758FFFE05%04X', 0x1000 + $i);
        $lager[] = [
            'matar_id' => $dev, 'fabrikat' => 'Elsys', 'modell' => $i <= 20 ? 'ERS' : 'ERS Lite', 'matartyp' => 'Klimatgivare',
            'kommunikation' => 'LoRaWAN', 'storheter' => $i <= 20 ? 'Temperatur, Relativ fuktighet' : 'Temperatur',
            'gateway' => 'GW-1303-A', 'inkop' => $i <= 20 ? 'Order 2026-118' : 'Order 2026-131',
            'kommentar' => $i === 30 ? 'Returnerad till leverantören för kontroll' : '',
            'nyckel:DevEUI' => $dev, 'nyckel:JoinEUI/AppEUI' => $join, 'nyckel:AppKey' => prj_demo_hex('appkey' . $dev, 32),
        ];
    }
    $logg[] = 'Mätarlager: ' . prj_demo_lager($lager) . ' inköpta mätare med nycklar (två inköp).';

    // Egen exportmall (exempel på en mall för en LoRaWAN-plattform)
    prj_export_mall_spara(null, [
        'namn' => 'Exempel – LoRaWAN-plattform', 'beskrivning' => 'Exempel på en egen mall: enheter med nycklar för en nätverksserver.',
        'avgransare' => 'komma', 'kodning' => 'utf8', 'rubrikrad' => 1, 'max_rader' => 0, 'urval' => 'provisionerade', 'med_nycklar' => 1,
    ], [
        ['rubrik' => 'dev_eui', 'kalla' => 'nyckel:DevEUI', 'fast_varde' => ''],
        ['rubrik' => 'join_eui', 'kalla' => 'nyckel:JoinEUI/AppEUI', 'fast_varde' => ''],
        ['rubrik' => 'app_key', 'kalla' => 'nyckel:AppKey', 'fast_varde' => ''],
        ['rubrik' => 'name', 'kalla' => 'matpunktsnamn', 'fast_varde' => ''],
        ['rubrik' => 'device_profile', 'kalla' => 'fast', 'fast_varde' => 'Elsys ERS'],
        ['rubrik' => 'address', 'kalla' => 'gatuadress', 'fast_varde' => ''],
    ]);
    $logg[] = 'Exportmall: "Exempel – LoRaWAN-plattform".';

    // P1: Lärkhuset – pågående
    $p1 = prj_demo_projekt('Klimatgivare Lärkhuset', 'pagaende', 'Anna Exempel', '2026-10-01', '2026-12-18',
        "Klimatgivare i alla lägenheter i Lärkhuset.\nTvå givare i de tre största lägenheterna på översta planet.");
    prj_omfattning_lagg_till($p1['uuid'], 'byggnad', ['1303-02']);
    prj_objektlista_uppdatera($p1);
    $tpo = kc_tabell('md_projektobjekt');
    $antal = [];
    foreach (kc_hamta_alla("SELECT uuid, objektnummer FROM `$tpo` WHERE projekt_uuid = ? AND is_deleted = 0", [$p1['uuid']]) as $o) {
        $antal[$o['uuid']] = match (true) {
            $o['objektnummer'] === '1303-02-0101' => 0,                                  // gemensamhetslokalen
            in_array($o['objektnummer'], ['1303-02-0013', '1303-02-0014', '1303-02-0015'], true) => 2,
            default => 1,
        };
    }
    prj_objekt_antal_spara($p1['uuid'], $antal);
    prj_matpunkter_generera($p1);
    $tm = kc_tabell('md_matpunkt');
    $mp = kc_hamta_alla("SELECT uuid, matpunktsnamn, objektnummer FROM `$tm` WHERE projekt_uuid = ? AND is_deleted = 0 ORDER BY matpunktsnamn", [$p1['uuid']]);
    prj_matpunkter_las($p1['uuid']);
    kc_kor("UPDATE `$tm` SET etikett_tid = ?, last_changed = ? WHERE projekt_uuid = ? AND is_deleted = 0", [kc_nu(), kc_nu(), $p1['uuid']]);
    $kopplade = $monterade = 0;
    foreach ($mp as $i => $m) {
        if ($i >= 15) {
            break;                                                                        // resten väntar på provisionering
        }
        prj_provisionera($p1, $m['uuid'], sprintf('A81758FFFE05%04X', 0x1001 + $i));
        $kopplade++;
        if ($i < 11) {
            prj_montera($p1, $m['objektnummer'], sprintf('A81758FFFE05%04X', 0x1001 + $i), PRJ_DEMO_ANVANDARE);
            $monterade++;
        }
    }
    $exp = prj_export_skapa_omgang($mat_mall, $p1, false, PRJ_DEMO_ANVANDARE);
    $logg[] = "Projekt {$p1['projektnummer']} {$p1['namn']}: " . count($mp) . " mätpunkter (låsta, etiketter utskrivna), $kopplade provisionerade, "
        . "$monterade monterade, export med MätarKollen-mallen ({$exp['antal']} mätpunkter).";

    // P2: Hjorten Hus B – planering
    $p2 = prj_demo_projekt('Klimatgivare Hjorten Hus B', 'planering', 'Erik Exempel', '2027-02-01', null,
        'Komplettering: Hus B har givare i de fyra första lägenheterna sedan tidigare.');
    prj_omfattning_lagg_till($p2['uuid'], 'byggnad', ['1301-02']);
    $s = prj_objektlista_uppdatera($p2);
    $logg[] = "Projekt {$p2['projektnummer']} {$p2['namn']}: {$s['obj_nya']} objekt, {$s['skapade']} preliminära mätpunkter "
        . '(de med befintlig givare i MätarKollen har infotext).';
    return $logg;
}

if (kc_ar_post() && kc_post('atgard') === 'fyll') {
    if (!$oppen) {
        throw new KcFel('Exempeldata kan bara läggas in i DEMO/GITHUB när KOLL_INSTALL = true.', 403);
    }
    try {
        $_SESSION['prj_demo_logg'] = kc_transaktion(fn() => prj_demo_kor());
        kc_flash('ok', 'Klart. Skicka ändringarna till CentralKollen under Synk.');
    } catch (KcFel $e) {
        kc_flash('fel', $e->getMessage());
    }
    kc_omdirigera('demo_data.php');
}

$logg = $_SESSION['prj_demo_logg'] ?? [];
unset($_SESSION['prj_demo_logg']);
kc_sidhuvud('Exempeldata');
?>
<div class="kc-ruta" style="max-width:52rem">
  <h2>Exempeldata för ProjektKollen</h2>
  <p>Lägger in ett mätarlager med 30 klimatgivare, två projekt i olika skeden (ett pågående med provisionerade, monterade
     och exporterade mätare, ett under planering) och en egen exportmall. Körs bara en gång.</p>
  <p><strong>Först:</strong> exempeldata i FastighetsKollen och MätarKollen → Skicka där → Hämta här under Synk.</p>
  <?php if ($logg !== []): ?><ul><?php foreach ($logg as $r): ?><li><?= kc_h($r) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if (!$oppen): ?>
    <p><em class="kc-dampad">Stängd. Kräver KOLL_INSTALL = true i config.php och variant DEMO eller GITHUB (nu: <?= kc_h(KOLL_VARIANT) ?>).</em></p>
  <?php else: ?>
    <form method="post"><?= kc_csrf_falt() ?><input type="hidden" name="atgard" value="fyll">
      <button type="submit" class="kc-knapp-primar">Lägg in exempeldata</button></form>
  <?php endif; ?>
</div>
<?php kc_sidfot();
