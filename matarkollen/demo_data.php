<?php
/**
 * MätarKollen – demo_data.php
 * Variant: DEMO och GITHUB (nekas i LABB)
 * Fyller verktyget med påhittade exempeldata som hör ihop med FastighetsKollens exempeldata:
 * klimatgivare i lägenheterna i Hjorten, vattenmätare med huvudmätare, föregående och en virtuell
 * mätpunkt i Räven, värme- och elmätare i Lärkan, nycklar, monteringar med ställningar, egenskaper,
 * en avvecklad och en planerad mätpunkt samt två mätare i lager. Allt läggs in via importen
 * "Mätpunkter och mätare"; bara egenskapsdefinitionerna skrivs direkt.
 * Kräver att fastighetsdatan har hämtats (Synk → Hämta). Öppen bara när KOLL_INSTALL = true.
 * Körs en gång: finns det redan mätpunkter görs inget. Efteråt: Synk → Skicka.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/mat.php';
require __DIR__ . '/mat_import.php';

$oppen = defined('KOLL_INSTALL') && KOLL_INSTALL === true && KOLL_VARIANT !== 'labb';

/** Påhittad hexsträng med given längd, styrd av en text (samma text ger samma värde). */
function mat_demo_hex(string $fro, int $langd): string
{
    return strtoupper(substr(hash('sha256', 'kollen-demo-' . $fro), 0, $langd));
}

