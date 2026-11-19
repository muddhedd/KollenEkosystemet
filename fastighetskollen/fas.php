<?php
/**
 * FastighetsKollen – fas.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * FastighetsKollens egna hjälpfunktioner (prefix fas_).
 * Inkluderas direkt efter kärnpaketet:
 *   require __DIR__ . '/core/bootstrap.php';
 *   require __DIR__ . '/fas.php';
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

/**
 * Typlistor: nyckel => [tabell, rubrik (plural), etikett (singular), förifyllda värden].
 * Varje lista är en egen md_-tabell med namn, beskrivning, sortering, is_active.
 */
const FAS_TYPLISTOR = [
    'byggnadstyp' => [
        'tabell' => 'md_byggnadstyp', 'rubrik' => 'Byggnadstyper', 'etikett' => 'byggnadstyp',
        'standard' => ['Flerbostadshus', 'Radhus', 'Småhus', 'Lokal/kontor', 'Garage/carport',
                       'Förrådsbyggnad', 'Miljöhus', 'Utomhusområde'],
    ],
    'objekttyp' => [
        'tabell' => 'md_objekttyp', 'rubrik' => 'Objekttyper', 'etikett' => 'objekttyp',
        'standard' => ['Lägenhet', 'Lokal', 'Garage', 'Parkeringsplats', 'Förråd', 'Extra förråd',
                       'Tvättstuga', 'Teknikutrymme', 'Soprum', 'Gemensamhetslokal'],
    ],
    'objektgruppstyp' => [
        'tabell' => 'md_objektgruppstyp', 'rubrik' => 'Objektgruppstyper', 'etikett' => 'objektgruppstyp',
        'standard' => ['Trapphus', 'Tvättstuga', 'Soprum/miljöhus', 'Värmesystem', 'Värmecentral', 'Gemensam gård'],
    ],
    'koktyp' => [
        'tabell' => 'md_koktyp', 'rubrik' => 'Kök', 'etikett' => 'köksvariant',
        'standard' => ['Kök', 'Kokvrå', 'Kokskåp', 'Inget kök'],
    ],
];

/**
 * Var typerna används: typlista => [[tabell, kolumn], ...].
 * En typ som används kan inte raderas (bara göras inaktiv).
 */
const FAS_TYP_ANVANDNING = [
    'byggnadstyp'     => [['md_byggnad', 'byggnadstyp_uuid']],
    'objekttyp'       => [['md_objekt', 'objekttyp_uuid']],
    'objektgruppstyp' => [['md_objektgrupp', 'objektgruppstyp_uuid']],
    'koktyp'          => [['md_objekt', 'koktyp_uuid']],
];

/** Kolumner i en typtabell, utöver standardfälten. */
const FAS_TYP_KOLUMNER = [
    'namn'        => "VARCHAR(100) NOT NULL DEFAULT ''",
    'beskrivning' => "VARCHAR(255) NOT NULL DEFAULT ''",
    'sortering'   => "INT NOT NULL DEFAULT 0",
    'is_active'   => "TINYINT(1) NOT NULL DEFAULT 1",
];

/**
 * Masterdatatabeller (utöver typlistorna). install.php skapar dem och lägger
 * till saknade kolumner och index. Standardfälten läggs till automatiskt.
 */
