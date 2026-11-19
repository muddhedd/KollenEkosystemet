<?php
/**
 * MätarKollen – mat.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * MätarKollens egna hjälpfunktioner (prefix mat_).
 * Inkluderas direkt efter kärnpaketet:
 *   require __DIR__ . '/core/bootstrap.php';
 *   require __DIR__ . '/mat.php';
 *
 * Etapp 1: grund, inloggning och lokala kopior av fastighetsdata från FastighetsKollen.
 * Etapp 2: typlistan storhet och mätpunkter (placering, typ, status, föregående, förväntade storheter).
 * Etapp 3: typlistor för mätare, mätare (eget mätarnummer + mätarId), nycklar och montering med ställningar.
 * Etapp 4: egenskaper på mätpunkt och mätare (motorn i kärnpaketet 1.8.0, core/egenskap.php).
 * Etapp 5: push av alla 14 delade tabeller; mätpunkten bär placeringens nummer (placering_nummer).
 * Etapp 6a: betjäning på mätpunkten (vad den mäter för), skild från placeringen (var mätaren sitter).
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

/**
 * Lokala kopior av FastighetsKollens data (pull via CentralKollen). Bara de fält MätarKollen
 * behöver för att söka, välja och visa placering. Alla kolumner tillåter NULL, eftersom
 * mastern kan skicka fält som saknas eller är tomma. Standardfälten läggs till automatiskt.
 */
const MAT_LD_TABELLER = [
    'ld_fas_fastighet' => [
        'kolumner' => [
            'fastighetsnummer' => "VARCHAR(50)  NULL DEFAULT NULL",
            'namn'             => "VARCHAR(150) NULL DEFAULT NULL",
        ],
        'index' => ['fastighetsnummer'],
    ],
    'ld_fas_byggnad' => [
        'kolumner' => [
            'fastighet_uuid'   => "CHAR(36)     NULL DEFAULT NULL",
            'byggnadsnummer'   => "VARCHAR(50)  NULL DEFAULT NULL",
            'namn'             => "VARCHAR(150) NULL DEFAULT NULL",
            'byggnadstyp_uuid' => "CHAR(36)     NULL DEFAULT NULL",
        ],
        'index' => ['fastighet_uuid', 'byggnadsnummer'],
    ],
    'ld_fas_objekt' => [
        'kolumner' => [
            'byggnad_uuid'   => "CHAR(36)     NULL DEFAULT NULL",
            'objektnummer'   => "VARCHAR(50)  NULL DEFAULT NULL",
            'objekttyp_uuid' => "CHAR(36)     NULL DEFAULT NULL",
            'adress_uuid'    => "CHAR(36)     NULL DEFAULT NULL",
            'vaningsplan'    => "SMALLINT     NULL DEFAULT NULL",
        ],
        'index' => ['byggnad_uuid', 'objektnummer'],
    ],
    'ld_fas_objektgrupp' => [
        'kolumner' => [
            'objektgruppsnummer'   => "VARCHAR(50)  NULL DEFAULT NULL",
            'namn'                 => "VARCHAR(150) NULL DEFAULT NULL",
            'objektgruppstyp_uuid' => "CHAR(36)     NULL DEFAULT NULL",
        ],
        'index' => ['objektgruppsnummer'],
    ],
    'ld_fas_adress' => [
        'kolumner' => [
            'fastighet_uuid' => "CHAR(36)     NULL DEFAULT NULL",
            'byggnad_uuid'   => "CHAR(36)     NULL DEFAULT NULL",
            'gatuadress'     => "VARCHAR(150) NULL DEFAULT NULL",
            'postnummer'     => "VARCHAR(6)   NULL DEFAULT NULL",
            'ort'            => "VARCHAR(100) NULL DEFAULT NULL",
            'ar_huvudadress' => "TINYINT(1)   NULL DEFAULT NULL",
        ],
        'index' => ['fastighet_uuid', 'byggnad_uuid'],
    ],
    'ld_fas_byggnadstyp' => [
        'kolumner' => ['namn' => "VARCHAR(100) NULL DEFAULT NULL", 'sortering' => "INT NULL DEFAULT NULL", 'is_active' => "TINYINT(1) NULL DEFAULT NULL"],
        'index'    => [],
    ],
    'ld_fas_objekttyp' => [
        'kolumner' => ['namn' => "VARCHAR(100) NULL DEFAULT NULL", 'sortering' => "INT NULL DEFAULT NULL", 'is_active' => "TINYINT(1) NULL DEFAULT NULL"],
        'index'    => [],
    ],
    'ld_fas_objektgruppstyp' => [
        'kolumner' => ['namn' => "VARCHAR(100) NULL DEFAULT NULL", 'sortering' => "INT NULL DEFAULT NULL", 'is_active' => "TINYINT(1) NULL DEFAULT NULL"],
        'index'    => [],
    ],
];

/**
 * Typlistor: nyckel => tabell, rubrik, etikett, extra kolumner [kolumn => etikett], förifyllda värden.
 * Varje lista är en egen md_-tabell med namn, beskrivning, sortering, is_active (+ extra).
 */
