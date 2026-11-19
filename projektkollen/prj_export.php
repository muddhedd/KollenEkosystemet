<?php
/**
 * ProjektKollen – prj_export.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Exportmallar och exporter (etapp 6). Inkluderas efter prj.php av install.php, exportmallar.php,
 * exportmall_redigera.php och export.php.
 *
 *  - Exportmall: avgränsare, teckenkodning, rubrikrad, max rader per fil (0 = ingen delning),
 *    urval (alla / provisionerade / monterade mätpunkter), om nycklar får ingå.
 *  - Kolumner: rubrik i filen, ordning och källa ur PRJ_EXPORT_KALLOR (mätpunkt, mätare, projekt,
 *    nyckel:<typ> eller ett fast värde).
 *  - Den färdiga mallen "MätarKollen – Mätpunkter och mätare" (systemnyckel mat) är låst; den kan
 *    kopieras till en egen mall. Egna mallar kan ändras och tas bort.
 *  - En export skapar en exportomgång: varje mätpunkt får sin del (1, 2 …) som aldrig ändras.
 *    Förval: bara mätpunkter som inte exporterats med mallen tidigare (md_exportrad).
 *    Filerna byggs med aktuella data vid nedladdning; "Ladda ner alla" ger en zip.
 *  - Nedladdningar loggas i sys_exportlogg. Export med nycklar kräver inloggning.
 * Inga tabeller här delas via CentralKollen.
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

const PRJ_EXPORT_TABELLER = [
    'md_exportmall' => [
        'kolumner' => [
            'namn'         => "VARCHAR(100) NOT NULL DEFAULT ''",
            'beskrivning'  => "VARCHAR(255) NOT NULL DEFAULT ''",
            'avgransare'   => "VARCHAR(10)  NOT NULL DEFAULT 'semikolon'",   // semikolon | komma | tab
            'kodning'      => "VARCHAR(10)  NOT NULL DEFAULT 'utf8bom'",     // utf8bom | utf8 | cp1252
            'rubrikrad'    => "TINYINT(1)   NOT NULL DEFAULT 1",
            'max_rader'    => "INT          NOT NULL DEFAULT 0",             // 0 = ingen delning
            'urval'        => "VARCHAR(20)  NOT NULL DEFAULT 'alla'",        // alla | provisionerade | monterade
            'med_nycklar'  => "TINYINT(1)   NOT NULL DEFAULT 0",
            'last'         => "TINYINT(1)   NOT NULL DEFAULT 0",             // färdig mall som inte kan ändras
            'systemnyckel' => "VARCHAR(40)  NOT NULL DEFAULT ''",            // t.ex. mat (för installationen)
        ],
        'index' => ['systemnyckel'],
    ],
    'md_exportkolumn' => [
        'kolumner' => [
            'mall_uuid'  => "CHAR(36)     NOT NULL DEFAULT ''",
            'ordning'    => "INT          NOT NULL DEFAULT 0",
            'rubrik'     => "VARCHAR(100) NOT NULL DEFAULT ''",
            'kalla'      => "VARCHAR(100) NOT NULL DEFAULT ''",
            'fast_varde' => "VARCHAR(255) NOT NULL DEFAULT ''",
        ],
        'index' => ['mall_uuid'],
    ],
    'md_exportrad' => [
        'kolumner' => [
            'mall_uuid'     => "CHAR(36)     NOT NULL DEFAULT ''",
            'projekt_uuid'  => "CHAR(36)     NOT NULL DEFAULT ''",
            'matpunkt_uuid' => "CHAR(36)     NOT NULL DEFAULT ''",
            'omgang_uuid'   => "CHAR(36)     NOT NULL DEFAULT ''",
            'omgang_tid'    => "DATETIME(6)  NULL DEFAULT NULL",
            'del_nr'        => "INT          NOT NULL DEFAULT 1",
            'antal_delar'   => "INT          NOT NULL DEFAULT 1",
            'exporterad_av' => "VARCHAR(100) NOT NULL DEFAULT ''",
        ],
        'index' => ['mall_uuid', 'projekt_uuid', 'matpunkt_uuid', 'omgang_uuid'],
    ],
];

const PRJ_EXPORT_AVGRANSARE = ['semikolon' => ['Semikolon (;)', ';'], 'komma' => ['Komma (,)', ','], 'tab' => ['Tab', "\t"]];
const PRJ_EXPORT_KODNING = ['utf8bom' => 'UTF-8 med BOM (Excel)', 'utf8' => 'UTF-8 utan BOM', 'cp1252' => 'Windows-1252'];
const PRJ_EXPORT_URVAL = [
    'alla'           => 'Alla mätpunkter',
    'provisionerade' => 'Mätpunkter med kopplad mätare',
    'monterade'      => 'Monterade mätpunkter',
];

/** Källor för kolumner: nyckel => [etikett, grupp]. Nycklar och fast värde hanteras separat. */
const PRJ_EXPORT_KALLOR = [
    'matpunktsnamn'    => ['Mätpunktsnamn', 'Mätpunkt'],
    'objektnummer'     => ['Objektnummer', 'Mätpunkt'],
    'byggnadsnummer'   => ['Byggnadsnummer', 'Mätpunkt'],
    'fastighetsnummer' => ['Fastighetsnummer', 'Mätpunkt'],
    'gatuadress'       => ['Gatuadress (objektets)', 'Mätpunkt'],
    'funktion'         => ['Funktion (t.ex. Temp)', 'Mätpunkt'],
    'storheter'        => ['Storheter (mätpunktens)', 'Mätpunkt'],
    'platsbeskrivning' => ['Platsbeskrivning', 'Mätpunkt'],
    'monterad_datum'   => ['Monteringsdatum', 'Mätpunkt'],
    'monterad_av'      => ['Monterad av', 'Mätpunkt'],
    'matar_id'         => ['MätarId', 'Mätare'],
    'fabrikat'         => ['Fabrikat', 'Mätare'],
    'modell'           => ['Modell', 'Mätare'],
    'matartyp'         => ['Mätartyp', 'Mätare'],
    'kommunikation'    => ['Kommunikation', 'Mätare'],
    'matare_storheter' => ['Storheter (mätarens)', 'Mätare'],
    'gateway'          => ['Gateway', 'Mätare'],
    'inkop'            => ['Inköp', 'Mätare'],
    'matare_kommentar' => ['Kommentar (mätarens)', 'Mätare'],
    'projektnummer'    => ['Projektnummer', 'Projekt'],
    'projektnamn'      => ['Projektnamn', 'Projekt'],
];