const FAS_TABELLER = [
    'md_fastighet' => [
        'kolumner' => [
            'namn'             => "VARCHAR(150) NOT NULL DEFAULT ''",
            'fastighetsnummer' => "VARCHAR(50)  NOT NULL DEFAULT ''",
            'team'             => "VARCHAR(100) NOT NULL DEFAULT ''",   // tillfälligt, ägs av org
            'bolag'            => "VARCHAR(100) NOT NULL DEFAULT ''",   // tillfälligt, ägs av org
            'kostnadsstalle'   => "VARCHAR(50)  NOT NULL DEFAULT ''",   // tillfälligt, ägs av bud
        ],
        'index' => ['fastighetsnummer', 'namn'],
    ],
    'md_adress' => [
        'kolumner' => [
            'fastighet_uuid' => "CHAR(36)     NOT NULL DEFAULT ''",     // satt för fastighetens adresser
            'byggnad_uuid'   => "CHAR(36)     NOT NULL DEFAULT ''",     // satt för byggnadens adresser
            'gatuadress'     => "VARCHAR(150) NOT NULL DEFAULT ''",
            'postnummer'     => "VARCHAR(6)   NOT NULL DEFAULT ''",     // lagras som "123 45"
            'ort'            => "VARCHAR(100) NOT NULL DEFAULT ''",
            'beskrivning'    => "VARCHAR(255) NOT NULL DEFAULT ''",
            'ar_huvudadress' => "TINYINT(1)   NOT NULL DEFAULT 0",
        ],
        'index' => ['fastighet_uuid', 'byggnad_uuid'],
    ],
    'md_byggnad' => [
        'kolumner' => [
            'fastighet_uuid'   => "CHAR(36)     NOT NULL DEFAULT ''",
            'byggnadsnummer'   => "VARCHAR(50)  NOT NULL DEFAULT ''",
            'namn'             => "VARCHAR(150) NOT NULL DEFAULT ''",
            'byggnadstyp_uuid' => "CHAR(36)     NOT NULL DEFAULT ''",
        ],
        'index' => ['fastighet_uuid', 'byggnadsnummer'],
    ],
    'md_objekt' => [
        'kolumner' => [
            'byggnad_uuid'   => "CHAR(36)     NOT NULL DEFAULT ''",
            'objektnummer'   => "VARCHAR(50)  NOT NULL DEFAULT ''",
            'objekttyp_uuid' => "CHAR(36)     NOT NULL DEFAULT ''",
            'adress_uuid'    => "CHAR(36)     NOT NULL DEFAULT ''",     // en av byggnadens adresser
            'kvadratmeter'   => "DECIMAL(9,1) NULL DEFAULT NULL",
            'antal_rum'      => "DECIMAL(4,1) NULL DEFAULT NULL",
            'koktyp_uuid'    => "CHAR(36)     NOT NULL DEFAULT ''",
            'vaningsplan'    => "SMALLINT     NULL DEFAULT NULL",       // 0 = BV, 1 = en trappa upp, -1 = källare
            'beskrivning'    => "VARCHAR(255) NOT NULL DEFAULT ''",
        ],
        'index' => ['byggnad_uuid', 'objektnummer', 'adress_uuid'],
    ],
    'md_objektgrupp' => [
        'kolumner' => [
            'objektgruppsnummer'   => "VARCHAR(50)  NOT NULL DEFAULT ''",
            'namn'                 => "VARCHAR(150) NOT NULL DEFAULT ''",
            'objektgruppstyp_uuid' => "CHAR(36)     NOT NULL DEFAULT ''",
            'beskrivning'          => "VARCHAR(255) NOT NULL DEFAULT ''",
        ],
        'index' => ['objektgruppsnummer'],
    ],
    'md_objektgrupp_koppling' => [
        'kolumner' => [
            'objektgrupp_uuid' => "CHAR(36)    NOT NULL DEFAULT ''",
            'maltyp'           => "VARCHAR(20) NOT NULL DEFAULT ''",     // fastighet | byggnad | objekt
            'mal_uuid'         => "CHAR(36)    NOT NULL DEFAULT ''",
            'kopplingstyp'     => "VARCHAR(20) NOT NULL DEFAULT ''",     // anvandare | resurs
        ],
        'index' => ['objektgrupp_uuid', 'mal_uuid'],
    ],
] + KC_EGENSKAP_TABELLER;   // egenskapstabellerna kommer från kärnpaketet (1.8.0)

/** Nivåer som kan ha egenskaper: nivå => tabell, nummerkolumn, sida, etikett (läses av kärnpaketet). */
const FAS_EGENSKAP_NIVAER = [
    'fastighet'   => ['tabell' => 'md_fastighet',   'nummer' => 'fastighetsnummer',   'sida' => 'fastighet.php',   'etikett' => 'Fastighet'],
    'byggnad'     => ['tabell' => 'md_byggnad',     'nummer' => 'byggnadsnummer',     'sida' => 'byggnad.php',     'etikett' => 'Byggnad'],
    'objekt'      => ['tabell' => 'md_objekt',      'nummer' => 'objektnummer',       'sida' => 'objekt.php',      'etikett' => 'Objekt'],
    'objektgrupp' => ['tabell' => 'md_objektgrupp', 'nummer' => 'objektgruppsnummer', 'sida' => 'objektgrupp.php', 'etikett' => 'Objektgrupp'],
];

/** Vad en objektgrupp kan kopplas till: måltyp => tabell, nummerkolumn, sida, etikett. */
const FAS_MALTYPER = [
    'fastighet' => ['tabell' => 'md_fastighet', 'nummer' => 'fastighetsnummer', 'sida' => 'fastighet.php', 'etikett' => 'Fastighet'],
    'byggnad'   => ['tabell' => 'md_byggnad',   'nummer' => 'byggnadsnummer',   'sida' => 'byggnad.php',   'etikett' => 'Byggnad'],
    'objekt'    => ['tabell' => 'md_objekt',    'nummer' => 'objektnummer',     'sida' => 'objekt.php',    'etikett' => 'Objekt'],
];