const MAT_TYPLISTOR = [
    'storhet' => [
        'tabell' => 'md_storhet', 'rubrik' => 'Storheter', 'etikett' => 'storhet',
        'extra' => ['enhet' => 'Enhet'],
        'standard' => [
            ['namn' => 'Temperatur', 'enhet' => '°C'], ['namn' => 'Relativ fuktighet', 'enhet' => '%RH'],
            ['namn' => 'Kallvatten', 'enhet' => 'm³'], ['namn' => 'Varmvatten', 'enhet' => 'm³'],
            ['namn' => 'Värmeenergi', 'enhet' => 'kWh'], ['namn' => 'Fjärrvärme', 'enhet' => 'kWh'],
            ['namn' => 'El', 'enhet' => 'kWh'], ['namn' => 'Kyla', 'enhet' => 'kWh'],
            ['namn' => 'Gas', 'enhet' => 'm³'], ['namn' => 'CO₂', 'enhet' => 'ppm'],
        ],
    ],
    'matartyp' => [
        'tabell' => 'md_matartyp', 'rubrik' => 'Mätartyper', 'etikett' => 'mätartyp',
        'standard' => [
            ['namn' => 'Temperaturgivare'], ['namn' => 'Klimatgivare'], ['namn' => 'Kallvattenmätare'],
            ['namn' => 'Varmvattenmätare'], ['namn' => 'Värmemätare'], ['namn' => 'Kylmätare'],
            ['namn' => 'Elmätare'], ['namn' => 'Gasmätare'],
        ],
    ],
    'fabrikat' => [
        'tabell' => 'md_fabrikat', 'rubrik' => 'Fabrikat', 'etikett' => 'fabrikat',
        'standard' => [
            ['namn' => 'Kamstrup'], ['namn' => 'Elvaco'], ['namn' => 'Diehl Metering'],
            ['namn' => 'Axioma Metering'], ['namn' => 'Landis+Gyr'],
        ],
    ],
    'matarmodell' => [
        'tabell' => 'md_matarmodell', 'rubrik' => 'Mätarmodeller', 'etikett' => 'mätarmodell',
        'ref' => ['fabrikat_uuid' => ['lista' => 'fabrikat', 'etikett' => 'Fabrikat']],
        'standard' => [],
    ],
    'kommunikation' => [
        'tabell' => 'md_kommunikation', 'rubrik' => 'Kommunikation', 'etikett' => 'kommunikation',
        'standard' => [['namn' => 'LoRaWAN'], ['namn' => 'Trådlös M-Bus'], ['namn' => 'M-Bus'], ['namn' => 'Manuell']],
    ],
    'nyckeltyp' => [
        'tabell' => 'md_nyckeltyp', 'rubrik' => 'Nyckeltyper', 'etikett' => 'nyckeltyp',
        'standard' => [
            ['namn' => 'DevEUI'], ['namn' => 'JoinEUI/AppEUI'], ['namn' => 'AppKey'], ['namn' => 'AES-nyckel (wM-Bus)'],
        ],
    ],
];

/** Kolumner i en typtabell, utöver standardfälten (extra kolumner läggs till per lista). */
const MAT_TYP_KOLUMNER = [
    'namn'        => "VARCHAR(100) NOT NULL DEFAULT ''",
    'beskrivning' => "VARCHAR(255) NOT NULL DEFAULT ''",
    'sortering'   => "INT NOT NULL DEFAULT 0",
    'is_active'   => "TINYINT(1) NOT NULL DEFAULT 1",
];
const MAT_TYP_EXTRA_KOLUMNER = [
    'enhet'         => "VARCHAR(20) NOT NULL DEFAULT ''",
    'fabrikat_uuid' => "CHAR(36)    NOT NULL DEFAULT ''",
];

/** Var typerna används: typlista => [[tabell, kolumn], ...]. En typ som används kan inte raderas. */
const MAT_TYP_ANVANDNING = [
    'storhet'       => [['md_matpunkt_storhet', 'storhet_uuid'], ['md_stallning', 'storhet_uuid']],
    'matartyp'      => [['md_matare', 'matartyp_uuid']],
    'fabrikat'      => [['md_matarmodell', 'fabrikat_uuid'], ['md_matare', 'fabrikat_uuid']],
    'matarmodell'   => [['md_matare', 'matarmodell_uuid']],
    'kommunikation' => [['md_matare', 'kommunikation_uuid']],
    'nyckeltyp'     => [['md_matarnyckel', 'nyckeltyp_uuid']],
];

/** Egna mastertabeller (utöver typlistorna). Standardfälten läggs till automatiskt. */
const MAT_TABELLER = [
    'md_matpunkt' => [
        'beskrivning' => 'Mätpunkter: mätpunktsnamn, typ (fysisk/virtuell), status, placering (niva + placering_uuid + placering_nummer = var mätaren sitter), betjäning (betjaning_niva + betjaning_uuid + betjaning_nummer = vad mätpunkten mäter för), platsbeskrivning, föregående mätpunkt.',
        'kolumner' => [
            'matpunktsnamn'    => "VARCHAR(100) NOT NULL DEFAULT ''",
            'typ'              => "VARCHAR(20)  NOT NULL DEFAULT 'fysisk'",      // fysisk | virtuell
            'status'           => "VARCHAR(20)  NOT NULL DEFAULT 'projekt'",     // projekt | drifttagen | avvecklad
            'niva'             => "VARCHAR(20)  NOT NULL DEFAULT ''",            // fastighet | byggnad | objekt | objektgrupp
            'placering_uuid'   => "CHAR(36)     NOT NULL DEFAULT ''",            // uuid i FastighetsKollen
            'placering_nummer' => "VARCHAR(50)  NOT NULL DEFAULT ''",            // objekt-/byggnads-/fastighets-/objektgruppsnummer (kopia, uppdateras före push)
            'betjaning_niva'   => "VARCHAR(20)  NOT NULL DEFAULT ''",            // vad mätpunkten mäter för: fastighet | byggnad | objekt | objektgrupp
            'betjaning_uuid'   => "CHAR(36)     NOT NULL DEFAULT ''",            // uuid i FastighetsKollen
            'betjaning_nummer' => "VARCHAR(50)  NOT NULL DEFAULT ''",            // kopia av numret, uppdateras före push
            'platsbeskrivning' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'foregaende_uuid'  => "CHAR(36)     NOT NULL DEFAULT ''",            // föregående mätpunkt (flöde)
        ],
        'index' => ['matpunktsnamn', 'placering_uuid', 'betjaning_uuid', 'foregaende_uuid'],
    ],
    'md_matpunkt_storhet' => [
        'beskrivning' => 'Förväntade storheter per mätpunkt (matpunkt_uuid, storhet_uuid).',
        'kolumner' => [
            'matpunkt_uuid' => "CHAR(36) NOT NULL DEFAULT ''",
            'storhet_uuid'  => "CHAR(36) NOT NULL DEFAULT ''",
        ],
        'index' => ['matpunkt_uuid', 'storhet_uuid'],
    ],
    'md_matare' => [
        'beskrivning' => 'Mätare: matarnummer (MätarKollens eget id, t.ex. M000001), matar_id (det mätaren skickar), typ, fabrikat, modell, kommunikation, gateway.',
        'kolumner' => [
            'matarnummer'        => "VARCHAR(20)  NOT NULL DEFAULT ''",
            'matar_id'           => "VARCHAR(100) NOT NULL DEFAULT ''",
            'matartyp_uuid'      => "CHAR(36)     NOT NULL DEFAULT ''",
            'fabrikat_uuid'      => "CHAR(36)     NOT NULL DEFAULT ''",
            'matarmodell_uuid'   => "CHAR(36)     NOT NULL DEFAULT ''",
            'kommunikation_uuid' => "CHAR(36)     NOT NULL DEFAULT ''",
            'gateway'            => "VARCHAR(100) NOT NULL DEFAULT ''",   // text tills TeknikKollen finns
            'kommentar'          => "VARCHAR(255) NOT NULL DEFAULT ''",
        ],
        'index' => ['matarnummer', 'matar_id'],
    ],
    // Nycklar: delas ALDRIG (inte i API, listor eller export). Visas bara för inloggade.
    'md_matarnyckel' => [
        'delad' => false,
        'beskrivning' => 'Nycklar per mätare (DevEUI, AppKey m.fl.). Delas inte.',
        'kolumner' => [
            'matare_uuid'    => "CHAR(36)     NOT NULL DEFAULT ''",
            'nyckeltyp_uuid' => "CHAR(36)     NOT NULL DEFAULT ''",
            'varde'          => "VARCHAR(255) NOT NULL DEFAULT ''",
        ],
        'index' => ['matare_uuid'],
    ],
    'md_montering' => [
        'beskrivning' => 'Montering: vilken mätare som sitter i vilken mätpunkt, startdatum och slutdatum (tomt = monterad nu).',
        'kolumner' => [
            'matare_uuid'   => "CHAR(36) NOT NULL DEFAULT ''",
            'matpunkt_uuid' => "CHAR(36) NOT NULL DEFAULT ''",
            'startdatum'    => "DATE NULL",
            'slutdatum'     => "DATE NULL",
        ],
        'index' => ['matare_uuid', 'matpunkt_uuid'],
    ],
    'md_stallning' => [
        'beskrivning' => 'Mätarställning vid montering eller demontering, per storhet (värde som decimaltext med punkt).',
        'kolumner' => [
            'montering_uuid' => "CHAR(36)    NOT NULL DEFAULT ''",
            'handelse'       => "VARCHAR(20) NOT NULL DEFAULT ''",   // montering | demontering
            'storhet_uuid'   => "CHAR(36)    NOT NULL DEFAULT ''",
            'varde'          => "VARCHAR(30) NOT NULL DEFAULT ''",
        ],
        'index' => ['montering_uuid'],
    ],
] + KC_EGENSKAP_TABELLER;   // egenskapstabellerna kommer från kärnpaketet (1.8.0)