/** Den färdiga mallen för MätarKollens import "Mätpunkter och mätare". */
const PRJ_EXPORT_MAT_MALL = [
    'systemnyckel' => 'mat',
    'namn'         => 'MätarKollen – Mätpunkter och mätare',
    'beskrivning'  => 'Importfilen till MätarKollen (Import → Mätpunkter och mätare). Monterade mätpunkter, 100 rader per fil, med nycklar.',
    'avgransare'   => 'semikolon', 'kodning' => 'utf8bom', 'rubrikrad' => 1, 'max_rader' => 100,
    'urval'        => 'monterade', 'med_nycklar' => 1, 'last' => 1,
    'kolumner'     => [
        ['matpunktsnamn', 'matpunktsnamn'],
        ['objektnummer', 'objektnummer'],
        ['platsbeskrivning', 'platsbeskrivning'],
        ['typ', 'fast', 'fysisk'],
        ['status', 'fast', 'drifttagen'],
        ['storheter', 'storheter'],
        ['matar_id', 'matar_id'],
        ['gateway', 'gateway'],
        ['matartyp', 'matartyp'],
        ['fabrikat', 'fabrikat'],
        ['modell', 'modell'],
        ['kommunikation', 'kommunikation'],
        ['kommentar', 'matare_kommentar'],
        ['monteringsdatum', 'monterad_datum'],
        ['nyckel:DevEUI', 'nyckel:DevEUI'],
        ['nyckel:JoinEUI/AppEUI', 'nyckel:JoinEUI/AppEUI'],
        ['nyckel:AppKey', 'nyckel:AppKey'],
    ],
];