/** Hur målet hör till gruppen. Resurs = själva saken (t.ex. soprummet), användare = de som använder den. */
const FAS_KOPPLINGSTYPER = ['resurs' => 'Resurs', 'anvandare' => 'Användare'];

/** Vem som kan äga adresser: ägartyp => tabell, kolumn i md_adress, sida, etikett. */
const FAS_ADRESS_AGARE = [
    'fastighet' => ['tabell' => 'md_fastighet', 'kolumn' => 'fastighet_uuid', 'sida' => 'fastighet.php', 'etikett' => 'fastigheten'],
    'byggnad'   => ['tabell' => 'md_byggnad',   'kolumn' => 'byggnad_uuid',   'sida' => 'byggnad.php',   'etikett' => 'byggnaden'],
];

/** Definition för en typlista, eller KcFel 404. */
function fas_typlista(string $lista): array
{
    if (!array_key_exists($lista, FAS_TYPLISTOR)) {
        throw new KcFel('Okänd typlista.', 404);
    }
    return FAS_TYPLISTOR[$lista] + ['nyckel' => $lista];
}

/** Aktiva typer för en rullista: [uuid => namn], sorterade. */
function fas_typer_aktiva(string $lista): array
{
    $t = kc_tabell(fas_typlista($lista)['tabell']);
    $rader = kc_hamta_alla("SELECT uuid, namn FROM `$t` WHERE is_deleted = 0 AND is_active = 1 ORDER BY sortering, namn");
    return array_column($rader, 'namn', 'uuid');
}

/** Hur många ej raderade poster som använder typen. */
function fas_typ_anvand(string $lista, string $uuid): int
{
    $n = 0;
    foreach (FAS_TYP_ANVANDNING[$lista] ?? [] as [$tabell, $kolumn]) {
        $t = kc_tabell($tabell);
        if (kc_inst_tabell_finns($t)) {
            $n += (int)kc_hamta_varde("SELECT COUNT(*) FROM `$t` WHERE `$kolumn` = ? AND is_deleted = 0", [$uuid]);
        }
    }
    return $n;
}

/**
 * Typer för en rullista: aktiva typer, plus den valda även om den är inaktiv
 * (så att en post med en inaktiverad typ kan sparas utan att typen byts).
 * Returnerar [uuid => namn].
 */
function fas_typ_val(string $lista, string $valt = ''): array
{
    $val = fas_typer_aktiva($lista);
    if ($valt !== '' && !isset($val[$valt])) {
        $namn = fas_typ_namn($lista, $valt);
        if ($namn !== '') {
            $val[$valt] = $namn . ' (inaktiv)';
        }
    }
    return $val;
}

/** Typens namn (även inaktiv), eller ''. */
function fas_typ_namn(string $lista, string $uuid): string
{
    if ($uuid === '') {
        return '';
    }
    $t = kc_tabell(fas_typlista($lista)['tabell']);
    return (string)(kc_hamta_varde("SELECT namn FROM `$t` WHERE uuid = ? AND is_deleted = 0 LIMIT 1", [$uuid]) ?? '');
}

/** <option>-taggar för en rullista. $tom = text för tomt val, eller null om val krävs. */
function fas_alternativ(array $val, string $valt, ?string $tom = null): string
{
    $h = $tom !== null ? '<option value="">' . kc_h($tom) . '</option>' : '<option value="">– välj –</option>';
    foreach ($val as $uuid => $namn) {
        $h .= '<option value="' . kc_h($uuid) . '"' . ($uuid === $valt ? ' selected' : '') . '>' . kc_h($namn) . '</option>';
    }
    return $h;
}

/** Är värdet unikt i tabellen bland ej raderade rader? */
function fas_ar_unik(string $tabell, string $kolumn, string $varde, ?string $egen_uuid): bool
{
    $t = kc_tabell($tabell);
    $params = [$varde];
    $undantag = '';
    if ($egen_uuid !== null) {
        $undantag = ' AND uuid <> ?';
        $params[] = $egen_uuid;
    }
    return (int)kc_hamta_varde("SELECT COUNT(*) FROM `$t` WHERE is_deleted = 0 AND `$kolumn` = ?$undantag", $params) === 0;
}

/* ---------- Tal och visning ---------- */

/**
 * Tolkar ett decimaltal från formulär ("72,5" eller "72.5"). Tomt = null.
 * Returnerar [värde|null, fel|null].
 */