/** Nivåer som kan ha egenskaper (läses av kärnpaketets egenskapsmotor). */
const MAT_EGENSKAP_NIVAER = [
    'matpunkt' => ['tabell' => 'md_matpunkt', 'nummer' => 'matpunktsnamn', 'sida' => 'matpunkt.php', 'etikett' => 'Mätpunkt'],
    'matare'   => ['tabell' => 'md_matare',   'nummer' => 'matarnummer',   'sida' => 'matare.php',   'etikett' => 'Mätare'],
];

function mat_egenskap_nivaer(): array
{
    return MAT_EGENSKAP_NIVAER;
}

/** Händelser för ställningar. */
const MAT_HANDELSER = ['montering' => 'Vid montering', 'demontering' => 'Vid demontering'];

/** Mätpunktens typ och status (fasta listor – flöden bygger på dem). */
const MAT_MATPUNKT_TYPER  = ['fysisk' => 'Fysisk', 'virtuell' => 'Virtuell'];
const MAT_MATPUNKT_STATUS = ['projekt' => 'Projekt', 'drifttagen' => 'Drifttagen', 'avvecklad' => 'Avvecklad'];

/** Var en mätpunkt kan placeras: nivå => lokal kopia, nummerkolumn, etikett. */
const MAT_PLACERING = [
    'objekt'      => ['ld' => 'ld_fas_objekt',      'nummer' => 'objektnummer',       'etikett' => 'Objekt'],
    'byggnad'     => ['ld' => 'ld_fas_byggnad',     'nummer' => 'byggnadsnummer',     'etikett' => 'Byggnad'],
    'fastighet'   => ['ld' => 'ld_fas_fastighet',   'nummer' => 'fastighetsnummer',   'etikett' => 'Fastighet'],
    'objektgrupp' => ['ld' => 'ld_fas_objektgrupp', 'nummer' => 'objektgruppsnummer', 'etikett' => 'Objektgrupp'],
];

/**
 * Prenumerationer för kc_pull_alla(): [nyckel => master, tabell, ld, rubrik].
 * Måste också väljas som prenumerationer för MätarKollen i CentralKollen.
 */
function mat_prenumerationer(): array
{
    $rubrik = [
        'fastighet' => 'Fastigheter', 'byggnad' => 'Byggnader', 'objekt' => 'Objekt', 'objektgrupp' => 'Objektgrupper',
        'adress' => 'Adresser', 'byggnadstyp' => 'Byggnadstyper', 'objekttyp' => 'Objekttyper', 'objektgruppstyp' => 'Objektgruppstyper',
    ];
    $p = [];
    foreach (array_keys(MAT_LD_TABELLER) as $ld) {
        $tabell = substr($ld, strlen('ld_fas_'));
        $p['fas_' . $tabell] = ['master' => 'fas', 'tabell' => $tabell, 'ld' => $ld, 'rubrik' => $rubrik[$tabell] ?? $tabell];
    }
    return $p;
}

/** Tabellerna MätarKollen delar via CentralKollen (egen masterdata och typlistor). Push byggs i etapp 5. */
function mat_delade_tabeller(): array
{
    $t = [];
    foreach (MAT_TABELLER as $tabell => $def) {
        if (($def['delad'] ?? true) === false) {
            continue;
        }
        $t[substr($tabell, 3)] = ['tabell' => $tabell, 'beskrivning' => $def['beskrivning'] ?? ''];
    }
    foreach (MAT_TYPLISTOR as $def) {
        $t[substr($def['tabell'], 3)] = ['tabell' => $def['tabell'], 'beskrivning' => 'Typlista: ' . $def['rubrik'] . '.'];
    }
    ksort($t);
    return $t;
}

/* ---------- Typlistor ---------- */

/** Definition för en typlista, eller KcFel 404. */
function mat_typlista(string $lista): array
{
    if (!array_key_exists($lista, MAT_TYPLISTOR)) {
        throw new KcFel('Okänd typlista.', 404);
    }
    return MAT_TYPLISTOR[$lista] + ['nyckel' => $lista, 'extra' => [], 'ref' => []];
}

/** Installationsdefinition för en typlistas tabell. */
function mat_typ_tabelldef(string $lista): array
{
    $kol = MAT_TYP_KOLUMNER;
    $def = mat_typlista($lista);
    foreach (array_merge(array_keys($def['extra']), array_keys($def['ref'])) as $k) {
        $kol[$k] = MAT_TYP_EXTRA_KOLUMNER[$k];
    }
    return ['kolumner' => $kol, 'index' => ['namn']];
}

