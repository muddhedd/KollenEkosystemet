<?php
/**
 * ProjektKollen – prj_import.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Importdefinitioner för kärnpaketets import (kc_import_kor). Inkluderas av import.php efter prj.php.
 *
 * "Inköpta mätare" (max 1 000 rader): en rad = en mätare i det gemensamma lagret, med nycklar.
 *  - Matchas på mätarId (normaliserat: LoRaWAN-identitet = 16 hextecken med versaler). Ingen uuid-kolumn;
 *    uuid sätts automatiskt.
 *  - MätarId är unikt i lagret. Samma mätarId i MätarKollen ger en varning, inte fel.
 *  - Fabrikat, modell, mätartyp, kommunikation och storheter lagras som text (MätarKollen äger listorna
 *    och skapar typerna när mätarna importeras där).
 *  - Nycklar: kolumner nyckel:<typ>. Kända typer (DevEUI, JoinEUI/AppEUI, AppKey, AES-nyckel) får
 *    MätarKollens stavning; okända tas emot och flaggas. Nycklar exporteras aldrig härifrån och
 *    förhandsvisningen visar bara vilka nycklar som ändras, aldrig värdena. En borttagen nyckel ("(tom)")
 *    töms på värdet.
 *  - Projekt och mätpunkt sätts inte via import (provisionering, etapp 5).
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

const PRJ_IMPORT_TABELLER = [
    'matare' => ['rubrik' => 'Inköpta mätare', 'tillbaka' => 'matarlista.php'],
];

/** Mallens kolumner (utöver nyckel:<typ>). */
const PRJ_IMPORT_MATARKOLUMNER = [
    'matar_id', 'fabrikat', 'modell', 'matartyp', 'kommunikation', 'storheter', 'gateway', 'inkop', 'kommentar',
];

/** Textfält och maxlängd. */
const PRJ_IMPORT_MATARFALT = [
    'fabrikat' => 100, 'modell' => 100, 'matartyp' => 100, 'kommunikation' => 100,
    'storheter' => 255, 'gateway' => 100, 'inkop' => 100, 'kommentar' => 255,
];

function prj_import_def(string $tabell): array
{
    $grund = PRJ_IMPORT_TABELLER[$tabell] ?? throw new KcFel('Okänd import.', 404);
    return match ($tabell) {
        'matare' => prj_import_def_matare() + $grund + ['tabell' => $tabell],
    };
}

function prj_import_def_matare(): array
{
    return [
        'kolumner'   => PRJ_IMPORT_MATARKOLUMNER,
        'kravs'      => ['matar_id'],
        'extra'      => '/^nyckel:.+$/u',
        'mall_extra' => static fn(): array => ['nyckel:DevEUI', 'nyckel:JoinEUI/AppEUI', 'nyckel:AppKey'],
        'hjalp'      => '<ul>'
            . '<li><strong>matar_id</strong> (krävs): det mätaren skickar. För LoRaWAN är det DevEUI – 16 hextecken; '
            . 'blanksteg, kolon och bindestreck tas bort och bokstäverna blir versaler. Unikt i lagret.</li>'
            . '<li><strong>fabrikat, modell, matartyp, kommunikation</strong>: text, stavade som i MätarKollen (där skapas typerna vid import).</li>'
            . '<li><strong>storheter</strong>: kommaseparerade, t.ex. <code>Temperatur, Relativ fuktighet</code>.</li>'
            . '<li><strong>gateway, inkop</strong> (t.ex. ordernummer eller leverans), <strong>kommentar</strong>: fritext.</li>'
            . '<li><strong>nyckel:&lt;typ&gt;</strong>: t.ex. <code>nyckel:DevEUI</code>, <code>nyckel:JoinEUI/AppEUI</code>, '
            . '<code>nyckel:AppKey</code>, <code>nyckel:AES-nyckel (wM-Bus)</code>. Visas aldrig i förhandsvisningen och exporteras aldrig '
            . '("Exportera för import" har tomma nyckelkolumner – en tom cell ändrar inget).</li>'
            . '<li>Projekt och mätpunkt sätts vid provisionering, inte här. Ingen uuid-kolumn behövs.</li>'
            . '</ul>',
        'forbered'   => 'prj_import_matare_forbered',
        'planera'    => 'prj_import_matare_planera',
        'export'     => 'prj_import_matare_export',
    ];
}