function fas_decimal(string $s, float $min, float $max, float $steg, string $etikett): array
{
    $s = str_replace([' ', ','], ['', '.'], trim($s));
    if ($s === '') {
        return [null, null];
    }
    if (!preg_match('/^\d+(\.\d+)?$/', $s)) {
        return [null, "$etikett måste vara ett tal, t.ex. " . ($steg < 1 ? '72,5' : '3') . '.'];
    }
    $v = (float)$s;
    if ($v < $min || $v > $max) {
        return [null, "$etikett måste vara mellan " . fas_tal($min) . ' och ' . fas_tal($max) . '.'];
    }
    if (abs($v / $steg - round($v / $steg)) > 0.0001) {
        return [null, "$etikett kan anges i steg om " . fas_tal($steg) . '.'];
    }
    return [round($v, 1), null];
}

/** Tal för visning med svenskt decimalkomma, utan onödig decimal: 72,5 / 3. */
function fas_tal(mixed $v): string
{
    if ($v === null || $v === '') {
        return '';
    }
    $f = (float)$v;
    return floor($f) === $f ? (string)(int)$f : str_replace('.', ',', rtrim(rtrim(number_format($f, 1, '.', ''), '0'), '.'));
}

/** Våningsplan för visning: 0 = BV. */
function fas_vaning(mixed $v): string
{
    if ($v === null || $v === '') {
        return '';
    }
    return (int)$v === 0 ? 'BV' : (string)(int)$v;
}

/** "2 RoK", "1 rum + kokvrå", "3 rum". */
function fas_storlek(mixed $rum, string $kok): string
{
    if ($rum === null || $rum === '') {
        return '';
    }
    $r = fas_tal($rum);
    return match (mb_strtolower($kok)) {
        'kök'      => "$r RoK",
        '', 'inget kök' => "$r rum",
        default    => "$r rum + " . mb_strtolower($kok),
    };
}

/* ---------- Fastigheter ---------- */

function fas_fastighet_hamta(string $uuid): ?array
{
    if (!kc_ar_uuid($uuid)) {
        return null;
    }
    $t = kc_tabell('md_fastighet');
    return kc_hamta_en("SELECT * FROM `$t` WHERE uuid = ? AND is_deleted = 0 LIMIT 1", [$uuid]);
}

/** Antal aktiva byggnader på fastigheten. */
function fas_fastighet_antal_byggnader(string $uuid): int
{
    $t = kc_tabell('md_byggnad');
    if (!kc_inst_tabell_finns($t)) {
        return 0;
    }
    return (int)kc_hamta_varde("SELECT COUNT(*) FROM `$t` WHERE fastighet_uuid = ? AND is_deleted = 0", [$uuid]);
}

/** Validerar fastighetens uppgifter. Returnerar lista med fel (tom = ok). */
function fas_fastighet_validera(array $v, ?string $egen_uuid): array
{
    $fel = [];
    if ($v['namn'] === '' || mb_strlen($v['namn']) > 150) {
        $fel[] = 'Namn måste fyllas i (max 150 tecken).';
    }
    if ($v['fastighetsnummer'] === '' || mb_strlen($v['fastighetsnummer']) > 50) {
        $fel[] = 'Fastighetsnummer måste fyllas i (max 50 tecken).';
    }
    foreach (['team' => 100, 'bolag' => 100, 'kostnadsstalle' => 50] as $falt => $max) {
        if (mb_strlen($v[$falt]) > $max) {
            $fel[] = ucfirst($falt === 'kostnadsstalle' ? 'kostnadsställe' : $falt) . " får vara max $max tecken.";
        }
    }
    if ($v['fastighetsnummer'] !== '') {
        $t = kc_tabell('md_fastighet');
        $params = [$v['fastighetsnummer']];
        $undantag = '';
        if ($egen_uuid !== null) {
            $undantag = ' AND uuid <> ?';
            $params[] = $egen_uuid;
        }
        if ((int)kc_hamta_varde("SELECT COUNT(*) FROM `$t` WHERE is_deleted = 0 AND fastighetsnummer = ?$undantag", $params) > 0) {
            $fel[] = 'Fastighetsnumret används redan av en annan fastighet.';
        }
    }
    return $fel;
}

/** Alla fastigheter för en rullista: [uuid => "nummer namn"]. */
function fas_fastighet_val(): array
{
    $t = kc_tabell('md_fastighet');
    $val = [];
    foreach (kc_hamta_alla("SELECT uuid, fastighetsnummer, namn FROM `$t` WHERE is_deleted = 0 ORDER BY fastighetsnummer, namn") as $r) {
        $val[$r['uuid']] = trim($r['fastighetsnummer'] . ' ' . $r['namn']);
    }
    return $val;
}