/** Aktiva typer: [uuid => rad], sorterade. */
function mat_typer_aktiva(string $lista): array
{
    $t = kc_tabell(mat_typlista($lista)['tabell']);
    $ut = [];
    foreach (kc_hamta_alla("SELECT * FROM `$t` WHERE is_deleted = 0 AND is_active = 1 ORDER BY sortering, namn") as $r) {
        $ut[$r['uuid']] = $r;
    }
    return $ut;
}

/** En typ (även inaktiv), eller null. */
function mat_typ_hamta(string $lista, string $uuid): ?array
{
    if (!kc_ar_uuid($uuid)) {
        return null;
    }
    $t = kc_tabell(mat_typlista($lista)['tabell']);
    return kc_hamta_en("SELECT * FROM `$t` WHERE uuid = ? AND is_deleted = 0 LIMIT 1", [$uuid]);
}

/** Hur många ej raderade poster som använder typen. */
function mat_typ_anvand(string $lista, string $uuid): int
{
    $n = 0;
    foreach (MAT_TYP_ANVANDNING[$lista] ?? [] as [$tabell, $kolumn]) {
        $t = kc_tabell($tabell);
        if (kc_inst_tabell_finns($t)) {
            $n += (int)kc_hamta_varde("SELECT COUNT(*) FROM `$t` WHERE `$kolumn` = ? AND is_deleted = 0", [$uuid]);
        }
    }
    return $n;
}

/** Storhet som text: "Temperatur (°C)". */
function mat_storhet_text(array $s): string
{
    return $s['namn'] . ($s['enhet'] !== '' ? ' (' . $s['enhet'] . ')' : '');
}

/* ---------- Mätpunkter ---------- */

function mat_matpunkt_hamta(string $uuid): ?array
{
    if (!kc_ar_uuid($uuid)) {
        return null;
    }
    $t = kc_tabell('md_matpunkt');
    return kc_hamta_en("SELECT * FROM `$t` WHERE uuid = ? AND is_deleted = 0 LIMIT 1", [$uuid]);
}

/** Mätpunkt via namn, eller null. */
function mat_matpunkt_via_namn(string $namn): ?array
{
    $t = kc_tabell('md_matpunkt');
    return kc_hamta_en("SELECT * FROM `$t` WHERE matpunktsnamn = ? AND is_deleted = 0 LIMIT 1", [$namn]);
}

/** Giltigt mätpunktsnamn: 1–100 tecken, bokstäver, siffror och - _ . / (inga mellanslag). */
function mat_matpunktsnamn_ok(string $namn): bool
{
    return preg_match('#^[\p{L}0-9_.\-/]{1,100}$#u', $namn) === 1;
}

/** Placeringens uuid via nivå och nummer (i lokala kopian), eller null. */
function mat_placering_hitta(string $niva, string $nummer): ?string
{
    $def = MAT_PLACERING[$niva] ?? null;
    if ($def === null || $nummer === '') {
        return null;
    }
    $t = kc_tabell($def['ld']);
    $v = kc_hamta_varde("SELECT uuid FROM `$t` WHERE `{$def['nummer']}` = ? AND is_deleted = 0 ORDER BY id LIMIT 1", [$nummer]);
    return $v === null ? null : (string)$v;
}

/**
 * Placeringen som text för visning. Returnerar ['finns' => bool, 'nummer', 'text', 'adress'].
 * finns = false om posten saknas eller är raderad i FastighetsKollen (enligt senaste hämtning).
 */
/** Kolumnerna för mätpunktens två kopplingar till fastighetsdatan: [nivå, uuid, nummer]. */
const MAT_FAS_KOPPLINGAR = [
    'placering' => ['niva', 'placering_uuid', 'placering_nummer'],
    'betjaning' => ['betjaning_niva', 'betjaning_uuid', 'betjaning_nummer'],
];

/**
 * Uppdaterar mätpunkternas placering_nummer och betjaning_nummer från fastighetsdatan (om numret
 * ändrats i FastighetsKollen). Ändrade mätpunkter får ny last_changed och följer med i nästa push.
 * En koppling som saknas behåller sitt senast kända nummer. Anropas före push och av install.php.
 * Returnerar antal uppdaterade nummer.
 */
function mat_placering_nummer_uppdatera(): int
{
    $tm = kc_tabell('md_matpunkt');
    $n = 0;
    kc_transaktion(function () use ($tm, &$n): void {
        $nu = kc_nu();
        foreach (MAT_FAS_KOPPLINGAR as [$kn, $ku, $knr]) {
            foreach (MAT_PLACERING as $niva => $def) {
                $tl = kc_tabell($def['ld']);
                $rader = kc_hamta_alla(
                    "SELECT m.uuid, x.`{$def['nummer']}` AS nummer
                       FROM `$tm` m JOIN `$tl` x ON x.uuid = m.`$ku` AND x.is_deleted = 0
                      WHERE m.`$kn` = ? AND m.is_deleted = 0 AND x.`{$def['nummer']}` IS NOT NULL
                        AND x.`{$def['nummer']}` <> '' AND m.`$knr` <> x.`{$def['nummer']}`",
                    [$niva]
                );
                foreach ($rader as $r) {
                    $n += kc_kor("UPDATE `$tm` SET `$knr` = ?, last_changed = ?, source_tool = 'mat' WHERE uuid = ?",
                                 [(string)$r['nummer'], $nu, $r['uuid']]);
                }
            }
        }
    });
    return $n;
}

/** Sätter betjäning = placering på mätpunkter som saknar betjäning (install.php). Returnerar antal. */
function mat_betjaning_fyll(): int
{
    $tm = kc_tabell('md_matpunkt');
    return kc_kor(
        "UPDATE `$tm` SET betjaning_niva = niva, betjaning_uuid = placering_uuid, betjaning_nummer = placering_nummer,
                last_changed = ?, source_tool = 'mat'
          WHERE is_deleted = 0 AND betjaning_uuid = '' AND placering_uuid <> ''",
        [kc_nu()]
    );
}

/**
 * Tolkar nivå + nummer mot fastighetsdatan för placering eller betjäning.
 * En oförändrad koppling som saknas i fastighetsdatan får ligga kvar ($nu_niva/$nu_uuid = nuvarande).
 * Returnerar [uuid|null, fel|null].
 */