/** Lägger in en egenskapsdefinition (och val) om den saknas. */
function mat_demo_egenskap(string $niva, string $namn, string $datatyp, string $enhet, string $beskrivning, int $sortering, array $val = []): void
{
    $te = kc_tabell('md_egenskap');
    $tv = kc_tabell('md_egenskapsval');
    $u = kc_hamta_varde("SELECT uuid FROM `$te` WHERE niva = ? AND LOWER(namn) = LOWER(?) AND is_deleted = 0", [$niva, $namn]);
    if ($u === null || $u === false) {
        $u = kc_uuid();
        kc_kor("INSERT INTO `$te` (uuid, last_changed, is_deleted, source_tool, namn, beskrivning, niva, datatyp, enhet, sortering, is_active)
                VALUES (?, ?, 0, 'mat', ?, ?, ?, ?, ?, ?, 1)", [$u, kc_nu(), $namn, $beskrivning, $niva, $datatyp, $enhet, $sortering]);
    }
    foreach ($val as $i => $v) {
        if ((int)kc_hamta_varde("SELECT COUNT(*) FROM `$tv` WHERE egenskap_uuid = ? AND namn = ? AND is_deleted = 0", [$u, $v]) === 0) {
            kc_kor("INSERT INTO `$tv` (uuid, last_changed, is_deleted, source_tool, egenskap_uuid, namn, sortering, is_active)
                    VALUES (?, ?, 0, 'mat', ?, ?, ?, 1)", [kc_uuid(), kc_nu(), $u, $v, ($i + 1) * 10]);
        }
    }
}

/** Kör importen "Mätpunkter och mätare" (mjuk) i delar om 100 rader. Kastar KcFel vid fel. */
function mat_demo_importera(array $rubriker, array $rader): array
{
    $def = mat_import_def('matpunkt');
    $summa = ['ny' => 0, 'kompletterad' => 0];
    foreach (array_chunk($rader, 100) as $del) {
        $fh = fopen('php://memory', 'w+b');
        fputcsv($fh, $rubriker, ';', '"', '');
        foreach ($del as $r) {
            fputcsv($fh, array_map(static fn($k) => (string)($r[$k] ?? ''), $rubriker), ';', '"', '');
        }
        rewind($fh);
        $csv = (string)stream_get_contents($fh);
        fclose($fh);
        $plan = kc_import_planera($def, kc_import_las($csv, kc_import_max_rader($def)), 'mjuk');
        $fel = array_merge($plan['fel'], array_map(static fn($p) => "rad {$p['rad']}: {$p['text']}",
            array_filter($plan['plan'], static fn($p) => $p['status'] === 'fel')));
        if ($fel !== []) {
            throw new KcFel('Import: ' . implode(' | ', array_slice($fel, 0, 5)), 500);
        }
        foreach ($plan['plan'] as $p) {
            if ($p['status'] !== 'oforandrad' && $p['utfor'] !== null) {
                ($p['utfor'])();
            }
        }
        $s = kc_import_summera($plan['plan']);
        $summa['ny'] += $s['ny'];
        $summa['kompletterad'] += $s['kompletterad'];
    }
    return $summa;
}

function mat_demo_kor(): array
{
    if ((int)kc_hamta_varde('SELECT COUNT(*) FROM `' . kc_tabell('md_matpunkt') . '` WHERE is_deleted = 0') > 0) {
        return ['Det finns redan mätpunkter – inga exempeldata lades in.'];
    }
    $to = kc_tabell('ld_fas_objekt');
    $finns = static fn(string $nr): bool => (int)kc_hamta_varde("SELECT COUNT(*) FROM `$to` WHERE objektnummer = ? AND is_deleted = 0", [$nr]) > 0;
    if (!$finns('1301-01-0001') || !$finns('1303-01-0003')) {
        throw new KcFel('Fastighetsdatan saknas. Lägg in exempeldata i FastighetsKollen, skicka den och hämta den här under Synk först.', 409);
    }

    mat_demo_egenskap('matpunkt', 'Montagehöjd', 'decimal', 'm', 'Höjd över golv', 10);
    mat_demo_egenskap('matpunkt', 'Rum', 'val', '', 'Rum där givaren sitter', 20, ['Vardagsrum', 'Sovrum', 'Hall', 'Kök', 'Teknikutrymme']);
    mat_demo_egenskap('matare', 'Batteribyte senast', 'datum', '', '', 10);
    mat_demo_egenskap('matare', 'Firmware', 'text', '', 'Version enligt leverantören', 20);
    mat_demo_egenskap('matare', 'Plomberad', 'janej', '', '', 30);

    $rubriker = ['matpunktsnamn', 'objektnummer', 'byggnadsnummer', 'fastighetsnummer', 'objektgruppsnummer',
                 'betjanar_byggnadsnummer', 'betjanar_objektgruppsnummer', 'platsbeskrivning', 'typ', 'status', 'storheter', 'foregaende',
                 'matar_id', 'gateway', 'matartyp', 'fabrikat', 'modell', 'kommunikation', 'kommentar', 'monteringsdatum',
                 'nyckel:DevEUI', 'nyckel:JoinEUI/AppEUI', 'nyckel:AppKey', 'nyckel:AES-nyckel (wM-Bus)',
                 'stallning:kallvatten', 'stallning:värmeenergi', 'stallning:el',
                 'egenskap:montagehöjd', 'egenskap:rum', 'egenskap_matare:batteribyte senast', 'egenskap_matare:firmware', 'egenskap_matare:plomberad'];
    $rader = [];
    $join = '70B3D57ED0001A2B';
    $rum = ['Vardagsrum', 'Sovrum', 'Hall'];

    // Klimatgivare i Hjorten: alla lägenheter i Hus A och de fyra första i Hus B
    $lgh = [];
    for ($n = 1; $n <= 16; $n++) {
        $lgh[] = ['1301-01', sprintf('1301-01-%04d', $n), $n <= 8 ? 'GW-1301-A' : 'GW-1301-B'];
    }
    for ($n = 1; $n <= 4; $n++) {
        $lgh[] = ['1301-02', sprintf('1301-02-%04d', $n), 'GW-1301-B'];
    }
    foreach ($lgh as $i => [$bnr, $onr, $gw]) {
        $dev = 'A81758FFFE04' . mat_demo_hex($onr, 4);
        $rader[] = [
            'matpunktsnamn' => $onr . '_Temp_11', 'objektnummer' => $onr, 'platsbeskrivning' => 'Innervägg, ca 1,6 m över golv',
            'typ' => 'fysisk', 'status' => 'drifttagen', 'storheter' => 'Temperatur, Relativ fuktighet',
            'matar_id' => $dev, 'gateway' => $gw, 'matartyp' => 'Klimatgivare', 'fabrikat' => 'Elsys', 'modell' => 'ERS',
            'kommunikation' => 'LoRaWAN', 'kommentar' => '', 'monteringsdatum' => sprintf('2025-%02d-%02d', 3 + intdiv($i, 10), 10 + $i % 10),
            'nyckel:DevEUI' => $dev, 'nyckel:JoinEUI/AppEUI' => $join, 'nyckel:AppKey' => mat_demo_hex('appkey' . $onr, 32),
            'egenskap:montagehöjd' => '1,6', 'egenskap:rum' => $rum[$i % 3],
            'egenskap_matare:batteribyte senast' => '', 'egenskap_matare:firmware' => '1.9.2', 'egenskap_matare:plomberad' => 'nej',
        ];
    }

    // Kallvatten i Hjorten Hus A: huvudmätare i teknikutrymmet, betjänar byggnaden
    $rader[] = [
        'matpunktsnamn' => '1301-01_KV_11', 'objektnummer' => '1301-01-0102', 'betjanar_byggnadsnummer' => '1301-01',
        'platsbeskrivning' => 'Inkommande kallvatten, undercentralen', 'typ' => 'fysisk', 'status' => 'drifttagen', 'storheter' => 'Kallvatten',
        'matar_id' => '71234501', 'gateway' => 'GW-1301-A', 'matartyp' => 'Kallvattenmätare', 'fabrikat' => 'Kamstrup', 'modell' => 'flowIQ 2200',
        'kommunikation' => 'Trådlös M-Bus', 'kommentar' => 'Huvudmätare för Hus A', 'monteringsdatum' => '2024-11-04',
        'nyckel:AES-nyckel (wM-Bus)' => mat_demo_hex('aes71234501', 32), 'stallning:kallvatten' => '1523,412',
        'egenskap_matare:batteribyte senast' => '2024-11-04', 'egenskap_matare:plomberad' => 'ja',
    ];

    // Räven: huvudmätare för radhuslängan, en mätare per radhus (föregående = huvudmätaren) och en virtuell mätpunkt
    $rader[] = [
        'matpunktsnamn' => '1302-01_KV_11', 'byggnadsnummer' => '1302-01', 'platsbeskrivning' => 'Vattenmätarbrunn vid Rävstigen 2A',
        'typ' => 'fysisk', 'status' => 'drifttagen', 'storheter' => 'Kallvatten',
        'matar_id' => '71234510', 'matartyp' => 'Kallvattenmätare', 'fabrikat' => 'Kamstrup', 'modell' => 'flowIQ 2200',
        'kommunikation' => 'Trådlös M-Bus', 'kommentar' => 'Huvudmätare för radhusen', 'monteringsdatum' => '2023-06-01',
        'nyckel:AES-nyckel (wM-Bus)' => mat_demo_hex('aes71234510', 32), 'stallning:kallvatten' => '3388,045',
        'egenskap_matare:plomberad' => 'ja',
    ];
    for ($n = 1; $n <= 4; $n++) {
        $onr = sprintf('1302-01-%04d', $n);
        $rader[] = [
            'matpunktsnamn' => $onr . '_KV_11', 'objektnummer' => $onr, 'platsbeskrivning' => 'Under diskbänken', 'typ' => 'fysisk',
            'status' => 'drifttagen', 'storheter' => 'Kallvatten', 'foregaende' => '1302-01_KV_11',
            'matar_id' => sprintf('7123452%d', $n), 'matartyp' => 'Kallvattenmätare', 'fabrikat' => 'Axioma Metering', 'modell' => 'Qalcosonic W1',
            'kommunikation' => 'Trådlös M-Bus', 'monteringsdatum' => '2023-06-02',
            'nyckel:AES-nyckel (wM-Bus)' => mat_demo_hex('aes' . $onr, 32), 'stallning:kallvatten' => number_format(180 + $n * 23.417, 3, ',', ''),
            'egenskap:rum' => 'Kök', 'egenskap_matare:plomberad' => 'ja',
        ];
    }
    $rader[] = [
        'matpunktsnamn' => '1302-01_KV_GEM', 'byggnadsnummer' => '1302-01', 'platsbeskrivning' => 'Gemensam förbrukning: huvudmätaren minus radhusen',
        'typ' => 'virtuell', 'status' => 'drifttagen', 'storheter' => 'Kallvatten', 'foregaende' => '1302-01_KV_11',
    ];

    // Lärkan: värmemätare i värmecentralen (betjänar värmesystemet) och elmätare för kontoret
    $rader[] = [
        'matpunktsnamn' => '1303-01-0003_VE_11', 'objektnummer' => '1303-01-0003', 'betjanar_objektgruppsnummer' => 'OG-1303-VS',
        'platsbeskrivning' => 'Primärsidan, värmeväxlare VVX1', 'typ' => 'fysisk', 'status' => 'drifttagen', 'storheter' => 'Värmeenergi',
        'matar_id' => '68001122', 'matartyp' => 'Värmemätare', 'fabrikat' => 'Kamstrup', 'modell' => 'MULTICAL 403',
        'kommunikation' => 'M-Bus', 'kommentar' => 'Avläses via M-Bus-master i värmecentralen', 'monteringsdatum' => '2022-09-15',
        'stallning:värmeenergi' => '412873', 'egenskap:rum' => 'Teknikutrymme', 'egenskap_matare:plomberad' => 'ja',
    ];
    $rader[] = [
        'matpunktsnamn' => '1303-01-0001_EL_11', 'objektnummer' => '1303-01-0001', 'platsbeskrivning' => 'Elcentral plan 1',
        'typ' => 'fysisk', 'status' => 'drifttagen', 'storheter' => 'El',
        'matar_id' => '35000981', 'matartyp' => 'Elmätare', 'fabrikat' => 'Landis+Gyr', 'modell' => 'E360',
        'kommunikation' => 'M-Bus', 'monteringsdatum' => '2024-02-20', 'stallning:el' => '58214,7', 'egenskap_matare:firmware' => '2.4.1',
    ];
    $rader[] = [
        'matpunktsnamn' => '1303-01-0002_EL_11', 'objektnummer' => '1303-01-0002', 'platsbeskrivning' => 'Elcentral plan 2 – planerad',
        'typ' => 'fysisk', 'status' => 'projekt', 'storheter' => 'El',
    ];
    $rader[] = [
        'matpunktsnamn' => '1301-03-0001_Temp_11', 'objektnummer' => '1301-03-0001', 'platsbeskrivning' => 'Givaren togs bort vid ombyggnad 2025',
        'typ' => 'fysisk', 'status' => 'avvecklad', 'storheter' => 'Temperatur',
    ];

    // Två mätare i lager (inga mätpunkter)
    foreach (['A81758FFFE04F001', 'A81758FFFE04F002'] as $dev) {
        $rader[] = [
            'matar_id' => $dev, 'matartyp' => 'Klimatgivare', 'fabrikat' => 'Elsys', 'modell' => 'ERS', 'kommunikation' => 'LoRaWAN',
            'kommentar' => 'Reserv i lager', 'nyckel:DevEUI' => $dev, 'nyckel:JoinEUI/AppEUI' => $join, 'nyckel:AppKey' => mat_demo_hex('appkey' . $dev, 32),
            'egenskap_matare:firmware' => '1.9.2',
        ];
    }

    $s = mat_demo_importera($rubriker, $rader);
    return [
        'Egenskaper: definitioner på mätpunkt och mätare.',
        "Mätpunkter och mätare: {$s['ny']} nya rader (" . count($rader) . ' i filen), med nycklar, monteringar och ställningar.',
    ];
}

if (kc_ar_post() && kc_post('atgard') === 'fyll') {
    if (!$oppen) {
        throw new KcFel('Exempeldata kan bara läggas in i DEMO/GITHUB när KOLL_INSTALL = true.', 403);
    }
    try {
        $_SESSION['mat_demo_logg'] = kc_transaktion(fn() => mat_demo_kor());
        kc_flash('ok', 'Klart. Skicka ändringarna till CentralKollen under Synk.');
    } catch (KcFel $e) {
        kc_flash('fel', $e->getMessage());
    }
    kc_omdirigera('demo_data.php');
}

$logg = $_SESSION['mat_demo_logg'] ?? [];
unset($_SESSION['mat_demo_logg']);
kc_sidhuvud('Exempeldata');
?>
<div class="kc-ruta" style="max-width:52rem">
  <h2>Exempeldata för MätarKollen</h2>
  <p>Lägger in påhittade mätpunkter och mätare som hör till FastighetsKollens exempeldata: klimatgivare i Hjortens lägenheter,
     vattenmätare med huvudmätare, föregående och en virtuell mätpunkt i Räven, värme- och elmätare i Lärkan, nycklar,
     monteringar med ställningar och egenskaper. Körs bara en gång.</p>
  <p><strong>Först:</strong> exempeldata i FastighetsKollen → Skicka där → Hämta här under Synk.</p>
  <?php if ($logg !== []): ?><ul><?php foreach ($logg as $r): ?><li><?= kc_h($r) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if (!$oppen): ?>
    <p><em class="kc-dampad">Stängd. Kräver KOLL_INSTALL = true i config.php och variant DEMO eller GITHUB (nu: <?= kc_h(KOLL_VARIANT) ?>).</em></p>
  <?php else: ?>
    <form method="post"><?= kc_csrf_falt() ?><input type="hidden" name="atgard" value="fyll">
      <button type="submit" class="kc-knapp-primar">Lägg in exempeldata</button></form>
  <?php endif; ?>
</div>
<?php kc_sidfot();
