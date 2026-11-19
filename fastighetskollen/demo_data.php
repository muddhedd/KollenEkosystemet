<?php
/**
 * FastighetsKollen – demo_data.php
 * Variant: DEMO och GITHUB (nekas i LABB)
 * Fyller verktyget med påhittade exempeldata: tre fastigheter med byggnader, adresser, objekt av olika
 * typer, objektgrupper med kopplingar och egenskaper på alla nivåer. Allt läggs in via verktygets
 * egna importer (samma validering som i gränssnittet); bara egenskapsdefinitionerna skrivs direkt.
 * Öppen bara när config.php har KOLL_INSTALL = true. Körs en gång: finns datan redan görs inget.
 * Efteråt: skicka ändringarna till CentralKollen under Synk.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/fas.php';
require __DIR__ . '/fas_import.php';

$oppen = defined('KOLL_INSTALL') && KOLL_INSTALL === true && KOLL_VARIANT !== 'labb';

/** Bygger CSV (semikolon) av rubriker och rader. */
function fas_demo_csv(array $rubriker, array $rader): string
{
    $fh = fopen('php://memory', 'w+b');
    fputcsv($fh, $rubriker, ';', '"', '');
    foreach ($rader as $r) {
        fputcsv($fh, array_map(static fn($k) => (string)($r[$k] ?? ''), $rubriker), ';', '"', '');
    }
    rewind($fh);
    $csv = (string)stream_get_contents($fh);
    fclose($fh);
    return $csv;
}

/** Kör en import (mjuk) med verktygets importdefinition. Kastar KcFel vid fel på någon rad. */
function fas_demo_importera(string $tabell, array $rubriker, array $rader): string
{
    $def = fas_import_def($tabell);
    $fil = kc_import_las(fas_demo_csv($rubriker, $rader), kc_import_max_rader($def));
    $plan = kc_import_planera($def, $fil, 'mjuk');
    $fel = array_merge($plan['fel'], array_map(static fn($p) => "rad {$p['rad']}: {$p['text']}",
        array_filter($plan['plan'], static fn($p) => $p['status'] === 'fel')));
    if ($fel !== []) {
        throw new KcFel("Import $tabell: " . implode(' | ', array_slice($fel, 0, 5)), 500);
    }
    foreach ($plan['plan'] as $p) {
        if ($p['status'] !== 'oforandrad' && $p['utfor'] !== null) {
            ($p['utfor'])();
        }
    }
    $s = kc_import_summera($plan['plan']);
    return FAS_IMPORT_TABELLER[$tabell]['rubrik'] . ": {$s['ny']} nya, {$s['kompletterad']} kompletterade.";
}