function mat_fas_koppling_tolka(string $niva, string $nummer, string $vad, string $nu_niva = '', string $nu_uuid = ''): array
{
    if (!array_key_exists($niva, MAT_PLACERING)) {
        return [null, "Välj nivå för $vad."];
    }
    if ($nummer === '') {
        return [null, "Ange numret för $vad."];
    }
    $u = mat_placering_hitta($niva, $nummer);
    if ($u === null && $nu_uuid !== '' && $niva === $nu_niva && $nummer === mat_placering_info($nu_niva, $nu_uuid)['nummer']) {
        $u = $nu_uuid;
    }
    if ($u === null) {
        return [null, MAT_PLACERING[$niva]['etikett'] . " $nummer ($vad) finns inte i fastighetsdatan. "
                    . 'Kontrollera numret eller hämta fastighetsdata under Synk.'];
    }
    return [$u, null];
}

/** Numret att spara för en koppling: från fastighetsdatan, annars senast kända (om oförändrad), annars det inmatade. */
function mat_fas_koppling_nummer(string $niva, string $uuid, string $inmatat, string $nu_uuid = '', string $nu_nummer = ''): string
{
    $nr = mat_placering_info($niva, $uuid)['nummer'];
    if ($nr !== '') {
        return $nr;
    }
    return $uuid === $nu_uuid && $nu_nummer !== '' ? $nu_nummer : $inmatat;
}

function mat_placering_info(string $niva, string $uuid): array
{
    $def = MAT_PLACERING[$niva] ?? null;
    $tom = ['finns' => false, 'nummer' => '', 'text' => '', 'adress' => ''];
    if ($def === null || !kc_ar_uuid($uuid)) {
        return $tom;
    }
    $t = kc_tabell($def['ld']);
    $r = kc_hamta_en("SELECT * FROM `$t` WHERE uuid = ? LIMIT 1", [$uuid]);
    if ($r === null || (int)$r['is_deleted'] === 1) {
        return ['nummer' => (string)($r[$def['nummer']] ?? '')] + $tom;
    }
    $nummer = (string)$r[$def['nummer']];
    $text = $def['etikett'] . ' ' . $nummer;
    $adress = '';
    if ($niva === 'objekt') {
        $tb = kc_tabell('ld_fas_byggnad');
        $b = kc_hamta_en("SELECT byggnadsnummer, namn FROM `$tb` WHERE uuid = ? AND is_deleted = 0 LIMIT 1", [(string)$r['byggnad_uuid']]);
        if ($b !== null) {
            $text .= ' i byggnad ' . trim($b['byggnadsnummer'] . ' ' . $b['namn']);
        }
        $ta = kc_tabell('ld_fas_adress');
        $a = kc_hamta_en("SELECT gatuadress, postnummer, ort FROM `$ta` WHERE uuid = ? AND is_deleted = 0 LIMIT 1", [(string)$r['adress_uuid']]);
        $adress = $a === null ? '' : trim($a['gatuadress'] . ', ' . trim($a['postnummer'] . ' ' . $a['ort']), ', ');
    } else {
        if (($r['namn'] ?? '') !== '') {
            $text .= ' ' . $r['namn'];
        }
        if (in_array($niva, ['fastighet', 'byggnad'], true)) {
            $adress = mat_adress_text($niva, $uuid);
        }
    }
    return ['finns' => true, 'nummer' => $nummer, 'text' => $text, 'adress' => $adress];
}

/** Förväntade storheter för en mätpunkt: lista med storhetsrader (även inaktiva typer). */
function mat_matpunkt_storheter(string $matpunkt_uuid): array
{
    $tk = kc_tabell('md_matpunkt_storhet');
    $ts = kc_tabell('md_storhet');
    return kc_hamta_alla(
        "SELECT s.* FROM `$tk` k JOIN `$ts` s ON s.uuid = k.storhet_uuid AND s.is_deleted = 0
          WHERE k.matpunkt_uuid = ? AND k.is_deleted = 0 ORDER BY s.sortering, s.namn",
        [$matpunkt_uuid]
    );
}

/** Mätpunkter som har denna som föregående. */
function mat_efterfoljande(string $uuid): array
{
    $t = kc_tabell('md_matpunkt');
    return kc_hamta_alla("SELECT * FROM `$t` WHERE foregaende_uuid = ? AND is_deleted = 0 ORDER BY matpunktsnamn", [$uuid]);
}

/**
 * Skulle föregående = $foregaende skapa en slinga för $uuid? (Följer kedjan uppåt.)
 */
function mat_ger_slinga(string $uuid, string $foregaende): bool
{
    $t = kc_tabell('md_matpunkt');
    $nu = $foregaende;
    for ($i = 0; $i < 1000 && $nu !== ''; $i++) {
        if ($nu === $uuid) {
            return true;
        }
        $nu = (string)(kc_hamta_varde("SELECT foregaende_uuid FROM `$t` WHERE uuid = ? AND is_deleted = 0 LIMIT 1", [$nu]) ?? '');
    }
    return false;
}

/** <option>-taggar. $tom = text för tomt val, eller null (= "– välj –"). */
function mat_alternativ(array $val, string $valt, ?string $tom = null): string
{
    $h = '<option value="">' . kc_h($tom ?? '– välj –') . '</option>';
    foreach ($val as $k => $namn) {
        $h .= '<option value="' . kc_h($k) . '"' . ((string)$k === $valt ? ' selected' : '') . '>' . kc_h($namn) . '</option>';
    }
    return $h;
}

/* ---------- Fastighetsdata (lokala kopior) ---------- */

/** Antal aktiva rader i en lokal kopia. */
function mat_ld_antal(string $ld): int
{
    $t = kc_tabell($ld);
    return kc_inst_tabell_finns($t) ? (int)kc_hamta_varde("SELECT COUNT(*) FROM `$t` WHERE is_deleted = 0") : 0;
}

/** Huvudadress (eller första adress) för en fastighet/byggnad som text, eller ''. */
function mat_adress_text(string $agare, string $uuid): string
{
    $kol = $agare === 'fastighet' ? 'fastighet_uuid' : 'byggnad_uuid';
    $t = kc_tabell('ld_fas_adress');
    $a = kc_hamta_en(
        "SELECT gatuadress, postnummer, ort FROM `$t` WHERE `$kol` = ? AND is_deleted = 0 ORDER BY ar_huvudadress DESC, id LIMIT 1",
        [$uuid]
    );
    return $a === null ? '' : trim(($a['gatuadress'] ?? '') . ', ' . trim(($a['postnummer'] ?? '') . ' ' . ($a['ort'] ?? '')), ', ');
}

/**
 * Söker i fastighetsdatan på nummer, namn och gatuadress.
 * Returnerar [['niva', 'uuid', 'nummer', 'namn', 'plats', 'typ'], ...], högst $max per nivå.
 * Grunden för placeringsvalet för mätpunkter (etapp 2).
 */
