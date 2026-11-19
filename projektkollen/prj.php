<?php
/**
 * ProjektKollen – prj.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Verktygets hjälpfunktioner (prefix prj_). Inkluderas efter core/bootstrap.php.
 *
 * ProjektKollen äger projekt, projektets omfattning och objektlista, planerade mätpunkter och
 * inköpta mätare (med nycklar). Mätpunkter och mätare blir riktiga först när de importeras i
 * MätarKollen (mat är master). Pilot: projekttypen "Installation temperaturmätare".
 *
 * Etapp 1: grund, inloggning, hämtning av fastighets- och mätardata, projekt, projekttyper och funktioner.
 * Etapp 2: omfattning (fastigheter, byggnader, objekt, objektgrupper) → objektlista (antal givare per
 *          objekt) → mätpunktslista enligt namnstandarden <objektnummer>_<funktion>_<X><Y>.
 * Etapp 3: etiketter som PDF med QR-kod (egen kod: prj_qr.php, prj_pdf.php). Utskrift låser namnen.
 * Etapp 4: gemensamt mätarlager – import av inköpta mätare med nycklar (prj_import.php). Mätaren får
 *          projekt och mätpunkt först vid provisionering (etapp 5).
 * Etapp 5a: provisionering med kameraläsning (provisionering.php, prj_lasare.js): mätpunkt ↔ mätare.
 * Etapp 5b: monteringskontroll (montering.php): läs objekt + mätare; rätt matchning sparar monteringsdatum.
 * Etapp 6: exportmallar och exporter (prj_export.php, export.php).
 * Etapp 7: avslut med kontrollista och rensning av nycklar, återöppning (avslut.php).
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

/**
 * Lokala kopior från FastighetsKollen och MätarKollen (pull via CentralKollen).
 * Alla kolumner tillåter NULL. Standardfälten läggs till automatiskt.
 */