/* ---------- Byggnader ---------- */

function fas_byggnad_hamta(string $uuid): ?array
{
    if (!kc_ar_uuid($uuid)) {
        return null;
    }
    $t = kc_tabell('md_byggnad');
    return kc_hamta_en("SELECT * FROM `$t` WHERE uuid = ? AND is_deleted = 0 LIMIT 1", [$uuid]);
}

/** Antal aktiva objekt i byggnaden. */
function fas_byggnad_antal_objekt(string $uuid): int
{
    $t = kc_tabell('md_objekt');
    return (int)kc_hamta_varde("SELECT COUNT(*) FROM `$t` WHERE byggnad_uuid = ? AND is_deleted = 0", [$uuid]);
}

/** Alla byggnader för en rullista, grupperade per fastighet: [fastighetstext => [uuid => text]]. */
function fas_byggnad_val(): array
{
    $tb = kc_tabell('md_byggnad');
    $tf = kc_tabell('md_fastighet');
    $val = [];
    foreach (kc_hamta_alla(
        "SELECT b.uuid, b.byggnadsnummer, b.namn, f.fastighetsnummer, f.namn AS fnamn
           FROM `$tb` b JOIN `$tf` f ON f.uuid = b.fastighet_uuid AND f.is_deleted = 0
          WHERE b.is_deleted = 0
          ORDER BY f.fastighetsnummer, b.byggnadsnummer"
    ) as $r) {
        $val[trim($r['fastighetsnummer'] . ' ' . $r['fnamn'])][$r['uuid']] = trim($r['byggnadsnummer'] . ' ' . $r['namn']);
    }
    return $val;
}

/* ---------- Objekt ---------- */

function fas_objekt_hamta(string $uuid): ?array
{
    if (!kc_ar_uuid($uuid)) {
        return null;
    }
    $t = kc_tabell('md_objekt');
    return kc_hamta_en("SELECT * FROM `$t` WHERE uuid = ? AND is_deleted = 0 LIMIT 1", [$uuid]);
}

/* ---------- Objektgrupper ---------- */

function fas_objektgrupp_hamta(string $uuid): ?array
{
    if (!kc_ar_uuid($uuid)) {
        return null;
    }
    $t = kc_tabell('md_objektgrupp');
    return kc_hamta_en("SELECT * FROM `$t` WHERE uuid = ? AND is_deleted = 0 LIMIT 1", [$uuid]);
}

/** uuid för den fastighet/byggnad/det objekt som har numret, eller null. */
function fas_mal_hitta(string $maltyp, string $nummer): ?string
{
    $def = FAS_MALTYPER[$maltyp] ?? null;
    if ($def === null || $nummer === '') {
        return null;
    }
    $t = kc_tabell($def['tabell']);
    $v = kc_hamta_varde("SELECT uuid FROM `$t` WHERE `{$def['nummer']}` = ? AND is_deleted = 0 LIMIT 1", [$nummer]);
    return $v === null ? null : (string)$v;
}

/** Gruppens aktiva kopplingar med målets nummer, namn och placering. Resurser först. */
function fas_objektgrupp_kopplingar(string $grupp_uuid): array
{
    $tk = kc_tabell('md_objektgrupp_koppling');
    $tf = kc_tabell('md_fastighet');
    $tb = kc_tabell('md_byggnad');
    $to = kc_tabell('md_objekt');
    $bas = "k.uuid, k.maltyp, k.mal_uuid, k.kopplingstyp";
    $var = "k.objektgrupp_uuid = ? AND k.is_deleted = 0 AND k.maltyp = ";
    return kc_hamta_alla(
        "SELECT $bas, f.fastighetsnummer AS nummer, f.namn AS namn, '' AS plats, 1 AS ordning
           FROM `$tk` k JOIN `$tf` f ON f.uuid = k.mal_uuid AND f.is_deleted = 0
          WHERE $var'fastighet'
         UNION ALL
         SELECT $bas, b.byggnadsnummer, b.namn, COALESCE(f.fastighetsnummer, ''), 2
           FROM `$tk` k JOIN `$tb` b ON b.uuid = k.mal_uuid AND b.is_deleted = 0
           LEFT JOIN `$tf` f ON f.uuid = b.fastighet_uuid
          WHERE $var'byggnad'
         UNION ALL
         SELECT $bas, o.objektnummer, '', COALESCE(b.byggnadsnummer, ''), 3
           FROM `$tk` k JOIN `$to` o ON o.uuid = k.mal_uuid AND o.is_deleted = 0
           LEFT JOIN `$tb` b ON b.uuid = o.byggnad_uuid
          WHERE $var'objekt'
         ORDER BY kopplingstyp DESC, ordning, nummer",
        [$grupp_uuid, $grupp_uuid, $grupp_uuid]
    );
}