function mat_sok_placering(string $q, ?string $bara_niva = null, int $max = 25): array
{
    $q = trim($q);
    if (mb_strlen($q) < 1) {
        return [];
    }
    $lik = '%' . addcslashes($q, '%_\\') . '%';
    $tf = kc_tabell('ld_fas_fastighet');
    $tb = kc_tabell('ld_fas_byggnad');
    $to = kc_tabell('ld_fas_objekt');
    $tg = kc_tabell('ld_fas_objektgrupp');
    $ta = kc_tabell('ld_fas_adress');
    $tbt = kc_tabell('ld_fas_byggnadstyp');
    $tot = kc_tabell('ld_fas_objekttyp');
    $tgt = kc_tabell('ld_fas_objektgruppstyp');
    $fragor = [
        'fastighet' => [
            "SELECT 'fastighet' AS niva, f.uuid, f.fastighetsnummer AS nummer, f.namn, '' AS plats, '' AS typ
               FROM `$tf` f
              WHERE f.is_deleted = 0 AND (f.fastighetsnummer LIKE ? OR f.namn LIKE ?
                    OR EXISTS (SELECT 1 FROM `$ta` a WHERE a.fastighet_uuid = f.uuid AND a.is_deleted = 0 AND a.gatuadress LIKE ?))
              ORDER BY f.fastighetsnummer LIMIT $max",
            [$lik, $lik, $lik],
        ],
        'byggnad' => [
            "SELECT 'byggnad' AS niva, b.uuid, b.byggnadsnummer AS nummer, b.namn, COALESCE(f.fastighetsnummer, '') AS plats, COALESCE(t.namn, '') AS typ
               FROM `$tb` b
               LEFT JOIN `$tf` f ON f.uuid = b.fastighet_uuid AND f.is_deleted = 0
               LEFT JOIN `$tbt` t ON t.uuid = b.byggnadstyp_uuid
              WHERE b.is_deleted = 0 AND (b.byggnadsnummer LIKE ? OR b.namn LIKE ?
                    OR EXISTS (SELECT 1 FROM `$ta` a WHERE a.byggnad_uuid = b.uuid AND a.is_deleted = 0 AND a.gatuadress LIKE ?))
              ORDER BY b.byggnadsnummer LIMIT $max",
            [$lik, $lik, $lik],
        ],
        'objekt' => [
            "SELECT 'objekt' AS niva, o.uuid, o.objektnummer AS nummer, COALESCE(a.gatuadress, '') AS namn,
                    COALESCE(b.byggnadsnummer, '') AS plats, COALESCE(t.namn, '') AS typ
               FROM `$to` o
               LEFT JOIN `$tb` b ON b.uuid = o.byggnad_uuid AND b.is_deleted = 0
               LEFT JOIN `$ta` a ON a.uuid = o.adress_uuid AND a.is_deleted = 0
               LEFT JOIN `$tot` t ON t.uuid = o.objekttyp_uuid
              WHERE o.is_deleted = 0 AND (o.objektnummer LIKE ? OR a.gatuadress LIKE ?)
              ORDER BY o.objektnummer LIMIT $max",
            [$lik, $lik],
        ],
        'objektgrupp' => [
            "SELECT 'objektgrupp' AS niva, g.uuid, g.objektgruppsnummer AS nummer, g.namn, '' AS plats, COALESCE(t.namn, '') AS typ
               FROM `$tg` g
               LEFT JOIN `$tgt` t ON t.uuid = g.objektgruppstyp_uuid
              WHERE g.is_deleted = 0 AND (g.objektgruppsnummer LIKE ? OR g.namn LIKE ?)
              ORDER BY g.objektgruppsnummer LIMIT $max",
            [$lik, $lik],
        ],
    ];
    $ut = [];
    foreach ($fragor as $niva => [$sql, $params]) {
        if ($bara_niva !== null && $bara_niva !== $niva) {
            continue;
        }
        array_push($ut, ...kc_hamta_alla($sql, $params));
    }
    return $ut;
}

/* ---------- Mätare ---------- */

function mat_matare_hamta(string $uuid): ?array
{
    if (!kc_ar_uuid($uuid)) {
        return null;
    }
    $t = kc_tabell('md_matare');
    return kc_hamta_en("SELECT * FROM `$t` WHERE uuid = ? AND is_deleted = 0 LIMIT 1", [$uuid]);
}

/**
 * Hittar en mätare via mätarnummer (exakt) eller mätarId.
 * Returnerar [rad|null, fel|null]; flera mätare med samma mätarId ger fel med deras mätarnummer.
 */
function mat_matare_sok(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [null, 'Ange mätare (mätarnummer eller mätarId).'];
    }
    $t = kc_tabell('md_matare');
    $r = kc_hamta_en("SELECT * FROM `$t` WHERE matarnummer = ? AND is_deleted = 0 LIMIT 1", [$text]);
    if ($r !== null) {
        return [$r, null];
    }
    $rader = kc_hamta_alla("SELECT * FROM `$t` WHERE matar_id = ? AND is_deleted = 0 ORDER BY matarnummer LIMIT 10", [$text]);
    if (count($rader) === 1) {
        return [$rader[0], null];
    }
    if ($rader === []) {
        return [null, "Hittar ingen mätare med mätarnummer eller mätarId $text."];
    }
    return [null, "Flera mätare har mätarId $text (" . implode(', ', array_column($rader, 'matarnummer')) . '). Ange mätarnumret.'];
}

/** Mätare som text: "M000001 (mätarId 12345678)". */
function mat_matare_text(array $m): string
{
    return $m['matarnummer'] . ($m['matar_id'] !== '' ? ' (mätarId ' . $m['matar_id'] . ')' : '');
}

/**
 * Nästa lediga mätarnummer, M + minst sex siffror. Ska anropas inuti en transaktion:
 * räknaren i sys_raknare låses (FOR UPDATE) så att två samtidiga sparningar inte får samma nummer.
 */
function mat_nasta_matarnummer(): string
{
    $tr = kc_tabell('sys_raknare');
    $tm = kc_tabell('md_matare');
    kc_kor("INSERT IGNORE INTO `$tr` (namn, varde) VALUES ('matare', 0)");
    $v = (int)kc_hamta_varde("SELECT varde FROM `$tr` WHERE namn = 'matare' FOR UPDATE");
    do {
        $v++;
        $nr = 'M' . str_pad((string)$v, 6, '0', STR_PAD_LEFT);
    } while ((int)kc_hamta_varde("SELECT COUNT(*) FROM `$tm` WHERE matarnummer = ?", [$nr]) > 0);
    kc_kor("UPDATE `$tr` SET varde = ? WHERE namn = 'matare'", [$v]);
    return $nr;
}