const PRJ_LD_TABELLER = [
    // FastighetsKollen
    'ld_fas_fastighet' => [
        'kolumner' => ['fastighetsnummer' => "VARCHAR(50) NULL DEFAULT NULL", 'namn' => "VARCHAR(150) NULL DEFAULT NULL"],
        'index'    => ['fastighetsnummer'],
    ],
    'ld_fas_byggnad' => [
        'kolumner' => [
            'fastighet_uuid' => "CHAR(36) NULL DEFAULT NULL", 'byggnadsnummer' => "VARCHAR(50) NULL DEFAULT NULL",
            'namn' => "VARCHAR(150) NULL DEFAULT NULL", 'byggnadstyp_uuid' => "CHAR(36) NULL DEFAULT NULL",
        ],
        'index' => ['fastighet_uuid', 'byggnadsnummer'],
    ],
    'ld_fas_objekt' => [
        'kolumner' => [
            'byggnad_uuid' => "CHAR(36) NULL DEFAULT NULL", 'objektnummer' => "VARCHAR(50) NULL DEFAULT NULL",
            'objekttyp_uuid' => "CHAR(36) NULL DEFAULT NULL", 'adress_uuid' => "CHAR(36) NULL DEFAULT NULL",
            'vaningsplan' => "SMALLINT NULL DEFAULT NULL",
        ],
        'index' => ['byggnad_uuid', 'objektnummer'],
    ],
    'ld_fas_objektgrupp' => [
        'kolumner' => [
            'objektgruppsnummer' => "VARCHAR(50) NULL DEFAULT NULL", 'namn' => "VARCHAR(150) NULL DEFAULT NULL",
            'objektgruppstyp_uuid' => "CHAR(36) NULL DEFAULT NULL",
        ],
        'index' => ['objektgruppsnummer'],
    ],
    'ld_fas_objektgrupp_koppling' => [
        'kolumner' => [
            'objektgrupp_uuid' => "CHAR(36) NULL DEFAULT NULL", 'maltyp' => "VARCHAR(20) NULL DEFAULT NULL",
            'mal_uuid' => "CHAR(36) NULL DEFAULT NULL", 'kopplingstyp' => "VARCHAR(20) NULL DEFAULT NULL",
        ],
        'index' => ['objektgrupp_uuid', 'mal_uuid'],
    ],
    'ld_fas_adress' => [
        'kolumner' => [
            'fastighet_uuid' => "CHAR(36) NULL DEFAULT NULL", 'byggnad_uuid' => "CHAR(36) NULL DEFAULT NULL",
            'gatuadress' => "VARCHAR(150) NULL DEFAULT NULL", 'postnummer' => "VARCHAR(6) NULL DEFAULT NULL",
            'ort' => "VARCHAR(100) NULL DEFAULT NULL", 'ar_huvudadress' => "TINYINT(1) NULL DEFAULT NULL",
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
    // MätarKollen (krockkontroll av mätpunktsnamn, befintliga mätpunkter och mätare)
    'ld_mat_matpunkt' => [
        'kolumner' => [
            'matpunktsnamn' => "VARCHAR(100) NULL DEFAULT NULL", 'typ' => "VARCHAR(20) NULL DEFAULT NULL",
            'status' => "VARCHAR(20) NULL DEFAULT NULL",
            'niva' => "VARCHAR(20) NULL DEFAULT NULL", 'placering_uuid' => "CHAR(36) NULL DEFAULT NULL",
            'placering_nummer' => "VARCHAR(50) NULL DEFAULT NULL",
            'betjaning_niva' => "VARCHAR(20) NULL DEFAULT NULL", 'betjaning_uuid' => "CHAR(36) NULL DEFAULT NULL",
            'betjaning_nummer' => "VARCHAR(50) NULL DEFAULT NULL",
        ],
        'index' => ['matpunktsnamn', 'betjaning_uuid'],
    ],
    'ld_mat_matpunkt_storhet' => [
        'kolumner' => ['matpunkt_uuid' => "CHAR(36) NULL DEFAULT NULL", 'storhet_uuid' => "CHAR(36) NULL DEFAULT NULL"],
        'index'    => ['matpunkt_uuid'],
    ],
    'ld_mat_storhet' => [
        'kolumner' => ['namn' => "VARCHAR(100) NULL DEFAULT NULL", 'enhet' => "VARCHAR(20) NULL DEFAULT NULL"],
        'index'    => [],
    ],
    'ld_mat_matare' => [
        'kolumner' => [
            'matarnummer' => "VARCHAR(20) NULL DEFAULT NULL", 'matar_id' => "VARCHAR(100) NULL DEFAULT NULL",
            'gateway' => "VARCHAR(100) NULL DEFAULT NULL",
        ],
        'index' => ['matarnummer', 'matar_id'],
    ],
];

/** Rubriker för prenumerationerna (synksidan). */
const PRJ_LD_RUBRIK = [
    'ld_fas_fastighet' => 'Fastigheter', 'ld_fas_byggnad' => 'Byggnader', 'ld_fas_objekt' => 'Objekt',
    'ld_fas_objektgrupp' => 'Objektgrupper', 'ld_fas_objektgrupp_koppling' => 'Objektgruppskopplingar',
    'ld_fas_adress' => 'Adresser', 'ld_fas_byggnadstyp' => 'Byggnadstyper', 'ld_fas_objekttyp' => 'Objekttyper',
    'ld_fas_objektgruppstyp' => 'Objektgruppstyper',
    'ld_mat_matpunkt' => 'Mätpunkter', 'ld_mat_matpunkt_storhet' => 'Mätpunkternas storheter',
    'ld_mat_storhet' => 'Storheter', 'ld_mat_matare' => 'Mätare',
];

/**
 * Typlistor: nyckel => tabell, rubrik, etikett, extra kolumner, referenser, förifyllda värden.
 * Funktion = mätpunktens funktion i namnstandarden (Temp, KV, VV, EL, FJV, Gas) med storheter
 * (namn enligt MätarKollen, kommaseparerade). Projekttypen pekar ut sin funktion.
 */
const PRJ_TYPLISTOR = [
    'funktion' => [
        'tabell' => 'md_funktion', 'rubrik' => 'Funktioner', 'etikett' => 'funktion',
        'extra' => ['storheter' => 'Storheter'],
        'standard' => [
            ['namn' => 'Temp', 'beskrivning' => 'Temperatur/luftfuktighet', 'storheter' => 'Temperatur, Relativ fuktighet'],
            ['namn' => 'KV',   'beskrivning' => 'Kallvatten',               'storheter' => 'Kallvatten'],
            ['namn' => 'VV',   'beskrivning' => 'Varmvatten',               'storheter' => 'Varmvatten'],
            ['namn' => 'EL',   'beskrivning' => 'Elenergi',                 'storheter' => 'El'],
            ['namn' => 'FJV',  'beskrivning' => 'Fjärrvärme',               'storheter' => 'Fjärrvärme'],
            ['namn' => 'Gas',  'beskrivning' => 'Gasenergi',                'storheter' => 'Gas'],
        ],
    ],
    'projekttyp' => [
        'tabell' => 'md_projekttyp', 'rubrik' => 'Projekttyper', 'etikett' => 'projekttyp',
        'ref' => ['funktion_uuid' => ['lista' => 'funktion', 'etikett' => 'Funktion']],
        'standard' => [
            ['namn' => 'Installation temperaturmätare', 'beskrivning' => 'Installation och drifttagning av temperaturgivare.',
             'funktion' => 'Temp'],
        ],
    ],
];

const PRJ_TYP_KOLUMNER = [
    'namn'        => "VARCHAR(100) NOT NULL DEFAULT ''",
    'beskrivning' => "VARCHAR(255) NOT NULL DEFAULT ''",
    'sortering'   => "INT NOT NULL DEFAULT 0",
    'is_active'   => "TINYINT(1) NOT NULL DEFAULT 1",
];
const PRJ_TYP_EXTRA_KOLUMNER = [
    'storheter'     => "VARCHAR(255) NOT NULL DEFAULT ''",
    'funktion_uuid' => "CHAR(36)     NOT NULL DEFAULT ''",
];

/** Var typerna används: typlista => [[tabell, kolumn], ...]. En typ som används kan inte raderas. */
const PRJ_TYP_ANVANDNING = [
    'funktion'   => [['md_projekttyp', 'funktion_uuid']],
    'projekttyp' => [['md_projekt', 'projekttyp_uuid']],
];

/** Egna mastertabeller (utöver typlistorna). */
const PRJ_TABELLER = [
    'md_projekt' => [
        'beskrivning' => 'Projekt: projektnummer (P00001 …), namn, projekttyp, status, beskrivning, projektledare (text tills PersonalKollen finns), start- och slutdatum.',
        'kolumner' => [
            'projektnummer'   => "VARCHAR(20)  NOT NULL DEFAULT ''",
            'namn'            => "VARCHAR(150) NOT NULL DEFAULT ''",
            'projekttyp_uuid' => "CHAR(36)     NOT NULL DEFAULT ''",
            'status'          => "VARCHAR(20)  NOT NULL DEFAULT 'planering'",   // planering | pagaende | avslutat
            'beskrivning'     => "TEXT NULL",
            'projektledare'   => "VARCHAR(150) NOT NULL DEFAULT ''",
            'startdatum'      => "DATE NULL",
            'slutdatum'       => "DATE NULL",
        ],
        'index' => ['projektnummer', 'projekttyp_uuid'],
    ],
    'md_omfattning' => [
        'beskrivning' => 'Projektets omfattning: valda fastigheter, byggnader, objekt och objektgrupper (niva + post_uuid + nummer).',
        'kolumner' => [
            'projekt_uuid' => "CHAR(36)    NOT NULL DEFAULT ''",
            'niva'         => "VARCHAR(20) NOT NULL DEFAULT ''",   // fastighet | byggnad | objekt | objektgrupp
            'post_uuid'    => "CHAR(36)    NOT NULL DEFAULT ''",
            'nummer'       => "VARCHAR(50) NOT NULL DEFAULT ''",   // kopia, för visning
        ],
        'index' => ['projekt_uuid'],
    ],
    'md_projektobjekt' => [
        'beskrivning' => 'Projektets objektlista: alla objekt som omfattningen ger, med antal givare per objekt (0 = ingen).',
        'kolumner' => [
            'projekt_uuid' => "CHAR(36)    NOT NULL DEFAULT ''",
            'objekt_uuid'  => "CHAR(36)    NOT NULL DEFAULT ''",
            'objektnummer' => "VARCHAR(50) NOT NULL DEFAULT ''",
            'antal'        => "TINYINT NOT NULL DEFAULT 1",          // 0–9
            'utanfor'      => "TINYINT(1) NOT NULL DEFAULT 0",       // 1 = inte längre i omfattningen men har låsta mätpunkter
        ],
        'index' => ['projekt_uuid', 'objekt_uuid'],
    ],
    'md_matpunkt' => [
        'beskrivning' => 'Planerade mätpunkter: namn enligt namnstandarden, objekt (betjäning och placering), funktion, storheter. Blir riktiga i MätarKollen vid import där.',
        'kolumner' => [
            'projekt_uuid'     => "CHAR(36)     NOT NULL DEFAULT ''",
            'objekt_uuid'      => "CHAR(36)     NOT NULL DEFAULT ''",
            'objektnummer'     => "VARCHAR(50)  NOT NULL DEFAULT ''",
            'funktion_uuid'    => "CHAR(36)     NOT NULL DEFAULT ''",
            'funktion'         => "VARCHAR(20)  NOT NULL DEFAULT ''",   // koden i namnet, t.ex. Temp
            'lopnummer'        => "TINYINT NOT NULL DEFAULT 1",          // X
            'antal_i_objekt'   => "TINYINT NOT NULL DEFAULT 1",          // Y när namnet sattes
            'matpunktsnamn'    => "VARCHAR(100) NOT NULL DEFAULT ''",
            'namn_last'        => "TINYINT(1) NOT NULL DEFAULT 0",       // 1 = etiketter utskrivna eller exporterad
            'storheter'        => "VARCHAR(255) NOT NULL DEFAULT ''",
            'platsbeskrivning' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'info'             => "VARCHAR(255) NOT NULL DEFAULT ''",    // t.ex. befintlig mätpunkt i MätarKollen, namnkrock
            'etikett_tid'      => "DATETIME(6) NULL DEFAULT NULL",       // senaste etikettutskrift (UTC)
            'monterad_datum'   => "DATE NULL DEFAULT NULL",              // monteringskontroll (etapp 5b)
            'monterad_av'      => "VARCHAR(100) NOT NULL DEFAULT ''",
        ],
        'index' => ['projekt_uuid', 'objekt_uuid', 'matpunktsnamn'],
    ],
    'md_matare' => [
        'beskrivning' => 'Inköpta mätare (gemensamt lager): mätarId, fabrikat, modell, mätartyp, kommunikation, storheter, gateway, inköp. Projekt och mätpunkt sätts vid provisionering. Nycklar delas aldrig.',
        'kolumner' => [
            'matar_id'      => "VARCHAR(100) NOT NULL DEFAULT ''",   // LoRaWAN: DevEUI, 16 hextecken med versaler
            'fabrikat'      => "VARCHAR(100) NOT NULL DEFAULT ''",   // text tills vidare; MätarKollen äger typlistorna
            'modell'        => "VARCHAR(100) NOT NULL DEFAULT ''",
            'matartyp'      => "VARCHAR(100) NOT NULL DEFAULT ''",
            'kommunikation' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'storheter'     => "VARCHAR(255) NOT NULL DEFAULT ''",   // kommaseparerade, namn som i MätarKollen
            'gateway'       => "VARCHAR(100) NOT NULL DEFAULT ''",
            'inkop'         => "VARCHAR(100) NOT NULL DEFAULT ''",   // t.ex. ordernummer eller leverans
            'kommentar'     => "VARCHAR(255) NOT NULL DEFAULT ''",
            'projekt_uuid'  => "CHAR(36)     NOT NULL DEFAULT ''",   // sätts vid provisionering (etapp 5)
            'matpunkt_uuid' => "CHAR(36)     NOT NULL DEFAULT ''",   // prj_md_matpunkt, sätts vid provisionering
        ],
        'index' => ['matar_id', 'projekt_uuid', 'matpunkt_uuid'],
    ],
    'md_matarnyckel' => [
        'delad' => false,   // nycklar lämnar ProjektKollen bara via exporterna (etapp 6)
        'beskrivning' => 'Mätarnas nycklar (DevEUI, JoinEUI/AppEUI, AppKey …). Delas aldrig.',
        'kolumner' => [
            'matare_uuid' => "CHAR(36)     NOT NULL DEFAULT ''",
            'nyckeltyp'   => "VARCHAR(100) NOT NULL DEFAULT ''",     // namn som i MätarKollens typlista
            'varde'       => "VARCHAR(255) NOT NULL DEFAULT ''",
        ],
        'index' => ['matare_uuid'],
    ],
];

/**
 * Kända nyckeltyper (namn som i MätarKollen) med alias för kolumnrubriker (gemener).
 * Okända typer tas emot med rubrikens namn och flaggas i förhandsvisningen.
 */
const PRJ_NYCKELTYPER = [
    'DevEUI'              => ['deveui', 'dev eui', 'dev_eui'],
    'JoinEUI/AppEUI'      => ['joineui/appeui', 'joineui', 'appeui', 'join eui', 'app eui', 'join_eui', 'app_eui'],
    'AppKey'              => ['appkey', 'app key', 'app_key'],
    'AES-nyckel (wM-Bus)' => ['aes-nyckel (wm-bus)', 'aes-nyckel', 'aes', 'aes_nyckel'],
];

/** Nivåer för omfattningen: ld-tabell, nummerkolumn, etikett. */
const PRJ_NIVAER = [
    'fastighet'   => ['ld' => 'ld_fas_fastighet',   'nummer' => 'fastighetsnummer',   'etikett' => 'Fastighet'],
    'byggnad'     => ['ld' => 'ld_fas_byggnad',     'nummer' => 'byggnadsnummer',     'etikett' => 'Byggnad'],
    'objekt'      => ['ld' => 'ld_fas_objekt',      'nummer' => 'objektnummer',       'etikett' => 'Objekt'],
    'objektgrupp' => ['ld' => 'ld_fas_objektgrupp', 'nummer' => 'objektgruppsnummer', 'etikett' => 'Objektgrupp'],
];

const PRJ_STATUS = ['planering' => 'Planering', 'pagaende' => 'Pågående', 'avslutat' => 'Avslutat'];

/* ---------- Prenumerationer och delade tabeller ---------- */

/** Prenumerationer för kc_pull_alla(): [nyckel => master, tabell, ld, rubrik]. */
function prj_prenumerationer(): array
{
    $p = [];
    foreach (array_keys(PRJ_LD_TABELLER) as $ld) {
        [, $master, $tabell] = explode('_', $ld, 3);
        $p[$master . '_' . $tabell] = ['master' => $master, 'tabell' => $tabell, 'ld' => $ld, 'rubrik' => PRJ_LD_RUBRIK[$ld] ?? $tabell];
    }
    return $p;
}

/** Tabellerna ProjektKollen delar via CentralKollen. Nycklar delas aldrig (från etapp 4). */
function prj_delade_tabeller(): array
{
    $t = [];
    foreach (PRJ_TABELLER as $tabell => $def) {
        if (($def['delad'] ?? true) === false) {
            continue;
        }
        $t[substr($tabell, 3)] = ['tabell' => $tabell, 'beskrivning' => $def['beskrivning'] ?? ''];
    }
    foreach (PRJ_TYPLISTOR as $def) {
        $t[substr($def['tabell'], 3)] = ['tabell' => $def['tabell'], 'beskrivning' => 'Typlista: ' . $def['rubrik'] . '.'];
    }
    ksort($t);
    return $t;
}

/* ---------- Typlistor ---------- */

function prj_typlista(string $lista): array
{
    if (!array_key_exists($lista, PRJ_TYPLISTOR)) {
        throw new KcFel('Okänd typlista.', 404);
    }
    return PRJ_TYPLISTOR[$lista] + ['nyckel' => $lista, 'extra' => [], 'ref' => []];
}

/** Tabelldefinition för en typlista (för kc_inst_tabell). */
function prj_typ_tabelldef(string $lista): array
{
    $kol = PRJ_TYP_KOLUMNER;
    $def = prj_typlista($lista);
    foreach (array_merge(array_keys($def['extra']), array_keys($def['ref'])) as $k) {
        $kol[$k] = PRJ_TYP_EXTRA_KOLUMNER[$k];
    }
    return ['kolumner' => $kol, 'index' => []];
}

/** Aktiva typer i en lista: [uuid => rad]. */
function prj_typer_aktiva(string $lista): array
{
    $t = kc_tabell(prj_typlista($lista)['tabell']);
    $ut = [];
    foreach (kc_hamta_alla("SELECT * FROM `$t` WHERE is_deleted = 0 AND is_active = 1 ORDER BY sortering, namn") as $r) {
        $ut[$r['uuid']] = $r;
    }
    return $ut;
}

/** En typ (även inaktiv), eller null. */
function prj_typ_hamta(string $lista, string $uuid): ?array
{
    if (!kc_ar_uuid($uuid)) {
        return null;
    }
    $t = kc_tabell(prj_typlista($lista)['tabell']);
    return kc_hamta_en("SELECT * FROM `$t` WHERE uuid = ? AND is_deleted = 0 LIMIT 1", [$uuid]);
}

/** Hur många ej raderade poster som använder typen. */
function prj_typ_anvand(string $lista, string $uuid): int
{
    $n = 0;
    foreach (PRJ_TYP_ANVANDNING[$lista] ?? [] as [$tabell, $kolumn]) {
        $t = kc_tabell($tabell);
        if (kc_inst_tabell_finns($t)) {
            $n += (int)kc_hamta_varde("SELECT COUNT(*) FROM `$t` WHERE `$kolumn` = ? AND is_deleted = 0", [$uuid]);
        }
    }
    return $n;
}

/** Projekttypens funktion (rad ur md_funktion), eller null. */
function prj_projekttyp_funktion(string $projekttyp_uuid): ?array
{
    $pt = prj_typ_hamta('projekttyp', $projekttyp_uuid);
    return $pt === null ? null : prj_typ_hamta('funktion', (string)$pt['funktion_uuid']);
}

/* ---------- Projekt ---------- */

function prj_projekt_hamta(string $uuid): ?array
{
    if (!kc_ar_uuid($uuid)) {
        return null;
    }
    $t = kc_tabell('md_projekt');
    return kc_hamta_en("SELECT * FROM `$t` WHERE uuid = ? AND is_deleted = 0 LIMIT 1", [$uuid]);
}

/**
 * Nästa lediga projektnummer, P + minst fem siffror. Anropas inuti en transaktion:
 * räknaren i sys_raknare låses (FOR UPDATE) så att två samtidiga sparningar inte får samma nummer.
 */
function prj_nasta_projektnummer(): string
{
    $tr = kc_tabell('sys_raknare');
    $tp = kc_tabell('md_projekt');
    kc_kor("INSERT IGNORE INTO `$tr` (namn, varde) VALUES ('projekt', 0)");
    $v = (int)kc_hamta_varde("SELECT varde FROM `$tr` WHERE namn = 'projekt' FOR UPDATE");
    do {
        $v++;
        $nr = 'P' . str_pad((string)$v, 5, '0', STR_PAD_LEFT);
    } while ((int)kc_hamta_varde("SELECT COUNT(*) FROM `$tp` WHERE projektnummer = ?", [$nr]) > 0);
    kc_kor("UPDATE `$tr` SET varde = ? WHERE namn = 'projekt'", [$v]);
    return $nr;
}

function prj_datum_ok(string $d): bool
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}

/** <option>-taggar. $tom = text för tomt val, eller null (= "– välj –"). */
function prj_alternativ(array $val, string $valt, ?string $tom = null): string
{
    $h = '<option value="">' . kc_h($tom ?? '– välj –') . '</option>';
    foreach ($val as $k => $namn) {
        $h .= '<option value="' . kc_h((string)$k) . '"' . ((string)$k === $valt ? ' selected' : '') . '>' . kc_h($namn) . '</option>';
    }
    return $h;
}

/** Projektet kan inte ändras när det är avslutat. */
function prj_projekt_last(array $projekt): bool
{
    return $projekt['status'] === 'avslutat';
}

/* ---------- Omfattning ---------- */

/** Projektets omfattning med text: [uuid, niva, post_uuid, nummer, namn, finns]. */
function prj_omfattning(string $projekt_uuid): array
{
    $t = kc_tabell('md_omfattning');
    $ut = [];
    foreach (kc_hamta_alla("SELECT * FROM `$t` WHERE projekt_uuid = ? AND is_deleted = 0 ORDER BY FIELD(niva, 'fastighet', 'byggnad', 'objektgrupp', 'objekt'), nummer", [$projekt_uuid]) as $r) {
        $def = PRJ_NIVAER[$r['niva']] ?? null;
        $post = $def === null ? null : kc_hamta_en('SELECT * FROM `' . kc_tabell($def['ld']) . '` WHERE uuid = ? AND is_deleted = 0', [$r['post_uuid']]);
        $r['finns'] = $post !== null;
        $r['namn'] = (string)($post['namn'] ?? '');
        if ($post !== null && $r['niva'] === 'objekt') {
            $r['namn'] = prj_adress_text_objekt($post);
        }
        $ut[] = $r;
    }
    return $ut;
}

/** Objektets adress som text (via adress_uuid), eller ''. */
function prj_adress_text_objekt(array $objekt): string
{
    if (($objekt['adress_uuid'] ?? '') === '') {
        return '';
    }
    $a = kc_hamta_en('SELECT gatuadress FROM `' . kc_tabell('ld_fas_adress') . '` WHERE uuid = ? AND is_deleted = 0', [$objekt['adress_uuid']]);
    return (string)($a['gatuadress'] ?? '');
}

/** Hittar en post i fastighetsdatan på nummer. Returnerar uuid eller null. */
function prj_post_hitta(string $niva, string $nummer): ?string
{
    $def = PRJ_NIVAER[$niva] ?? null;
    if ($def === null) {
        return null;
    }
    $r = kc_hamta_en('SELECT uuid FROM `' . kc_tabell($def['ld']) . "` WHERE `{$def['nummer']}` = ? AND is_deleted = 0 LIMIT 1", [trim($nummer)]);
    return $r['uuid'] ?? null;
}

/**
 * Lägger till poster i omfattningen. $nummer = lista med nummer.
 * Returnerar ['nya' => n, 'fanns' => n, 'okanda' => [...]]. Anropas i transaktion.
 */
function prj_omfattning_lagg_till(string $projekt_uuid, string $niva, array $nummer): array
{
    $t = kc_tabell('md_omfattning');
    $ut = ['nya' => 0, 'fanns' => 0, 'okanda' => []];
    foreach ($nummer as $nr) {
        $nr = trim($nr);
        if ($nr === '') {
            continue;
        }
        $u = prj_post_hitta($niva, $nr);
        if ($u === null) {
            $ut['okanda'][] = $nr;
            continue;
        }
        $r = kc_hamta_en("SELECT uuid, is_deleted FROM `$t` WHERE projekt_uuid = ? AND niva = ? AND post_uuid = ? ORDER BY is_deleted ASC, id ASC LIMIT 1",
                         [$projekt_uuid, $niva, $u]);
        if ($r !== null && (int)$r['is_deleted'] === 0) {
            $ut['fanns']++;
        } elseif ($r !== null) {
            kc_kor("UPDATE `$t` SET is_deleted = 0, nummer = ?, last_changed = ?, source_tool = 'prj' WHERE uuid = ?", [$nr, kc_nu(), $r['uuid']]);
            $ut['nya']++;
        } else {
            kc_kor("INSERT INTO `$t` (uuid, last_changed, is_deleted, source_tool, projekt_uuid, niva, post_uuid, nummer) VALUES (?, ?, 0, 'prj', ?, ?, ?, ?)",
                   [kc_uuid(), kc_nu(), $projekt_uuid, $niva, $u, $nr]);
            $ut['nya']++;
        }
    }
    return $ut;
}

/**
 * Objekten som omfattningen ger: [objekt_uuid => objektnummer].
 * Fastighet → objekten i dess byggnader; byggnad → dess objekt; objektgrupp → objekten som gruppen
 * är kopplad till (direkt eller via byggnad/fastighet, alla kopplingstyper).
 */
function prj_omfattning_objekt(string $projekt_uuid): array
{
    $tom = kc_tabell('md_omfattning');
    $to = kc_tabell('ld_fas_objekt');
    $tb = kc_tabell('ld_fas_byggnad');
    $tk = kc_tabell('ld_fas_objektgrupp_koppling');
    $rader = kc_hamta_alla(
        "SELECT o.uuid, o.objektnummer FROM `$tom` m JOIN `$to` o ON o.uuid = m.post_uuid AND o.is_deleted = 0
          WHERE m.projekt_uuid = ? AND m.is_deleted = 0 AND m.niva = 'objekt'
         UNION
         SELECT o.uuid, o.objektnummer FROM `$tom` m JOIN `$to` o ON o.byggnad_uuid = m.post_uuid AND o.is_deleted = 0
          WHERE m.projekt_uuid = ? AND m.is_deleted = 0 AND m.niva = 'byggnad'
         UNION
         SELECT o.uuid, o.objektnummer FROM `$tom` m
           JOIN `$tb` b ON b.fastighet_uuid = m.post_uuid AND b.is_deleted = 0
           JOIN `$to` o ON o.byggnad_uuid = b.uuid AND o.is_deleted = 0
          WHERE m.projekt_uuid = ? AND m.is_deleted = 0 AND m.niva = 'fastighet'
         UNION
         SELECT o.uuid, o.objektnummer FROM `$tom` m
           JOIN `$tk` k ON k.objektgrupp_uuid = m.post_uuid AND k.is_deleted = 0
           JOIN `$to` o ON o.is_deleted = 0 AND (
                (k.maltyp = 'objekt' AND o.uuid = k.mal_uuid)
             OR (k.maltyp = 'byggnad' AND o.byggnad_uuid = k.mal_uuid)
             OR (k.maltyp = 'fastighet' AND o.byggnad_uuid IN (SELECT b2.uuid FROM `$tb` b2 WHERE b2.fastighet_uuid = k.mal_uuid AND b2.is_deleted = 0)))
          WHERE m.projekt_uuid = ? AND m.is_deleted = 0 AND m.niva = 'objektgrupp'",
        [$projekt_uuid, $projekt_uuid, $projekt_uuid, $projekt_uuid]
    );
    $ut = [];
    foreach ($rader as $r) {
        $ut[$r['uuid']] = (string)$r['objektnummer'];
    }
    return $ut;
}

/* ---------- Objektlista ---------- */

/**
 * Synkar objektlistan med omfattningen: nya objekt får antal 1, objekt som fallit ur omfattningen
 * tas bort – utom om de har låsta mätpunkter (då markeras de "utanför"). Genererar sedan mätpunkterna.
 * Returnerar ['obj_nya', 'obj_borttagna', 'utanfor', + mätpunktssammanfattning]. Anropas i transaktion.
 */
function prj_objektlista_uppdatera(array $projekt): array
{
    $pu = $projekt['uuid'];
    $t = kc_tabell('md_projektobjekt');
    $tm = kc_tabell('md_matpunkt');
    $nu = kc_nu();
    $onskade = prj_omfattning_objekt($pu);
    $befintliga = [];
    foreach (kc_hamta_alla("SELECT * FROM `$t` WHERE projekt_uuid = ? ORDER BY is_deleted ASC, id ASC", [$pu]) as $r) {
        $befintliga[$r['objekt_uuid']] ??= $r;
    }
    $ut = ['obj_nya' => 0, 'obj_borttagna' => 0, 'utanfor' => 0];
    foreach ($onskade as $ou => $nr) {
        $r = $befintliga[$ou] ?? null;
        if ($r === null) {
            kc_kor("INSERT INTO `$t` (uuid, last_changed, is_deleted, source_tool, projekt_uuid, objekt_uuid, objektnummer, antal, utanfor)
                    VALUES (?, ?, 0, 'prj', ?, ?, ?, 1, 0)", [kc_uuid(), $nu, $pu, $ou, $nr]);
            $ut['obj_nya']++;
        } elseif ((int)$r['is_deleted'] === 1) {
            kc_kor("UPDATE `$t` SET is_deleted = 0, utanfor = 0, antal = 1, objektnummer = ?, last_changed = ?, source_tool = 'prj' WHERE uuid = ?", [$nr, $nu, $r['uuid']]);
            $ut['obj_nya']++;
        } elseif ((int)$r['utanfor'] === 1 || $r['objektnummer'] !== $nr) {
            kc_kor("UPDATE `$t` SET utanfor = 0, objektnummer = ?, last_changed = ?, source_tool = 'prj' WHERE uuid = ?", [$nr, $nu, $r['uuid']]);
        }
    }
    foreach ($befintliga as $ou => $r) {
        if (isset($onskade[$ou]) || (int)$r['is_deleted'] === 1) {
            continue;
        }
        $lasta = (int)kc_hamta_varde("SELECT COUNT(*) FROM `$tm` WHERE projekt_uuid = ? AND objekt_uuid = ? AND is_deleted = 0 AND namn_last = 1", [$pu, $ou]);
        if ($lasta > 0) {
            if ((int)$r['utanfor'] === 0) {
                kc_kor("UPDATE `$t` SET utanfor = 1, last_changed = ?, source_tool = 'prj' WHERE uuid = ?", [$nu, $r['uuid']]);
            }
            $ut['utanfor']++;
        } else {
            kc_kor("UPDATE `$t` SET is_deleted = 1, last_changed = ?, source_tool = 'prj' WHERE uuid = ?", [$nu, $r['uuid']]);
            $ut['obj_borttagna']++;
        }
    }
    return $ut + prj_matpunkter_generera($projekt);
}

/** Sätter antal givare (0–9) för objekt i projektet: [projektobjekt_uuid => antal]. Returnerar antal ändrade. */
function prj_objekt_antal_spara(string $projekt_uuid, array $antal): int
{
    $t = kc_tabell('md_projektobjekt');
    $n = 0;
    foreach ($antal as $u => $a) {
        $n += kc_kor("UPDATE `$t` SET antal = ?, last_changed = ?, source_tool = 'prj' WHERE uuid = ? AND projekt_uuid = ? AND is_deleted = 0 AND antal <> ?",
                     [$a, kc_nu(), $u, $projekt_uuid, $a]);
    }
    return $n;
}

/* ---------- Mätpunktslista ---------- */

/** Befintliga mätpunkter i MätarKollen som betjänar objektet och har funktionen: [matpunktsnamn, ...]. */
function prj_befintliga_matpunkter(string $objekt_uuid, array $funktion): array
{
    $tm = kc_tabell('ld_mat_matpunkt');
    $tms = kc_tabell('ld_mat_matpunkt_storhet');
    $ts = kc_tabell('ld_mat_storhet');
    $storheter = array_values(array_filter(array_map('trim', explode(',', (string)$funktion['storheter'])), static fn($x) => $x !== ''));
    $sql = "SELECT DISTINCT m.matpunktsnamn FROM `$tm` m
             WHERE m.is_deleted = 0 AND COALESCE(m.status, '') <> 'avvecklad'
               AND (m.betjaning_uuid = ? OR (COALESCE(m.betjaning_uuid, '') = '' AND m.placering_uuid = ?))
               AND (m.matpunktsnamn LIKE ?";
    $params = [$objekt_uuid, $objekt_uuid, '%\\_' . addcslashes((string)$funktion['namn'], '%_\\') . '\\_%'];
    if ($storheter !== []) {
        $sql .= " OR EXISTS (SELECT 1 FROM `$tms` k JOIN `$ts` s ON s.uuid = k.storhet_uuid
                              WHERE k.matpunkt_uuid = m.uuid AND k.is_deleted = 0 AND s.namn IN (" . implode(',', array_fill(0, count($storheter), '?')) . '))';
        array_push($params, ...$storheter);
    }
    $sql .= ') ORDER BY m.matpunktsnamn';
    return array_column(kc_hamta_alla($sql, $params), 'matpunktsnamn');
}

/** Är namnet upptaget i MätarKollen eller i ett annat projekts mätpunkter? */
function prj_namn_upptaget(string $namn, string $projekt_uuid): bool
{
    $tm = kc_tabell('ld_mat_matpunkt');
    if ((int)kc_hamta_varde("SELECT COUNT(*) FROM `$tm` WHERE matpunktsnamn = ? AND is_deleted = 0", [$namn]) > 0) {
        return true;
    }
    $tp = kc_tabell('md_matpunkt');
    return (int)kc_hamta_varde("SELECT COUNT(*) FROM `$tp` WHERE matpunktsnamn = ? AND projekt_uuid <> ? AND is_deleted = 0", [$namn, $projekt_uuid]) > 0;
}

/**
 * Genererar projektets mätpunkter från objektlistan enligt namnstandarden <objektnummer>_<funktion>_<X><Y>.
 * - Låsta namn (etiketter utskrivna/exporterade) ändras aldrig och tas aldrig bort.
 * - Olåsta räknas om: X = 1..antal, Y = antal. Upptaget namn (i MätarKollen eller annat projekt)
 *   ger nästa lediga X, med infotext. Låsta behåller sina X; nya tar lediga X.
 * - Befintlig mätpunkt i MätarKollen för objektet och funktionen noteras som infotext.
 * Returnerar ['skapade', 'andrade', 'borttagna', 'over_antal'] (over_antal = objekt med fler låsta än antal).
 * Anropas i transaktion.
 */
function prj_matpunkter_generera(array $projekt): array
{
    $pu = $projekt['uuid'];
    $ut = ['skapade' => 0, 'andrade' => 0, 'borttagna' => 0, 'over_antal' => 0];
    $funktion = prj_projekttyp_funktion($projekt['projekttyp_uuid']);
    if ($funktion === null) {
        return $ut;
    }
    $tpo = kc_tabell('md_projektobjekt');
    $tm = kc_tabell('md_matpunkt');
    $nu = kc_nu();
    $alla = [];
    foreach (kc_hamta_alla("SELECT * FROM `$tm` WHERE projekt_uuid = ? AND is_deleted = 0 ORDER BY lopnummer, id", [$pu]) as $m) {
        $alla[$m['objekt_uuid']][] = $m;
    }
    $objekt = kc_hamta_alla("SELECT * FROM `$tpo` WHERE projekt_uuid = ? AND is_deleted = 0 ORDER BY objektnummer", [$pu]);
    $hanterade = [];
    foreach ($objekt as $o) {
        $ou = $o['objekt_uuid'];
        $hanterade[$ou] = true;
        $mp = $alla[$ou] ?? [];
        $lasta = array_values(array_filter($mp, static fn($m) => (int)$m['namn_last'] === 1));
        $olasta = array_values(array_filter($mp, static fn($m) => (int)$m['namn_last'] === 0));
        $antal = (int)$o['antal'];
        if ((int)$o['utanfor'] === 1) {
            $antal = 0;   // utanför omfattningen: bara låsta finns kvar
        }
        if (count($lasta) > $antal && (int)$o['utanfor'] === 0) {
            $ut['over_antal']++;
        }
        $behov = max(0, $antal - count($lasta));
        $upptagna_x = array_map(static fn($m) => (int)$m['lopnummer'], $lasta);
        $befintliga = prj_befintliga_matpunkter($ou, $funktion);
        $grundinfo = $befintliga !== [] ? 'Befintlig i MätarKollen: ' . implode(', ', $befintliga) . '.' : '';
        // Planera namnen för de olåsta
        $planer = [];
        $krock = [];
        $x = 0;
        while (count($planer) < $behov && $x < 99) {
            $x++;
            if (in_array($x, $upptagna_x, true)) {
                continue;
            }
            $namn = $o['objektnummer'] . '_' . $funktion['namn'] . '_' . $x . $antal;
            if ($x > 9 || prj_namn_upptaget($namn, $pu)) {
                if ($x <= 9) {
                    $krock[] = $namn;
                }
                continue;
            }
            $planer[] = ['x' => $x, 'namn' => $namn];
        }
        $info = trim($grundinfo . ($krock !== [] ? ' Namnet ' . implode(', ', $krock) . ' var upptaget – nästa lediga löpnummer användes.' : '')
                . (count($planer) < $behov ? ' Inte nog med lediga löpnummer (1–9).' : ''));
        // Återanvänd olåsta rader (samma uuid), skapa nya, ta bort överskott
        foreach ($planer as $i => $p) {
            $falt = ['lopnummer' => $p['x'], 'antal_i_objekt' => $antal, 'matpunktsnamn' => $p['namn'], 'objektnummer' => $o['objektnummer'],
                     'funktion_uuid' => $funktion['uuid'], 'funktion' => $funktion['namn'], 'storheter' => (string)$funktion['storheter'], 'info' => mb_substr($info, 0, 255)];
            $r = $olasta[$i] ?? null;
            if ($r === null) {
                kc_kor("INSERT INTO `$tm` (uuid, last_changed, is_deleted, source_tool, projekt_uuid, objekt_uuid, namn_last, platsbeskrivning, `"
                       . implode('`, `', array_keys($falt)) . "`) VALUES (?, ?, 0, 'prj', ?, ?, 0, ''" . str_repeat(', ?', count($falt)) . ')',
                       array_merge([kc_uuid(), $nu, $pu, $ou], array_values($falt)));
                $ut['skapade']++;
                continue;
            }
            $andras = array_filter($falt, static fn($v, $k) => (string)$r[$k] !== (string)$v, ARRAY_FILTER_USE_BOTH);
            if ($andras !== []) {
                $set = implode(', ', array_map(static fn($k) => "`$k` = ?", array_keys($andras)));
                kc_kor("UPDATE `$tm` SET $set, last_changed = ?, source_tool = 'prj' WHERE uuid = ?", array_merge(array_values($andras), [$nu, $r['uuid']]));
                $ut['andrade']++;
            }
        }
        foreach (array_slice($olasta, count($planer)) as $r) {
            kc_kor("UPDATE `$tm` SET is_deleted = 1, last_changed = ?, source_tool = 'prj' WHERE uuid = ?", [$nu, $r['uuid']]);
            $ut['borttagna']++;
        }
        // Låsta: uppdatera bara infotexten (Y kan avvika när antalet ändrats)
        foreach ($lasta as $r) {
            $li = trim($grundinfo . ((int)$r['antal_i_objekt'] !== $antal && (int)$o['utanfor'] === 0 ? ' Låst namn – antalet i objektet är nu ' . $antal . '.' : '')
                  . ((int)$o['utanfor'] === 1 ? ' Objektet är inte längre med i omfattningen.' : ''));
            if ($r['info'] !== mb_substr($li, 0, 255)) {
                kc_kor("UPDATE `$tm` SET info = ?, last_changed = ?, source_tool = 'prj' WHERE uuid = ?", [mb_substr($li, 0, 255), $nu, $r['uuid']]);
            }
        }
    }
    // Mätpunkter för objekt som inte längre finns i objektlistan (och inte är låsta)
    foreach ($alla as $ou => $mp) {
        if (isset($hanterade[$ou])) {
            continue;
        }
        foreach ($mp as $r) {
            if ((int)$r['namn_last'] === 0) {
                kc_kor("UPDATE `$tm` SET is_deleted = 1, last_changed = ?, source_tool = 'prj' WHERE uuid = ?", [$nu, $r['uuid']]);
                $ut['borttagna']++;
            }
        }
    }
    return $ut;
}

/** Låser alla mätpunktsnamn i projektet (t.ex. när etiketterna skrivs ut). Returnerar antal nylåsta. */
function prj_matpunkter_las(string $projekt_uuid, ?array $bara_uuid = null): int
{
    $tm = kc_tabell('md_matpunkt');
    $sql = "UPDATE `$tm` SET namn_last = 1, last_changed = ?, source_tool = 'prj' WHERE projekt_uuid = ? AND is_deleted = 0 AND namn_last = 0";
    $params = [kc_nu(), $projekt_uuid];
    if ($bara_uuid !== null) {
        if ($bara_uuid === []) {
            return 0;
        }
        $sql .= ' AND uuid IN (' . implode(',', array_fill(0, count($bara_uuid), '?')) . ')';
        array_push($params, ...$bara_uuid);
    }
    return kc_kor($sql, $params);
}

/* ---------- Provisionering ---------- */

/**
 * Mätarens identitet ur en avläst QR-kod. LoRaWAN-formatet (TR005)
 * "LW:D0:<JoinEUI>:<DevEUI>:<ProfilId>[:…]" ger DevEUI; annat innehåll normaliseras som mätarId.
 */
function prj_qr_till_matar_id(string $text): string
{
    $text = trim($text);
    if (preg_match('/^LW:D0:/i', $text)) {
        $delar = explode(':', $text);
        if (isset($delar[3]) && $delar[3] !== '') {
            return prj_matar_id_normalisera($delar[3]);
        }
    }
    return prj_matar_id_normalisera($text);
}

/** Projektets mätpunkt med ett visst namn (exakt, annars skiftlägesokänsligt), eller null. */
function prj_matpunkt_med_namn(string $projekt_uuid, string $namn): ?array
{
    $t = kc_tabell('md_matpunkt');
    $namn = trim($namn);
    if ($namn === '') {
        return null;
    }
    return kc_hamta_en("SELECT * FROM `$t` WHERE projekt_uuid = ? AND is_deleted = 0 AND matpunktsnamn = ? LIMIT 1", [$projekt_uuid, $namn])
        ?? kc_hamta_en("SELECT * FROM `$t` WHERE projekt_uuid = ? AND is_deleted = 0 AND LOWER(matpunktsnamn) = LOWER(?) ORDER BY id LIMIT 1", [$projekt_uuid, $namn]);
}

/** Mätaren som är kopplad till en mätpunkt, eller null. */
function prj_matare_for_matpunkt(string $matpunkt_uuid): ?array
{
    $t = kc_tabell('md_matare');
    return kc_hamta_en("SELECT * FROM `$t` WHERE matpunkt_uuid = ? AND is_deleted = 0 ORDER BY id LIMIT 1", [$matpunkt_uuid]);
}

/** Kort beskrivning av en mätpunkt för läsaren. */
function prj_matpunkt_info(array $mp): array
{
    $m = prj_matare_for_matpunkt($mp['uuid']);
    return [
        'uuid'          => $mp['uuid'],
        'namn'          => $mp['matpunktsnamn'],
        'objektnummer'  => $mp['objektnummer'],
        'storheter'     => $mp['storheter'],
        'matar_id'      => $m['matar_id'] ?? '',
        'monterad'      => $mp['monterad_datum'] !== null && $mp['monterad_datum'] !== '',
    ];
}

/** Storheter som mätpunkten kräver men mätaren saknar (bara om båda har storheter angivna). */
function prj_storheter_saknas(string $kravs, string $har): array
{
    $k = array_filter(array_map('trim', explode(',', $kravs)));
    $h = array_map('mb_strtolower', array_filter(array_map('trim', explode(',', $har))));
    if ($k === [] || $h === []) {
        return [];
    }
    return array_values(array_filter($k, static fn($s) => !in_array(mb_strtolower($s), $h, true)));
}

/**
 * Kopplar en mätare ur lagret till en av projektets mätpunkter. Anropas i transaktion.
 * Kastar KcFel (409) när det inte går. Returnerar ['text' => ..., 'varningar' => [...], 'matpunkt' => info].
 */
function prj_provisionera(array $projekt, string $matpunkt_uuid, string $matar_text): array
{
    if (prj_projekt_last($projekt)) {
        throw new KcFel('Projektet är avslutat.', 409);
    }
    $tm = kc_tabell('md_matpunkt');
    $mp = kc_hamta_en("SELECT * FROM `$tm` WHERE uuid = ? AND projekt_uuid = ? AND is_deleted = 0 FOR UPDATE", [$matpunkt_uuid, $projekt['uuid']]);
    if ($mp === null) {
        throw new KcFel('Mätpunkten finns inte i projektet.', 404);
    }
    $matar_text = trim($matar_text);
    if ($matar_text === '') {
        throw new KcFel('Läs mätarens QR-kod eller skriv mätarId.', 400);
    }
    if (prj_matpunkt_med_namn($projekt['uuid'], $matar_text) !== null) {
        throw new KcFel('Det där är en mätpunktsetikett. Läs mätarens QR-kod.', 409);
    }
    $id = prj_qr_till_matar_id($matar_text);
    if (($f = prj_matar_id_fel($id)) !== null) {
        throw new KcFel($f, 400);
    }
    $ta = kc_tabell('md_matare');
    $m = kc_hamta_en("SELECT * FROM `$ta` WHERE matar_id = ? AND is_deleted = 0 ORDER BY id LIMIT 1 FOR UPDATE", [$id]);
    if ($m === null) {
        throw new KcFel("Mätaren $id finns inte i lagret. Importera den först.", 404);
    }
    $nuvarande = prj_matare_for_matpunkt($mp['uuid']);
    if ($nuvarande !== null && $nuvarande['uuid'] === $m['uuid']) {
        return ['text' => "{$mp['matpunktsnamn']} är redan kopplad till $id.", 'varningar' => [], 'matpunkt' => prj_matpunkt_info($mp), 'ny' => false];
    }
    if ($nuvarande !== null) {
        throw new KcFel("{$mp['matpunktsnamn']} är redan kopplad till {$nuvarande['matar_id']}. Koppla loss den först.", 409);
    }
    if ($m['matpunkt_uuid'] !== '') {
        $annan = kc_hamta_en("SELECT m.matpunktsnamn, p.projektnummer FROM `$tm` m LEFT JOIN `" . kc_tabell('md_projekt')
            . "` p ON p.uuid = m.projekt_uuid WHERE m.uuid = ?", [$m['matpunkt_uuid']]);
        throw new KcFel("Mätaren $id är redan kopplad till " . ($annan['matpunktsnamn'] ?? 'en annan mätpunkt')
            . (isset($annan['projektnummer']) ? " ({$annan['projektnummer']})" : '') . '.', 409);
    }
    $varningar = [];
    $saknas = prj_storheter_saknas($mp['storheter'], $m['storheter']);
    if ($saknas !== []) {
        $varningar[] = 'Mätaren saknar storheter som mätpunkten ska mäta: ' . implode(', ', $saknas) . '.';
    }
    $nu = kc_nu();
    kc_kor("UPDATE `$ta` SET matpunkt_uuid = ?, projekt_uuid = ?, last_changed = ?, source_tool = 'prj' WHERE uuid = ?",
        [$mp['uuid'], $projekt['uuid'], $nu, $m['uuid']]);
    prj_matpunkter_las($projekt['uuid'], [$mp['uuid']]);
    return ['text' => "{$mp['matpunktsnamn']} ↔ $id", 'varningar' => $varningar, 'matpunkt' => prj_matpunkt_info($mp), 'ny' => true];
}

/** Kopplar loss mätaren från en mätpunkt (felkoppling). Monterad mätare måste demonteras först. I transaktion. */
function prj_koppla_loss(array $projekt, string $matpunkt_uuid): string
{
    if (prj_projekt_last($projekt)) {
        throw new KcFel('Projektet är avslutat.', 409);
    }
    $tm = kc_tabell('md_matpunkt');
    $mp = kc_hamta_en("SELECT * FROM `$tm` WHERE uuid = ? AND projekt_uuid = ? AND is_deleted = 0", [$matpunkt_uuid, $projekt['uuid']]);
    if ($mp === null) {
        throw new KcFel('Mätpunkten finns inte i projektet.', 404);
    }
    if ($mp['monterad_datum'] !== null && $mp['monterad_datum'] !== '') {
        throw new KcFel("{$mp['matpunktsnamn']} är monterad. Demontera mätaren först.", 409);
    }
    $m = prj_matare_for_matpunkt($mp['uuid']);
    if ($m === null) {
        throw new KcFel("{$mp['matpunktsnamn']} har ingen kopplad mätare.", 409);
    }
    kc_kor('UPDATE `' . kc_tabell('md_matare') . "` SET matpunkt_uuid = '', projekt_uuid = '', last_changed = ?, source_tool = 'prj' WHERE uuid = ?",
        [kc_nu(), $m['uuid']]);
    return "{$m['matar_id']} är loss från {$mp['matpunktsnamn']} och ledig i lagret.";
}

/** Antal mätpunkter i projektet: [totalt, kopplade, monterade]. */
function prj_provisionering_antal(string $projekt_uuid): array
{
    $tm = kc_tabell('md_matpunkt');
    $ta = kc_tabell('md_matare');
    $r = kc_hamta_en("SELECT COUNT(*) AS totalt,
                             SUM(CASE WHEN EXISTS (SELECT 1 FROM `$ta` a WHERE a.matpunkt_uuid = m.uuid AND a.is_deleted = 0) THEN 1 ELSE 0 END) AS kopplade,
                             SUM(CASE WHEN m.monterad_datum IS NOT NULL THEN 1 ELSE 0 END) AS monterade
                        FROM `$tm` m WHERE m.projekt_uuid = ? AND m.is_deleted = 0", [$projekt_uuid]);
    return [(int)($r['totalt'] ?? 0), (int)($r['kopplade'] ?? 0), (int)($r['monterade'] ?? 0)];
}

/* ---------- Avslut ---------- */

/**
 * Villkor (SQL) för mätare a vars mätpunkt har exporterats med en mall som innehåller nycklar,
 * t.ex. MätarKollen-mallen eller en kopia av den. Bara deras nycklar rensas vid avslut.
 */
function prj_avslut_exporterad_sql(string $alias = 'a'): string
{
    $tr = kc_tabell('md_exportrad');
    $tx = kc_tabell('md_exportmall');
    return "EXISTS (SELECT 1 FROM `$tr` r JOIN `$tx` x ON x.uuid = r.mall_uuid
                     WHERE r.matpunkt_uuid = $alias.matpunkt_uuid AND r.is_deleted = 0 AND x.med_nycklar = 1)";
}

/** Kontrollista inför avslut: antal per kategori. */
function prj_avslut_kontroll(string $projekt_uuid): array
{
    $tm = kc_tabell('md_matpunkt');
    $ta = kc_tabell('md_matare');
    $tn = kc_tabell('md_matarnyckel');
    $exp = prj_avslut_exporterad_sql('a');
    $r = kc_hamta_en("SELECT COUNT(*) AS matpunkter,
                             SUM(CASE WHEN a.uuid IS NULL THEN 1 ELSE 0 END) AS utan_matare,
                             SUM(CASE WHEN a.uuid IS NOT NULL AND m.monterad_datum IS NULL THEN 1 ELSE 0 END) AS ej_monterade,
                             SUM(CASE WHEN a.uuid IS NOT NULL AND m.monterad_datum IS NOT NULL AND NOT $exp THEN 1 ELSE 0 END) AS ej_exporterade
                        FROM `$tm` m LEFT JOIN `$ta` a ON a.matpunkt_uuid = m.uuid AND a.is_deleted = 0
                       WHERE m.projekt_uuid = ? AND m.is_deleted = 0", [$projekt_uuid]) ?? [];
    $nycklar = kc_hamta_en("SELECT SUM(CASE WHEN $exp THEN 1 ELSE 0 END) AS rensas, SUM(CASE WHEN $exp THEN 0 ELSE 1 END) AS behalls
                              FROM `$tn` n JOIN `$ta` a ON a.uuid = n.matare_uuid AND a.is_deleted = 0
                             WHERE a.projekt_uuid = ? AND n.is_deleted = 0", [$projekt_uuid]) ?? [];
    return [
        'matpunkter'     => (int)($r['matpunkter'] ?? 0),
        'utan_matare'    => (int)($r['utan_matare'] ?? 0),
        'ej_monterade'   => (int)($r['ej_monterade'] ?? 0),
        'ej_exporterade' => (int)($r['ej_exporterade'] ?? 0),
        'nycklar_rensas' => (int)($nycklar['rensas'] ?? 0),
        'nycklar_behalls' => (int)($nycklar['behalls'] ?? 0),
    ];
}

/**
 * Avslutar projektet: status Avslutat och nycklarna rensas för projektets mätare som exporterats med
 * nycklar (värdet töms och raden markeras raderad – går inte att ångra). I transaktion.
 * Returnerar antal rensade nycklar.
 */
function prj_avsluta(array $projekt): int
{
    if ($projekt['status'] === 'avslutat') {
        throw new KcFel('Projektet är redan avslutat.', 409);
    }
    $nu = kc_nu();
    $ta = kc_tabell('md_matare');
    $n = kc_kor('UPDATE `' . kc_tabell('md_matarnyckel') . "`
                  SET varde = '', is_deleted = 1, last_changed = ?, source_tool = 'prj'
                WHERE (is_deleted = 0 OR varde <> '') AND matare_uuid IN (
                      SELECT a.uuid FROM `$ta` a WHERE a.projekt_uuid = ? AND a.is_deleted = 0 AND " . prj_avslut_exporterad_sql('a') . ')',
        [$nu, $projekt['uuid']]);
    kc_kor('UPDATE `' . kc_tabell('md_projekt') . "` SET status = 'avslutat', last_changed = ?, source_tool = 'prj' WHERE uuid = ?",
        [$nu, $projekt['uuid']]);
    return $n;
}

/** Återöppnar ett avslutat projekt (status Pågående). Rensade nycklar kommer inte tillbaka. I transaktion. */
function prj_ateroppna(array $projekt): void
{
    if ($projekt['status'] !== 'avslutat') {
        throw new KcFel('Projektet är inte avslutat.', 409);
    }
    kc_kor('UPDATE `' . kc_tabell('md_projekt') . "` SET status = 'pagaende', last_changed = ?, source_tool = 'prj' WHERE uuid = ?",
        [kc_nu(), $projekt['uuid']]);
}

/* ---------- Monteringskontroll ---------- */

/**
 * Objektnumret ur en avläst kod: etikettens QR-kod (mätpunktsnamn → mätpunktens objekt) eller ett
 * objektnummer (QR, "Skanna text" eller manuellt) som finns bland projektets mätpunkter. Annars null.
 */
function prj_objekt_fran_kod(string $projekt_uuid, string $kod): ?string
{
    $kod = trim($kod);
    if ($kod === '') {
        return null;
    }
    $mp = prj_matpunkt_med_namn($projekt_uuid, $kod);
    if ($mp !== null) {
        return $mp['objektnummer'];
    }
    $t = kc_tabell('md_matpunkt');
    foreach (array_unique([$kod, (string)preg_replace('/\s+/u', '', $kod)]) as $forsok) {
        $r = kc_hamta_varde("SELECT objektnummer FROM `$t` WHERE projekt_uuid = ? AND is_deleted = 0 AND objektnummer <> ''
                              AND LOWER(objektnummer) = LOWER(?) LIMIT 1", [$projekt_uuid, $forsok]);
        if ($r !== null && $r !== false) {
            return (string)$r;
        }
    }
    return null;
}

/** Projektets mätpunkter i ett objekt, med kopplad mätare och montering. */
function prj_objekt_matpunkter(string $projekt_uuid, string $objektnummer): array
{
    $tm = kc_tabell('md_matpunkt');
    $ta = kc_tabell('md_matare');
    return kc_hamta_alla("SELECT m.uuid, m.matpunktsnamn AS namn, COALESCE(a.matar_id, '') AS matar_id,
                                 COALESCE(m.monterad_datum, '') AS monterad_datum
                            FROM `$tm` m LEFT JOIN `$ta` a ON a.matpunkt_uuid = m.uuid AND a.is_deleted = 0
                           WHERE m.projekt_uuid = ? AND m.is_deleted = 0 AND m.objektnummer = ?
                           ORDER BY m.matpunktsnamn", [$projekt_uuid, $objektnummer]);
}

/**
 * Monteringskontroll: mätaren (avläst kod) ska vara provisionerad till en mätpunkt i objektet.
 * Rätt matchning sparar dagens datum (svensk tid) och användaren. Fel kastar KcFel och sparar inget.
 * Anropas i transaktion. Returnerar ['text', 'ny', 'matpunkter'].
 */
function prj_montera(array $projekt, string $objektnummer, string $kod, string $anvandarnamn): array
{
    if (prj_projekt_last($projekt)) {
        throw new KcFel('Projektet är avslutat.', 409);
    }
    $id = prj_qr_till_matar_id($kod);
    if (($f = prj_matar_id_fel($id)) !== null) {
        throw new KcFel($f, 400);
    }
    $ta = kc_tabell('md_matare');
    $m = kc_hamta_en("SELECT * FROM `$ta` WHERE matar_id = ? AND is_deleted = 0 ORDER BY id LIMIT 1", [$id]);
    if ($m === null) {
        throw new KcFel("Mätaren $id finns inte i lagret.", 404);
    }
    if ($m['matpunkt_uuid'] === '') {
        throw new KcFel("Mätaren $id är inte provisionerad. Koppla den till en mätpunkt först.", 409);
    }
    $tm = kc_tabell('md_matpunkt');
    $mp = kc_hamta_en("SELECT m.*, p.projektnummer FROM `$tm` m LEFT JOIN `" . kc_tabell('md_projekt')
        . "` p ON p.uuid = m.projekt_uuid WHERE m.uuid = ? AND m.is_deleted = 0 FOR UPDATE", [$m['matpunkt_uuid']]);
    if ($mp === null) {
        throw new KcFel("Mätaren $id är kopplad till en mätpunkt som inte finns längre.", 409);
    }
    if ($mp['projekt_uuid'] !== $projekt['uuid']) {
        throw new KcFel("FEL MÄTARE: $id hör till {$mp['matpunktsnamn']} i projekt {$mp['projektnummer']}.", 409);
    }
    if ($mp['objektnummer'] !== $objektnummer) {
        throw new KcFel("FEL MÄTARE: $id hör till {$mp['matpunktsnamn']} (objekt {$mp['objektnummer']}), inte objekt $objektnummer.", 409);
    }
    if ($mp['monterad_datum'] !== null && $mp['monterad_datum'] !== '') {
        return ['text' => "$id är redan monterad i {$mp['matpunktsnamn']} ({$mp['monterad_datum']}).", 'ny' => false,
                'matpunkter' => prj_objekt_matpunkter($projekt['uuid'], $objektnummer)];
    }
    $idag = (new DateTimeImmutable('now', new DateTimeZone(KOLL_TIDSZON)))->format('Y-m-d');
    kc_kor("UPDATE `$tm` SET monterad_datum = ?, monterad_av = ?, last_changed = ?, source_tool = 'prj' WHERE uuid = ?",
        [$idag, mb_substr($anvandarnamn, 0, 100), kc_nu(), $mp['uuid']]);
    return ['text' => "$id monterad i {$mp['matpunktsnamn']}.", 'ny' => true,
            'matpunkter' => prj_objekt_matpunkter($projekt['uuid'], $objektnummer)];
}

/** Demonterar (tömmer monteringen) så att mätaren kan kopplas loss. I transaktion. */
function prj_demontera(array $projekt, string $matpunkt_uuid): string
{
    if (prj_projekt_last($projekt)) {
        throw new KcFel('Projektet är avslutat.', 409);
    }
    $tm = kc_tabell('md_matpunkt');
    $mp = kc_hamta_en("SELECT * FROM `$tm` WHERE uuid = ? AND projekt_uuid = ? AND is_deleted = 0", [$matpunkt_uuid, $projekt['uuid']]);
    if ($mp === null) {
        throw new KcFel('Mätpunkten finns inte i projektet.', 404);
    }
    if ($mp['monterad_datum'] === null || $mp['monterad_datum'] === '') {
        throw new KcFel("{$mp['matpunktsnamn']} är inte monterad.", 409);
    }
    kc_kor("UPDATE `$tm` SET monterad_datum = NULL, monterad_av = '', last_changed = ?, source_tool = 'prj' WHERE uuid = ?",
        [kc_nu(), $mp['uuid']]);
    return "{$mp['matpunktsnamn']} är demonterad. Mätaren är fortfarande kopplad och kan nu kopplas loss.";
}

/**
 * Kameraläsarens HTML (siktesruta, zoom, lampa, foto, manuell inmatning). Läsaren styrs från sidan via
 * prj_lasare.js: PrjLasare.skapa({onKod: fn(text)}). Avkodaren (BarcodeDetector-polyfill, ZXing/WebAssembly)
 * hämtas tills vidare från jsdelivr; ett eget exemplar i verktyget är ett förbättringsförslag.
 */
function prj_lasare_html(string $platshallare = 'Skriv in'): string
{
    $js = kc_h(kc_url('prj_lasare.js') . '?v=1');
    $platshallare = kc_h($platshallare);
    return <<<HTML
<style>
  .prj-lasare { max-width: 32rem; }
  .prj-lasare-bild { position: relative; width: 100%; height: 260px; background: #111; border-radius: 6px; overflow: hidden; }
  .prj-lasare-bild video { width: 100%; height: 100%; object-fit: cover; display: block; }
  .prj-lasare-sikte { position: absolute; left: 10%; right: 10%; top: 10%; bottom: 10%; border: 2px solid rgba(255,255,255,.85);
                      border-radius: 6px; box-shadow: 0 0 0 999px rgba(0,0,0,.35); display: none; pointer-events: none; }
  .prj-lasare-bild.aktiv .prj-lasare-sikte { display: block; }
  .prj-lasare-kontroller { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-top: .5rem; }
  .prj-lasare-kontroller label { display: flex; align-items: center; gap: .4rem; flex: 1 1 10rem; }
  .prj-lasare-kontroller input[type=range] { flex: 1; }
  .prj-lasare-manuell { display: flex; gap: .4rem; margin-top: .6rem; }
  .prj-lasare-manuell input { flex: 1; font-size: 16px; padding: .5rem; min-width: 0; }
  .prj-lasare-manuell button { padding: .5rem .9rem; }
  .prj-steg { font-size: 1.1rem; margin: .6rem 0; }
  .prj-resultat { padding: .7rem .9rem; border-radius: 6px; margin: .6rem 0; font-weight: bold; }
  .prj-resultat.ok { background: #e3f6e3; border: 2px solid #1d7a1d; color: #145214; }
  .prj-resultat.fel { background: #fde8e8; border: 2px solid #b91c1c; color: #8b1111; }
  .prj-resultat.info { background: #f3f3f3; border: 2px solid #555; }
  .prj-resultat small { display: block; font-weight: normal; margin-top: .25rem; }
</style>
<div class="prj-lasare">
  <div class="prj-lasare-bild" id="prj-lasare-bild">
    <video id="prj-lasare-video" playsinline muted autoplay></video>
    <div class="prj-lasare-sikte"></div>
  </div>
  <div class="prj-lasare-kontroller">
    <button type="button" id="prj-lasare-start">Starta kameran</button>
    <label id="prj-lasare-zoom-ram" hidden>Zoom <input type="range" id="prj-lasare-zoom"></label>
    <button type="button" id="prj-lasare-lampa" hidden>Lampa</button>
    <label class="kc-knapp" style="flex:0 0 auto">Ta foto
      <input type="file" id="prj-lasare-foto" accept="image/*" capture="environment" hidden></label>
  </div>
  <div class="prj-lasare-manuell">
    <input type="text" id="prj-lasare-text" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="{$platshallare}">
    <button type="button" id="prj-lasare-ok">OK</button>
  </div>
  <p class="kc-hjalp" id="prj-lasare-status"></p>
</div>
<script src="https://cdn.jsdelivr.net/npm/barcode-detector@2.2.5/dist/iife/pure.js"></script>
<script src="{$js}"></script>
HTML;
}

/* ---------- Etiketter ---------- */

/**
 * Etikettark: A4 med 3 × 8 etiketter à 70 × 37 mm (vanligaste arket, t.ex. Avery 3475).
 * Mått i mm. Etiketten: QR-kod till vänster, mätpunktsnamnet i klartext till höger.
 */
const PRJ_ETIKETT = [
    'kolumner' => 3, 'rader' => 8, 'bredd' => 70.0, 'hojd' => 37.0,
    'vanster' => 0.0, 'topp' => 0.5,          // arkets marginaler
    'marginal' => 4.0,                         // innermarginal i etiketten
    'qr' => 24.0,                              // QR-kodens sida inkl. tyst zon (2 moduler)
    'mellanrum' => 2.0,                        // mellan QR-kod och text
];

/**
 * Delar ett mätpunktsnamn i rader som ryms i bredden (mm). Returnerar [rader, storlek i pt].
 * Ordning: en rad (10–7 pt), två rader delade vid '_' (10–7 pt), en rad (6,5–6 pt),
 * två rader (6,5–6 pt), en rad per del (6 pt), sist hårda radbrytningar (6 pt).
 */
function prj_etikett_rader(string $namn, float $bredd, float $max_pt = 10.0): array
{
    $passar = static fn(array $rader, float $pt): bool =>
        max(array_map(static fn($r) => prj_pdf_textbredd($r, $pt), $rader)) <= $bredd;
    $delar = explode('_', $namn);
    $tva = null;
    if (count($delar) > 1) {
        $basta = PHP_INT_MAX;
        for ($i = 1; $i < count($delar); $i++) {
            $a = implode('_', array_slice($delar, 0, $i)) . '_';
            $b = implode('_', array_slice($delar, $i));
            $langst = max(mb_strlen($a), mb_strlen($b));
            if ($langst < $basta) {
                $basta = $langst;
                $tva = [$a, $b];
            }
        }
    }
    $forsok = [[[$namn], $max_pt, 7.0]];
    if ($tva !== null) {
        $forsok[] = [$tva, $max_pt, 7.0];
    }
    $forsok[] = [[$namn], 6.5, 6.0];
    if ($tva !== null) {
        $forsok[] = [$tva, 6.5, 6.0];
    }
    if (count($delar) > 2) {
        $forsok[] = [array_map(static fn($d, $i) => $i < count($delar) - 1 ? $d . '_' : $d, $delar, array_keys($delar)), 6.0, 6.0];
    }
    foreach ($forsok as [$rader, $fran, $till]) {
        for ($pt = $fran; $pt >= $till; $pt -= 0.5) {
            if ($passar($rader, $pt)) {
                return [$rader, $pt];
            }
        }
    }
    $per_rad = max(1, (int)floor($bredd / prj_pdf_textbredd('X', 6.0)));
    return [mb_str_split($namn, $per_rad), 6.0];
}

/** Kortar en text så att den ryms i bredden (mm) vid given storlek. */
function prj_etikett_korta(string $text, float $bredd, float $pt): string
{
    if (prj_pdf_textbredd($text, $pt) <= $bredd) {
        return $text;
    }
    $max = max(1, (int)floor($bredd / prj_pdf_textbredd('X', $pt)) - 1);
    return mb_substr($text, 0, $max) . '…';
}

/**
 * Bygger etikett-PDF:en. $etiketter: [['namn' => ..., 'objektnummer' => ..., 'adress' => ...], ...].
 * QR-koden innehåller bara mätpunktsnamnet; klartexten är namnet (fetstil), objektnumret och adressen.
 * $start = första lediga position på första arket (1–24). $ramar ritar etikettkanterna.
 * $prov skriver $prov_text på varje etikett (provutskrift). $max_pt = största textstorlek för namnet
 * (objektetiketter använder större text). Används av etiketter.php och objektetiketter.php.
 * Kräver prj_qr.php och prj_pdf.php.
 */
function prj_etiketter_pdf(array $etiketter, int $start = 1, bool $ramar = false, bool $prov = false, string $titel = '',
                           float $max_pt = 10.0, string $prov_text = 'PROV – låser inget'): string
{
    $e = PRJ_ETIKETT;
    $per_ark = $e['kolumner'] * $e['rader'];
    $pdf = prj_pdf_ny();
    $pos = max(1, min($per_ark, $start)) - 1;
    $textbredd = $e['bredd'] - 2 * $e['marginal'] - $e['qr'] - $e['mellanrum'];
    $ny_sida = true;
    foreach ($etiketter as $et) {
        if ($ny_sida) {
            prj_pdf_sida($pdf);
            if ($ramar) {
                for ($i = 0; $i < $per_ark; $i++) {
                    prj_pdf_rekt($pdf, $e['vanster'] + ($i % $e['kolumner']) * $e['bredd'],
                        $e['topp'] + intdiv($i, $e['kolumner']) * $e['hojd'], $e['bredd'], $e['hojd'], false);
                }
            }
            $ny_sida = false;
        }
        $x0 = $e['vanster'] + ($pos % $e['kolumner']) * $e['bredd'];
        $y0 = $e['topp'] + intdiv($pos, $e['kolumner']) * $e['hojd'];

        // QR-kod, vertikalt centrerad
        $qy = $y0 + ($e['hojd'] - $e['qr']) / 2;
        prj_pdf_qr($pdf, $x0 + $e['marginal'], $qy, $e['qr'], prj_qr_matris($et['namn']));

        // Text: namnet (fetstil) + objektnummer, ev. adress och PROV, centrerat i textytan
        [$rader, $pt] = prj_etikett_rader($et['namn'], $textbredd, $max_pt);
        $block = [];
        foreach ($rader as $r) {
            $block[] = [$r, $pt, true];
        }
        $objektnummer = trim((string)($et['objektnummer'] ?? ''));
        if ($objektnummer !== '') {
            $block[] = [prj_etikett_korta('Objekt ' . $objektnummer, $textbredd, 7.0), 7.0, false];
        }
        $adress = trim((string)($et['adress'] ?? ''));
        if ($adress !== '') {
            $block[] = [prj_etikett_korta($adress, $textbredd, 6.0), 6.0, false];
        }
        if ($prov) {
            $block[] = [$prov_text, 6.0, true];
        }
        $hojd = 0.0;
        foreach ($block as $i => [, $s]) {
            $hojd += ($i === 0 ? $s * 0.75 : $s * 1.25) / PRJ_PDF_PT;
        }
        $tx0 = $x0 + $e['marginal'] + $e['qr'] + $e['mellanrum'];
        $y = $y0 + ($e['hojd'] - $hojd) / 2;
        foreach ($block as $i => [$text, $s, $fet]) {
            $y += ($i === 0 ? $s * 0.75 : $s * 1.25) / PRJ_PDF_PT;
            $x = $tx0 + ($textbredd - prj_pdf_textbredd($text, $s)) / 2;
            prj_pdf_text($pdf, $x, $y, $s, $text, $fet);
        }

        $pos++;
        if ($pos >= $per_ark) {
            $pos = 0;
            $ny_sida = true;
        }
    }
    return prj_pdf_ut($pdf, $titel);
}

/** Sammanfattning för projektets sida. */
function prj_projekt_sammanfattning(string $projekt_uuid): array
{
    $tpo = kc_tabell('md_projektobjekt');
    $tm = kc_tabell('md_matpunkt');
    return [
        'objekt'       => (int)kc_hamta_varde("SELECT COUNT(*) FROM `$tpo` WHERE projekt_uuid = ? AND is_deleted = 0 AND utanfor = 0", [$projekt_uuid]),
        'objekt_utan'  => (int)kc_hamta_varde("SELECT COUNT(*) FROM `$tpo` WHERE projekt_uuid = ? AND is_deleted = 0 AND utanfor = 0 AND antal = 0", [$projekt_uuid]),
        'matpunkter'   => (int)kc_hamta_varde("SELECT COUNT(*) FROM `$tm` WHERE projekt_uuid = ? AND is_deleted = 0", [$projekt_uuid]),
        'lasta'        => (int)kc_hamta_varde("SELECT COUNT(*) FROM `$tm` WHERE projekt_uuid = ? AND is_deleted = 0 AND namn_last = 1", [$projekt_uuid]),
        'med_info'     => (int)kc_hamta_varde("SELECT COUNT(*) FROM `$tm` WHERE projekt_uuid = ? AND is_deleted = 0 AND info <> ''", [$projekt_uuid]),
        'utskrivna'    => (int)kc_hamta_varde("SELECT COUNT(*) FROM `$tm` WHERE projekt_uuid = ? AND is_deleted = 0 AND etikett_tid IS NOT NULL", [$projekt_uuid]),
    ];
}

/* ---------- Mätare (lager) ---------- */

/**
 * Normaliserar ett mätarId: blanksteg runt tas bort. Ser det ut som en LoRaWAN-identitet
 * (16 hextecken, ev. med blanksteg, kolon eller bindestreck) blir det 16 hextecken med versaler.
 */
function prj_matar_id_normalisera(string $id): string
{
    $id = trim($id);
    $hex = preg_replace('/[\s:\-]/', '', $id);
    return preg_match('/^[0-9a-fA-F]{16}$/', (string)$hex) ? strtoupper($hex) : $id;
}

/** Fel för ett (normaliserat) mätarId, eller null. */
function prj_matar_id_fel(string $id): ?string
{
    if ($id === '') {
        return 'MätarId saknas.';
    }
    if (mb_strlen($id) > 100) {
        return 'MätarId får vara max 100 tecken.';
    }
    if (preg_match('/\s/u', $id)) {
        return 'MätarId får inte innehålla mellanslag.';
    }
    return null;
}

/** Nyckeltypens namn för en kolumnrubrik "nyckel:<typ>" (gemener): [namn, känd]. */
function prj_nyckeltyp_fran_rubrik(string $typ): array
{
    $typ = trim($typ);
    foreach (PRJ_NYCKELTYPER as $namn => $alias) {
        if (mb_strtolower($namn) === $typ || in_array($typ, $alias, true)) {
            return [$namn, true];
        }
    }
    return [$typ, false];
}

/** Storheter som "A, B, C" (ordning bevaras, dubbletter bort). */
function prj_storheter_normalisera(string $s): string
{
    $ut = [];
    foreach (explode(',', $s) as $d) {
        $d = trim($d);
        if ($d !== '' && !in_array(mb_strtolower($d), array_map('mb_strtolower', $ut), true)) {
            $ut[] = $d;
        }
    }
    return implode(', ', $ut);
}

function prj_matare_hamta(string $uuid): ?array
{
    if (!kc_ar_uuid($uuid)) {
        return null;
    }
    $t = kc_tabell('md_matare');
    return kc_hamta_en("SELECT * FROM `$t` WHERE uuid = ? AND is_deleted = 0 LIMIT 1", [$uuid]);
}

/** Mätare i lagret med ett visst mätarId (ej raderad), eller null. */
function prj_matare_med_id(string $matar_id, string $utom_uuid = ''): ?array
{
    $t = kc_tabell('md_matare');
    return kc_hamta_en("SELECT * FROM `$t` WHERE matar_id = ? AND uuid <> ? AND is_deleted = 0 ORDER BY id LIMIT 1", [$matar_id, $utom_uuid]);
}

/** Mätare i MätarKollen (lokal kopia) med samma mätarId: [mätarnummer, ...]. */
function prj_matar_id_i_mat(string $matar_id): array
{
    $t = kc_tabell('ld_mat_matare');
    if (!kc_inst_tabell_finns($t)) {
        return [];
    }
    return array_column(kc_hamta_alla("SELECT matarnummer FROM `$t` WHERE matar_id = ? AND is_deleted = 0 ORDER BY matarnummer", [$matar_id]), 'matarnummer');
}

/** Mätarens nycklar: [nyckeltyp => rad]. Visas bara för inloggade, aldrig i listor, exporter eller API. */
function prj_nycklar(string $matare_uuid): array
{
    $t = kc_tabell('md_matarnyckel');
    $ut = [];
    foreach (kc_hamta_alla("SELECT * FROM `$t` WHERE matare_uuid = ? AND is_deleted = 0 ORDER BY id", [$matare_uuid]) as $r) {
        $ut[$r['nyckeltyp']] = $r;
    }
    return $ut;
}

/** Maskerat nyckelvärde: punkter + de fyra sista tecknen. */
function prj_maskera(string $v): string
{
    $n = mb_strlen($v);
    return $n <= 4 ? str_repeat('•', $n) : str_repeat('•', min(12, $n - 4)) . mb_substr($v, -4);
}

/** Raderar en felregistrerad mätare som inte är provisionerad, med dess nycklar. Anropas i transaktion. */
function prj_matare_radera(array $m): void
{
    if (($m['matpunkt_uuid'] ?? '') !== '') {
        throw new KcFel('Mätaren är provisionerad och kan inte raderas.', 409);
    }
    $nu = kc_nu();
    kc_kor('UPDATE `' . kc_tabell('md_matarnyckel') . "` SET varde = '', is_deleted = 1, last_changed = ?, source_tool = 'prj' WHERE matare_uuid = ? AND (is_deleted = 0 OR varde <> '')", [$nu, $m['uuid']]);
    kc_kor('UPDATE `' . kc_tabell('md_matare') . "` SET is_deleted = 1, last_changed = ?, source_tool = 'prj' WHERE uuid = ?", [$nu, $m['uuid']]);
}

/** Antal mätare i lagret: [totalt, lediga]. */
function prj_matare_antal(): array
{
    $t = kc_tabell('md_matare');
    if (!kc_inst_tabell_finns($t)) {
        return [0, 0];
    }
    return [
        (int)kc_hamta_varde("SELECT COUNT(*) FROM `$t` WHERE is_deleted = 0"),
        (int)kc_hamta_varde("SELECT COUNT(*) FROM `$t` WHERE is_deleted = 0 AND matpunkt_uuid = ''"),
    ];
}

/* ---------- Lokala kopior ---------- */

/** Antal aktiva rader i en lokal kopia. */
function prj_ld_antal(string $ld): int
{
    $t = kc_tabell($ld);
    return kc_inst_tabell_finns($t) ? (int)kc_hamta_varde("SELECT COUNT(*) FROM `$t` WHERE is_deleted = 0") : 0;
}

/** Huvudadress (eller första adress) för en fastighet/byggnad som text, eller ''. */
function prj_adress_text(string $agare, string $uuid): string
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
 * Söker i fastighetsdatan på nummer, namn och gatuadress (grunden för projektets omfattning).
 * Returnerar [['niva', 'uuid', 'nummer', 'namn', 'plats', 'typ'], ...], högst $max per nivå.
 */
function prj_sok_fastighetsdata(string $q, ?string $bara_niva = null, int $max = 25): array
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

/* ---------- Inloggning ---------- */

/** Inloggad användare (kontrollerad mot lokal kopia), eller null. */
function prj_inloggad(): ?array
{
    return kc_anvandare_kontrollera();
}

/** Kräver inloggning för att ändra data. */
function prj_krav_inloggning(): array
{
    prj_inloggad();
    return kc_krav_inloggning();
}

// Kontrollera inloggningen tidigt på varje sida (utom installationen och API:et)
if (!defined('KC_API') && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'install.php') {
    prj_inloggad();
}