/** Nyckelkolumner: [rubrik => nyckeltyp]. Okända typer flaggas. */
function prj_import_matare_forbered(array $rubriker): array
{
    $nycklar = [];
    $varningar = [];
    $fel = [];
    foreach ($rubriker as $r) {
        if (!str_starts_with($r, 'nyckel:')) {
            continue;
        }
        $typ = trim(mb_substr($r, 7));
        if ($typ === '' || mb_strlen($typ) > 100) {
            $fel[] = "Ogiltig nyckelkolumn: $r.";
            continue;
        }
        [$namn, $kand] = prj_nyckeltyp_fran_rubrik($typ);
        if (in_array($namn, $nycklar, true)) {
            $fel[] = "Nyckeltypen $namn finns i flera kolumner.";
            continue;
        }
        $nycklar[$r] = $namn;
        if (!$kand) {
            $varningar[] = "Okänd nyckeltyp \"$namn\" tas emot som den är skriven (kontrollera stavningen mot MätarKollen).";
        }
    }
    return ['fel' => $fel, 'varningar' => $varningar, 'ctx' => ['nycklar' => $nycklar, 'sedda' => []]];
}

/** Planerar en rad. */
function prj_import_matare_planera(array $rad, string $lage, array &$ctx): array
{
    $cell_id = kc_import_cell($rad, 'matar_id');
    if ($cell_id === null || $cell_id === KC_IMPORT_TOMT) {
        return ['status' => 'fel', 'nyckel' => '', 'text' => 'MätarId saknas.', 'utfor' => null];
    }
    $id = prj_matar_id_normalisera($cell_id);
    if (($f = prj_matar_id_fel($id)) !== null) {
        return ['status' => 'fel', 'nyckel' => $cell_id, 'text' => $f, 'utfor' => null];
    }
    $nyckel_dubb = mb_strtolower($id);
    if (isset($ctx['sedda'][$nyckel_dubb])) {
        return ['status' => 'fel', 'nyckel' => $id, 'text' => 'Samma mätarId finns redan på rad ' . $ctx['sedda'][$nyckel_dubb] . ' i filen.', 'utfor' => null];
    }
    $ctx['sedda'][$nyckel_dubb] = $rad['_rad'];

    // Fält
    $fel = [];
    $nya = [];
    foreach (PRJ_IMPORT_MATARFALT as $k => $max) {
        $v = kc_import_cell($rad, $k);
        if ($v !== null && $v !== KC_IMPORT_TOMT) {
            if ($k === 'storheter') {
                $v = prj_storheter_normalisera($v);
            }
            if (mb_strlen($v) > $max) {
                $fel[] = "$k får vara max $max tecken.";
            }
        }
        $nya[$k] = $v;
    }
    $nya_nycklar = [];
    foreach ($ctx['nycklar'] as $rubrik => $typ) {
        $v = kc_import_cell($rad, $rubrik);
        if ($v !== null && $v !== KC_IMPORT_TOMT) {
            $v = trim($v);
            if (mb_strlen($v) > 255) {
                $fel[] = "Nyckeln $typ får vara max 255 tecken.";
            }
        }
        $nya_nycklar[$typ] = $v;
    }
    if ($fel !== []) {
        return ['status' => 'fel', 'nyckel' => $id, 'text' => implode(' ', $fel), 'utfor' => null];
    }

    $i_mat = prj_matar_id_i_mat($id);
    $info = $i_mat !== [] ? 'Obs: mätarId finns redan i MätarKollen (' . implode(', ', $i_mat) . ').' : '';
    if ($id !== trim($cell_id)) {
        $info = trim('MätarId normaliserat till ' . $id . '. ' . $info);
    }

    $befintlig = prj_matare_med_id($id);
    if ($befintlig === null) {
        $falt = [];
        foreach ($nya as $k => $v) {
            $falt[$k] = ($v === null || $v === KC_IMPORT_TOMT) ? '' : $v;
        }
        $nyck = array_filter($nya_nycklar, static fn($v) => $v !== null && $v !== KC_IMPORT_TOMT && $v !== '');
        $text = trim(($nyck !== [] ? 'Nycklar: ' . implode(', ', array_keys($nyck)) . '. ' : '') . $info);
        return [
            'status' => 'ny', 'nyckel' => $id, 'text' => $text !== '' ? $text : 'skapas',
            'utfor'  => static function () use ($id, $falt, $nyck): void {
                $u = kc_uuid();
                prj_import_infoga('md_matare', $u, ['matar_id' => $id] + $falt + ['projekt_uuid' => '', 'matpunkt_uuid' => '']);
                foreach ($nyck as $typ => $v) {
                    prj_import_infoga('md_matarnyckel', kc_uuid(), ['matare_uuid' => $u, 'nyckeltyp' => $typ, 'varde' => $v]);
                }
            },
        ];
    }

    // Befintlig mätare
    $s = kc_import_sammanfoga(array_intersect_key($befintlig, $nya), $nya, $lage);
    $bef_nycklar = array_map(static fn($r) => $r['varde'], prj_nycklar($befintlig['uuid']));
    $sn = kc_import_sammanfoga(array_intersect_key($bef_nycklar, $nya_nycklar), $nya_nycklar, $lage);
    $typ = $s['typ'];
    if ($sn['typ'] === 'uppdaterad' || ($sn['typ'] === 'kompletterad' && $typ === null)) {
        $typ = $sn['typ'];
    }
    $texter = [];
    if ($s['falt'] !== []) {
        $texter[] = 'Ändras: ' . implode(', ', $s['falt']) . '.';
    }
    if ($sn['falt'] !== []) {
        $texter[] = 'Nycklar: ' . implode(', ', array_map(static fn($t) => $t . ' ('
            . ($sn['andringar'][$t] === '' ? 'tas bort' : (isset($bef_nycklar[$t]) ? 'ändras' : 'ny')) . ')', $sn['falt'])) . '.';
    }
    if ($info !== '') {
        $texter[] = $info;
    }
    if ($typ === null) {
        return ['status' => 'oforandrad', 'nyckel' => $id, 'text' => implode(' ', $texter), 'utfor' => null];
    }
    $uuid = $befintlig['uuid'];
    $andringar = $s['andringar'];
    $nyckel_andringar = $sn['andringar'];
    return [
        'status' => $typ, 'nyckel' => $id, 'text' => implode(' ', $texter),
        'utfor'  => static function () use ($uuid, $andringar, $nyckel_andringar): void {
            $nu = kc_nu();
            if ($andringar !== []) {
                $set = implode(', ', array_map(static fn($k) => "`$k` = ?", array_keys($andringar)));
                kc_kor('UPDATE `' . kc_tabell('md_matare') . "` SET $set, last_changed = ?, source_tool = 'prj' WHERE uuid = ?",
                    [...array_values($andringar), $nu, $uuid]);
            }
            $tn = kc_tabell('md_matarnyckel');
            foreach ($nyckel_andringar as $typ => $v) {
                if ($v === '') {
                    kc_kor("UPDATE `$tn` SET varde = '', is_deleted = 1, last_changed = ?, source_tool = 'prj' WHERE matare_uuid = ? AND nyckeltyp = ? AND is_deleted = 0",
                        [$nu, $uuid, $typ]);
                    continue;
                }
                $r = kc_hamta_en("SELECT uuid FROM `$tn` WHERE matare_uuid = ? AND nyckeltyp = ? ORDER BY is_deleted ASC, id ASC LIMIT 1", [$uuid, $typ]);
                if ($r !== null) {
                    kc_kor("UPDATE `$tn` SET varde = ?, is_deleted = 0, last_changed = ?, source_tool = 'prj' WHERE uuid = ?", [$v, $nu, $r['uuid']]);
                } else {
                    prj_import_infoga('md_matarnyckel', kc_uuid(), ['matare_uuid' => $uuid, 'nyckeltyp' => $typ, 'varde' => $v]);
                }
            }
        },
    ];
}

/** "Exportera för import": lagret utan nycklar (nyckelkolumnerna blir tomma). */
function prj_import_matare_export(): array
{
    $t = kc_tabell('md_matare');
    return kc_hamta_alla('SELECT ' . implode(', ', array_map(static fn($k) => "`$k`", PRJ_IMPORT_MATARKOLUMNER))
        . " FROM `$t` WHERE is_deleted = 0 ORDER BY matar_id");
}

/** Infogar en ny rad i en md_-tabell. */
function prj_import_infoga(string $md, string $uuid, array $falt): void
{
    $t = kc_tabell($md);
    $kol = array_keys($falt);
    kc_kor(
        "INSERT INTO `$t` (uuid, last_changed, is_deleted, source_tool, `" . implode('`, `', $kol) . "`)
         VALUES (?, ?, 0, 'prj'" . str_repeat(', ?', count($kol)) . ')',
        [$uuid, kc_nu(), ...array_values($falt)]
    );
}
