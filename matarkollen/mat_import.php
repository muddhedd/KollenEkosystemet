<?php
/**
 * MätarKollen – mat_import.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Importdefinitioner för kärnpaketets import (kc_import_kor): filen "Mätpunkter och mätare"
 * och typlistorna. Inkluderas av import.php efter mat.php.
 *
 * Filen "Mätpunkter och mätare" (max 100 rader): en rad = en mätpunkt och/eller en mätare,
 * med nycklar, montering och ställningar. Samma format som ProjektKollen exporterar.
 *  - Mätpunkt matchas på uuid, annars på mätpunktsnamn. Ny mätpunkt kräver placering;
 *    tom betjäning = samma som placering.
 *  - Mätare matchas på mätarnummer, sedan mätarId (+ gateway om angiven) om exakt en,
 *    sedan mätpunktens monterade mätare; annars skapas en ny (mätarnumret sätts vid import).
 *  - Monteringsdatum monterar mätaren i mätpunkten. Har mätpunkten en annan mätare blir det
 *    fel vid mjuk import och byte vid hård import (den gamla demonteras utan ställning).
 *  - Okända typer (mätartyp, fabrikat, modell, kommunikation, storhet, nyckeltyp) läggs till
 *    automatiskt och flaggas i förhandsvisningen. Okänd modell utan fabrikat är ett fel.
 *  - Nycklar (nyckel:<typ>) kan importeras men exporteras aldrig; förhandsvisningen visar bara
 *    vilka nycklar som ändras.
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

const MAT_IMPORT_TABELLER = [
    'matpunkt'      => ['rubrik' => 'Mätpunkter och mätare', 'tillbaka' => 'matpunkter.php', 'max_rader' => 100],
    'storhet'       => ['rubrik' => 'Storheter',      'tillbaka' => 'typer.php?lista=storhet'],
    'matartyp'      => ['rubrik' => 'Mätartyper',     'tillbaka' => 'typer.php?lista=matartyp'],
    'fabrikat'      => ['rubrik' => 'Fabrikat',       'tillbaka' => 'typer.php?lista=fabrikat'],
    'matarmodell'   => ['rubrik' => 'Mätarmodeller',  'tillbaka' => 'typer.php?lista=matarmodell'],
    'kommunikation' => ['rubrik' => 'Kommunikation',  'tillbaka' => 'typer.php?lista=kommunikation'],
    'nyckeltyp'     => ['rubrik' => 'Nyckeltyper',    'tillbaka' => 'typer.php?lista=nyckeltyp'],
];

/** Mallens kolumner för "Mätpunkter och mätare" (utöver nyckel:, stallning:, egenskap:, egenskap_matare:). */
const MAT_IMPORT_KOLUMNER = [
    'uuid', 'matpunktsnamn',
    'objektnummer', 'byggnadsnummer', 'fastighetsnummer', 'objektgruppsnummer',
    'betjanar_objektnummer', 'betjanar_byggnadsnummer', 'betjanar_fastighetsnummer', 'betjanar_objektgruppsnummer',
    'platsbeskrivning', 'typ', 'status', 'storheter', 'foregaende',
    'matarnummer', 'matar_id', 'gateway', 'matartyp', 'fabrikat', 'modell', 'kommunikation', 'kommentar',
    'monteringsdatum',
];

/** Kolumn per nivå för placering och betjäning. */
const MAT_IMPORT_NIVAKOLUMN = [
    'objekt' => 'objektnummer', 'byggnad' => 'byggnadsnummer',
    'fastighet' => 'fastighetsnummer', 'objektgrupp' => 'objektgruppsnummer',
];

/** Kolumner som betyder att raden innehåller en mätare. */
const MAT_IMPORT_MATARKOLUMNER = ['matarnummer', 'matar_id', 'gateway', 'matartyp', 'fabrikat', 'modell', 'kommunikation', 'kommentar', 'monteringsdatum'];

/* ---------- Gemensamma hjälpare ---------- */

/** Resultat i kärnans format. $delar = [['typ' => kompletterad|uppdaterad|null, 'text' => ...]]. */
function mat_import_resultat(string $nyckel, bool $ny, array $fel, array $delar, ?callable $utfor): array
{
    if ($fel !== []) {
        return ['status' => 'fel', 'nyckel' => $nyckel, 'text' => implode(' ', $fel), 'utfor' => null];
    }
    $texter = array_values(array_filter(array_map(static fn($d) => (string)($d['text'] ?? ''), $delar), static fn($t) => $t !== ''));
    if ($ny) {
        return ['status' => 'ny', 'nyckel' => $nyckel, 'text' => implode(' ', $texter) ?: 'skapas', 'utfor' => $utfor];
    }
    $typ = null;
    foreach ($delar as $d) {
        if (($d['typ'] ?? null) === 'uppdaterad') {
            $typ = 'uppdaterad';
        } elseif (($d['typ'] ?? null) === 'kompletterad') {
            $typ ??= 'kompletterad';
        }
    }
    return $typ === null
        ? ['status' => 'oforandrad', 'nyckel' => $nyckel, 'text' => implode(' ', $texter), 'utfor' => null]
        : ['status' => $typ, 'nyckel' => $nyckel, 'text' => implode(' ', $texter), 'utfor' => $utfor];
}

/** Dubblettkontroll inom filen. Returnerar fel eller null. */
function mat_import_dubblett(array &$ctx, ?string ...$nycklar): ?string
{
    $nycklar = array_values(array_filter($nycklar, static fn($n) => $n !== null && $n !== '' && !str_ends_with($n, ':')));
    foreach ($nycklar as $k) {
        if (isset($ctx['sedda'][$k])) {
            return 'Samma post finns redan på rad ' . $ctx['sedda'][$k] . ' i filen.';
        }
    }
    foreach ($nycklar as $k) {
        $ctx['sedda'][$k] = $ctx['rad'];
    }
    return null;
}

/** Text med maxlängd: [värde|TOMT|null, fel|null]. */
function mat_import_text(?string $cell, int $max, string $etikett): array
{
    if ($cell !== null && $cell !== KC_IMPORT_TOMT && mb_strlen($cell) > $max) {
        return [null, "$etikett får vara max $max tecken."];
    }
    return [$cell, null];
}