/**
 * Alla objekt som gruppen omfattar: direkt kopplade objekt, objekt i kopplade
 * byggnader och objekt i byggnader på kopplade fastigheter (även nya).
 * En rad per objekt och kopplingstyp.
 */
function fas_objektgrupp_omfattar(string $grupp_uuid): array
{
    $tk = kc_tabell('md_objektgrupp_koppling');
    $tb = kc_tabell('md_byggnad');
    $to = kc_tabell('md_objekt');
    $ot = kc_tabell('md_objekttyp');
    $var = "k.objektgrupp_uuid = ? AND k.is_deleted = 0 AND k.maltyp = ";
    return kc_hamta_alla(
        "SELECT u.kopplingstyp, o.uuid, o.objektnummer, t.namn AS typ, b.byggnadsnummer
           FROM (SELECT k.kopplingstyp, k.mal_uuid AS objekt_uuid
                   FROM `$tk` k WHERE $var'objekt'
                 UNION
                 SELECT k.kopplingstyp, o2.uuid
                   FROM `$tk` k JOIN `$to` o2 ON o2.byggnad_uuid = k.mal_uuid AND o2.is_deleted = 0
                  WHERE $var'byggnad'
                 UNION
                 SELECT k.kopplingstyp, o3.uuid
                   FROM `$tk` k JOIN `$tb` b3 ON b3.fastighet_uuid = k.mal_uuid AND b3.is_deleted = 0
                   JOIN `$to` o3 ON o3.byggnad_uuid = b3.uuid AND o3.is_deleted = 0
                  WHERE $var'fastighet') u
           JOIN `$to` o ON o.uuid = u.objekt_uuid AND o.is_deleted = 0
           LEFT JOIN `$ot` t ON t.uuid = o.objekttyp_uuid
           LEFT JOIN `$tb` b ON b.uuid = o.byggnad_uuid
          ORDER BY u.kopplingstyp DESC, o.objektnummer",
        [$grupp_uuid, $grupp_uuid, $grupp_uuid]
    );
}

/**
 * Grupper som omfattar en post, direkt eller via byggnad/fastighet.
 * $mal = ['objekt' => uuid, 'byggnad' => uuid, 'fastighet' => uuid] (de nivåer som gäller).
 */
function fas_objektgrupper_for(array $mal): array
{
    $tk = kc_tabell('md_objektgrupp_koppling');
    $tg = kc_tabell('md_objektgrupp');
    $tt = kc_tabell('md_objektgruppstyp');
    $delar = [];
    $params = [];
    foreach ($mal as $maltyp => $uuid) {
        if (isset(FAS_MALTYPER[$maltyp]) && $uuid !== '') {
            $delar[] = '(k.maltyp = ? AND k.mal_uuid = ?)';
            array_push($params, $maltyp, $uuid);
        }
    }
    if ($delar === []) {
        return [];
    }
    return kc_hamta_alla(
        "SELECT g.uuid, g.objektgruppsnummer, g.namn, t.namn AS typ, k.kopplingstyp, k.maltyp
           FROM `$tk` k
           JOIN `$tg` g ON g.uuid = k.objektgrupp_uuid AND g.is_deleted = 0
           LEFT JOIN `$tt` t ON t.uuid = g.objektgruppstyp_uuid
          WHERE k.is_deleted = 0 AND (" . implode(' OR ', $delar) . ")
          ORDER BY g.objektgruppsnummer, k.kopplingstyp DESC",
        $params
    );
}