/** Andra mätare med samma mätarId på samma gateway (varning, inte fel). */
function mat_matar_id_dubbletter(string $matar_id, string $gateway, string $utom_uuid = ''): array
{
    if ($matar_id === '') {
        return [];
    }
    $t = kc_tabell('md_matare');
    return kc_hamta_alla(
        "SELECT * FROM `$t` WHERE matar_id = ? AND LOWER(gateway) = LOWER(?) AND uuid <> ? AND is_deleted = 0 ORDER BY matarnummer",
        [$matar_id, $gateway, $utom_uuid]
    );
}

/** Mätarens nycklar: [nyckeltyp_uuid => rad] (bara ej raderade). Visas bara för inloggade. */
function mat_nycklar(string $matare_uuid): array
{
    $t = kc_tabell('md_matarnyckel');
    $ut = [];
    foreach (kc_hamta_alla("SELECT * FROM `$t` WHERE matare_uuid = ? AND is_deleted = 0 ORDER BY id", [$matare_uuid]) as $r) {
        $ut[$r['nyckeltyp_uuid']] = $r;
    }
    return $ut;
}

/** Maskerat nyckelvärde: punkter + de fyra sista tecknen. */
function mat_maskera(string $v): string
{
    $n = mb_strlen($v);
    return $n <= 4 ? str_repeat('•', $n) : str_repeat('•', min(12, $n - 4)) . mb_substr($v, -4);
}

/* ---------- Montering och ställningar ---------- */

/** Dagens datum i verktygets tidszon (ÅÅÅÅ-MM-DD). */
function mat_idag(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(KOLL_TIDSZON)))->format('Y-m-d');
}

function mat_datum_ok(string $d): bool
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}

function mat_montering_hamta(string $uuid): ?array
{
    if (!kc_ar_uuid($uuid)) {
        return null;
    }
    $t = kc_tabell('md_montering');
    return kc_hamta_en("SELECT * FROM `$t` WHERE uuid = ? AND is_deleted = 0 LIMIT 1", [$uuid]);
}

/**
 * Monteringar med mätarens och mätpunktens namn, senaste först.
 * $kolumn = 'matpunkt_uuid' eller 'matare_uuid'.
 */
function mat_monteringar(string $kolumn, string $uuid): array
{
    if (!in_array($kolumn, ['matpunkt_uuid', 'matare_uuid'], true)) {
        throw new KcFel('mat_monteringar: ogiltig kolumn.', 500);
    }
    $t  = kc_tabell('md_montering');
    $tm = kc_tabell('md_matare');
    $tp = kc_tabell('md_matpunkt');
    return kc_hamta_alla(
        "SELECT g.*, COALESCE(m.matarnummer, '') AS matarnummer, COALESCE(m.matar_id, '') AS matar_id,
                COALESCE(p.matpunktsnamn, '') AS matpunktsnamn
           FROM `$t` g
           LEFT JOIN `$tm` m ON m.uuid = g.matare_uuid AND m.is_deleted = 0
           LEFT JOIN `$tp` p ON p.uuid = g.matpunkt_uuid AND p.is_deleted = 0
          WHERE g.`$kolumn` = ? AND g.is_deleted = 0
          ORDER BY g.slutdatum IS NULL DESC, g.startdatum DESC, g.id DESC",
        [$uuid]
    );
}

/** Pågående montering (utan slutdatum) för en mätpunkt eller en mätare, eller null. */
function mat_aktiv_montering(string $kolumn, string $uuid): ?array
{
    foreach (mat_monteringar($kolumn, $uuid) as $g) {
        if ($g['slutdatum'] === null || $g['slutdatum'] === '') {
            return $g;
        }
    }
    return null;
}

/**
 * Kontrollerar att en montering [start, slut) inte krockar med andra monteringar för samma
 * mätare eller samma mätpunkt. Tomt slut = pågår. Byte samma dag är tillåtet (slut = nästa start).
 * Returnerar en lista med fel (tom = ok).
 */
function mat_montering_kontroll(string $matare_uuid, string $matpunkt_uuid, string $start, string $slut, string $utom_uuid = ''): array
{
    $fel = [];
    if (!mat_datum_ok($start)) {
        return ['Ange ett giltigt startdatum (ÅÅÅÅ-MM-DD).'];
    }
    if ($slut !== '' && !mat_datum_ok($slut)) {
        return ['Ange ett giltigt slutdatum (ÅÅÅÅ-MM-DD).'];
    }
    if ($slut !== '' && $slut < $start) {
        return ['Slutdatum kan inte vara före startdatum.'];
    }
    $slut_cmp = $slut === '' ? '9999-12-31' : $slut;
    foreach (['matare_uuid' => $matare_uuid, 'matpunkt_uuid' => $matpunkt_uuid] as $kolumn => $uuid) {
        foreach (mat_monteringar($kolumn, $uuid) as $g) {
            if ($g['uuid'] === $utom_uuid) {
                continue;
            }
            $g_slut = ($g['slutdatum'] ?? '') === '' ? '9999-12-31' : $g['slutdatum'];
            if ($start < $g_slut && (string)$g['startdatum'] < $slut_cmp) {
                $period = $g['startdatum'] . ' – ' . (($g['slutdatum'] ?? '') === '' ? 'pågår' : $g['slutdatum']);
                $fel[] = $kolumn === 'matare_uuid'
                    ? "Mätaren är monterad i {$g['matpunktsnamn']} under perioden $period."
                    : "Mätpunkten har mätare {$g['matarnummer']} under perioden $period.";
            }
        }
    }
    return array_values(array_unique($fel));
}

/** Normaliserar en ställning: '' = tom, null = ogiltig, annars decimal med punkt. */
function mat_stallning_normalisera(string $v): ?string
{
    $v = str_replace([' ', "\u{00A0}"], '', trim($v));
    if ($v === '') {
        return '';
    }
    if (!preg_match('/^\d{1,12}([.,]\d{1,6})?$/', $v)) {
        return null;
    }
    $v = str_replace(',', '.', $v);
    if (str_contains($v, '.')) {
        $v = rtrim(rtrim($v, '0'), '.');
    }
    $v = ltrim($v, '0');
    if ($v === '' || $v[0] === '.') {
        $v = '0' . $v;
    }
    return $v;
}

/** Ställning för visning (decimalkomma). */
function mat_stallning_visa(string $v): string
{
    return str_replace('.', ',', $v);
}