/* ---------- Installation ---------- */

/** Skapar tabellerna (anropas av install.php). */
function prj_export_installera(array &$logg): void
{
    foreach (PRJ_EXPORT_TABELLER as $namn => $def) {
        kc_inst_tabell($namn, $def, $logg);
    }
    kc_inst_sys_tabell('sys_exportlogg', "
      id            BIGINT       NOT NULL AUTO_INCREMENT PRIMARY KEY,
      tid           DATETIME(6)  NOT NULL,
      projekt_uuid  CHAR(36)     NOT NULL DEFAULT '',
      mall_uuid     CHAR(36)     NOT NULL DEFAULT '',
      omgang_uuid   CHAR(36)     NOT NULL DEFAULT '',
      del_nr        INT          NOT NULL DEFAULT 0,
      antal         INT          NOT NULL DEFAULT 0,
      med_nycklar   TINYINT(1)   NOT NULL DEFAULT 0,
      anvandare     VARCHAR(100) NOT NULL DEFAULT '',
      KEY omgang (omgang_uuid)", $logg);
}

/** Lägger in den färdiga MätarKollen-mallen om den saknas (anropas i transaktion av install.php). */
function prj_export_seed(array &$logg): void
{
    $tm = kc_tabell('md_exportmall');
    if (kc_hamta_varde("SELECT COUNT(*) FROM `$tm` WHERE systemnyckel = ? AND is_deleted = 0", [PRJ_EXPORT_MAT_MALL['systemnyckel']]) > 0) {
        return;
    }
    $m = PRJ_EXPORT_MAT_MALL;
    $u = prj_export_mall_spara(null, $m, array_map(static fn($k) => ['rubrik' => $k[0], 'kalla' => $k[1], 'fast_varde' => $k[2] ?? ''], $m['kolumner']));
    kc_kor("UPDATE `$tm` SET last = 1, systemnyckel = ? WHERE uuid = ?", [$m['systemnyckel'], $u]);
    $logg[] = 'Exportmallen "' . $m['namn'] . '" har lagts in.';
}

/* ---------- Mallar ---------- */

/** Mallen, eller null. $aven_borttagen = true används vid nedladdning av äldre exporter. */
function prj_export_mall(string $uuid, bool $aven_borttagen = false): ?array
{
    if (!kc_ar_uuid($uuid)) {
        return null;
    }
    return kc_hamta_en('SELECT * FROM `' . kc_tabell('md_exportmall') . '` WHERE uuid = ?' . ($aven_borttagen ? '' : ' AND is_deleted = 0'), [$uuid]);
}

function prj_export_mallar(): array
{
    return kc_hamta_alla('SELECT * FROM `' . kc_tabell('md_exportmall') . '` WHERE is_deleted = 0 ORDER BY last DESC, namn');
}

function prj_export_kolumner(string $mall_uuid): array
{
    return kc_hamta_alla('SELECT * FROM `' . kc_tabell('md_exportkolumn') . '` WHERE mall_uuid = ? AND is_deleted = 0 ORDER BY ordning, id', [$mall_uuid]);
}

/** Är källan giltig? Kända fält, "fast" eller nyckel:<typ>. */
function prj_export_kalla_giltig(string $kalla): bool
{
    return isset(PRJ_EXPORT_KALLOR[$kalla]) || $kalla === 'fast'
        || (str_starts_with($kalla, 'nyckel:') && trim(mb_substr($kalla, 7)) !== '');
}

/** Etikett för en källa. */
function prj_export_kalla_etikett(string $kalla, string $fast = ''): string
{
    if ($kalla === 'fast') {
        return 'Fast värde: "' . $fast . '"';
    }
    if (str_starts_with($kalla, 'nyckel:')) {
        return 'Nyckel: ' . mb_substr($kalla, 7);
    }
    return PRJ_EXPORT_KALLOR[$kalla][0] ?? $kalla;
}

/** Valideringsfel för mallens inställningar och kolumner. */
function prj_export_mall_fel(array $m, array $kolumner, ?string $uuid): array
{
    $fel = [];
    $namn = trim((string)($m['namn'] ?? ''));
    if ($namn === '' || mb_strlen($namn) > 100) {
        $fel[] = 'Namn krävs (max 100 tecken).';
    } elseif (kc_hamta_varde('SELECT COUNT(*) FROM `' . kc_tabell('md_exportmall') . '` WHERE LOWER(namn) = LOWER(?) AND uuid <> ? AND is_deleted = 0',
        [$namn, $uuid ?? '']) > 0) {
        $fel[] = 'Det finns redan en mall med det namnet.';
    }
    if (mb_strlen((string)($m['beskrivning'] ?? '')) > 255) {
        $fel[] = 'Beskrivningen får vara max 255 tecken.';
    }
    if (!isset(PRJ_EXPORT_AVGRANSARE[$m['avgransare'] ?? ''])) {
        $fel[] = 'Välj avgränsare.';
    }
    if (!isset(PRJ_EXPORT_KODNING[$m['kodning'] ?? ''])) {
        $fel[] = 'Välj teckenkodning.';
    }
    if (!isset(PRJ_EXPORT_URVAL[$m['urval'] ?? ''])) {
        $fel[] = 'Välj urval.';
    }
    $max = trim((string)($m['max_rader'] ?? '0'));
    if (!preg_match('/^\d{1,6}$/', $max) || (int)$max > 100000) {
        $fel[] = 'Max rader per fil ska vara ett heltal 0–100 000 (0 = ingen delning).';
    }
    if ($kolumner === []) {
        $fel[] = 'Mallen behöver minst en kolumn.';
    }
    $rubriker = [];
    foreach ($kolumner as $i => $k) {
        $nr = $i + 1;
        $rub = trim((string)$k['rubrik']);
        if ($rub === '' || mb_strlen($rub) > 100) {
            $fel[] = "Kolumn $nr: rubrik krävs (max 100 tecken).";
        } elseif (isset($rubriker[mb_strtolower($rub)])) {
            $fel[] = "Kolumn $nr: rubriken \"$rub\" finns redan.";
        }
        $rubriker[mb_strtolower($rub)] = true;
        if (!prj_export_kalla_giltig((string)$k['kalla'])) {
            $fel[] = "Kolumn $nr: välj källa.";
        }
        if (str_starts_with((string)$k['kalla'], 'nyckel:') && empty($m['med_nycklar'])) {
            $fel[] = "Kolumn $nr: nycklar kräver att \"Nycklar får ingå\" är ikryssat.";
        }
        if (mb_strlen((string)($k['fast_varde'] ?? '')) > 255) {
            $fel[] = "Kolumn $nr: fast värde får vara max 255 tecken.";
        }
    }
    return $fel;
}

/**
 * Sparar en mall och dess kolumner (ny om $uuid är null). Kolumnerna ersätts helt (gamla raderas mjukt).
 * Anropas i transaktion. Returnerar mallens uuid.
 */
function prj_export_mall_spara(?string $uuid, array $m, array $kolumner): string
{
    $tm = kc_tabell('md_exportmall');
    $tk = kc_tabell('md_exportkolumn');
    $nu = kc_nu();
    $falt = [
        'namn' => trim((string)$m['namn']), 'beskrivning' => trim((string)($m['beskrivning'] ?? '')),
        'avgransare' => $m['avgransare'], 'kodning' => $m['kodning'], 'rubrikrad' => !empty($m['rubrikrad']) ? 1 : 0,
        'max_rader' => (int)$m['max_rader'], 'urval' => $m['urval'], 'med_nycklar' => !empty($m['med_nycklar']) ? 1 : 0,
    ];
    if ($uuid === null) {
        $uuid = kc_uuid();
        kc_kor("INSERT INTO `$tm` (uuid, last_changed, is_deleted, source_tool, `" . implode('`, `', array_keys($falt)) . "`)
                VALUES (?, ?, 0, 'prj'" . str_repeat(', ?', count($falt)) . ')', [$uuid, $nu, ...array_values($falt)]);
    } else {
        $bef = prj_export_mall($uuid) ?? throw new KcFel('Mallen finns inte.', 404);
        if ((int)$bef['last'] === 1) {
            throw new KcFel('Mallen är låst. Kopiera den till en egen mall för att ändra.', 409);
        }
        $set = implode(', ', array_map(static fn($k) => "`$k` = ?", array_keys($falt)));
        kc_kor("UPDATE `$tm` SET $set, last_changed = ?, source_tool = 'prj' WHERE uuid = ?", [...array_values($falt), $nu, $uuid]);
        kc_kor("UPDATE `$tk` SET is_deleted = 1, last_changed = ?, source_tool = 'prj' WHERE mall_uuid = ? AND is_deleted = 0", [$nu, $uuid]);
    }
    foreach (array_values($kolumner) as $i => $k) {
        kc_kor("INSERT INTO `$tk` (uuid, last_changed, is_deleted, source_tool, mall_uuid, ordning, rubrik, kalla, fast_varde)
                VALUES (?, ?, 0, 'prj', ?, ?, ?, ?, ?)",
            [kc_uuid(), $nu, $uuid, ($i + 1) * 10, trim((string)$k['rubrik']), (string)$k['kalla'], $k['kalla'] === 'fast' ? (string)($k['fast_varde'] ?? '') : '']);
    }
    return $uuid;
}

/** Tar bort en egen mall (mjukt) med dess kolumner. Exporthistoriken finns kvar. I transaktion. */
function prj_export_mall_radera(array $mall): void
{
    if ((int)$mall['last'] === 1) {
        throw new KcFel('En låst mall kan inte tas bort.', 409);
    }
    $nu = kc_nu();
    kc_kor('UPDATE `' . kc_tabell('md_exportkolumn') . "` SET is_deleted = 1, last_changed = ?, source_tool = 'prj' WHERE mall_uuid = ? AND is_deleted = 0", [$nu, $mall['uuid']]);
    kc_kor('UPDATE `' . kc_tabell('md_exportmall') . "` SET is_deleted = 1, last_changed = ?, source_tool = 'prj' WHERE uuid = ?", [$nu, $mall['uuid']]);
}

/* ---------- Omgångar ---------- */

/** Urvalets villkor på mätpunkt m och mätare a (LEFT JOIN). */
function prj_export_urval_sql(string $urval): string
{
    return match ($urval) {
        'provisionerade' => ' AND a.uuid IS NOT NULL',
        'monterade'      => ' AND a.uuid IS NOT NULL AND m.monterad_datum IS NOT NULL',
        default          => '',
    };
}

/** Antal mätpunkter i projektet som matchar mallens urval: [alla, ej exporterade med mallen]. */
function prj_export_antal(array $mall, string $projekt_uuid): array
{
    $tm = kc_tabell('md_matpunkt');
    $ta = kc_tabell('md_matare');
    $tr = kc_tabell('md_exportrad');
    $bas = "FROM `$tm` m LEFT JOIN `$ta` a ON a.matpunkt_uuid = m.uuid AND a.is_deleted = 0
            WHERE m.projekt_uuid = ? AND m.is_deleted = 0" . prj_export_urval_sql($mall['urval']);
    return [
        (int)kc_hamta_varde("SELECT COUNT(*) $bas", [$projekt_uuid]),
        (int)kc_hamta_varde("SELECT COUNT(*) $bas AND NOT EXISTS (SELECT 1 FROM `$tr` r WHERE r.mall_uuid = ? AND r.matpunkt_uuid = m.uuid AND r.is_deleted = 0)",
            [$projekt_uuid, $mall['uuid']]),
    ];
}

/**
 * Skapar en exportomgång: matchande mätpunkter (förval: bara ej exporterade med mallen) delas i delar
 * om max_rader i namnordning. I transaktion. Returnerar ['omgang_uuid', 'antal', 'delar'].
 */
function prj_export_skapa_omgang(array $mall, array $projekt, bool $aven_tidigare, string $anvandare): array
{
    $tm = kc_tabell('md_matpunkt');
    $ta = kc_tabell('md_matare');
    $tr = kc_tabell('md_exportrad');
    $sql = "SELECT m.uuid FROM `$tm` m LEFT JOIN `$ta` a ON a.matpunkt_uuid = m.uuid AND a.is_deleted = 0
             WHERE m.projekt_uuid = ? AND m.is_deleted = 0" . prj_export_urval_sql($mall['urval']);
    $params = [$projekt['uuid']];
    if (!$aven_tidigare) {
        $sql .= " AND NOT EXISTS (SELECT 1 FROM `$tr` r WHERE r.mall_uuid = ? AND r.matpunkt_uuid = m.uuid AND r.is_deleted = 0)";
        $params[] = $mall['uuid'];
    }
    $uuids = array_column(kc_hamta_alla($sql . ' ORDER BY m.matpunktsnamn', $params), 'uuid');
    if ($uuids === []) {
        throw new KcFel('Inga mätpunkter att exportera med det här urvalet.', 409);
    }
    $per = (int)$mall['max_rader'] > 0 ? (int)$mall['max_rader'] : count($uuids);
    $delar = (int)ceil(count($uuids) / $per);
    $omgang = kc_uuid();
    $nu = kc_nu();
    foreach ($uuids as $i => $mp) {
        kc_kor("INSERT INTO `$tr` (uuid, last_changed, is_deleted, source_tool, mall_uuid, projekt_uuid, matpunkt_uuid,
                                   omgang_uuid, omgang_tid, del_nr, antal_delar, exporterad_av)
                VALUES (?, ?, 0, 'prj', ?, ?, ?, ?, ?, ?, ?, ?)",
            [kc_uuid(), $nu, $mall['uuid'], $projekt['uuid'], $mp, $omgang, $nu, intdiv($i, $per) + 1, $delar, mb_substr($anvandare, 0, 100)]);
    }
    prj_matpunkter_las($projekt['uuid'], $uuids);
    return ['omgang_uuid' => $omgang, 'antal' => count($uuids), 'delar' => $delar];
}

/** Projektets exportomgångar (nyast först) med mallnamn och antal per del. */
function prj_export_omgangar(string $projekt_uuid): array
{
    $tr = kc_tabell('md_exportrad');
    $tm = kc_tabell('md_exportmall');
    $rader = kc_hamta_alla("SELECT r.omgang_uuid, r.mall_uuid, MIN(r.omgang_tid) AS omgang_tid, MIN(r.exporterad_av) AS exporterad_av,
                                   r.del_nr, MAX(r.antal_delar) AS antal_delar, COUNT(*) AS antal,
                                   COALESCE(MIN(x.namn), '(borttagen mall)') AS mallnamn
                              FROM `$tr` r LEFT JOIN `$tm` x ON x.uuid = r.mall_uuid
                             WHERE r.projekt_uuid = ? AND r.is_deleted = 0
                             GROUP BY r.omgang_uuid, r.mall_uuid, r.del_nr
                             ORDER BY omgang_tid DESC, r.omgang_uuid, r.del_nr", [$projekt_uuid]);
    $ut = [];
    foreach ($rader as $r) {
        $o = $r['omgang_uuid'];
        $ut[$o] ??= ['omgang_uuid' => $o, 'mall_uuid' => $r['mall_uuid'], 'mallnamn' => $r['mallnamn'],
                     'omgang_tid' => $r['omgang_tid'], 'exporterad_av' => $r['exporterad_av'], 'antal' => 0, 'delar' => []];
        $ut[$o]['antal'] += (int)$r['antal'];
        $ut[$o]['delar'][(int)$r['del_nr']] = ['antal' => (int)$r['antal'], 'senast' => null];
    }
    if ($ut !== []) {
        $tl = kc_tabell('sys_exportlogg');
        $o_lista = array_keys($ut);
        foreach (kc_hamta_alla("SELECT omgang_uuid, del_nr, MAX(tid) AS tid FROM `$tl`
                                 WHERE omgang_uuid IN (" . implode(',', array_fill(0, count($o_lista), '?')) . ')
                                 GROUP BY omgang_uuid, del_nr', $o_lista) as $l) {
            // del 0 = "Ladda ner alla" och räknas för varje del
            foreach ($ut[$l['omgang_uuid']]['delar'] ?? [] as $d => $info) {
                if ((int)$l['del_nr'] === 0 || (int)$l['del_nr'] === $d) {
                    $ut[$l['omgang_uuid']]['delar'][$d]['senast'] = max((string)$info['senast'], (string)$l['tid']);
                }
            }
        }
    }
    return array_values($ut);
}

/** Ångrar en exportomgång (raderna räknas som ej exporterade igen). I transaktion. */
function prj_export_angra_omgang(string $projekt_uuid, string $omgang_uuid): int
{
    return kc_kor('UPDATE `' . kc_tabell('md_exportrad') . "` SET is_deleted = 1, last_changed = ?, source_tool = 'prj'
                    WHERE projekt_uuid = ? AND omgang_uuid = ? AND is_deleted = 0", [kc_nu(), $projekt_uuid, $omgang_uuid]);
}

/* ---------- Filer ---------- */

/** Rader (mätpunkt + mätare + projekt) för en del av en omgång (del 0 = alla delar). */
function prj_export_rader(string $projekt_uuid, string $omgang_uuid, int $del): array
{
    $tr = kc_tabell('md_exportrad');
    $tm = kc_tabell('md_matpunkt');
    $ta = kc_tabell('md_matare');
    $tp = kc_tabell('md_projekt');
    $to = kc_tabell('ld_fas_objekt');
    $tb = kc_tabell('ld_fas_byggnad');
    $tf = kc_tabell('ld_fas_fastighet');
    $tad = kc_tabell('ld_fas_adress');
    return kc_hamta_alla("SELECT r.del_nr, m.matpunktsnamn, m.objektnummer, COALESCE(b.byggnadsnummer, '') AS byggnadsnummer,
                                 COALESCE(f.fastighetsnummer, '') AS fastighetsnummer, COALESCE(ad.gatuadress, '') AS gatuadress,
                                 m.funktion, m.storheter, m.platsbeskrivning, COALESCE(m.monterad_datum, '') AS monterad_datum, m.monterad_av,
                                 COALESCE(a.uuid, '') AS matare_uuid, COALESCE(a.matar_id, '') AS matar_id, COALESCE(a.fabrikat, '') AS fabrikat,
                                 COALESCE(a.modell, '') AS modell, COALESCE(a.matartyp, '') AS matartyp,
                                 COALESCE(a.kommunikation, '') AS kommunikation, COALESCE(a.storheter, '') AS matare_storheter,
                                 COALESCE(a.gateway, '') AS gateway, COALESCE(a.inkop, '') AS inkop, COALESCE(a.kommentar, '') AS matare_kommentar,
                                 p.projektnummer, p.namn AS projektnamn
                            FROM `$tr` r
                            JOIN `$tm` m ON m.uuid = r.matpunkt_uuid
                            JOIN `$tp` p ON p.uuid = r.projekt_uuid
                            LEFT JOIN `$ta` a ON a.matpunkt_uuid = m.uuid AND a.is_deleted = 0
                            LEFT JOIN `$to` o ON o.uuid = m.objekt_uuid
                            LEFT JOIN `$tb` b ON b.uuid = o.byggnad_uuid
                            LEFT JOIN `$tf` f ON f.uuid = b.fastighet_uuid
                            LEFT JOIN `$tad` ad ON ad.uuid = o.adress_uuid
                           WHERE r.projekt_uuid = ? AND r.omgang_uuid = ? AND r.is_deleted = 0" . ($del > 0 ? ' AND r.del_nr = ?' : '') . '
                           ORDER BY r.del_nr, m.matpunktsnamn',
        $del > 0 ? [$projekt_uuid, $omgang_uuid, $del] : [$projekt_uuid, $omgang_uuid]);
}

/** Bygger CSV-innehållet för mallen och raderna. Nycklar hämtas bara om mallen tillåter dem. */
function prj_export_csv(array $mall, array $kolumner, array $rader): string
{
    $avg = PRJ_EXPORT_AVGRANSARE[$mall['avgransare']][1] ?? ';';
    $behover_nycklar = (int)$mall['med_nycklar'] === 1
        && array_filter($kolumner, static fn($k) => str_starts_with($k['kalla'], 'nyckel:')) !== [];
    $fh = fopen('php://memory', 'w+b');
    if ((int)$mall['rubrikrad'] === 1) {
        fputcsv($fh, array_map(static fn($k) => kc_lista_csv_varde($k['rubrik']), $kolumner), $avg, '"', '');
    }
    foreach ($rader as $r) {
        $nycklar = [];
        if ($behover_nycklar && $r['matare_uuid'] !== '') {
            foreach (prj_nycklar($r['matare_uuid']) as $typ => $n) {
                $nycklar[mb_strtolower($typ)] = $n['varde'];
            }
        }
        $ut = [];
        foreach ($kolumner as $k) {
            $kalla = $k['kalla'];
            $v = match (true) {
                $kalla === 'fast'                  => $k['fast_varde'],
                str_starts_with($kalla, 'nyckel:') => (int)$mall['med_nycklar'] === 1 ? ($nycklar[mb_strtolower(mb_substr($kalla, 7))] ?? '') : '',
                default                            => (string)($r[$kalla] ?? ''),
            };
            $ut[] = kc_lista_csv_varde($v);
        }
        fputcsv($fh, $ut, $avg, '"', '');
    }
    rewind($fh);
    $csv = (string)stream_get_contents($fh);
    fclose($fh);
    return match ($mall['kodning']) {
        'utf8'   => $csv,
        'cp1252' => (string)mb_convert_encoding($csv, 'Windows-1252', 'UTF-8'),
        default  => "\xEF\xBB\xBF" . $csv,
    };
}

/** Filnamn: <projektnummer>_<mall>_<omgångstid>[_del<n>av<m>].csv */
function prj_export_filnamn(array $projekt, array $mall, string $omgang_tid, int $del, int $delar, string $andelse = 'csv'): string
{
    $mallnamn = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '_',
        strtr($mall['namn'], ['å' => 'a', 'ä' => 'a', 'ö' => 'o', 'Å' => 'A', 'Ä' => 'A', 'Ö' => 'O', '–' => '-'])), '_'));
    $tid = (new DateTimeImmutable($omgang_tid, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(KOLL_TIDSZON))->format('Ymd-Hi');
    $bas = preg_replace('/[^A-Za-z0-9_-]/', '', $projekt['projektnummer']) . '_' . substr($mallnamn, 0, 40) . '_' . $tid;
    return $bas . ($del > 0 && $delar > 1 ? "_del{$del}av{$delar}" : '') . '.' . $andelse;
}

/** Loggar en nedladdning. */
function prj_export_logga(array $projekt, array $mall, string $omgang_uuid, int $del, int $antal, string $anvandare): void
{
    kc_kor('INSERT INTO `' . kc_tabell('sys_exportlogg') . '` (tid, projekt_uuid, mall_uuid, omgang_uuid, del_nr, antal, med_nycklar, anvandare)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [kc_nu(), $projekt['uuid'], $mall['uuid'], $omgang_uuid, $del, $antal, (int)$mall['med_nycklar'], mb_substr($anvandare, 0, 100)]);
}