/** Rutan "Objektgrupper" på fastighets-, byggnads- och objektsidan. $niva = sidans egen måltyp. */
function fas_objektgrupper_ruta(string $niva, array $mal): string
{
    $rader = fas_objektgrupper_for($mal);
    $via = ['fastighet' => 'via fastigheten', 'byggnad' => 'via byggnaden', 'objekt' => 'via objektet'];
    $h = '<div class="kc-ruta"><h2>Objektgrupper</h2>';
    if ($rader === []) {
        $h .= '<p><em class="kc-dampad">Ingår inte i någon objektgrupp.</em></p>';
    } else {
        $h .= '<div class="kc-tabell-wrap"><table class="kc-tabell"><thead><tr>'
            . '<th>Nr</th><th>Namn</th><th>Typ</th><th>Som</th><th>Koppling</th></tr></thead><tbody>';
        foreach ($rader as $r) {
            $h .= '<tr><td><a href="' . kc_h(kc_url('objektgrupp.php?uuid=' . rawurlencode($r['uuid']))) . '">'
                . kc_h($r['objektgruppsnummer']) . '</a></td>'
                . '<td>' . kc_h($r['namn']) . '</td><td>' . kc_h($r['typ']) . '</td>'
                . '<td>' . kc_h(FAS_KOPPLINGSTYPER[$r['kopplingstyp']] ?? $r['kopplingstyp']) . '</td>'
                . '<td>' . kc_h($r['maltyp'] === $niva ? 'direkt' : ($via[$r['maltyp']] ?? '')) . '</td></tr>';
        }
        $h .= '</tbody></table></div>';
    }
    $h .= '<p class="kc-hjalp" style="margin-top:.6rem">Kopplingar görs på objektgruppens sida. '
        . '<a href="' . kc_h(kc_url('objektgrupper.php')) . '">Alla objektgrupper »</a></p></div>';
    return $h;
}

/** Raderar (mjukt) alla kopplingar till ett mål. Anropas när målet raderas. Returnerar antal. */
function fas_kopplingar_radera(string $maltyp, string $mal_uuid, string $nu): int
{
    $t = kc_tabell('md_objektgrupp_koppling');
    if (!kc_inst_tabell_finns($t)) {
        return 0;
    }
    return kc_kor(
        "UPDATE `$t` SET is_deleted = 1, last_changed = ?, source_tool = 'fas' WHERE maltyp = ? AND mal_uuid = ? AND is_deleted = 0",
        [$nu, $maltyp, $mal_uuid]
    );
}

/* ---------- Egenskaper (motorn finns i kärnpaketet, core/egenskap.php) ---------- */

/** Nivåerna för kärnpaketets egenskapsmotor. */
function fas_egenskap_nivaer(): array
{
    return FAS_EGENSKAP_NIVAER;
}

/** Rutan "Egenskaper" på postens sida (kvar för att sidorna inte ska behöva ändras). */
function fas_egenskaper_ruta(string $niva, string $post_uuid, bool $inloggad): string
{
    return kc_egenskaper_ruta($niva, $post_uuid, $inloggad);
}

/** Raderar (mjukt) alla egenskapsvärden för en post. */
function fas_egenskapsvarden_radera(string $post_uuid, string $nu): int
{
    return kc_egenskapsvarden_radera($post_uuid, $nu);
}

/* ---------- Adresser ---------- */

/** "12345" / "123 45" → "123 45", annars null. */
function fas_postnummer(string $s): ?string
{
    $d = preg_replace('/\s+/', '', $s) ?? '';
    return preg_match('/^\d{5}$/', $d) ? substr($d, 0, 3) . ' ' . substr($d, 3) : null;
}

/** Ägardefinition eller KcFel 404. */
function fas_adress_agartyp(string $agare): array
{
    if (!array_key_exists($agare, FAS_ADRESS_AGARE)) {
        throw new KcFel('Okänd adressägare.', 404);
    }
    return FAS_ADRESS_AGARE[$agare] + ['nyckel' => $agare];
}

/** Ägarens rad (fastighet/byggnad), eller null. */
function fas_adress_agare_hamta(string $agare, string $uuid): ?array
{
    $def = fas_adress_agartyp($agare);
    if (!kc_ar_uuid($uuid)) {
        return null;
    }
    $t = kc_tabell($def['tabell']);
    return kc_hamta_en("SELECT * FROM `$t` WHERE uuid = ? AND is_deleted = 0 LIMIT 1", [$uuid]);
}

/** Ägarens aktiva adresser, huvudadressen först. */
function fas_adresser(string $agare, string $agare_uuid): array
{
    $kol = fas_adress_agartyp($agare)['kolumn'];
    $t = kc_tabell('md_adress');
    return kc_hamta_alla(
        "SELECT * FROM `$t` WHERE `$kol` = ? AND is_deleted = 0 ORDER BY ar_huvudadress DESC, gatuadress, id",
        [$agare_uuid]
    );
}

/** "Storgatan 10a, 123 45 Sundsvall" */
function fas_adress_text(array $a): string
{
    $ort = trim($a['postnummer'] . ' ' . $a['ort']);
    return trim($a['gatuadress'] . ($ort !== '' ? ', ' . $ort : ''));
}