/** Lägger in en egenskapsdefinition (och val) om den saknas. */
function fas_demo_egenskap(string $niva, string $namn, string $datatyp, string $enhet, string $beskrivning, int $sortering, array $val = []): void
{
    $te = kc_tabell('md_egenskap');
    $tv = kc_tabell('md_egenskapsval');
    $u = kc_hamta_varde("SELECT uuid FROM `$te` WHERE niva = ? AND LOWER(namn) = LOWER(?) AND is_deleted = 0", [$niva, $namn]);
    if ($u === null || $u === false) {
        $u = kc_uuid();
        kc_kor("INSERT INTO `$te` (uuid, last_changed, is_deleted, source_tool, namn, beskrivning, niva, datatyp, enhet, sortering, is_active)
                VALUES (?, ?, 0, 'fas', ?, ?, ?, ?, ?, ?, 1)", [$u, kc_nu(), $namn, $beskrivning, $niva, $datatyp, $enhet, $sortering]);
    }
    foreach ($val as $i => $v) {
        if ((int)kc_hamta_varde("SELECT COUNT(*) FROM `$tv` WHERE egenskap_uuid = ? AND namn = ? AND is_deleted = 0", [$u, $v]) === 0) {
            kc_kor("INSERT INTO `$tv` (uuid, last_changed, is_deleted, source_tool, egenskap_uuid, namn, sortering, is_active)
                    VALUES (?, ?, 0, 'fas', ?, ?, ?, 1)", [kc_uuid(), kc_nu(), $u, $v, ($i + 1) * 10]);
        }
    }
}

/** Alla exempeldata. */
function fas_demo_kor(): array
{
    $logg = [];
    if ((int)kc_hamta_varde('SELECT COUNT(*) FROM `' . kc_tabell('md_fastighet') . '` WHERE is_deleted = 0') > 0) {
        return ['Det finns redan fastigheter – inga exempeldata lades in.'];
    }

    // Egenskapsdefinitioner
    fas_demo_egenskap('fastighet', 'Fastighetsbeteckning', 'text', '', 'Lantmäteriets beteckning', 10);
    fas_demo_egenskap('fastighet', 'Förvärvsår', 'heltal', '', 'År då fastigheten köptes', 20);
    fas_demo_egenskap('fastighet', 'Tomtarea', 'decimal', 'm²', 'Enligt fastighetsregistret', 30);
    fas_demo_egenskap('byggnad', 'Byggår', 'heltal', '', '', 10);
    fas_demo_egenskap('byggnad', 'BOA', 'decimal', 'm²', 'Boarea', 20);
    fas_demo_egenskap('byggnad', 'Energiklass', 'val', '', 'Enligt energideklarationen', 30, ['A', 'B', 'C', 'D', 'E', 'F', 'G']);
    fas_demo_egenskap('byggnad', 'Energideklarerad', 'datum', '', 'Datum för senaste energideklaration', 40);
    fas_demo_egenskap('byggnad', 'Hiss', 'janej', '', '', 50);
    fas_demo_egenskap('objekt', 'Balkong', 'janej', '', '', 10);
    fas_demo_egenskap('objekt', 'Tillgänglighetsanpassad', 'janej', '', '', 20);
    fas_demo_egenskap('objekt', 'Golv', 'val', '', 'Dominerande golvmaterial', 30, ['Parkett', 'Laminat', 'Klinker', 'Linoleum']);
    fas_demo_egenskap('objekt', 'Senast renoverad', 'datum', '', '', 40);
    fas_demo_egenskap('objektgrupp', 'Antal maskiner', 'heltal', 'st', 'T.ex. tvättmaskiner och torktumlare', 10);
    fas_demo_egenskap('objektgrupp', 'Bokningsbar', 'janej', '', '', 20);
    $logg[] = 'Egenskaper: definitioner på fastighet, byggnad, objekt och objektgrupp.';

    // Fastigheter
    $logg[] = fas_demo_importera('fastighet',
        ['fastighetsnummer', 'namn', 'team', 'bolag', 'kostnadsstalle', 'egenskap:fastighetsbeteckning', 'egenskap:förvärvsår', 'egenskap:tomtarea'], [
        ['fastighetsnummer' => '1301', 'namn' => 'Hjorten 1', 'team' => 'Team Norr', 'bolag' => 'Exempelbostäder AB', 'kostnadsstalle' => '4110',
         'egenskap:fastighetsbeteckning' => 'Exempelstad Hjorten 1', 'egenskap:förvärvsår' => '1998', 'egenskap:tomtarea' => '4250,0'],
        ['fastighetsnummer' => '1302', 'namn' => 'Räven 4', 'team' => 'Team Norr', 'bolag' => 'Exempelbostäder AB', 'kostnadsstalle' => '4120',
         'egenskap:fastighetsbeteckning' => 'Exempelstad Räven 4', 'egenskap:förvärvsår' => '2012', 'egenskap:tomtarea' => '2980,5'],
        ['fastighetsnummer' => '1303', 'namn' => 'Lärkan 2', 'team' => 'Team Syd', 'bolag' => 'Exempelfastigheter i Söder AB', 'kostnadsstalle' => '5210',
         'egenskap:fastighetsbeteckning' => 'Exempelstad Lärkan 2', 'egenskap:förvärvsår' => '2005', 'egenskap:tomtarea' => '3615,0'],
    ]);

    // Byggnader
    $logg[] = fas_demo_importera('byggnad',
        ['byggnadsnummer', 'fastighetsnummer', 'namn', 'byggnadstyp', 'egenskap:byggår', 'egenskap:boa', 'egenskap:energiklass', 'egenskap:energideklarerad', 'egenskap:hiss'], [
        ['byggnadsnummer' => '1301-01', 'fastighetsnummer' => '1301', 'namn' => 'Hus A', 'byggnadstyp' => 'Flerbostadshus',
         'egenskap:byggår' => '1964', 'egenskap:boa' => '612,0', 'egenskap:energiklass' => 'D', 'egenskap:energideklarerad' => '2021-05-12', 'egenskap:hiss' => 'nej'],
        ['byggnadsnummer' => '1301-02', 'fastighetsnummer' => '1301', 'namn' => 'Hus B', 'byggnadstyp' => 'Flerbostadshus',
         'egenskap:byggår' => '1964', 'egenskap:boa' => '598,5', 'egenskap:energiklass' => 'D', 'egenskap:energideklarerad' => '2021-05-12', 'egenskap:hiss' => 'nej'],
        ['byggnadsnummer' => '1301-03', 'fastighetsnummer' => '1301', 'namn' => 'Miljöhuset', 'byggnadstyp' => 'Miljöhus', 'egenskap:byggår' => '2016'],
        ['byggnadsnummer' => '1301-04', 'fastighetsnummer' => '1301', 'namn' => 'Innergården', 'byggnadstyp' => 'Utomhusområde'],
        ['byggnadsnummer' => '1302-01', 'fastighetsnummer' => '1302', 'namn' => 'Radhuslängan', 'byggnadstyp' => 'Radhus',
         'egenskap:byggår' => '1987', 'egenskap:boa' => '432,0', 'egenskap:energiklass' => 'C', 'egenskap:energideklarerad' => '2019-09-30', 'egenskap:hiss' => 'nej'],
        ['byggnadsnummer' => '1302-02', 'fastighetsnummer' => '1302', 'namn' => 'Garagelängan', 'byggnadstyp' => 'Garage/carport', 'egenskap:byggår' => '1987'],
        ['byggnadsnummer' => '1303-01', 'fastighetsnummer' => '1303', 'namn' => 'Kontorshuset', 'byggnadstyp' => 'Lokal/kontor',
         'egenskap:byggår' => '2002', 'egenskap:energiklass' => 'B', 'egenskap:energideklarerad' => '2022-03-01', 'egenskap:hiss' => 'ja'],
        ['byggnadsnummer' => '1303-02', 'fastighetsnummer' => '1303', 'namn' => 'Lärkhuset', 'byggnadstyp' => 'Flerbostadshus',
         'egenskap:byggår' => '2004', 'egenskap:boa' => '846,0', 'egenskap:energiklass' => 'C', 'egenskap:energideklarerad' => '2022-03-01', 'egenskap:hiss' => 'ja'],
    ]);

    // Adresser (fastighetens och byggnadernas; första per ägare blir huvudadress)
    $logg[] = fas_demo_importera('adress',
        ['fastighetsnummer', 'byggnadsnummer', 'gatuadress', 'postnummer', 'ort', 'beskrivning', 'huvudadress'], [
        ['fastighetsnummer' => '1301', 'gatuadress' => 'Hjortgatan 3', 'postnummer' => '123 45', 'ort' => 'Exempelstad', 'beskrivning' => 'Fastighetens postadress', 'huvudadress' => 'ja'],
        ['fastighetsnummer' => '1302', 'gatuadress' => 'Rävstigen 2', 'postnummer' => '123 46', 'ort' => 'Exempelstad', 'beskrivning' => 'Fastighetens postadress', 'huvudadress' => 'ja'],
        ['fastighetsnummer' => '1303', 'gatuadress' => 'Lärkvägen 10', 'postnummer' => '123 50', 'ort' => 'Exempelstad', 'beskrivning' => 'Fastighetens postadress', 'huvudadress' => 'ja'],
        ['byggnadsnummer' => '1301-01', 'gatuadress' => 'Hjortgatan 3', 'postnummer' => '123 45', 'ort' => 'Exempelstad', 'beskrivning' => 'Uppgång A', 'huvudadress' => 'ja'],
        ['byggnadsnummer' => '1301-01', 'gatuadress' => 'Hjortgatan 5', 'postnummer' => '123 45', 'ort' => 'Exempelstad', 'beskrivning' => 'Uppgång B'],
        ['byggnadsnummer' => '1301-02', 'gatuadress' => 'Hjortgatan 7', 'postnummer' => '123 45', 'ort' => 'Exempelstad', 'beskrivning' => 'Uppgång C', 'huvudadress' => 'ja'],
        ['byggnadsnummer' => '1301-03', 'gatuadress' => 'Hjortgatan 9', 'postnummer' => '123 45', 'ort' => 'Exempelstad', 'beskrivning' => 'Miljöhuset vid parkeringen', 'huvudadress' => 'ja'],
        ['byggnadsnummer' => '1302-01', 'gatuadress' => 'Rävstigen 2A', 'postnummer' => '123 46', 'ort' => 'Exempelstad', 'huvudadress' => 'ja'],
        ['byggnadsnummer' => '1302-01', 'gatuadress' => 'Rävstigen 2B', 'postnummer' => '123 46', 'ort' => 'Exempelstad'],
        ['byggnadsnummer' => '1302-01', 'gatuadress' => 'Rävstigen 2C', 'postnummer' => '123 46', 'ort' => 'Exempelstad'],
        ['byggnadsnummer' => '1302-01', 'gatuadress' => 'Rävstigen 2D', 'postnummer' => '123 46', 'ort' => 'Exempelstad'],
        ['byggnadsnummer' => '1302-02', 'gatuadress' => 'Rävstigen 4', 'postnummer' => '123 46', 'ort' => 'Exempelstad', 'huvudadress' => 'ja'],
        ['byggnadsnummer' => '1303-01', 'gatuadress' => 'Lärkvägen 10', 'postnummer' => '123 50', 'ort' => 'Exempelstad', 'huvudadress' => 'ja'],
        ['byggnadsnummer' => '1303-02', 'gatuadress' => 'Lärkvägen 12', 'postnummer' => '123 50', 'ort' => 'Exempelstad', 'huvudadress' => 'ja'],
    ]);

    // Objekt
    $obj = [];
    $golv = ['Parkett', 'Laminat', 'Parkett', 'Linoleum'];
    $lgh = static function (string $bnr, int $nr, string $adress, int $plan, float $m2, float $rum, string $kok, bool $balkong, bool $tillg, string $golvtyp, string $renoverad) use (&$obj): void {
        $obj[] = ['objektnummer' => sprintf('%s-%04d', $bnr, $nr), 'byggnadsnummer' => $bnr, 'objekttyp' => 'Lägenhet', 'gatuadress' => $adress,
                  'vaningsplan' => $plan === 0 ? 'BV' : (string)$plan, 'kvadratmeter' => number_format($m2, 1, ',', ''),
                  'antal_rum' => number_format($rum, 1, ',', ''), 'kok' => $kok,
                  'beskrivning' => sprintf('Lägenhet %d%02d', $plan + 10, $nr), 'egenskap:balkong' => $balkong ? 'ja' : 'nej',
                  'egenskap:tillgänglighetsanpassad' => $tillg ? 'ja' : 'nej', 'egenskap:golv' => $golvtyp, 'egenskap:senast renoverad' => $renoverad];
    };
    // Hjorten: 1301-01 två uppgångar × 4 plan × 2 lgh, 1301-02 en uppgång × 4 plan × 2 lgh
    $n = 0;
    foreach (['Hjortgatan 3', 'Hjortgatan 5'] as $adress) {
        for ($plan = 0; $plan < 4; $plan++) {
            for ($i = 0; $i < 2; $i++) {
                $n++;
                $lgh('1301-01', $n, $adress, $plan, $i === 0 ? 61.5 : 74.0, $i === 0 ? 2 : 3, 'Kök', $plan > 0, $plan === 0, $golv[$n % 4], $n % 3 === 0 ? '2019-06-15' : '');
            }
        }
    }
    for ($n = 1; $n <= 8; $n++) {
        $plan = intdiv($n - 1, 2);
        $lgh('1301-02', $n, 'Hjortgatan 7', $plan, $n % 2 ? 41.0 : 78.5, $n % 2 ? 1 : 3, $n % 2 ? 'Kokvrå' : 'Kök', $plan > 0, false, $golv[$n % 4], $n === 4 ? '2023-02-01' : '');
    }
    // Räven: fyra radhus i två plan
    foreach (['Rävstigen 2A', 'Rävstigen 2B', 'Rävstigen 2C', 'Rävstigen 2D'] as $i => $adress) {
        $lgh('1302-01', $i + 1, $adress, 0, 108.0, 4.5, 'Kök', false, $i === 0, 'Parkett', $i === 2 ? '2020-10-01' : '');
    }
    // Lärkan: 1303-02 fem plan × 3 lgh
    for ($n = 1; $n <= 15; $n++) {
        $plan = intdiv($n - 1, 3);
        $lgh('1303-02', $n, 'Lärkvägen 12', $plan, [52.0, 68.5, 89.0][($n - 1) % 3], [2.0, 3.0, 4.0][($n - 1) % 3], 'Kök', true, true, $golv[$n % 4], '');
    }
    $ovrigt = [
        ['1301-01', 101, 'Tvättstuga', 'Hjortgatan 3', -1, 22.0, 'Tvättstuga uppgång A och B'],
        ['1301-01', 102, 'Teknikutrymme', 'Hjortgatan 5', -1, 9.5, 'Undercentral och elcentral'],
        ['1301-01', 103, 'Förråd', 'Hjortgatan 3', -1, 4.0, 'Lägenhetsförråd, källare'],
        ['1301-03', 1, 'Soprum', 'Hjortgatan 9', 0, 18.0, 'Sortering hushållsavfall och förpackningar'],
        ['1302-02', 1, 'Garage', 'Rävstigen 4', 0, 18.0, 'Garage 1'],
        ['1302-02', 2, 'Garage', 'Rävstigen 4', 0, 18.0, 'Garage 2'],
        ['1302-02', 3, 'Parkeringsplats', 'Rävstigen 4', 0, 12.5, 'Carport med motorvärmare'],
        ['1303-01', 1, 'Lokal', 'Lärkvägen 10', 0, 245.0, 'Kontor, plan 1'],
        ['1303-01', 2, 'Lokal', 'Lärkvägen 10', 1, 238.5, 'Kontor, plan 2'],
        ['1303-01', 3, 'Teknikutrymme', 'Lärkvägen 10', -1, 32.0, 'Värmecentral för Lärkan'],
        ['1303-02', 101, 'Gemensamhetslokal', 'Lärkvägen 12', 0, 46.0, 'Samlingslokal med pentry'],
    ];
    foreach ($ovrigt as [$bnr, $nr, $typ, $adress, $plan, $m2, $beskr]) {
        $obj[] = ['objektnummer' => sprintf('%s-%04d', $bnr, $nr), 'byggnadsnummer' => $bnr, 'objekttyp' => $typ, 'gatuadress' => $adress,
                  'vaningsplan' => $plan === 0 ? 'BV' : (string)$plan, 'kvadratmeter' => number_format($m2, 1, ',', ''),
                  'kok' => $typ === 'Gemensamhetslokal' ? 'Kokskåp' : 'Inget kök', 'beskrivning' => $beskr,
                  'egenskap:tillgänglighetsanpassad' => $typ === 'Gemensamhetslokal' ? 'ja' : '', 'egenskap:golv' => in_array($typ, ['Lokal', 'Gemensamhetslokal'], true) ? 'Linoleum' : ''];
    }
    $logg[] = fas_demo_importera('objekt',
        ['objektnummer', 'byggnadsnummer', 'objekttyp', 'gatuadress', 'vaningsplan', 'kvadratmeter', 'antal_rum', 'kok', 'beskrivning',
         'egenskap:balkong', 'egenskap:tillgänglighetsanpassad', 'egenskap:golv', 'egenskap:senast renoverad'], $obj);

    // Objektgrupper och kopplingar
    $logg[] = fas_demo_importera('objektgrupp',
        ['objektgruppsnummer', 'namn', 'objektgruppstyp', 'beskrivning', 'egenskap:antal maskiner', 'egenskap:bokningsbar'], [
        ['objektgruppsnummer' => 'OG-1301-TR-A', 'namn' => 'Trapphus Hjortgatan 3', 'objektgruppstyp' => 'Trapphus', 'beskrivning' => 'Uppgång A'],
        ['objektgruppsnummer' => 'OG-1301-TV', 'namn' => 'Tvättstugan Hjorten', 'objektgruppstyp' => 'Tvättstuga', 'beskrivning' => 'Gemensam för Hus A och Hus B',
         'egenskap:antal maskiner' => '4', 'egenskap:bokningsbar' => 'ja'],
        ['objektgruppsnummer' => 'OG-1301-SOP', 'namn' => 'Miljöhuset Hjorten', 'objektgruppstyp' => 'Soprum/miljöhus', 'beskrivning' => 'Används av hela fastigheten'],
        ['objektgruppsnummer' => 'OG-1303-VS', 'namn' => 'Värmesystem Lärkan', 'objektgruppstyp' => 'Värmesystem', 'beskrivning' => 'Värmecentralen i Kontorshuset försörjer båda husen'],
        ['objektgruppsnummer' => 'OG-1301-GARD', 'namn' => 'Innergården Hjorten', 'objektgruppstyp' => 'Gemensam gård', 'egenskap:bokningsbar' => 'nej'],
    ]);
    $kopplingar = [
        ['objektgruppsnummer' => 'OG-1301-TV', 'kopplingstyp' => 'resurs', 'objektnummer' => '1301-01-0101'],
        ['objektgruppsnummer' => 'OG-1301-TV', 'kopplingstyp' => 'anvandare', 'byggnadsnummer' => '1301-01'],
        ['objektgruppsnummer' => 'OG-1301-TV', 'kopplingstyp' => 'anvandare', 'byggnadsnummer' => '1301-02'],
        ['objektgruppsnummer' => 'OG-1301-SOP', 'kopplingstyp' => 'resurs', 'objektnummer' => '1301-03-0001'],
        ['objektgruppsnummer' => 'OG-1301-SOP', 'kopplingstyp' => 'anvandare', 'fastighetsnummer' => '1301'],
        ['objektgruppsnummer' => 'OG-1303-VS', 'kopplingstyp' => 'resurs', 'objektnummer' => '1303-01-0003'],
        ['objektgruppsnummer' => 'OG-1303-VS', 'kopplingstyp' => 'anvandare', 'fastighetsnummer' => '1303'],
        ['objektgruppsnummer' => 'OG-1301-GARD', 'kopplingstyp' => 'resurs', 'byggnadsnummer' => '1301-04'],
        ['objektgruppsnummer' => 'OG-1301-GARD', 'kopplingstyp' => 'anvandare', 'byggnadsnummer' => '1301-01'],
        ['objektgruppsnummer' => 'OG-1301-GARD', 'kopplingstyp' => 'anvandare', 'byggnadsnummer' => '1301-02'],
    ];
    for ($n = 1; $n <= 8; $n++) {
        $kopplingar[] = ['objektgruppsnummer' => 'OG-1301-TR-A', 'kopplingstyp' => 'anvandare', 'objektnummer' => sprintf('1301-01-%04d', $n)];
    }
    $logg[] = fas_demo_importera('koppling', ['objektgruppsnummer', 'kopplingstyp', 'fastighetsnummer', 'byggnadsnummer', 'objektnummer'], $kopplingar);

    return $logg;
}

if (kc_ar_post() && kc_post('atgard') === 'fyll') {
    if (!$oppen) {
        throw new KcFel('Exempeldata kan bara läggas in i DEMO/GITHUB när KOLL_INSTALL = true.', 403);
    }
    $_SESSION['fas_demo_logg'] = kc_transaktion(fn() => fas_demo_kor());
    kc_flash('ok', 'Klart. Skicka ändringarna till CentralKollen under Synk.');
    kc_omdirigera('demo_data.php');
}

$logg = $_SESSION['fas_demo_logg'] ?? [];
unset($_SESSION['fas_demo_logg']);
kc_sidhuvud('Exempeldata');
?>
<div class="kc-ruta" style="max-width:52rem">
  <h2>Exempeldata för FastighetsKollen</h2>
  <p>Lägger in påhittade data: 3 fastigheter, 8 byggnader (bl.a. flerbostadshus, radhus, miljöhus och ett
     utomhusområde), adresser, drygt 50 objekt av olika typer, 5 objektgrupper med kopplingar och egenskaper på alla nivåer.
     Allt går via verktygets importer. Körs bara en gång – finns det redan fastigheter görs inget.</p>
  <?php if ($logg !== []): ?><ul><?php foreach ($logg as $r): ?><li><?= kc_h($r) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if (!$oppen): ?>
    <p><em class="kc-dampad">Stängd. Kräver KOLL_INSTALL = true i config.php och variant DEMO eller GITHUB (nu: <?= kc_h(KOLL_VARIANT) ?>).</em></p>
  <?php else: ?>
    <form method="post"><?= kc_csrf_falt() ?><input type="hidden" name="atgard" value="fyll">
      <button type="submit" class="kc-knapp-primar">Lägg in exempeldata</button></form>
    <p class="kc-hjalp">Därefter: Synk → Skicka, så att MätarKollen och ProjektKollen kan hämta datan.</p>
  <?php endif; ?>
</div>
<?php kc_sidfot();