/** Infogar en ny rad i en md_-tabell. */
function mat_import_infoga(string $md, string $uuid, array $falt): void
{
    $t = kc_tabell($md);
    $kol = array_keys($falt);
    kc_kor(
        "INSERT INTO `$t` (uuid, last_changed, is_deleted, source_tool"
        . ($kol !== [] ? ', `' . implode('`, `', $kol) . '`' : '') . ")
         VALUES (?, ?, 0, 'mat'" . str_repeat(', ?', count($kol)) . ')',
        array_merge([$uuid, kc_nu()], array_values($falt))
    );
}

/** Uppdaterar fält i en befintlig rad. */
function mat_import_uppdatera(string $md, string $uuid, array $falt): void
{
    if ($falt === []) {
        return;
    }
    $t = kc_tabell($md);
    $set = implode(', ', array_map(static fn($k) => "`$k` = ?", array_keys($falt)));
    kc_kor("UPDATE `$t` SET $set, last_changed = ?, source_tool = 'mat' WHERE uuid = ?",
           array_merge(array_values($falt), [kc_nu(), $uuid]));
}

/** Typlista som karta: [namn med gemener => rad]. För modeller: [fabrikat_uuid|namn => rad]. */
function mat_import_typkarta(string $lista): array
{
    $t = kc_tabell(mat_typlista($lista)['tabell']);
    $k = [];
    foreach (kc_hamta_alla("SELECT * FROM `$t` WHERE is_deleted = 0 ORDER BY id") as $r) {
        $nyckel = $lista === 'matarmodell' ? $r['fabrikat_uuid'] . '|' . mb_strtolower($r['namn']) : mb_strtolower($r['namn']);
        $k[$nyckel] ??= $r;
    }
    return $k;
}

/**
 * Typnamn → uuid. Okänd typ planeras som ny (uuid i ctx) och läggs till i $nya (för utfor och text).
 * Returnerar [uuid|TOMT|null, fel|null].
 */
function mat_import_typ(array &$ctx, string $lista, ?string $cell, string $nuvarande, array &$nya, string $fabrikat_uuid = ''): array
{
    if ($cell === null || $cell === KC_IMPORT_TOMT) {
        return [$cell, null];
    }
    $etikett = mat_typlista($lista)['etikett'];
    if (mb_strlen($cell) > 100) {
        return [null, ucfirst($etikett) . ' får vara max 100 tecken.'];
    }
    $nyckel = $lista === 'matarmodell' ? $fabrikat_uuid . '|' . mb_strtolower($cell) : mb_strtolower($cell);
    $t = $ctx['typer'][$lista][$nyckel] ?? null;
    if ($t !== null) {
        if ((int)$t['is_active'] !== 1 && $t['uuid'] !== $nuvarande) {
            return [null, ucfirst($etikett) . " \"$cell\" är inaktiv."];
        }
        return [$t['uuid'], null];
    }
    $plan = $ctx['nya_typer'][$lista][$nyckel] ?? null;
    if ($plan === null) {
        $plan = ['lista' => $lista, 'uuid' => kc_uuid(), 'namn' => $cell, 'extra' => $lista === 'matarmodell' ? ['fabrikat_uuid' => $fabrikat_uuid] : []];
        $ctx['nya_typer'][$lista][$nyckel] = $plan;
    }
    $nya[$plan['uuid']] = $plan;
    return [$plan['uuid'], null];
}

/** Lägger till en typ som planerats vid importen, om den inte redan finns (anropas i utfor). */
function mat_import_typ_sakra(array $plan): void
{
    $def = mat_typlista($plan['lista']);
    $t = kc_tabell($def['tabell']);
    if (kc_hamta_varde("SELECT COUNT(*) FROM `$t` WHERE uuid = ?", [$plan['uuid']]) > 0) {
        return;
    }
    $sort = (int)kc_hamta_varde("SELECT COALESCE(MAX(sortering), 0) FROM `$t` WHERE is_deleted = 0") + 10;
    $falt = ['namn' => $plan['namn'], 'beskrivning' => '', 'sortering' => $sort, 'is_active' => 1];
    foreach (array_merge(array_keys($def['extra']), array_keys($def['ref'])) as $k) {
        $falt[$k] = (string)($plan['extra'][$k] ?? '');
    }
    mat_import_infoga($def['tabell'], $plan['uuid'], $falt);
}

/** Text för nya typer: "ny fabrikat: Kamstrup". */
function mat_import_nya_text(array $nya): string
{
    $d = [];
    foreach ($nya as $p) {
        $d[] = 'ny ' . mat_typlista($p['lista'])['etikett'] . ': ' . $p['namn'];
    }
    return $d === [] ? '' : '(' . implode('; ', $d) . ')';
}

/** Typ- och statusvärden (etikett eller nyckel, skiftlägesokänsligt). Returnerar [nyckel|null, fel|null]. */
function mat_import_val(?string $cell, array $val, string $etikett): array
{
    if ($cell === null) {
        return [null, null];
    }
    if ($cell === KC_IMPORT_TOMT) {
        return [null, "$etikett kan inte tömmas."];
    }
    foreach ($val as $k => $namn) {
        if (mb_strtolower($cell) === $k || mb_strtolower($cell) === mb_strtolower($namn)) {
            return [$k, null];
        }
    }
    return [null, "Okänd $etikett \"$cell\" (" . implode(', ', $val) . ').'];
}

/**
 * Placering eller betjäning ur kolumnerna ($prefix '' eller 'betjanar_').
 * Returnerar [nivå|null, nummer|null, fel|null]; exakt en kolumn får vara ifylld.
 */
function mat_import_fas_koppling(array $rad, string $prefix, string $vad): array
{
    $hittade = [];
    foreach (MAT_IMPORT_NIVAKOLUMN as $niva => $kol) {
        $c = kc_import_cell($rad, $prefix . $kol);
        if ($c !== null) {
            $hittade[$niva] = $c;
        }
    }
    if ($hittade === []) {
        return [null, null, null];
    }
    if (count($hittade) > 1) {
        return [null, null, "Fyll i bara en kolumn för $vad (" . implode(', ', array_map(static fn($n) => $prefix . MAT_IMPORT_NIVAKOLUMN[$n], array_keys($hittade))) . ').'];
    }
    $niva = array_key_first($hittade);
    if ($hittade[$niva] === KC_IMPORT_TOMT) {
        return [null, null, ucfirst($vad) . ' kan inte tömmas.'];
    }
    return [$niva, $hittade[$niva], null];
}

/** Egenskapskolumner för en nivå, med kolumnprefix ('egenskap:' eller 'egenskap_matare:'). */
function mat_import_egenskaper_forbered(string $niva, string $prefix, array $rubriker): array
{
    // Kärnan läser kolumner "egenskap:<namn>" – mappa om prefixet för mätare
    $mappade = [];
    foreach ($rubriker as $r) {
        if (str_starts_with($r, $prefix)) {
            $mappade[] = 'egenskap:' . mb_substr($r, mb_strlen($prefix));
        }
    }
    $e = kc_egenskap_import_forbered($niva, $mappade);
    $e['fel'] = array_map(static fn($f) => $prefix === 'egenskap:' ? $f : str_replace('egenskap:', $prefix, $f), $e['fel']);
    return $e;
}

/** Planerar egenskaper för en post. Returnerar ['del' => [...], 'fel' => [...], 'skriv' => [...]]. */
function mat_import_egenskaper(array $karta, string $prefix, array $rad, ?string $post_uuid, string $lage): array
{
    if ($karta === []) {
        return ['del' => null, 'fel' => [], 'skriv' => []];
    }
    $mappad = ['_rad' => $rad['_rad']];
    foreach ($rad as $k => $v) {
        if (is_string($k) && str_starts_with($k, $prefix)) {
            $mappad['egenskap:' . mb_substr($k, mb_strlen($prefix))] = $v;
        }
    }
    $e = kc_egenskap_import_planera($karta, $mappad, $post_uuid, $lage);
    $text = $e['text'] !== '' && $prefix !== 'egenskap:' ? str_replace('egenskaper:', 'mätarens egenskaper:', $e['text']) : $e['text'];
    return ['del' => ['typ' => $e['typ'], 'text' => $text], 'fel' => $e['fel'], 'skriv' => $e['skriv']];
}

/* ---------- Definitioner ---------- */

function mat_import_def(string $tabell): array
{
    if (!array_key_exists($tabell, MAT_IMPORT_TABELLER)) {
        throw new KcFel('Okänd import.', 404);
    }
    $bas = MAT_IMPORT_TABELLER[$tabell] + ['tabell' => $tabell];
    return $tabell === 'matpunkt' ? $bas + mat_import_def_matpunkt() : $bas + mat_import_def_typlista($tabell);
}

/** Förberedelse för "Mätpunkter och mätare": typkartor, egenskaper, nyckel- och ställningskolumner. */
function mat_import_forbered_matpunkt(array $rubriker): array
{
    $ctx = ['typer' => [], 'nya_typer' => [], 'sedda' => [], 'nya_mp' => [], 'nya_matare' => [],
            'monteras' => [], 'far_matare' => [], 'nycklar' => [], 'stallningar' => [], 'rad' => 0];
    foreach (array_keys(MAT_TYPLISTOR) as $l) {
        $ctx['typer'][$l] = mat_import_typkarta($l);
    }
    $fel = [];
    $e = mat_import_egenskaper_forbered('matpunkt', 'egenskap:', $rubriker);
    $ctx['eg_mp'] = $e['karta'];
    $fel = array_merge($fel, $e['fel']);
    $e = mat_import_egenskaper_forbered('matare', 'egenskap_matare:', $rubriker);
    $ctx['eg_ma'] = $e['karta'];
    $fel = array_merge($fel, $e['fel']);
    foreach ($rubriker as $r) {
        if (str_starts_with($r, 'nyckel:') && trim(mb_substr($r, 7)) !== '') {
            $ctx['nycklar'][$r] = trim(mb_substr($r, 7));
        } elseif (str_starts_with($r, 'stallning:') && trim(mb_substr($r, 10)) !== '') {
            $ctx['stallningar'][$r] = trim(mb_substr($r, 10));
        }
    }
    if (!in_array('matpunktsnamn', $rubriker, true) && !in_array('uuid', $rubriker, true)
        && !in_array('matarnummer', $rubriker, true) && !in_array('matar_id', $rubriker, true)) {
        $fel[] = 'Filen måste ha minst en av kolumnerna matpunktsnamn, uuid, matarnummer eller matar_id.';
    }
    return ['fel' => $fel, 'ctx' => $ctx];
}

function mat_import_def_matpunkt(): array
{
    return [
        'kolumner'   => MAT_IMPORT_KOLUMNER,
        'kravs'      => [],
        'extra'      => '/^(nyckel|stallning|egenskap|egenskap_matare):.+$/u',
        'mall_extra' => static function (): array {
            $k = [];
            foreach (mat_typer_aktiva('nyckeltyp') as $t) {
                $k[] = 'nyckel:' . $t['namn'];
            }
            foreach (mat_typer_aktiva('storhet') as $t) {
                $k[] = 'stallning:' . $t['namn'];
            }
            foreach (kc_egenskaper_aktiva('matpunkt') as $e) {
                $k[] = 'egenskap:' . mb_strtolower($e['namn']);
            }
            foreach (kc_egenskaper_aktiva('matare') as $e) {
                $k[] = 'egenskap_matare:' . mb_strtolower($e['namn']);
            }
            return $k;
        },
        'hjalp'      => '<p>En rad per mätpunkt med dess mätare. Bara det som står i filen påverkas – tomma celler ändrar inget.</p>
            <ul>
              <li><strong>Mätpunkt:</strong> matchas på <code>uuid</code> (om raden kommer från en export), annars <code>matpunktsnamn</code>.
                  Placering anges i en av kolumnerna <code>objektnummer</code>, <code>byggnadsnummer</code>, <code>fastighetsnummer</code>
                  eller <code>objektgruppsnummer</code> (krävs för ny mätpunkt). Betjäning på samma sätt med <code>betjanar_</code>-kolumnerna;
                  tom betjäning på en ny mätpunkt = samma som placering. <code>storheter</code> kommaseparerade, <code>foregaende</code> med mätpunktsnamn.</li>
              <li><strong>Mätare:</strong> matchas på <code>matarnummer</code>, annars <code>matar_id</code> (+ <code>gateway</code>) om exakt en finns,
                  annars mätpunktens monterade mätare – annars skapas en ny. En rad utan mätpunkt ger en fristående (ej monterad) mätare.</li>
              <li><strong>Montering:</strong> <code>monteringsdatum</code> (ÅÅÅÅ-MM-DD) monterar mätaren. Har mätpunkten en annan mätare blir det fel
                  vid mjuk import och byte vid hård import. Ställningar i <code>stallning:&lt;storhet&gt;</code>.</li>
              <li><strong>Nycklar:</strong> <code>nyckel:&lt;nyckeltyp&gt;</code>. Exporteras aldrig.</li>
              <li><strong>Egenskaper:</strong> <code>egenskap:&lt;namn&gt;</code> för mätpunkten och <code>egenskap_matare:&lt;namn&gt;</code> för mätaren.</li>
              <li>Typ, status, mätartyp, fabrikat, modell och kommunikation anges med namn. Okända typer läggs till automatiskt (syns i förhandsvisningen).</li>
            </ul>',
        'forbered'   => 'mat_import_forbered_matpunkt',
        'planera'    => 'mat_import_planera_matpunkt',
        'export'     => 'mat_import_export_matpunkt',
    ];
}

/**
 * Planerar en rad i "Mätpunkter och mätare".
 */
function mat_import_planera_matpunkt(array $rad, string $lage, array &$ctx): array
{
    $ctx['rad'] = $rad['_rad'];
    $fel = [];
    $delar = [];
    $nya_typer = [];
    $tm = kc_tabell('md_matpunkt');

    /* --- Mätpunkt: hitta --- */
    $c_uuid = kc_import_cell($rad, 'uuid');
    $c_namn = kc_import_cell($rad, 'matpunktsnamn');
    $mp_uuid = null;
    $mp = null;
    if ($c_uuid !== null && $c_uuid !== KC_IMPORT_TOMT) {
        $mp_uuid = strtolower($c_uuid);
        if (!kc_ar_uuid($mp_uuid)) {
            return mat_import_resultat($c_namn ?? $c_uuid, false, ["Ogiltigt uuid \"$c_uuid\"."], [], null);
        }
        $mp = kc_hamta_en("SELECT * FROM `$tm` WHERE uuid = ? LIMIT 1", [$mp_uuid]);
        if ($mp !== null && (int)$mp['is_deleted'] === 1) {
            return mat_import_resultat($c_namn ?? $c_uuid, false, ['Mätpunkten med detta uuid är raderad.'], [], null);
        }
    }
    if ($c_namn === KC_IMPORT_TOMT) {
        $fel[] = 'Mätpunktsnamnet kan inte tömmas.';
        $c_namn = null;
    }
    if ($mp === null && $c_namn !== null) {
        $hittad = mat_matpunkt_via_namn($c_namn);
        if ($hittad !== null && $mp_uuid !== null && $hittad['uuid'] !== $mp_uuid) {
            $fel[] = "Mätpunktsnamnet $c_namn används redan av en annan mätpunkt (annat uuid).";
        } elseif ($hittad !== null) {
            $mp = $hittad;
            $mp_uuid = $hittad['uuid'];
        }
    }
    $har_mp = $mp !== null || $c_namn !== null || $mp_uuid !== null;
    $ny_mp = $har_mp && $mp === null;
    if ($ny_mp) {
        $mp_uuid ??= kc_uuid();
        if ($c_namn === null) {
            $fel[] = 'Ny mätpunkt kräver matpunktsnamn.';
        } elseif (!mat_matpunktsnamn_ok($c_namn)) {
            $fel[] = 'Mätpunktsnamnet måste vara 1–100 tecken: bokstäver, siffror och - _ . / (inga mellanslag).';
        }
    }
    $nyckel = $c_namn ?? ($mp['matpunktsnamn'] ?? '');

    $har_matare = false;
    foreach (MAT_IMPORT_MATARKOLUMNER as $k) {
        $har_matare = $har_matare || kc_import_cell($rad, $k) !== null;
    }
    foreach (array_keys($ctx['nycklar']) as $k) {
        $har_matare = $har_matare || kc_import_cell($rad, $k) !== null;
    }
    foreach ($rad as $k => $v) {
        $har_matare = $har_matare || (is_string($k) && str_starts_with($k, 'egenskap_matare:') && $v !== '');
    }
    if (!$har_mp && !$har_matare) {
        return mat_import_resultat('', false, ['Raden saknar både mätpunkt och mätare.'], [], null);
    }
    if ($har_mp && ($d = mat_import_dubblett($ctx, 'mp:' . $mp_uuid, $c_namn !== null ? 'mpnamn:' . $c_namn : null))) {
        $fel[] = $d;
    }

    /* --- Mätpunkt: fält --- */
    $mp_skriv = [];
    $mp_nya = [];
    $storheter_plan = null;
    $mp_koppling = ['placering' => null, 'betjaning' => null];
    $mp_typ = $mp['typ'] ?? 'fysisk';
    $mp_status = $mp['status'] ?? 'projekt';
    $eg_mp = ['skriv' => []];
    if ($har_mp) {
        $nya = ['matpunktsnamn' => $c_namn];
        [$nya['typ'], $f] = mat_import_val(kc_import_cell($rad, 'typ'), MAT_MATPUNKT_TYPER, 'typ');
        if ($f) { $fel[] = $f; }
        [$nya['status'], $f] = mat_import_val(kc_import_cell($rad, 'status'), MAT_MATPUNKT_STATUS, 'status');
        if ($f) { $fel[] = $f; }
        [$nya['platsbeskrivning'], $f] = mat_import_text(kc_import_cell($rad, 'platsbeskrivning'), 255, 'Platsbeskrivning');
        if ($f) { $fel[] = $f; }

        // Placering och betjäning som "nivå|uuid"
        $koppling = [];
        $placering_fel = false;
        foreach (['placering' => ['', 'placeringen', 'niva', 'placering_uuid'], 'betjaning' => ['betjanar_', 'betjäningen', 'betjaning_niva', 'betjaning_uuid']]
                 as $vad => [$prefix, $etikett, $kn, $ku]) {
            [$niva, $nummer, $f] = mat_import_fas_koppling($rad, $prefix, $etikett);
            $koppling[$vad] = null;
            if ($f) {
                $fel[] = $f;
                $placering_fel = true;
            } elseif ($niva !== null) {
                [$u, $f] = mat_fas_koppling_tolka($niva, $nummer, $etikett, (string)($mp[$kn] ?? ''), (string)($mp[$ku] ?? ''));
                if ($f) {
                    $fel[] = $f;
                    $placering_fel = true;
                } else {
                    $koppling[$vad] = ['niva' => $niva, 'uuid' => $u, 'nummer' => $nummer];
                    $nya[$vad] = $niva . '|' . $u;
                }
            }
        }
        if ($ny_mp && $koppling['placering'] === null && !$placering_fel) {
            $fel[] = 'Ny mätpunkt kräver placering (objektnummer, byggnadsnummer, fastighetsnummer eller objektgruppsnummer).';
        }
        if ($ny_mp && $koppling['betjaning'] === null && $koppling['placering'] !== null) {
            $koppling['betjaning'] = $koppling['placering'];
            $nya['betjaning'] = $nya['placering'];
        }

        // Föregående (mätpunktsnamn, även en mätpunkt som skapas tidigare i filen)
        $c = kc_import_cell($rad, 'foregaende');
        $nya['foregaende_uuid'] = null;
        if ($c === KC_IMPORT_TOMT) {
            $nya['foregaende_uuid'] = KC_IMPORT_TOMT;
        } elseif ($c !== null) {
            $f_uuid = $ctx['nya_mp'][$c] ?? (mat_matpunkt_via_namn($c)['uuid'] ?? null);
            if ($f_uuid === null) {
                $fel[] = "Föregående mätpunkt $c finns inte.";
            } elseif ($f_uuid === $mp_uuid) {
                $fel[] = 'En mätpunkt kan inte vara sin egen föregående.';
            } elseif (!$ny_mp && mat_ger_slinga($mp_uuid, $f_uuid)) {
                $fel[] = "Föregående mätpunkt $c skulle ge en slinga.";
            } else {
                $nya['foregaende_uuid'] = $f_uuid;
            }
        }

        if ($ny_mp) {
            $mp_nya = $nya;
            $mp_typ = $nya['typ'] ?? 'fysisk';
            $mp_status = $nya['status'] ?? 'projekt';
            if ($c_namn !== null && (int)kc_hamta_varde("SELECT COUNT(*) FROM `$tm` WHERE matpunktsnamn = ? AND is_deleted = 0", [$c_namn]) > 0) {
                $fel[] = "Mätpunktsnamnet $c_namn används redan.";
            }
        } else {
            $bef = [
                'matpunktsnamn' => $mp['matpunktsnamn'], 'typ' => $mp['typ'], 'status' => $mp['status'],
                'platsbeskrivning' => $mp['platsbeskrivning'],
                'placering' => $mp['niva'] . '|' . $mp['placering_uuid'],
                'betjaning' => $mp['betjaning_uuid'] !== '' ? $mp['betjaning_niva'] . '|' . $mp['betjaning_uuid'] : '',
                'foregaende_uuid' => $mp['foregaende_uuid'],
            ];
            $s = kc_import_sammanfoga($bef, $nya, $lage);
            $mp_skriv = $s['andringar'];
            if (isset($mp_skriv['matpunktsnamn'])) {
                if (!mat_matpunktsnamn_ok($mp_skriv['matpunktsnamn'])) {
                    $fel[] = 'Mätpunktsnamnet måste vara 1–100 tecken: bokstäver, siffror och - _ . / (inga mellanslag).';
                } elseif (($annan = mat_matpunkt_via_namn($mp_skriv['matpunktsnamn'])) !== null && $annan['uuid'] !== $mp_uuid) {
                    $fel[] = 'Mätpunktsnamnet ' . $mp_skriv['matpunktsnamn'] . ' används redan.';
                }
            }
            $mp_typ = $mp_skriv['typ'] ?? $mp['typ'];
            $mp_status = $mp_skriv['status'] ?? $mp['status'];
            if ($s['falt'] !== []) {
                $namn = ['matpunktsnamn' => 'namn', 'platsbeskrivning' => 'plats', 'foregaende_uuid' => 'föregående', 'betjaning' => 'betjäning'];
                $delar[] = ['typ' => $s['typ'], 'text' => 'mätpunkt: ' . implode(', ', array_map(static fn($k) => $namn[$k] ?? $k, $s['falt'])) . '.'];
            }
        }
        // Spara kopplingarna i tabellens kolumner
        $mp_koppling = $koppling;

        // Förväntade storheter
        $c = kc_import_cell($rad, 'storheter');
        if ($c !== null) {
            $befintliga = $ny_mp ? [] : array_column(mat_matpunkt_storheter($mp_uuid), 'uuid');
            $valda = [];
            if ($c !== KC_IMPORT_TOMT) {
                foreach (array_filter(array_map('trim', explode(',', $c)), static fn($x) => $x !== '') as $namn) {
                    [$u, $f] = mat_import_typ($ctx, 'storhet', $namn, '', $nya_typer);
                    if ($f) { $fel[] = $f; } else { $valda[] = $u; }
                }
                $valda = array_values(array_unique($valda));
            }
            if ($c === KC_IMPORT_TOMT) {
                if ($lage === 'hard' && $befintliga !== []) {
                    $storheter_plan = ['lagg_till' => [], 'ta_bort' => $befintliga];
                    $delar[] = ['typ' => 'uppdaterad', 'text' => 'storheter tas bort.'];
                }
            } elseif ($befintliga === []) {
                if ($valda !== []) {
                    $storheter_plan = ['lagg_till' => $valda, 'ta_bort' => []];
                    if (!$ny_mp) { $delar[] = ['typ' => 'kompletterad', 'text' => 'storheter läggs till.']; }
                }
            } elseif ($lage === 'hard') {
                $lagg = array_values(array_diff($valda, $befintliga));
                $ta = array_values(array_diff($befintliga, $valda));
                if ($lagg !== [] || $ta !== []) {
                    $storheter_plan = ['lagg_till' => $lagg, 'ta_bort' => $ta];
                    $delar[] = ['typ' => 'uppdaterad', 'text' => 'storheter ändras.'];
                }
            }
        }

        // Egenskaper för mätpunkten
        $eg_mp = mat_import_egenskaper($ctx['eg_mp'], 'egenskap:', $rad, $ny_mp ? null : $mp_uuid, $lage);
        $fel = array_merge($fel, $eg_mp['fel']);
        if ($eg_mp['del'] !== null) { $delar[] = $eg_mp['del']; }

        if (!$ny_mp && $mp_typ === 'virtuell' && $mp['typ'] !== 'virtuell' && mat_aktiv_montering('matpunkt_uuid', $mp_uuid) !== null) {
            $fel[] = 'Mätpunkten har en monterad mätare och kan inte bli virtuell.';
        }
    }

    /* --- Mätare --- */
    $ma = null;
    $ma_uuid = null;
    $ny_ma = false;
    $ma_skriv = [];
    $ma_nya = [];
    $nyckel_skriv = [];
    $eg_ma = ['skriv' => []];
    $mp_aktiv = $har_mp && !$ny_mp ? mat_aktiv_montering('matpunkt_uuid', $mp_uuid) : null;
    if ($har_matare) {
        $tma = kc_tabell('md_matare');
        $c_nr = kc_import_cell($rad, 'matarnummer');
        $c_id = kc_import_cell($rad, 'matar_id');
        $c_gw = kc_import_cell($rad, 'gateway');
        if ($c_nr === KC_IMPORT_TOMT) {
            $fel[] = 'Mätarnummer kan inte tömmas.';
            $c_nr = null;
        }
        if ($c_nr !== null) {
            $ma = kc_hamta_en("SELECT * FROM `$tma` WHERE matarnummer = ? AND is_deleted = 0 LIMIT 1", [$c_nr]);
            if ($ma === null) {
                $fel[] = "Okänt mätarnummer $c_nr (nya mätare får sitt nummer vid importen – lämna kolumnen tom).";
            }
        } elseif ($c_id !== null && $c_id !== KC_IMPORT_TOMT) {
            $kand = kc_hamta_alla(
                "SELECT * FROM `$tma` WHERE matar_id = ? AND is_deleted = 0"
                . ($c_gw !== null && $c_gw !== KC_IMPORT_TOMT ? ' AND LOWER(gateway) = LOWER(?)' : '') . ' ORDER BY matarnummer',
                $c_gw !== null && $c_gw !== KC_IMPORT_TOMT ? [$c_id, $c_gw] : [$c_id]
            );
            if (count($kand) === 1) {
                $ma = $kand[0];
            } elseif (count($kand) > 1) {
                $fel[] = "Flera mätare har mätarId $c_id (" . implode(', ', array_column($kand, 'matarnummer')) . '). Ange matarnummer.';
            }
        }
        if ($ma === null && $c_nr === null && $mp_aktiv !== null && ($c_id === null || $c_id === $mp_aktiv['matar_id'])) {
            $ma = mat_matare_hamta($mp_aktiv['matare_uuid']);
        }
        if ($ma !== null) {
            $ma_uuid = $ma['uuid'];
        } elseif ($c_nr === null) {
            $ny_ma = true;
            $ma_uuid = kc_uuid();
            $nyckel_ny = 'nyma:' . mb_strtolower((string)($c_id ?? '')) . '|' . mb_strtolower((string)($c_gw ?? ''));
            if ($c_id !== null && $c_id !== KC_IMPORT_TOMT && ($d = mat_import_dubblett($ctx, $nyckel_ny))) {
                $fel[] = 'Mätaren (samma mätarId och gateway) ' . lcfirst($d);
            }
            if ($c_id === null || $c_id === KC_IMPORT_TOMT) {
                $delar[] = ['typ' => null, 'text' => 'Obs: ny mätare utan mätarId kan inte matchas vid en ny import.'];
            }
        }
        if ($ma !== null && ($d = mat_import_dubblett($ctx, 'ma:' . $ma_uuid))) {
            $fel[] = 'Mätaren ' . $ma['matarnummer'] . ': ' . lcfirst($d);
        }

        // Fält
        $nya = [];
        foreach (['matar_id' => 100, 'gateway' => 100, 'kommentar' => 255] as $k => $max) {
            [$nya[$k], $f] = mat_import_text(kc_import_cell($rad, $k), $max, $k === 'matar_id' ? 'MätarId' : ucfirst($k));
            if ($f) { $fel[] = $f; }
        }
        [$nya['matartyp_uuid'], $f] = mat_import_typ($ctx, 'matartyp', kc_import_cell($rad, 'matartyp'), (string)($ma['matartyp_uuid'] ?? ''), $nya_typer);
        if ($f) { $fel[] = $f; }
        [$nya['kommunikation_uuid'], $f] = mat_import_typ($ctx, 'kommunikation', kc_import_cell($rad, 'kommunikation'), (string)($ma['kommunikation_uuid'] ?? ''), $nya_typer);
        if ($f) { $fel[] = $f; }
        [$nya['fabrikat_uuid'], $f] = mat_import_typ($ctx, 'fabrikat', kc_import_cell($rad, 'fabrikat'), (string)($ma['fabrikat_uuid'] ?? ''), $nya_typer);
        if ($f) { $fel[] = $f; }
        $c_mod = kc_import_cell($rad, 'modell');
        $nya['matarmodell_uuid'] = $c_mod === KC_IMPORT_TOMT ? KC_IMPORT_TOMT : null;
        if ($c_mod !== null && $c_mod !== KC_IMPORT_TOMT) {
            $fab = is_string($nya['fabrikat_uuid']) && $nya['fabrikat_uuid'] !== KC_IMPORT_TOMT ? $nya['fabrikat_uuid'] : (string)($ma['fabrikat_uuid'] ?? '');
            if ($fab === '') {
                // Ingen fabrikat angiven: ta fabrikatet från en befintlig modell med namnet, om den är entydig
                $traffar = array_filter(
                    array_merge(array_values($ctx['typer']['matarmodell']),
                                array_map(static fn($p) => ['namn' => $p['namn'], 'fabrikat_uuid' => $p['extra']['fabrikat_uuid']],
                                          array_values($ctx['nya_typer']['matarmodell'] ?? []))),
                    static fn($m) => mb_strtolower($m['namn']) === mb_strtolower($c_mod)
                );
                if (count($traffar) === 1) {
                    $fab = (string)array_values($traffar)[0]['fabrikat_uuid'];
                    $nya['fabrikat_uuid'] = $fab;
                } elseif ($traffar === []) {
                    $fel[] = "Okänd modell \"$c_mod\" – ange fabrikat så läggs modellen till.";
                } else {
                    $fel[] = "Modellen \"$c_mod\" finns hos flera fabrikat – ange fabrikat.";
                }
            }
            $fab_fran_fil = is_string($nya['fabrikat_uuid']) && $nya['fabrikat_uuid'] !== KC_IMPORT_TOMT;
            if ($fab !== '' && !$fab_fran_fil && !isset($ctx['typer']['matarmodell'][$fab . '|' . mb_strtolower($c_mod)])
                && !isset($ctx['nya_typer']['matarmodell'][$fab . '|' . mb_strtolower($c_mod)])) {
                // Modellen finns inte hos mätarens fabrikat – finns den hos ett annat är det troligen ett misstag
                foreach ($ctx['typer']['matarmodell'] as $m) {
                    if (mb_strtolower($m['namn']) === mb_strtolower($c_mod)) {
                        $fel[] = "Modellen \"$c_mod\" finns hos ett annat fabrikat än mätarens – ange fabrikat.";
                        $fab = '';
                        break;
                    }
                }
            }
            if ($fab !== '') {
                [$nya['matarmodell_uuid'], $f] = mat_import_typ($ctx, 'matarmodell', $c_mod, (string)($ma['matarmodell_uuid'] ?? ''), $nya_typer, $fab);
                if ($f) { $fel[] = $f; }
            }
        }
        if ($ny_ma) {
            $ma_nya = $nya;
            if ($c_id !== null && $c_id !== KC_IMPORT_TOMT) {
                $ctx['nya_matare'][mb_strtolower($c_id) . '|' . mb_strtolower((string)($c_gw ?? ''))] = $ma_uuid;
            }
        } elseif ($ma !== null) {
            $s = kc_import_sammanfoga(array_intersect_key($ma, $nya), $nya, $lage);
            $ma_skriv = $s['andringar'];
            if ($s['falt'] !== []) {
                $namn = ['matar_id' => 'mätarId', 'matartyp_uuid' => 'mätartyp', 'fabrikat_uuid' => 'fabrikat',
                         'matarmodell_uuid' => 'modell', 'kommunikation_uuid' => 'kommunikation'];
                $delar[] = ['typ' => $s['typ'], 'text' => 'mätare ' . $ma['matarnummer'] . ': ' . implode(', ', array_map(static fn($k) => $namn[$k] ?? $k, $s['falt'])) . '.'];
            }
        }

        // Modellen måste höra till mätarens fabrikat (efter importen)
        $slut = static fn(string $k) => (string)($ny_ma ? (is_string($ma_nya[$k] ?? null) && $ma_nya[$k] !== KC_IMPORT_TOMT ? $ma_nya[$k] : '')
                                                         : (array_key_exists($k, $ma_skriv) ? $ma_skriv[$k] : ($ma[$k] ?? '')));
        $slut_mod = $slut('matarmodell_uuid');
        if ($slut_mod !== '') {
            $mod_fab = null;
            foreach ($ctx['typer']['matarmodell'] as $m) {
                if ($m['uuid'] === $slut_mod) { $mod_fab = (string)$m['fabrikat_uuid']; break; }
            }
            foreach ($ctx['nya_typer']['matarmodell'] ?? [] as $p) {
                if ($p['uuid'] === $slut_mod) { $mod_fab = (string)$p['extra']['fabrikat_uuid']; break; }
            }
            if ($mod_fab !== null && $mod_fab !== $slut('fabrikat_uuid')) {
                $fel[] = 'Modellen hör inte till mätarens fabrikat – ange modell för det nya fabrikatet, eller (tom) i modell.';
            }
        }

        // Nycklar (bara namnen visas)
        if ($ctx['nycklar'] !== []) {
            $bef = $ny_ma || $ma === null ? [] : array_map(static fn($n) => $n['varde'], mat_nycklar($ma_uuid));
            $nya_n = [];
            $befintlig_n = [];
            $typnamn = [];
            foreach ($ctx['nycklar'] as $kol => $typ) {
                $c = kc_import_cell($rad, $kol);
                if ($c === null) {
                    continue;
                }
                [$u, $f] = mat_import_typ($ctx, 'nyckeltyp', $typ, '', $nya_typer);
                if ($f) { $fel[] = $f; continue; }
                if ($c !== KC_IMPORT_TOMT && mb_strlen($c) > 255) { $fel[] = "Nyckeln $typ är för lång (max 255 tecken)."; continue; }
                $nya_n[$u] = $c;
                $befintlig_n[$u] = $bef[$u] ?? '';
                $typnamn[$u] = $ctx['typer']['nyckeltyp'][mb_strtolower($typ)]['namn'] ?? $typ;
            }
            $s = kc_import_sammanfoga($befintlig_n, $nya_n, $lage);
            $nyckel_skriv = $s['andringar'];
            if ($s['falt'] !== []) {
                $delar[] = ['typ' => $ny_ma ? null : $s['typ'], 'text' => 'nycklar: ' . implode(', ', array_map(static fn($u) => $typnamn[$u], $s['falt'])) . '.'];
            }
        }

        // Mätarens egenskaper
        $eg_ma = mat_import_egenskaper($ctx['eg_ma'], 'egenskap_matare:', $rad, $ny_ma || $ma === null ? null : $ma_uuid, $lage);
        $fel = array_merge($fel, $eg_ma['fel']);
        if ($eg_ma['del'] !== null) { $delar[] = $ny_ma ? ['typ' => null, 'text' => $eg_ma['del']['text']] : $eg_ma['del']; }
    }

    /* --- Montering och ställningar --- */
    $montering = null;   // ['ny' => bool, 'uuid', 'datum', 'byte' => ?montering]
    $st_skriv = [];
    $c_datum = $har_matare ? kc_import_cell($rad, 'monteringsdatum') : null;
    $ma_aktiv = $ma_uuid !== null && !$ny_ma ? mat_aktiv_montering('matare_uuid', $ma_uuid) : null;
    $redan_har = $mp_aktiv !== null && $ma_uuid !== null && $mp_aktiv['matare_uuid'] === $ma_uuid;
    if ($c_datum === KC_IMPORT_TOMT) {
        $fel[] = 'Monteringsdatum kan inte tömmas (demontera i gränssnittet).';
    } elseif ($c_datum !== null && $ma_uuid !== null) {
        if (!$har_mp) {
            $fel[] = 'Monteringsdatum kräver en mätpunkt på raden.';
        } elseif (!mat_datum_ok($c_datum)) {
            $fel[] = 'Monteringsdatum ska vara ÅÅÅÅ-MM-DD.';
        } elseif ($redan_har) {
            $montering = ['ny' => false, 'uuid' => $mp_aktiv['uuid']];
            if ((string)$mp_aktiv['startdatum'] !== $c_datum) {
                $delar[] = ['typ' => null, 'text' => 'Obs: mätaren är redan monterad här sedan ' . $mp_aktiv['startdatum'] . ' (datumet ändras inte – använd Rätta).'];
            }
        } elseif ($mp_typ === 'virtuell') {
            $fel[] = 'En virtuell mätpunkt kan inte ha mätare.';
        } elseif ($mp_status === 'avvecklad') {
            $fel[] = 'Mätpunkten är avvecklad och kan inte få en ny mätare.';
        } elseif ($ma_aktiv !== null) {
            $fel[] = 'Mätaren ' . $ma_aktiv['matarnummer'] . ' sitter i ' . $ma_aktiv['matpunktsnamn'] . '. Demontera eller byt den först.';
        } elseif (isset($ctx['monteras'][$ma_uuid])) {
            $fel[] = 'Mätaren monteras redan på rad ' . $ctx['monteras'][$ma_uuid] . ' i filen.';
        } elseif (isset($ctx['far_matare'][$mp_uuid])) {
            $fel[] = 'Mätpunkten får redan en mätare på rad ' . $ctx['far_matare'][$mp_uuid] . ' i filen.';
        } elseif ($mp_aktiv !== null && $lage !== 'hard') {
            $fel[] = 'Mätpunkten har redan mätare ' . $mp_aktiv['matarnummer'] . '. Använd hård import (byte) eller Byt mätare.';
        } elseif ($mp_aktiv !== null && $c_datum < (string)$mp_aktiv['startdatum']) {
            $fel[] = 'Bytesdatumet kan inte vara före monteringen av ' . $mp_aktiv['matarnummer'] . ' (' . $mp_aktiv['startdatum'] . ').';
        } else {
            $k = mat_montering_kontroll($ma_uuid, $mp_uuid, $c_datum, '', $mp_aktiv['uuid'] ?? '');
            if ($k !== []) {
                $fel = array_merge($fel, $k);
            } else {
                $montering = ['ny' => true, 'uuid' => kc_uuid(), 'datum' => $c_datum, 'byte' => $mp_aktiv];
                $ctx['monteras'][$ma_uuid] = $ctx['rad'];
                $ctx['far_matare'][$mp_uuid] = $ctx['rad'];
                $delar[] = $mp_aktiv !== null
                    ? ['typ' => 'uppdaterad', 'text' => 'byte: ' . $mp_aktiv['matarnummer'] . ' demonteras och ' . ($ny_ma ? 'den nya mätaren' : $ma['matarnummer']) . " monteras $c_datum."]
                    : ['typ' => $ny_ma || $ny_mp ? null : 'kompletterad', 'text' => "monteras $c_datum."];
            }
        }
    } elseif ($c_datum === null && $har_mp && $ma_uuid !== null && !$redan_har) {
        $delar[] = ['typ' => null, 'text' => 'Obs: mätaren monteras inte (monteringsdatum saknas).'];
    }
    if ($redan_har && $montering === null) {
        $montering = ['ny' => false, 'uuid' => $mp_aktiv['uuid']];
    }
    // Ställningar vid montering (för den nya eller den pågående monteringen)
    if ($ctx['stallningar'] !== [] && $har_matare) {
        $nya_s = [];
        $bef_s = [];
        foreach ($ctx['stallningar'] as $kol => $namn) {
            $c = kc_import_cell($rad, $kol);
            if ($c === null) {
                continue;
            }
            if ($montering === null) {
                $delar[] = ['typ' => null, 'text' => "Obs: ställningen för $namn läses inte (ingen montering)."];
                continue;
            }
            [$u, $f] = mat_import_typ($ctx, 'storhet', $namn, '', $nya_typer);
            if ($f) { $fel[] = $f; continue; }
            if ($c === KC_IMPORT_TOMT) {
                $nya_s[$u] = KC_IMPORT_TOMT;
            } else {
                $v = mat_stallning_normalisera($c);
                if ($v === null) { $fel[] = "Ogiltig ställning för $namn (siffror, ev. decimaler med komma)."; continue; }
                $nya_s[$u] = $v;
            }
            $bef_s[$u] = $montering['ny'] ? '' : (mat_stallningar($montering['uuid'])['montering'][$u] ?? '');
        }
        if ($nya_s !== []) {
            $s = kc_import_sammanfoga($bef_s, $nya_s, $lage);
            $st_skriv = $s['andringar'];
            if ($s['falt'] !== [] && !$montering['ny']) {
                $delar[] = ['typ' => $s['typ'], 'text' => 'ställningar vid montering.'];
            }
        }
    }

    // Nya typer skapas bara om de faktiskt används (mjuk import kan ha valt bort ett värde)
    if ($nya_typer !== []) {
        $anvanda = array_merge(
            array_values($ny_ma ? $ma_nya : $ma_skriv),
            $storheter_plan['lagg_till'] ?? [],
            array_keys($nyckel_skriv),
            array_keys($st_skriv)
        );
        if ($ny_mp || $storheter_plan !== null) {
            $anvanda = array_merge($anvanda, $storheter_plan['lagg_till'] ?? []);
        }
        foreach ($nya_typer as $plan) {
            if ($plan['lista'] === 'matarmodell' && in_array($plan['uuid'], $anvanda, true)) {
                $anvanda[] = $plan['extra']['fabrikat_uuid'];
            }
        }
        $nya_typer = array_filter($nya_typer, static fn($p) => in_array($p['uuid'], $anvanda, true));
    }
    if ($har_mp && $ny_mp && $fel === [] && $c_namn !== null) {
        $ctx['nya_mp'][$c_namn] = $mp_uuid;
    }
    if ($nya_typer !== []) {
        $delar[] = ['typ' => null, 'text' => mat_import_nya_text($nya_typer)];
    }
    if ($ny_mp || $ny_ma) {
        array_unshift($delar, ['typ' => null, 'text' => implode(' + ', array_filter([
            $ny_mp ? 'ny mätpunkt' : null,
            $ny_ma ? 'ny mätare' : null,
        ])) . '.']);
    }
    $nyckel = $nyckel !== '' ? $nyckel : (string)($ma['matarnummer'] ?? (kc_import_cell($rad, 'matar_id') ?? ''));
    $ar_ny = $ny_mp || $ny_ma;
    if ($fel === [] && !$ar_ny && $nya_typer !== [] && array_filter($delar, static fn($d) => ($d['typ'] ?? null) !== null) === []) {
        $delar[] = ['typ' => 'kompletterad', 'text' => ''];   // bara nya typer
    }

    $utfor = static function () use (
        $nya_typer, $har_mp, $ny_mp, $mp_uuid, $mp, $mp_nya, $mp_skriv, $mp_koppling, $storheter_plan, $eg_mp,
        $har_matare, $ny_ma, $ma_uuid, $ma_nya, $ma_skriv, $nyckel_skriv, $eg_ma, $montering, $st_skriv
    ): void {
        $nu = kc_nu();
        // 1. Nya typer (fabrikat före modell – planeringsordningen bevaras)
        foreach ($nya_typer as $plan) {
            mat_import_typ_sakra($plan);
        }
        // 2. Mätpunkt
        if ($har_mp) {
            $kopplingsfalt = static function (array $falt, ?array $koppling, string $kn, string $ku, string $knr, ?array $nu_rad): array {
                if ($koppling === null) {
                    return [];
                }
                return [
                    $kn  => $koppling['niva'],
                    $ku  => $koppling['uuid'],
                    $knr => mat_fas_koppling_nummer($koppling['niva'], $koppling['uuid'], $koppling['nummer'],
                                                    (string)($nu_rad[$ku] ?? ''), (string)($nu_rad[$knr] ?? '')),
                ];
            };
            if ($ny_mp) {
                $falt = [
                    'matpunktsnamn' => $mp_nya['matpunktsnamn'],
                    'typ' => $mp_nya['typ'] ?? 'fysisk', 'status' => $mp_nya['status'] ?? 'projekt',
                    'platsbeskrivning' => is_string($mp_nya['platsbeskrivning']) && $mp_nya['platsbeskrivning'] !== KC_IMPORT_TOMT ? $mp_nya['platsbeskrivning'] : '',
                    'foregaende_uuid' => is_string($mp_nya['foregaende_uuid']) && $mp_nya['foregaende_uuid'] !== KC_IMPORT_TOMT ? $mp_nya['foregaende_uuid'] : '',
                ]
                + $kopplingsfalt([], $mp_koppling['placering'], 'niva', 'placering_uuid', 'placering_nummer', null)
                + $kopplingsfalt([], $mp_koppling['betjaning'], 'betjaning_niva', 'betjaning_uuid', 'betjaning_nummer', null);
                mat_import_infoga('md_matpunkt', $mp_uuid, $falt);
            } elseif ($mp_skriv !== []) {
                $falt = array_diff_key($mp_skriv, ['placering' => 1, 'betjaning' => 1]);
                if (isset($mp_skriv['placering'])) {
                    $falt += $kopplingsfalt([], $mp_koppling['placering'], 'niva', 'placering_uuid', 'placering_nummer', $mp);
                }
                if (isset($mp_skriv['betjaning'])) {
                    $falt += $kopplingsfalt([], $mp_koppling['betjaning'], 'betjaning_niva', 'betjaning_uuid', 'betjaning_nummer', $mp);
                }
                mat_import_uppdatera('md_matpunkt', $mp_uuid, $falt);
            }
            if ($storheter_plan !== null) {
                $tk = kc_tabell('md_matpunkt_storhet');
                foreach ($storheter_plan['lagg_till'] as $s) {
                    $r = kc_hamta_en("SELECT uuid FROM `$tk` WHERE matpunkt_uuid = ? AND storhet_uuid = ? ORDER BY is_deleted ASC, id ASC LIMIT 1", [$mp_uuid, $s]);
                    if ($r !== null) {
                        kc_kor("UPDATE `$tk` SET is_deleted = 0, last_changed = ?, source_tool = 'mat' WHERE uuid = ?", [$nu, $r['uuid']]);
                    } else {
                        mat_import_infoga('md_matpunkt_storhet', kc_uuid(), ['matpunkt_uuid' => $mp_uuid, 'storhet_uuid' => $s]);
                    }
                }
                foreach ($storheter_plan['ta_bort'] as $s) {
                    kc_kor("UPDATE `$tk` SET is_deleted = 1, last_changed = ?, source_tool = 'mat' WHERE matpunkt_uuid = ? AND storhet_uuid = ? AND is_deleted = 0",
                           [$nu, $mp_uuid, $s]);
                }
            }
            foreach ($eg_mp['skriv'] as $e => $v) {
                kc_egenskap_varde_skriv($e, $mp_uuid, (string)$v);
            }
        }
        // 3. Mätare
        if ($har_matare && $ma_uuid !== null) {
            if ($ny_ma) {
                $falt = ['matarnummer' => mat_nasta_matarnummer()];
                foreach (['matar_id', 'gateway', 'kommentar', 'matartyp_uuid', 'fabrikat_uuid', 'matarmodell_uuid', 'kommunikation_uuid'] as $k) {
                    $falt[$k] = is_string($ma_nya[$k] ?? null) && $ma_nya[$k] !== KC_IMPORT_TOMT ? $ma_nya[$k] : '';
                }
                mat_import_infoga('md_matare', $ma_uuid, $falt);
            } elseif ($ma_skriv !== []) {
                mat_import_uppdatera('md_matare', $ma_uuid, $ma_skriv);
            }
            $tn = kc_tabell('md_matarnyckel');
            foreach ($nyckel_skriv as $typ => $v) {
                $r = kc_hamta_en("SELECT uuid FROM `$tn` WHERE matare_uuid = ? AND nyckeltyp_uuid = ? ORDER BY is_deleted ASC, id ASC LIMIT 1", [$ma_uuid, $typ]);
                if ($v === '') {
                    kc_kor("UPDATE `$tn` SET varde = '', is_deleted = 1, last_changed = ?, source_tool = 'mat' WHERE matare_uuid = ? AND nyckeltyp_uuid = ? AND is_deleted = 0",
                           [$nu, $ma_uuid, $typ]);
                } elseif ($r !== null) {
                    kc_kor("UPDATE `$tn` SET varde = ?, is_deleted = 0, last_changed = ?, source_tool = 'mat' WHERE uuid = ?", [$v, $nu, $r['uuid']]);
                } else {
                    mat_import_infoga('md_matarnyckel', kc_uuid(), ['matare_uuid' => $ma_uuid, 'nyckeltyp_uuid' => $typ, 'varde' => $v]);
                }
            }
            foreach ($eg_ma['skriv'] as $e => $v) {
                kc_egenskap_varde_skriv($e, $ma_uuid, (string)$v);
            }
        }
        // 4. Montering (byte: den gamla demonteras samma datum, utan ställning)
        if ($montering !== null) {
            if ($montering['ny']) {
                if ($montering['byte'] !== null) {
                    kc_kor('UPDATE `' . kc_tabell('md_montering') . "` SET slutdatum = ?, last_changed = ?, source_tool = 'mat' WHERE uuid = ? AND is_deleted = 0",
                           [$montering['datum'], $nu, $montering['byte']['uuid']]);
                }
                kc_kor('INSERT INTO `' . kc_tabell('md_montering') . "` (uuid, last_changed, is_deleted, source_tool, matare_uuid, matpunkt_uuid, startdatum, slutdatum)
                        VALUES (?, ?, 0, 'mat', ?, ?, ?, NULL)", [$montering['uuid'], $nu, $ma_uuid, $mp_uuid, $montering['datum']]);
            }
            if ($st_skriv !== []) {
                mat_stallningar_spara($montering['uuid'], 'montering', array_map('strval', $st_skriv));
            }
        }
    };
    return mat_import_resultat($nyckel, $ar_ny, $fel, $delar, $utfor);
}

/** "Exportera för import": en rad per mätpunkt (med monterad mätare) plus omonterade mätare. Inga nycklar. */
function mat_import_export_matpunkt(): array
{
    $tm = kc_tabell('md_matpunkt');
    $tma = kc_tabell('md_matare');
    $tg = kc_tabell('md_montering');
    $typnamn = [];
    foreach (array_keys(MAT_TYPLISTOR) as $l) {
        $t = kc_tabell(MAT_TYPLISTOR[$l]['tabell']);
        $typnamn[$l] = array_column(kc_hamta_alla("SELECT uuid, namn FROM `$t` WHERE is_deleted = 0"), 'namn', 'uuid');
    }
    $mpnamn = array_column(kc_hamta_alla("SELECT uuid, matpunktsnamn FROM `$tm` WHERE is_deleted = 0"), 'matpunktsnamn', 'uuid');
    $storheter = [];
    foreach (kc_hamta_alla('SELECT matpunkt_uuid, storhet_uuid FROM `' . kc_tabell('md_matpunkt_storhet') . '` WHERE is_deleted = 0 ORDER BY id') as $r) {
        if (isset($typnamn['storhet'][$r['storhet_uuid']])) {
            $storheter[$r['matpunkt_uuid']][] = $typnamn['storhet'][$r['storhet_uuid']];
        }
    }
    $aktiva = [];
    foreach (kc_hamta_alla("SELECT * FROM `$tg` WHERE is_deleted = 0 AND slutdatum IS NULL") as $g) {
        $aktiva[$g['matpunkt_uuid']] = $g;
    }
    $stallningar = [];
    foreach (kc_hamta_alla('SELECT montering_uuid, storhet_uuid, varde FROM `' . kc_tabell('md_stallning') . "` WHERE is_deleted = 0 AND handelse = 'montering'") as $s) {
        if (isset($typnamn['storhet'][$s['storhet_uuid']])) {
            $stallningar[$s['montering_uuid']]['stallning:' . $typnamn['storhet'][$s['storhet_uuid']]] = mat_stallning_visa($s['varde']);
        }
    }
    $eg_mp = kc_egenskap_import_export('matpunkt');
    $eg_ma = [];
    foreach (kc_egenskap_import_export('matare') as $post => $v) {
        foreach ($v as $k => $x) {
            $eg_ma[$post]['egenskap_matare:' . mb_substr($k, 9)] = $x;
        }
    }
    $matare = [];
    foreach (kc_hamta_alla("SELECT * FROM `$tma` WHERE is_deleted = 0") as $m) {
        $matare[$m['uuid']] = $m;
    }
    $matardel = static function (array $m) use ($typnamn, $eg_ma): array {
        return [
            'matarnummer' => $m['matarnummer'], 'matar_id' => $m['matar_id'], 'gateway' => $m['gateway'],
            'matartyp' => $typnamn['matartyp'][$m['matartyp_uuid']] ?? '', 'fabrikat' => $typnamn['fabrikat'][$m['fabrikat_uuid']] ?? '',
            'modell' => $typnamn['matarmodell'][$m['matarmodell_uuid']] ?? '', 'kommunikation' => $typnamn['kommunikation'][$m['kommunikation_uuid']] ?? '',
            'kommentar' => $m['kommentar'],
        ] + ($eg_ma[$m['uuid']] ?? []);
    };

    $ut = [];
    $monterade = [];
    foreach (kc_hamta_alla("SELECT * FROM `$tm` WHERE is_deleted = 0 ORDER BY matpunktsnamn") as $p) {
        $rad = [
            'uuid' => $p['uuid'], 'matpunktsnamn' => $p['matpunktsnamn'],
            MAT_IMPORT_NIVAKOLUMN[$p['niva']] ?? 'objektnummer' => $p['placering_nummer'],
            'platsbeskrivning' => $p['platsbeskrivning'],
            'typ' => MAT_MATPUNKT_TYPER[$p['typ']] ?? $p['typ'], 'status' => MAT_MATPUNKT_STATUS[$p['status']] ?? $p['status'],
            'storheter' => implode(', ', $storheter[$p['uuid']] ?? []),
            'foregaende' => $mpnamn[$p['foregaende_uuid']] ?? '',
        ] + ($eg_mp[$p['uuid']] ?? []);
        // Betjäning bara när den skiljer sig från placeringen
        if ($p['betjaning_uuid'] !== '' && ($p['betjaning_niva'] !== $p['niva'] || $p['betjaning_uuid'] !== $p['placering_uuid'])) {
            $rad['betjanar_' . (MAT_IMPORT_NIVAKOLUMN[$p['betjaning_niva']] ?? 'objektnummer')] = $p['betjaning_nummer'];
        }
        $g = $aktiva[$p['uuid']] ?? null;
        if ($g !== null && isset($matare[$g['matare_uuid']])) {
            $rad += $matardel($matare[$g['matare_uuid']]) + ['monteringsdatum' => (string)$g['startdatum']] + ($stallningar[$g['uuid']] ?? []);
            $monterade[$g['matare_uuid']] = true;
        }
        $ut[] = $rad;
    }
    foreach ($matare as $u => $m) {
        if (!isset($monterade[$u])) {
            $ut[] = $matardel($m);
        }
    }
    return $ut;
}

/* ---------- Typlistor ---------- */

function mat_import_def_typlista(string $lista): array
{
    $def = mat_typlista($lista);
    $md = $def['tabell'];
    $kolumner = array_merge(['uuid', 'namn'], array_keys($def['extra']), $lista === 'matarmodell' ? ['fabrikat'] : [], ['beskrivning', 'sortering', 'aktiv']);
    return [
        'kolumner' => $kolumner,
        'kravs'    => $lista === 'matarmodell' ? ['namn', 'fabrikat'] : ['namn'],
        'hjalp'    => '<p>En rad per ' . kc_h($def['etikett']) . '. Matchas på uuid eller namn (skiftlägesokänsligt'
                    . ($lista === 'matarmodell' ? ', inom fabrikatet; okänt fabrikat läggs till automatiskt' : '') . '). '
                    . '<code>sortering</code> är ett heltal (utelämnat = sist), <code>aktiv</code> är <code>ja</code> eller <code>nej</code>. '
                    . 'Import tar aldrig bort typer.</p>',
        'forbered' => static function (array $rubriker) use ($lista): array {
            $ctx = ['typer' => ['fabrikat' => mat_import_typkarta('fabrikat'), $lista => mat_import_typkarta($lista)],
                    'nya_typer' => [], 'sedda' => [], 'rad' => 0];
            return ['fel' => [], 'ctx' => $ctx];
        },
        'planera'  => static function (array $rad, string $lage, array &$ctx) use ($md, $def, $lista): array {
            $ctx['rad'] = $rad['_rad'];
            $t = kc_tabell($md);
            $fel = [];
            $nya_typer = [];
            $uuid = kc_import_cell($rad, 'uuid');
            if ($uuid !== null && ($uuid === KC_IMPORT_TOMT || !kc_ar_uuid(strtolower($uuid)))) {
                return mat_import_resultat((string)$uuid, false, ["Ogiltigt uuid \"$uuid\"."], [], null);
            }
            $uuid = $uuid === null ? null : strtolower($uuid);
            $namn = kc_import_cell($rad, 'namn');
            $nyckel = $namn ?? (string)$uuid;
            if ($namn === KC_IMPORT_TOMT) {
                return mat_import_resultat($nyckel, false, ['Namn kan inte tömmas.'], [], null);
            }
            $fab = '';
            if ($lista === 'matarmodell') {
                $c = kc_import_cell($rad, 'fabrikat');
                if ($c === KC_IMPORT_TOMT) {
                    return mat_import_resultat($nyckel, false, ['Fabrikat kan inte tömmas.'], [], null);
                }
                if ($c !== null) {
                    [$fab, $f] = mat_import_typ($ctx, 'fabrikat', $c, '', $nya_typer);
                    if ($f) { $fel[] = $f; $fab = ''; }
                }
            }
            $befintlig = null;
            if ($uuid !== null) {
                $befintlig = kc_hamta_en("SELECT * FROM `$t` WHERE uuid = ? LIMIT 1", [$uuid]);
                if ($befintlig !== null && (int)$befintlig['is_deleted'] === 1) {
                    return mat_import_resultat($nyckel, false, ['Typen med detta uuid är raderad.'], [], null);
                }
            }
            if ($befintlig === null && $namn !== null) {
                $sql = "SELECT * FROM `$t` WHERE is_deleted = 0 AND LOWER(namn) = LOWER(?)" . ($lista === 'matarmodell' ? ' AND fabrikat_uuid = ?' : '') . ' ORDER BY id LIMIT 1';
                $hittad = kc_hamta_en($sql, $lista === 'matarmodell' ? [$namn, $fab] : [$namn]);
                if ($hittad !== null && $uuid !== null) {
                    return mat_import_resultat($nyckel, false, ["Namnet $namn används redan av en annan typ (annat uuid)."], [], null);
                }
                $befintlig = $hittad;
            }
            if ($befintlig === null && $namn === null) {
                return mat_import_resultat($nyckel, false, ['Namn måste fyllas i.'], [], null);
            }
            if ($befintlig === null && $lista === 'matarmodell' && $fab === '') {
                $fel[] = 'Ny modell kräver fabrikat.';
            }
            $post = $befintlig['uuid'] ?? $uuid ?? kc_uuid();
            if ($d = mat_import_dubblett($ctx, 'typ:' . $post, $namn !== null ? 'typnamn:' . $fab . '|' . mb_strtolower($namn) : null)) {
                return mat_import_resultat($nyckel, false, [$d], [], null);
            }
            $nya = [];
            [$nya['namn'], $f] = mat_import_text($namn, 100, 'Namn');
            if ($f) { $fel[] = $f; }
            foreach ($def['extra'] as $k => $etikett) {
                [$nya[$k], $f] = mat_import_text(kc_import_cell($rad, $k), 20, $etikett);
                if ($f) { $fel[] = $f; }
            }
            if ($lista === 'matarmodell') {
                $nya['fabrikat_uuid'] = $fab !== '' ? $fab : null;
            }
            [$nya['beskrivning'], $f] = mat_import_text(kc_import_cell($rad, 'beskrivning'), 255, 'Beskrivning');
            if ($f) { $fel[] = $f; }
            $c = kc_import_cell($rad, 'sortering');
            $nya['sortering'] = null;
            if ($c !== null && $c !== KC_IMPORT_TOMT) {
                if (preg_match('/^-?\d{1,6}$/', $c)) { $nya['sortering'] = (string)(int)$c; } else { $fel[] = 'Sortering ska vara ett heltal.'; }
            }
            $c = kc_import_cell($rad, 'aktiv');
            $nya['is_active'] = null;
            if ($c !== null && $c !== KC_IMPORT_TOMT) {
                $nya['is_active'] = match (mb_strtolower($c)) { 'ja', 'j', '1', 'x' => '1', 'nej', 'n', '0' => '0', default => null };
                if ($nya['is_active'] === null) { $fel[] = 'Aktiv ska vara ja eller nej.'; }
            }
            $ny = $befintlig === null;
            $delar = [];
            $skriv = [];
            if (!$ny) {
                $jamfor = $befintlig;
                $jamfor['sortering'] = (string)(int)$befintlig['sortering'];
                $jamfor['is_active'] = (string)(int)$befintlig['is_active'];
                $s = kc_import_sammanfoga($jamfor, $nya, $lage);
                $skriv = $s['andringar'];
                if (isset($skriv['namn']) || isset($skriv['fabrikat_uuid'])) {
                    $n2 = $skriv['namn'] ?? $befintlig['namn'];
                    $sql = "SELECT COUNT(*) FROM `$t` WHERE is_deleted = 0 AND LOWER(namn) = LOWER(?) AND uuid <> ?" . ($lista === 'matarmodell' ? ' AND fabrikat_uuid = ?' : '');
                    $p = $lista === 'matarmodell' ? [$n2, $post, $skriv['fabrikat_uuid'] ?? $befintlig['fabrikat_uuid']] : [$n2, $post];
                    if ((int)kc_hamta_varde($sql, $p) > 0) { $fel[] = "Namnet $n2 används redan."; }
                }
                $delar[] = ['typ' => $s['typ'], 'text' => $s['falt'] ? str_replace(['is_active', 'fabrikat_uuid'], ['aktiv', 'fabrikat'], implode(', ', $s['falt'])) . '.' : ''];
            } else {
                if ($nya['sortering'] === null) {
                    $ctx['max_sort'] ??= (int)kc_hamta_varde("SELECT COALESCE(MAX(sortering), 0) FROM `$t` WHERE is_deleted = 0");
                    $ctx['max_sort'] += 10;
                    $nya['sortering'] = (string)$ctx['max_sort'];
                }
                $nya['is_active'] ??= '1';
            }
            if ($nya_typer !== []) {
                $delar[] = ['typ' => $ny ? null : 'kompletterad', 'text' => mat_import_nya_text($nya_typer)];
            }
            $utfor = static function () use ($ny, $md, $post, $nya, $skriv, $nya_typer): void {
                foreach ($nya_typer as $plan) {
                    mat_import_typ_sakra($plan);
                }
                if ($ny) {
                    mat_import_infoga($md, $post, array_map(static fn($v) => $v === null || $v === KC_IMPORT_TOMT ? '' : $v, $nya));
                } else {
                    mat_import_uppdatera($md, $post, $skriv);
                }
            };
            return mat_import_resultat($nyckel, $ny, $fel, $delar, $utfor);
        },
        'export'   => static function () use ($md, $def, $lista): array {
            $t = kc_tabell($md);
            $fab = $lista === 'matarmodell' ? array_column(kc_hamta_alla('SELECT uuid, namn FROM `' . kc_tabell('md_fabrikat') . '` WHERE is_deleted = 0'), 'namn', 'uuid') : [];
            return array_map(static function ($r) use ($fab, $lista) {
                $r['aktiv'] = (int)$r['is_active'] === 1 ? 'ja' : 'nej';
                if ($lista === 'matarmodell') {
                    $r['fabrikat'] = $fab[$r['fabrikat_uuid']] ?? '';
                }
                return $r;
            }, kc_hamta_alla("SELECT * FROM `$t` WHERE is_deleted = 0 ORDER BY sortering, namn"));
        },
    ];
}