/**
 * Ser till att ägaren har exakt en huvudadress. $valt = adressen som ska bli
 * huvudadress, eller null för att behålla nuvarande (eller ta den första).
 * Rör bara rader som faktiskt ändras.
 */
function fas_huvudadress_satt(string $agare, string $agare_uuid, ?string $valt): void
{
    $rader = fas_adresser($agare, $agare_uuid);
    if ($rader === []) {
        return;
    }
    if ($valt === null || !in_array($valt, array_column($rader, 'uuid'), true)) {
        $valt = $rader[0]['uuid'];   // sorterade med befintlig huvudadress först
    }
    $t = kc_tabell('md_adress');
    foreach ($rader as $r) {
        $ska = $r['uuid'] === $valt ? 1 : 0;
        if ((int)$r['ar_huvudadress'] !== $ska) {
            kc_kor("UPDATE `$t` SET ar_huvudadress = ?, last_changed = ?, source_tool = 'fas' WHERE uuid = ?", [$ska, kc_nu(), $r['uuid']]);
        }
    }
}

/** Antal objekt som använder adressen. */
function fas_adress_anvand(string $uuid): int
{
    $t = kc_tabell('md_objekt');
    if (!kc_inst_tabell_finns($t)) {
        return 0;
    }
    return (int)kc_hamta_varde("SELECT COUNT(*) FROM `$t` WHERE adress_uuid = ? AND is_deleted = 0", [$uuid]);
}

/** En adress (oavsett ägare), eller null. */
function fas_adress_hamta(string $uuid): ?array
{
    if (!kc_ar_uuid($uuid)) {
        return null;
    }
    $t = kc_tabell('md_adress');
    return kc_hamta_en("SELECT * FROM `$t` WHERE uuid = ? AND is_deleted = 0 LIMIT 1", [$uuid]);
}

/* ---------- Inloggning ---------- */

/** Inloggad användare (kontrollerad mot lokal kopia), eller null. */
function fas_inloggad(): ?array
{
    return kc_anvandare_kontrollera();
}

/** Kräver inloggning för att ändra data. */
function fas_krav_inloggning(): array
{
    fas_inloggad();
    return kc_krav_inloggning();
}

/* ---------- Delning via CentralKollen ---------- */

/** Beskrivningar av de delade tabellerna (visas i CentralKollens register). */
const FAS_DELNING_BESKRIVNING = [
    'fastighet'             => 'Fastigheter: fastighetsnummer, namn; team, bolag och kostnadsställe tills vidare som text.',
    'adress'                => 'Adresser på fastigheter (fastighet_uuid) och byggnader (byggnad_uuid); ar_huvudadress = 1 för huvudadressen.',
    'byggnad'               => 'Byggnader och utomhusområden; tillhör en fastighet (fastighet_uuid).',
    'objekt'                => 'Objekt (lägenheter, lokaler, förråd …); tillhör en byggnad (byggnad_uuid).',
    'objektgrupp'           => 'Objektgrupper (trapphus, soprum, värmesystem …) med objektgruppsnummer.',
    'objektgrupp_koppling'  => 'Kopplingar objektgrupp → fastighet/byggnad/objekt (maltyp, mal_uuid) som resurs eller anvandare.',
    'egenskap'              => 'Egenskapsdefinitioner: namn, nivå, datatyp, enhet.',
    'egenskapsval'          => 'Valen för egenskaper med datatypen val.',
    'egenskapsvarde'        => 'Egenskapsvärden (egenskap_uuid, post_uuid, varde som normaliserad text).',
];

/**
 * Tabellerna FastighetsKollen delar via CentralKollen: alla md_-tabeller.
 * Format för kc_verktyg_api(): [tabellnamn => ['tabell' => 'md_xxx', 'beskrivning' => text]].
 */
function fas_delade_tabeller(): array
{
    $t = [];
    foreach (FAS_TABELLER as $tabell => $def) {
        $namn = substr($tabell, 3);
        $t[$namn] = ['tabell' => $tabell, 'beskrivning' => FAS_DELNING_BESKRIVNING[$namn] ?? ''];
    }
    foreach (FAS_TYPLISTOR as $def) {
        $t[substr($def['tabell'], 3)] = ['tabell' => $def['tabell'], 'beskrivning' => 'Typlista: ' . $def['rubrik'] . '.'];
    }
    ksort($t);
    return $t;
}

// Kontrollera inloggningen tidigt på varje sida (utom installationen, där tabellerna kan
// saknas, och API:et, som autentiseras med nyckel och saknar session)
if (!defined('KC_API') && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'install.php') {
    fas_inloggad();
}