/** Ställningar för en montering: [handelse => [storhet_uuid => varde]]. */
function mat_stallningar(string $montering_uuid): array
{
    $t = kc_tabell('md_stallning');
    $ut = ['montering' => [], 'demontering' => []];
    foreach (kc_hamta_alla("SELECT * FROM `$t` WHERE montering_uuid = ? AND is_deleted = 0 ORDER BY id", [$montering_uuid]) as $r) {
        $ut[$r['handelse']][$r['storhet_uuid']] = $r['varde'];
    }
    return $ut;
}

/**
 * Sparar ställningar för en händelse: [storhet_uuid => normaliserat värde]; tomt värde tar bort.
 * Borttagen ställning återaktiveras med samma uuid. Returnerar true om något ändrades. Anropas i transaktion.
 */
function mat_stallningar_spara(string $montering_uuid, string $handelse, array $varden): bool
{
    $t = kc_tabell('md_stallning');
    $nu = kc_nu();
    $andrat = false;
    foreach ($varden as $storhet => $v) {
        $r = kc_hamta_en(
            "SELECT * FROM `$t` WHERE montering_uuid = ? AND handelse = ? AND storhet_uuid = ? ORDER BY is_deleted ASC, id ASC LIMIT 1",
            [$montering_uuid, $handelse, $storhet]
        );
        if ($v === '') {
            if ($r !== null && (int)$r['is_deleted'] === 0) {
                kc_kor("UPDATE `$t` SET is_deleted = 1, last_changed = ?, source_tool = 'mat' WHERE uuid = ?", [$nu, $r['uuid']]);
                $andrat = true;
            }
        } elseif ($r === null) {
            kc_kor(
                "INSERT INTO `$t` (uuid, last_changed, is_deleted, source_tool, montering_uuid, handelse, storhet_uuid, varde)
                 VALUES (?, ?, 0, 'mat', ?, ?, ?, ?)",
                [kc_uuid(), $nu, $montering_uuid, $handelse, $storhet, $v]
            );
            $andrat = true;
        } elseif ((int)$r['is_deleted'] === 1 || $r['varde'] !== $v) {
            kc_kor("UPDATE `$t` SET varde = ?, is_deleted = 0, last_changed = ?, source_tool = 'mat' WHERE uuid = ?", [$v, $nu, $r['uuid']]);
            $andrat = true;
        }
    }
    return $andrat;
}

/**
 * Läser ställningsfält ur POST (namn "<prefix>[<storhet_uuid>]") för de givna storheterna.
 * Returnerar [värden, fel].
 */
function mat_stallningar_las(string $prefix, array $storheter): array
{
    $in = $_POST[$prefix] ?? [];
    $in = is_array($in) ? $in : [];
    $ut = [];
    $fel = [];
    foreach ($storheter as $uuid => $s) {
        $r = $in[$uuid] ?? '';
        $v = mat_stallning_normalisera(is_string($r) ? $r : '');
        if ($v === null) {
            $fel[] = 'Ogiltig ställning för ' . mat_storhet_text($s) . ' (siffror, ev. decimaler med komma).';
            $ut[$uuid] = is_string($r) ? $r : '';
        } else {
            $ut[$uuid] = $v;
        }
    }
    return [$ut, $fel];
}

/** Storheter att registrera ställning för: mätpunktens förväntade + de som redan har ställning. [uuid => rad] */
function mat_stallning_storheter(string $matpunkt_uuid, array $befintliga = []): array
{
    $ut = [];
    foreach (mat_matpunkt_storheter($matpunkt_uuid) as $s) {
        $ut[$s['uuid']] = $s;
    }
    foreach ($befintliga as $uuid) {
        if (!isset($ut[$uuid]) && ($s = mat_typ_hamta('storhet', (string)$uuid)) !== null) {
            $ut[$uuid] = $s;
        }
    }
    return $ut;
}

/** Mjuk radering av en montering och dess ställningar. Anropas i transaktion. */
function mat_montering_radera(string $uuid): void
{
    $nu = kc_nu();
    kc_kor('UPDATE `' . kc_tabell('md_stallning') . "` SET is_deleted = 1, last_changed = ?, source_tool = 'mat' WHERE montering_uuid = ? AND is_deleted = 0", [$nu, $uuid]);
    kc_kor('UPDATE `' . kc_tabell('md_montering') . "` SET is_deleted = 1, last_changed = ?, source_tool = 'mat' WHERE uuid = ? AND is_deleted = 0", [$nu, $uuid]);
}

/** HTML för ställningsfält (en rad per storhet). */
function mat_stallning_falt(string $prefix, array $storheter, array $varden): string
{
    if ($storheter === []) {
        return '<p class="kc-hjalp">Mätpunkten har inga förväntade storheter, så ingen ställning kan registreras.</p>';
    }
    $h = '';
    foreach ($storheter as $uuid => $s) {
        $id = $prefix . '_' . substr(md5($uuid), 0, 8);
        $v = $varden[$uuid] ?? '';
        $h .= '<div class="kc-falt"><label for="' . kc_h($id) . '">' . kc_h(mat_storhet_text($s)) . '</label>'
            . '<input type="text" inputmode="decimal" id="' . kc_h($id) . '" name="' . kc_h($prefix) . '[' . kc_h($uuid) . ']"'
            . ' maxlength="30" style="width:12rem" value="' . kc_h(mat_stallning_visa((string)$v)) . '"></div>';
    }
    return $h . '<p class="kc-hjalp">Valfritt. Lämna tomt om ingen ställning lästs av.</p>';
}

/** Ställningar som text för visning: "Kallvatten 12,5 m³; …". */
function mat_stallning_text(array $varden): string
{
    $d = [];
    foreach ($varden as $storhet => $v) {
        $s = mat_typ_hamta('storhet', (string)$storhet);
        $d[] = ($s['namn'] ?? '?') . ' ' . mat_stallning_visa((string)$v) . (($s['enhet'] ?? '') !== '' ? ' ' . $s['enhet'] : '');
    }
    return implode('; ', $d);
}

/* ---------- Inloggning ---------- */

/** Inloggad användare (kontrollerad mot lokal kopia), eller null. */
function mat_inloggad(): ?array
{
    return kc_anvandare_kontrollera();
}

/** Kräver inloggning för att ändra data. */
function mat_krav_inloggning(): array
{
    mat_inloggad();
    return kc_krav_inloggning();
}

// Kontrollera inloggningen tidigt på varje sida (utom installationen, där tabellerna kan
// saknas, och API:et, som autentiseras med nyckel och saknar session)
if (!defined('KC_API') && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'install.php') {
    mat_inloggad();
}
