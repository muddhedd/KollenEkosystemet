<?php
/**
 * FastighetsKollen – fas_import.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Importdefinitioner för kärnpaketets import (kc_import_kor): fastighet, byggnad, objekt,
 * adress, objektgrupp, objektgruppskopplingar och typlistor. Inkluderas av import.php efter fas.php.
 *
 * Gemensamt:
 *  - Matchning på uuid om kolumnen är ifylld, annars på nummer (adress: ägare + gatuadress).
 *    Ett uuid som inte finns skapar en ny post med det uuid:t (om numret är ledigt).
 *  - Referenser anges med nummer (fastighetsnummer, byggnadsnummer) och typer med namn.
 *    Okända typer ger fel – typlistorna ändras under Typer.
 *  - Egenskaper som extra kolumner "egenskap:<namn>" (fastighet, byggnad, objekt, objektgrupp).
 *  - Hård import kan flytta byggnader och objekt (ändrat fastighets-/byggnadsnummer).
 *  - Objekt: en gatuadress som saknas på byggnaden skapas om postnummer och ort finns.
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

const FAS_IMPORT_TABELLER = [
    'fastighet'   => ['rubrik' => 'Fastigheter',   'tillbaka' => 'fastigheter.php'],
    'byggnad'     => ['rubrik' => 'Byggnader',     'tillbaka' => 'byggnader.php'],
    'objekt'      => ['rubrik' => 'Objekt',        'tillbaka' => 'objektlista.php'],
    'adress'      => ['rubrik' => 'Adresser',      'tillbaka' => 'fastigheter.php'],
    'objektgrupp' => ['rubrik' => 'Objektgrupper', 'tillbaka' => 'objektgrupper.php'],
    'koppling'    => ['rubrik' => 'Kopplingar för objektgrupper', 'tillbaka' => 'objektgrupper.php'],
    'byggnadstyp'     => ['rubrik' => 'Byggnadstyper',     'tillbaka' => 'typer.php?lista=byggnadstyp'],
    'objekttyp'       => ['rubrik' => 'Objekttyper',       'tillbaka' => 'typer.php?lista=objekttyp'],
    'objektgruppstyp' => ['rubrik' => 'Objektgruppstyper', 'tillbaka' => 'typer.php?lista=objektgruppstyp'],
    'koktyp'          => ['rubrik' => 'Kök',               'tillbaka' => 'typer.php?lista=koktyp'],
];

/* ---------- Gemensamma hjälpare ---------- */

/** Typlista som karta: [namn med gemener => ['uuid', 'namn', 'aktiv']]. */
function fas_import_typkarta(string $lista): array
{
    $t = kc_tabell(fas_typlista($lista)['tabell']);
    $k = [];
    foreach (kc_hamta_alla("SELECT uuid, namn, is_active FROM `$t` WHERE is_deleted = 0") as $r) {
        $k[mb_strtolower($r['namn'])] = ['uuid' => $r['uuid'], 'namn' => $r['namn'], 'aktiv' => (int)$r['is_active'] === 1];
    }
    return $k;
}

/**
 * Typnamn → uuid. Returnerar [uuid|KC_IMPORT_TOMT|null, fel|null].
 * En inaktiv typ får behållas men inte väljas på nytt.
 */
function fas_import_typ(?string $cell, array $karta, string $etikett, string $nuvarande = ''): array
{
    if ($cell === null || $cell === KC_IMPORT_TOMT) {
        return [$cell, null];
    }
    $t = $karta[mb_strtolower($cell)] ?? null;
    if ($t === null) {
        return [null, "Okänd $etikett \"$cell\" (lägg till den under Typer)."];
    }
    if (!$t['aktiv'] && $t['uuid'] !== $nuvarande) {
        return [null, ucfirst($etikett) . " \"$cell\" är inaktiv."];
    }
    return [$t['uuid'], null];
}

/** Text med maxlängd. Returnerar [värde|TOMT|null, fel|null]. */
function fas_import_text(?string $cell, int $max, string $etikett): array
{
    if ($cell !== null && $cell !== KC_IMPORT_TOMT && mb_strlen($cell) > $max) {
        return [null, "$etikett får vara max $max tecken."];
    }
    return [$cell, null];
}

/** Uuid ur cell, eller fel. */
function fas_import_uuid(?string $cell): array
{
    if ($cell === null || $cell === KC_IMPORT_TOMT) {
        return [null, null];
    }
    $u = strtolower($cell);
    return kc_ar_uuid($u) ? [$u, null] : [null, "Ogiltigt uuid \"$cell\"."];
}

/**
 * Hittar posten som raden gäller. $nyckel = kolumnen med numret.
 * Returnerar ['rad' => ?array, 'uuid' => uuid för ny eller befintlig, 'fel' => ?text].
 */
function fas_import_hitta(string $md, string $nyckel, ?string $uuid, ?string $nummer): array
{
    $t = kc_tabell($md);
    if ($uuid !== null) {
        $r = kc_hamta_en("SELECT * FROM `$t` WHERE uuid = ? LIMIT 1", [$uuid]);
        if ($r !== null && (int)$r['is_deleted'] === 1) {
            return ['rad' => null, 'uuid' => $uuid, 'fel' => 'Posten med detta uuid är raderad.'];
        }
        if ($r !== null) {
            return ['rad' => $r, 'uuid' => $uuid, 'fel' => null];
        }
        if ($nummer !== null && $nummer !== KC_IMPORT_TOMT
            && kc_hamta_varde("SELECT uuid FROM `$t` WHERE `$nyckel` = ? AND is_deleted = 0 LIMIT 1", [$nummer]) !== null) {
            return ['rad' => null, 'uuid' => $uuid, 'fel' => "Numret $nummer används redan av en annan post (annat uuid)."];
        }
        return ['rad' => null, 'uuid' => $uuid, 'fel' => null];
    }
    if ($nummer === null || $nummer === KC_IMPORT_TOMT) {
        return ['rad' => null, 'uuid' => null, 'fel' => "Kolumnen $nyckel måste vara ifylld (eller uuid)."];
    }
    $r = kc_hamta_en("SELECT * FROM `$t` WHERE `$nyckel` = ? AND is_deleted = 0 ORDER BY id LIMIT 1", [$nummer]);
    return ['rad' => $r, 'uuid' => $r['uuid'] ?? kc_uuid(), 'fel' => null];
}

/**
 * Kontrollerar att samma post inte förekommer två gånger i filen. En post kan ha flera
 * nycklar (uuid och nummer) – träff på någon av dem räknas. Tomma nycklar hoppas över.
 */
function fas_import_dubblett(array &$ctx, ?string ...$nycklar): ?string
{
    $nycklar = array_values(array_filter(array_map(
        static fn($n) => $n === null || $n === '' || str_ends_with($n, ':') ? null : mb_strtolower($n), $nycklar
    )));
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

/** Nummer som dubblettnyckel (null om det saknas eller är "(tom)"). */
function fas_import_nr(?string $nummer): ?string
{
    return $nummer === null || $nummer === KC_IMPORT_TOMT ? null : $nummer;
}

/** Infogar en ny rad (standardfält sätts här). */
function fas_import_infoga(string $md, string $uuid, array $falt): void
{
    $t = kc_tabell($md);
    $kol = array_keys($falt);
    kc_kor(
        "INSERT INTO `$t` (uuid, last_changed, is_deleted, source_tool"
        . ($kol !== [] ? ', `' . implode('`, `', $kol) . '`' : '') . ")
         VALUES (?, ?, 0, 'fas'" . str_repeat(', ?', count($kol)) . ')',
        array_merge([$uuid, kc_nu()], array_values($falt))
    );
}

/** Uppdaterar fält i en befintlig rad. */
function fas_import_uppdatera(string $md, string $uuid, array $falt): void
{
    if ($falt === []) {
        return;
    }
    $t = kc_tabell($md);
    $set = implode(', ', array_map(static fn($k) => "`$k` = ?", array_keys($falt)));
    kc_kor("UPDATE `$t` SET $set, last_changed = ?, source_tool = 'fas' WHERE uuid = ? AND is_deleted = 0",
           array_merge(array_values($falt), [kc_nu(), $uuid]));
}

/** Värden för en ny rad: ej angivna och "(tom)" blir tomma. */
function fas_import_nya_falt(array $nya, array $tomt = []): array
{
    $ut = [];
    foreach ($nya as $k => $v) {
        $ut[$k] = ($v === null || $v === KC_IMPORT_TOMT) ? (array_key_exists($k, $tomt) ? $tomt[$k] : '') : $v;
    }
    return $ut;
}

/** Sammanställer status och text för en rad. */
function fas_import_resultat(string $nyckel, bool $ny, array $fel, array $delar, ?callable $utfor): array
{
    if ($fel !== []) {
        return ['status' => 'fel', 'nyckel' => $nyckel, 'text' => implode(' ', $fel), 'utfor' => null];
    }
    if ($ny) {
        return ['status' => 'ny', 'nyckel' => $nyckel, 'text' => implode(' ', array_filter(array_column($delar, 'text'))) ?: 'skapas',
                'utfor' => $utfor];
    }
    $typ = null;
    $texter = [];
    foreach ($delar as $d) {
        if ($d['typ'] === 'uppdaterad') {
            $typ = 'uppdaterad';
        } elseif ($d['typ'] === 'kompletterad') {
            $typ ??= 'kompletterad';
        }
        if (($d['text'] ?? '') !== '') {
            $texter[] = $d['text'];
        }
    }
    return $typ === null
        ? ['status' => 'oforandrad', 'nyckel' => $nyckel, 'text' => '', 'utfor' => null]
        : ['status' => $typ, 'nyckel' => $nyckel, 'text' => implode(' ', $texter), 'utfor' => $utfor];
}

/** Fältnamn för visning i förhandsvisningen. */
function fas_import_faltlista(array $falt): string
{
    $namn = [
        'fastighet_uuid' => 'fastighet', 'byggnad_uuid' => 'byggnad', 'byggnadstyp_uuid' => 'byggnadstyp',
        'objekttyp_uuid' => 'objekttyp', 'koktyp_uuid' => 'kök', 'adress_uuid' => 'adress',
        'objektgruppstyp_uuid' => 'objektgruppstyp', 'kostnadsstalle' => 'kostnadsställe',
        'vaningsplan' => 'våningsplan', 'antal_rum' => 'antal rum',
    ];
    return implode(', ', array_map(static fn($k) => $namn[$k] ?? $k, $falt));
}

/* ---------- Egenskaper som extra kolumner: se kärnpaketet (kc_egenskap_import_*) ---------- */

/* ---------- Definitioner per tabell ---------- */

/** Importdefinition för kc_import_kor(), eller KcFel 404. */
function fas_import_def(string $tabell): array
{
    if (!array_key_exists($tabell, FAS_IMPORT_TABELLER)) {
        throw new KcFel('Okänd import.', 404);
    }
    $bas = FAS_IMPORT_TABELLER[$tabell] + ['tabell' => $tabell];
    return match ($tabell) {
        'fastighet'   => $bas + fas_import_def_fastighet(),
        'byggnad'     => $bas + fas_import_def_byggnad(),
        'objekt'      => $bas + fas_import_def_objekt(),
        'adress'      => $bas + fas_import_def_adress(),
        'objektgrupp' => $bas + fas_import_def_objektgrupp(),
        'koppling'    => $bas + fas_import_def_koppling(),
        default       => $bas + fas_import_def_typlista($tabell),
    };
}

/** Gemensam förberedelse: typkartor och egenskapskolumner. */
function fas_import_forbered(?string $niva, array $typlistor): callable
{
    return static function (array $rubriker) use ($niva, $typlistor): array {
        $ctx = ['typer' => [], 'egenskaper' => [], 'sedda' => [], 'nya_adresser' => []];
        foreach ($typlistor as $l) {
            $ctx['typer'][$l] = fas_import_typkarta($l);
        }
        $fel = [];
        if ($niva !== null) {
            $e = kc_egenskap_import_forbered($niva, $rubriker);
            $ctx['egenskaper'] = $e['karta'];
            $fel = $e['fel'];
        }
        return ['fel' => $fel, 'ctx' => $ctx];
    };
}

/** Planerar egenskaper och lägger dem till delar/fel; returnerar skrivlistan. */
function fas_import_med_egenskaper(array $ctx, array $rad, ?string $post_uuid, string $lage, array &$delar, array &$fel): array
{
    if ($ctx['egenskaper'] === []) {
        return [];
    }
    $e = kc_egenskap_import_planera($ctx['egenskaper'], $rad, $post_uuid, $lage);
    $fel = array_merge($fel, $e['fel']);
    $delar[] = ['typ' => $e['typ'], 'text' => $e['text']];
    return $e['skriv'];
}

const FAS_IMPORT_HJALP_GEMENSAM = '<p>Fyll i <code>uuid</code> bara om raden kommer från en export – annars matchas raden på numret.
   Typer anges med namn (som under Typer). Decimaltal kan skrivas med komma eller punkt.
   Egenskaper läggs som kolumner <code>egenskap:namn</code> (mallen innehåller alla aktiva egenskaper för nivån);
   ja/nej skrivs <code>ja</code> eller <code>nej</code>, val med valets namn och datum som ÅÅÅÅ-MM-DD.</p>';

function fas_import_def_fastighet(): array
{
    return [
        'kolumner'   => ['uuid', 'fastighetsnummer', 'namn', 'team', 'bolag', 'kostnadsstalle'],
        'kravs'      => ['fastighetsnummer'],
        'extra'      => '/^egenskap:.+$/u',
        'mall_extra' => fn() => kc_egenskap_import_kolumner('fastighet'),
        'hjalp'      => FAS_IMPORT_HJALP_GEMENSAM . '<p>Ny fastighet kräver fastighetsnummer och namn.</p>',
        'forbered'   => fas_import_forbered('fastighet', []),
        'planera'    => static function (array $rad, string $lage, array &$ctx): array {
            $ctx['rad'] = $rad['_rad'];
            $fel = [];
            [$uuid, $f] = fas_import_uuid(kc_import_cell($rad, 'uuid'));
            if ($f) { $fel[] = $f; }
            $nummer = kc_import_cell($rad, 'fastighetsnummer');
            $nyckel = $nummer ?? (string)$uuid;
            if ($fel !== []) {
                return fas_import_resultat($nyckel, false, $fel, [], null);
            }
            $h = fas_import_hitta('md_fastighet', 'fastighetsnummer', $uuid, $nummer);
            if ($h['fel']) {
                return fas_import_resultat($nyckel, false, [$h['fel']], [], null);
            }
            if ($d = fas_import_dubblett($ctx, 'fas:' . $h['uuid'], 'fasnr:' . fas_import_nr($nummer))) {
                return fas_import_resultat($nyckel, false, [$d], [], null);
            }
            $nya = [];
            foreach (['fastighetsnummer' => 50, 'namn' => 150, 'team' => 100, 'bolag' => 100, 'kostnadsstalle' => 50] as $k => $max) {
                [$nya[$k], $f] = fas_import_text(kc_import_cell($rad, $k), $max, $k);
                if ($f) { $fel[] = $f; }
            }
            if ($h['rad'] === null) {
                foreach (['fastighetsnummer', 'namn'] as $k) {
                    if ($nya[$k] === null || $nya[$k] === KC_IMPORT_TOMT) {
                        $fel[] = "Ny fastighet kräver $k.";
                    }
                }
            } elseif ($nya['fastighetsnummer'] === KC_IMPORT_TOMT || $nya['namn'] === KC_IMPORT_TOMT) {
                $fel[] = 'Fastighetsnummer och namn kan inte tömmas.';
            }
            $delar = [];
            $skriv = [];
            $ny = $h['rad'] === null;
            if (!$ny) {
                $s = kc_import_sammanfoga($h['rad'], $nya, $lage);
                if (isset($s['andringar']['fastighetsnummer'])
                    && !fas_ar_unik('md_fastighet', 'fastighetsnummer', $s['andringar']['fastighetsnummer'], $h['uuid'])) {
                    $fel[] = 'Fastighetsnumret ' . $s['andringar']['fastighetsnummer'] . ' används redan.';
                }
                $skriv = $s['andringar'];
                $delar[] = ['typ' => $s['typ'], 'text' => $s['falt'] ? fas_import_faltlista($s['falt']) . '.' : ''];
            }
            $eg = fas_import_med_egenskaper($ctx, $rad, $ny ? null : $h['uuid'], $lage, $delar, $fel);
            $post = $h['uuid'];
            $utfor = static function () use ($ny, $post, $nya, $skriv, $eg): void {
                $ny ? fas_import_infoga('md_fastighet', $post, fas_import_nya_falt($nya)) : fas_import_uppdatera('md_fastighet', $post, $skriv);
                foreach ($eg as $e => $v) {
                    kc_egenskap_varde_skriv($e, $post, (string)$v);
                }
            };
            return fas_import_resultat($nyckel, $ny, $fel, $delar, $utfor);
        },
        'export'     => static function (): array {
            $eg = kc_egenskap_import_export('fastighet');
            $t = kc_tabell('md_fastighet');
            return array_map(static fn($r) => $r + ($eg[$r['uuid']] ?? []), kc_hamta_alla(
                "SELECT uuid, fastighetsnummer, namn, team, bolag, kostnadsstalle FROM `$t` WHERE is_deleted = 0 ORDER BY fastighetsnummer"
            ));
        },
    ];
}

function fas_import_def_byggnad(): array
{
    return [
        'kolumner'   => ['uuid', 'byggnadsnummer', 'fastighetsnummer', 'namn', 'byggnadstyp'],
        'kravs'      => ['byggnadsnummer'],
        'extra'      => '/^egenskap:.+$/u',
        'mall_extra' => fn() => kc_egenskap_import_kolumner('byggnad'),
        'hjalp'      => FAS_IMPORT_HJALP_GEMENSAM . '<p>Ny byggnad kräver byggnadsnummer, fastighetsnummer (fastigheten måste finnas)
                         och byggnadstyp. Ändrat fastighetsnummer vid hård import flyttar byggnaden; adresser och objekt följer med.</p>',
        'forbered'   => fas_import_forbered('byggnad', ['byggnadstyp']),
        'planera'    => static function (array $rad, string $lage, array &$ctx): array {
            $ctx['rad'] = $rad['_rad'];
            $fel = [];
            [$uuid, $f] = fas_import_uuid(kc_import_cell($rad, 'uuid'));
            if ($f) { $fel[] = $f; }
            $nummer = kc_import_cell($rad, 'byggnadsnummer');
            $nyckel = $nummer ?? (string)$uuid;
            if ($fel !== []) {
                return fas_import_resultat($nyckel, false, $fel, [], null);
            }
            $h = fas_import_hitta('md_byggnad', 'byggnadsnummer', $uuid, $nummer);
            if ($h['fel']) {
                return fas_import_resultat($nyckel, false, [$h['fel']], [], null);
            }
            if ($d = fas_import_dubblett($ctx, 'byg:' . $h['uuid'], 'bygnr:' . fas_import_nr($nummer))) {
                return fas_import_resultat($nyckel, false, [$d], [], null);
            }
            $nya = [];
            [$nya['byggnadsnummer'], $f] = fas_import_text($nummer, 50, 'byggnadsnummer');
            if ($f) { $fel[] = $f; }
            [$nya['namn'], $f] = fas_import_text(kc_import_cell($rad, 'namn'), 150, 'namn');
            if ($f) { $fel[] = $f; }
            $fnr = kc_import_cell($rad, 'fastighetsnummer');
            $nya['fastighet_uuid'] = null;
            if ($fnr === KC_IMPORT_TOMT) {
                $fel[] = 'Fastighetsnummer kan inte tömmas.';
            } elseif ($fnr !== null) {
                $nya['fastighet_uuid'] = fas_mal_hitta('fastighet', $fnr);
                if ($nya['fastighet_uuid'] === null) {
                    $fel[] = "Fastigheten $fnr finns inte.";
                }
            }
            [$nya['byggnadstyp_uuid'], $f] = fas_import_typ(kc_import_cell($rad, 'byggnadstyp'), $ctx['typer']['byggnadstyp'],
                                                            'byggnadstyp', (string)($h['rad']['byggnadstyp_uuid'] ?? ''));
            if ($f) { $fel[] = $f; }
            $ny = $h['rad'] === null;
            if ($ny) {
                foreach (['byggnadsnummer' => 'byggnadsnummer', 'fastighet_uuid' => 'fastighetsnummer', 'byggnadstyp_uuid' => 'byggnadstyp'] as $k => $e) {
                    if ($nya[$k] === null || $nya[$k] === KC_IMPORT_TOMT) {
                        $fel[] = "Ny byggnad kräver $e.";
                    }
                }
            } elseif ($nya['byggnadsnummer'] === KC_IMPORT_TOMT || $nya['byggnadstyp_uuid'] === KC_IMPORT_TOMT) {
                $fel[] = 'Byggnadsnummer och byggnadstyp kan inte tömmas.';
            }
            $fel = array_values(array_unique($fel));
            $delar = [];
            $skriv = [];
            if (!$ny) {
                $s = kc_import_sammanfoga($h['rad'], $nya, $lage);
                if (isset($s['andringar']['byggnadsnummer'])
                    && !fas_ar_unik('md_byggnad', 'byggnadsnummer', $s['andringar']['byggnadsnummer'], $h['uuid'])) {
                    $fel[] = 'Byggnadsnumret ' . $s['andringar']['byggnadsnummer'] . ' används redan.';
                }
                $skriv = $s['andringar'];
                $delar[] = ['typ' => $s['typ'], 'text' => $s['falt']
                    ? fas_import_faltlista($s['falt']) . (isset($skriv['fastighet_uuid']) ? " (flyttas till fastighet $fnr)" : '') . '.' : ''];
            }
            $eg = fas_import_med_egenskaper($ctx, $rad, $ny ? null : $h['uuid'], $lage, $delar, $fel);
            $post = $h['uuid'];
            $utfor = static function () use ($ny, $post, $nya, $skriv, $eg): void {
                $ny ? fas_import_infoga('md_byggnad', $post, fas_import_nya_falt($nya)) : fas_import_uppdatera('md_byggnad', $post, $skriv);
                foreach ($eg as $e => $v) {
                    kc_egenskap_varde_skriv($e, $post, (string)$v);
                }
            };
            return fas_import_resultat($nyckel, $ny, $fel, $delar, $utfor);
        },
        'export'     => static function (): array {
            $eg = kc_egenskap_import_export('byggnad');
            $tb = kc_tabell('md_byggnad');
            $tf = kc_tabell('md_fastighet');
            $tt = kc_tabell('md_byggnadstyp');
            return array_map(static fn($r) => $r + ($eg[$r['uuid']] ?? []), kc_hamta_alla(
                "SELECT b.uuid, b.byggnadsnummer, f.fastighetsnummer, b.namn, t.namn AS byggnadstyp
                   FROM `$tb` b LEFT JOIN `$tf` f ON f.uuid = b.fastighet_uuid LEFT JOIN `$tt` t ON t.uuid = b.byggnadstyp_uuid
                  WHERE b.is_deleted = 0 ORDER BY b.byggnadsnummer"
            ));
        },
    ];
}

/**
 * Objektets adress: gatuadress på målbyggnaden (skiftlägesokänsligt), annars en ny adress
 * om postnummer och ort finns. Returnerar [adress_uuid|TOMT|null, fel|null, ny adress|null].
 */
function fas_import_objekt_adress(array $rad, ?string $byggnad_uuid, array &$ctx): array
{
    $gata = kc_import_cell($rad, 'gatuadress');
    if ($gata === null || $gata === KC_IMPORT_TOMT) {
        return [$gata, null, null];
    }
    if ($byggnad_uuid === null) {
        return [null, null, null];
    }
    if (mb_strlen($gata) > 150) {
        return [null, 'Gatuadressen får vara max 150 tecken.', null];
    }
    $ta = kc_tabell('md_adress');
    $u = kc_hamta_varde(
        "SELECT uuid FROM `$ta` WHERE byggnad_uuid = ? AND is_deleted = 0 AND LOWER(gatuadress) = LOWER(?) ORDER BY ar_huvudadress DESC, id LIMIT 1",
        [$byggnad_uuid, $gata]
    );
    if ($u !== null) {
        return [(string)$u, null, null];
    }
    $nyckel = $byggnad_uuid . '|' . mb_strtolower($gata);
    if (isset($ctx['nya_adresser'][$nyckel])) {
        return [$ctx['nya_adresser'][$nyckel]['uuid'], null, $ctx['nya_adresser'][$nyckel]];
    }
    $pnr_cell = kc_import_cell($rad, 'postnummer');
    $ort = kc_import_cell($rad, 'ort');
    $pnr = $pnr_cell !== null && $pnr_cell !== KC_IMPORT_TOMT ? fas_postnummer($pnr_cell) : null;
    if ($pnr === null || $ort === null || $ort === KC_IMPORT_TOMT || mb_strlen($ort) > 100) {
        return [null, "Adressen \"$gata\" finns inte på byggnaden. Ange postnummer (fem siffror) och ort så skapas den.", null];
    }
    $ny = ['uuid' => kc_uuid(), 'byggnad_uuid' => $byggnad_uuid, 'gatuadress' => $gata, 'postnummer' => $pnr, 'ort' => $ort];
    $ctx['nya_adresser'][$nyckel] = $ny;
    return [$ny['uuid'], null, $ny];
}

/** Skapar en planerad ny adress på en byggnad (en gång, även om flera rader använder den). */
function fas_import_adress_skapa(array $a): void
{
    static $skapade = [];
    if (isset($skapade[$a['uuid']])) {
        return;
    }
    fas_import_infoga('md_adress', $a['uuid'], [
        'byggnad_uuid' => $a['byggnad_uuid'], 'fastighet_uuid' => '', 'gatuadress' => $a['gatuadress'],
        'postnummer' => $a['postnummer'], 'ort' => $a['ort'], 'beskrivning' => '', 'ar_huvudadress' => 0,
    ]);
    fas_huvudadress_satt('byggnad', $a['byggnad_uuid'], null);
    $skapade[$a['uuid']] = true;
}

function fas_import_def_objekt(): array
{
    $tal = static fn($v) => $v === null || $v === '' ? '' : sprintf('%.1f', (float)$v);
    return [
        'kolumner'   => ['uuid', 'objektnummer', 'byggnadsnummer', 'objekttyp', 'gatuadress', 'postnummer', 'ort',
                         'vaningsplan', 'kvadratmeter', 'antal_rum', 'kok', 'beskrivning'],
        'kravs'      => ['objektnummer'],
        'extra'      => '/^egenskap:.+$/u',
        'mall_extra' => fn() => kc_egenskap_import_kolumner('objekt'),
        'hjalp'      => FAS_IMPORT_HJALP_GEMENSAM . '<p>Nytt objekt kräver objektnummer, byggnadsnummer (byggnaden måste finnas)
                         och objekttyp. <code>gatuadress</code> väljs bland byggnadens adresser; finns den inte skapas den på byggnaden
                         om <code>postnummer</code> och <code>ort</code> är ifyllda (annars används de inte). Våningsplan: BV eller heltal
                         (−1 = källare). Kök anges med namn, t.ex. Kök eller Kokvrå. Ändrat byggnadsnummer vid hård import flyttar objektet;
                         adressen väljs då på nytt från <code>gatuadress</code> (annars nollställs den).</p>',
        'forbered'   => fas_import_forbered('objekt', ['objekttyp', 'koktyp']),
        'planera'    => static function (array $rad, string $lage, array &$ctx) use ($tal): array {
            $ctx['rad'] = $rad['_rad'];
            $fel = [];
            [$uuid, $f] = fas_import_uuid(kc_import_cell($rad, 'uuid'));
            if ($f) { $fel[] = $f; }
            $nummer = kc_import_cell($rad, 'objektnummer');
            $nyckel = $nummer ?? (string)$uuid;
            if ($fel !== []) {
                return fas_import_resultat($nyckel, false, $fel, [], null);
            }
            $h = fas_import_hitta('md_objekt', 'objektnummer', $uuid, $nummer);
            if ($h['fel']) {
                return fas_import_resultat($nyckel, false, [$h['fel']], [], null);
            }
            if ($d = fas_import_dubblett($ctx, 'obj:' . $h['uuid'], 'objnr:' . fas_import_nr($nummer))) {
                return fas_import_resultat($nyckel, false, [$d], [], null);
            }
            $befintlig = $h['rad'];
            if ($befintlig !== null) {
                $befintlig['kvadratmeter'] = $tal($befintlig['kvadratmeter']);
                $befintlig['antal_rum'] = $tal($befintlig['antal_rum']);
                $befintlig['vaningsplan'] = $befintlig['vaningsplan'] === null ? '' : (string)(int)$befintlig['vaningsplan'];
            }
            $nya = [];
            [$nya['objektnummer'], $f] = fas_import_text($nummer, 50, 'objektnummer');
            if ($f) { $fel[] = $f; }
            $bnr = kc_import_cell($rad, 'byggnadsnummer');
            $nya['byggnad_uuid'] = null;
            if ($bnr === KC_IMPORT_TOMT) {
                $fel[] = 'Byggnadsnummer kan inte tömmas.';
            } elseif ($bnr !== null) {
                $nya['byggnad_uuid'] = fas_mal_hitta('byggnad', $bnr);
                if ($nya['byggnad_uuid'] === null) {
                    $fel[] = "Byggnaden $bnr finns inte.";
                }
            }
            [$nya['objekttyp_uuid'], $f] = fas_import_typ(kc_import_cell($rad, 'objekttyp'), $ctx['typer']['objekttyp'],
                                                          'objekttyp', (string)($befintlig['objekttyp_uuid'] ?? ''));
            if ($f) { $fel[] = $f; }
            [$nya['koktyp_uuid'], $f] = fas_import_typ(kc_import_cell($rad, 'kok'), $ctx['typer']['koktyp'],
                                                       'köksvariant', (string)($befintlig['koktyp_uuid'] ?? ''));
            if ($f) { $fel[] = $f; }
            foreach (['kvadratmeter' => [99999, 0.1, 'Kvadratmeter'], 'antal_rum' => [99, 0.5, 'Antal rum']] as $k => [$max, $steg, $etikett]) {
                $c = kc_import_cell($rad, $k);
                if ($c === null || $c === KC_IMPORT_TOMT) {
                    $nya[$k] = $c;
                    continue;
                }
                [$v, $f] = fas_decimal($c, 0, $max, $steg, $etikett);
                if ($f) { $fel[] = $f; }
                $nya[$k] = $v === null ? null : $tal($v);
            }
            $c = kc_import_cell($rad, 'vaningsplan');
            $nya['vaningsplan'] = $c;
            if ($c !== null && $c !== KC_IMPORT_TOMT) {
                $v = mb_strtoupper($c);
                if ($v === 'BV') {
                    $nya['vaningsplan'] = '0';
                } elseif (preg_match('/^[-−]?\d{1,3}$/u', $v) && (int)str_replace('−', '-', $v) >= -20 && (int)str_replace('−', '-', $v) <= 200) {
                    $nya['vaningsplan'] = (string)(int)str_replace('−', '-', $v);
                } else {
                    $fel[] = 'Våningsplan ska vara BV eller ett heltal.';
                    $nya['vaningsplan'] = null;
                }
            }
            [$nya['beskrivning'], $f] = fas_import_text(kc_import_cell($rad, 'beskrivning'), 255, 'beskrivning');
            if ($f) { $fel[] = $f; }

            $ny = $befintlig === null;
            if ($ny) {
                foreach (['objektnummer' => 'objektnummer', 'byggnad_uuid' => 'byggnadsnummer', 'objekttyp_uuid' => 'objekttyp'] as $k => $e) {
                    if ($nya[$k] === null || $nya[$k] === KC_IMPORT_TOMT) {
                        $fel[] = "Nytt objekt kräver $e.";
                    }
                }
            } elseif ($nya['objektnummer'] === KC_IMPORT_TOMT || $nya['objekttyp_uuid'] === KC_IMPORT_TOMT) {
                $fel[] = 'Objektnummer och objekttyp kan inte tömmas.';
            }

            // Vilken byggnad gäller efter importen? (Flytt bara vid hård import.)
            $flytt = !$ny && $lage === 'hard' && $nya['byggnad_uuid'] !== null && $nya['byggnad_uuid'] !== $befintlig['byggnad_uuid'];
            $mal_byggnad = $ny ? $nya['byggnad_uuid'] : ($flytt ? $nya['byggnad_uuid'] : $befintlig['byggnad_uuid']);
            [$nya['adress_uuid'], $f, $ny_adress] = fas_import_objekt_adress($rad, $mal_byggnad, $ctx);
            if ($f) { $fel[] = $f; }
            if ($flytt && $nya['adress_uuid'] === null) {
                $nya['adress_uuid'] = KC_IMPORT_TOMT;   // flyttat utan ny adress: nollställ
            }
            $fel = array_values(array_unique($fel));

            $tomt = ['kvadratmeter' => null, 'antal_rum' => null, 'vaningsplan' => null];
            $delar = [];
            $skriv = [];
            if (!$ny) {
                $s = kc_import_sammanfoga($befintlig, $nya, $lage, $tomt);
                if (isset($s['andringar']['objektnummer'])
                    && !fas_ar_unik('md_objekt', 'objektnummer', $s['andringar']['objektnummer'], $h['uuid'])) {
                    $fel[] = 'Objektnumret ' . $s['andringar']['objektnummer'] . ' används redan.';
                }
                $skriv = $s['andringar'];
                $delar[] = ['typ' => $s['typ'], 'text' => $s['falt']
                    ? fas_import_faltlista($s['falt']) . ($flytt ? " (flyttas till byggnad $bnr)" : '') . '.' : ''];
                if (!isset($skriv['adress_uuid'])) {
                    $ny_adress = null;   // adressen används inte, skapa den inte
                }
            }
            if ($ny_adress !== null) {
                $delar[] = ['typ' => null, 'text' => 'Ny adress på byggnaden: ' . $ny_adress['gatuadress'] . '.'];
            }
            $eg = fas_import_med_egenskaper($ctx, $rad, $ny ? null : $h['uuid'], $lage, $delar, $fel);
            $post = $h['uuid'];
            $utfor = static function () use ($ny, $post, $nya, $skriv, $eg, $ny_adress, $tomt): void {
                if ($ny_adress !== null) {
                    fas_import_adress_skapa($ny_adress);
                }
                $ny ? fas_import_infoga('md_objekt', $post, fas_import_nya_falt($nya, $tomt)) : fas_import_uppdatera('md_objekt', $post, $skriv);
                foreach ($eg as $e => $v) {
                    kc_egenskap_varde_skriv($e, $post, (string)$v);
                }
            };
            return fas_import_resultat($nyckel, $ny, $fel, $delar, $utfor);
        },
        'export'     => static function (): array {
            $eg = kc_egenskap_import_export('objekt');
            $to = kc_tabell('md_objekt');
            $tb = kc_tabell('md_byggnad');
            $ta = kc_tabell('md_adress');
            $ot = kc_tabell('md_objekttyp');
            $kt = kc_tabell('md_koktyp');
            $rader = kc_hamta_alla(
                "SELECT o.uuid, o.objektnummer, b.byggnadsnummer, t.namn AS objekttyp, a.gatuadress, a.postnummer, a.ort,
                        o.vaningsplan, o.kvadratmeter, o.antal_rum, k.namn AS kok, o.beskrivning
                   FROM `$to` o
                   LEFT JOIN `$tb` b ON b.uuid = o.byggnad_uuid
                   LEFT JOIN `$ot` t ON t.uuid = o.objekttyp_uuid
                   LEFT JOIN `$kt` k ON k.uuid = o.koktyp_uuid
                   LEFT JOIN `$ta` a ON a.uuid = o.adress_uuid AND a.is_deleted = 0
                  WHERE o.is_deleted = 0 ORDER BY o.objektnummer"
            );
            foreach ($rader as &$r) {
                $r['vaningsplan'] = fas_vaning($r['vaningsplan']);
                $r['kvadratmeter'] = fas_tal($r['kvadratmeter']);
                $r['antal_rum'] = fas_tal($r['antal_rum']);
                $r += $eg[$r['uuid']] ?? [];
            }
            unset($r);
            return $rader;
        },
    ];
}

function fas_import_def_adress(): array
{
    return [
        'kolumner' => ['uuid', 'fastighetsnummer', 'byggnadsnummer', 'gatuadress', 'postnummer', 'ort', 'beskrivning', 'huvudadress'],
        'kravs'    => ['gatuadress'],
        'hjalp'    => '<p>Varje rad gäller antingen en fastighet (<code>fastighetsnummer</code>) eller en byggnad (<code>byggnadsnummer</code>) –
                       fyll i en av dem. Utan uuid matchas adressen på ägare och gatuadress. Ny adress kräver gatuadress, postnummer
                       (fem siffror) och ort. <code>huvudadress</code> = <code>ja</code> gör adressen till huvudadress vid hård import
                       (den första adressen blir alltid huvudadress). Adresser kan inte flyttas till en annan ägare via import.</p>',
        'forbered' => fas_import_forbered(null, []),
        'planera'  => static function (array $rad, string $lage, array &$ctx): array {
            $ctx['rad'] = $rad['_rad'];
            $fel = [];
            $ta = kc_tabell('md_adress');
            [$uuid, $f] = fas_import_uuid(kc_import_cell($rad, 'uuid'));
            if ($f) { $fel[] = $f; }
            $gata = kc_import_cell($rad, 'gatuadress');
            $fnr = kc_import_cell($rad, 'fastighetsnummer');
            $bnr = kc_import_cell($rad, 'byggnadsnummer');
            $fnr = $fnr === KC_IMPORT_TOMT ? null : $fnr;
            $bnr = $bnr === KC_IMPORT_TOMT ? null : $bnr;
            $nyckel = trim(($fnr ?? $bnr ?? '') . ' ' . ($gata ?? ''));

            // Ägare ur filen
            $agare = null;
            $agare_uuid = null;
            if ($fnr !== null && $bnr !== null) {
                $fel[] = 'Fyll i antingen fastighetsnummer eller byggnadsnummer, inte båda.';
            } elseif ($fnr !== null) {
                $agare = 'fastighet';
                $agare_uuid = fas_mal_hitta('fastighet', $fnr);
                if ($agare_uuid === null) { $fel[] = "Fastigheten $fnr finns inte."; }
            } elseif ($bnr !== null) {
                $agare = 'byggnad';
                $agare_uuid = fas_mal_hitta('byggnad', $bnr);
                if ($agare_uuid === null) { $fel[] = "Byggnaden $bnr finns inte."; }
            }
            if ($fel !== []) {
                return fas_import_resultat($nyckel, false, $fel, [], null);
            }

            // Befintlig adress
            $befintlig = null;
            if ($uuid !== null) {
                $befintlig = kc_hamta_en("SELECT * FROM `$ta` WHERE uuid = ? LIMIT 1", [$uuid]);
                if ($befintlig !== null && (int)$befintlig['is_deleted'] === 1) {
                    return fas_import_resultat($nyckel, false, ['Adressen med detta uuid är raderad.'], [], null);
                }
                if ($befintlig !== null) {
                    $ag_bef = $befintlig['fastighet_uuid'] !== '' ? 'fastighet' : 'byggnad';
                    $ag_uuid_bef = $befintlig[$ag_bef . '_uuid'];
                    if ($agare !== null && ($agare !== $ag_bef || $agare_uuid !== $ag_uuid_bef)) {
                        return fas_import_resultat($nyckel, false, ['Adresser kan inte flyttas till en annan ägare via import.'], [], null);
                    }
                    [$agare, $agare_uuid] = [$ag_bef, $ag_uuid_bef];
                }
            } else {
                if ($agare === null) {
                    return fas_import_resultat($nyckel, false, ['Fyll i fastighetsnummer eller byggnadsnummer (eller uuid).'], [], null);
                }
                if ($gata === null || $gata === KC_IMPORT_TOMT) {
                    return fas_import_resultat($nyckel, false, ['Gatuadress måste fyllas i.'], [], null);
                }
                $befintlig = kc_hamta_en(
                    "SELECT * FROM `$ta` WHERE `{$agare}_uuid` = ? AND is_deleted = 0 AND LOWER(gatuadress) = LOWER(?) ORDER BY id LIMIT 1",
                    [$agare_uuid, $gata]
                );
            }
            if ($agare === null) {
                return fas_import_resultat($nyckel, false, ['Ny adress kräver fastighetsnummer eller byggnadsnummer.'], [], null);
            }
            $post = $befintlig['uuid'] ?? $uuid ?? kc_uuid();
            if ($d = fas_import_dubblett($ctx, 'adr:' . $post,
                                          $gata !== null ? 'adrnamn:' . $agare_uuid . '|' . $gata : null)) {
                return fas_import_resultat($nyckel, false, [$d], [], null);
            }

            $nya = [];
            [$nya['gatuadress'], $f] = fas_import_text($gata, 150, 'Gatuadress');
            if ($f) { $fel[] = $f; }
            $c = kc_import_cell($rad, 'postnummer');
            $nya['postnummer'] = $c;
            if ($c !== null && $c !== KC_IMPORT_TOMT) {
                $nya['postnummer'] = fas_postnummer($c);
                if ($nya['postnummer'] === null) { $fel[] = 'Postnummer ska vara fem siffror.'; }
            }
            [$nya['ort'], $f] = fas_import_text(kc_import_cell($rad, 'ort'), 100, 'Ort');
            if ($f) { $fel[] = $f; }
            [$nya['beskrivning'], $f] = fas_import_text(kc_import_cell($rad, 'beskrivning'), 255, 'Beskrivning');
            if ($f) { $fel[] = $f; }
            $hu = mb_strtolower((string)kc_import_cell($rad, 'huvudadress'));
            $bli_huvud = in_array($hu, ['ja', 'j', '1', 'x'], true);

            $ny = $befintlig === null;
            foreach (['gatuadress', 'postnummer', 'ort'] as $k) {
                if ($ny && ($nya[$k] === null || $nya[$k] === KC_IMPORT_TOMT)) {
                    $fel[] = "Ny adress kräver $k.";
                } elseif (!$ny && $nya[$k] === KC_IMPORT_TOMT) {
                    $fel[] = "$k kan inte tömmas.";
                }
            }
            $fel = array_values(array_unique($fel));
            $delar = [];
            $skriv = [];
            $satt_huvud = false;
            if (!$ny) {
                $s = kc_import_sammanfoga($befintlig, $nya, $lage);
                $skriv = $s['andringar'];
                $delar[] = ['typ' => $s['typ'], 'text' => $s['falt'] ? fas_import_faltlista($s['falt']) . '.' : ''];
                if ($bli_huvud && $lage === 'hard' && (int)$befintlig['ar_huvudadress'] !== 1) {
                    $satt_huvud = true;
                    $delar[] = ['typ' => 'uppdaterad', 'text' => 'blir huvudadress.'];
                }
            } elseif ($bli_huvud && $lage === 'hard') {
                $satt_huvud = true;
            }
            $utfor = static function () use ($ny, $post, $nya, $skriv, $agare, $agare_uuid, $satt_huvud): void {
                if ($ny) {
                    $falt = fas_import_nya_falt($nya) + ['fastighet_uuid' => '', 'byggnad_uuid' => '', 'ar_huvudadress' => 0];
                    $falt[$agare . '_uuid'] = $agare_uuid;
                    fas_import_infoga('md_adress', $post, $falt);
                } else {
                    fas_import_uppdatera('md_adress', $post, $skriv);
                }
                fas_huvudadress_satt($agare, $agare_uuid, $satt_huvud ? $post : null);
            };
            return fas_import_resultat($nyckel, $ny, $fel, $delar, $utfor);
        },
        'export'   => static function (): array {
            $ta = kc_tabell('md_adress');
            $tf = kc_tabell('md_fastighet');
            $tb = kc_tabell('md_byggnad');
            return array_map(static function ($r) {
                $r['huvudadress'] = (int)$r['huvudadress'] === 1 ? 'ja' : 'nej';
                return $r;
            }, kc_hamta_alla(
                "SELECT a.uuid, f.fastighetsnummer, b.byggnadsnummer, a.gatuadress, a.postnummer, a.ort, a.beskrivning,
                        a.ar_huvudadress AS huvudadress
                   FROM `$ta` a
                   LEFT JOIN `$tf` f ON f.uuid = a.fastighet_uuid AND a.fastighet_uuid <> ''
                   LEFT JOIN `$tb` b ON b.uuid = a.byggnad_uuid AND a.byggnad_uuid <> ''
                  WHERE a.is_deleted = 0
                  ORDER BY COALESCE(f.fastighetsnummer, b.byggnadsnummer), a.gatuadress"
            ));
        },
    ];
}

function fas_import_def_objektgrupp(): array
{
    return [
        'kolumner'   => ['uuid', 'objektgruppsnummer', 'namn', 'objektgruppstyp', 'beskrivning'],
        'kravs'      => ['objektgruppsnummer'],
        'extra'      => '/^egenskap:.+$/u',
        'mall_extra' => fn() => kc_egenskap_import_kolumner('objektgrupp'),
        'hjalp'      => FAS_IMPORT_HJALP_GEMENSAM . '<p>Ny objektgrupp kräver objektgruppsnummer, namn och objektgruppstyp.
                         Kopplingar till fastigheter, byggnader och objekt görs tills vidare på gruppens sida.</p>',
        'forbered'   => fas_import_forbered('objektgrupp', ['objektgruppstyp']),
        'planera'    => static function (array $rad, string $lage, array &$ctx): array {
            $ctx['rad'] = $rad['_rad'];
            $fel = [];
            [$uuid, $f] = fas_import_uuid(kc_import_cell($rad, 'uuid'));
            if ($f) { $fel[] = $f; }
            $nummer = kc_import_cell($rad, 'objektgruppsnummer');
            $nyckel = $nummer ?? (string)$uuid;
            if ($fel !== []) {
                return fas_import_resultat($nyckel, false, $fel, [], null);
            }
            $h = fas_import_hitta('md_objektgrupp', 'objektgruppsnummer', $uuid, $nummer);
            if ($h['fel']) {
                return fas_import_resultat($nyckel, false, [$h['fel']], [], null);
            }
            if ($d = fas_import_dubblett($ctx, 'grp:' . $h['uuid'], 'grpnr:' . fas_import_nr($nummer))) {
                return fas_import_resultat($nyckel, false, [$d], [], null);
            }
            $nya = [];
            foreach (['objektgruppsnummer' => 50, 'namn' => 150, 'beskrivning' => 255] as $k => $max) {
                [$nya[$k], $f] = fas_import_text(kc_import_cell($rad, $k), $max, $k);
                if ($f) { $fel[] = $f; }
            }
            [$nya['objektgruppstyp_uuid'], $f] = fas_import_typ(kc_import_cell($rad, 'objektgruppstyp'), $ctx['typer']['objektgruppstyp'],
                                                                'objektgruppstyp', (string)($h['rad']['objektgruppstyp_uuid'] ?? ''));
            if ($f) { $fel[] = $f; }
            $ny = $h['rad'] === null;
            foreach (['objektgruppsnummer' => 'objektgruppsnummer', 'namn' => 'namn', 'objektgruppstyp_uuid' => 'objektgruppstyp'] as $k => $e) {
                if ($ny && ($nya[$k] === null || $nya[$k] === KC_IMPORT_TOMT)) {
                    $fel[] = "Ny objektgrupp kräver $e.";
                } elseif (!$ny && $nya[$k] === KC_IMPORT_TOMT) {
                    $fel[] = "$e kan inte tömmas.";
                }
            }
            $fel = array_values(array_unique($fel));
            $delar = [];
            $skriv = [];
            if (!$ny) {
                $s = kc_import_sammanfoga($h['rad'], $nya, $lage);
                if (isset($s['andringar']['objektgruppsnummer'])
                    && !fas_ar_unik('md_objektgrupp', 'objektgruppsnummer', $s['andringar']['objektgruppsnummer'], $h['uuid'])) {
                    $fel[] = 'Objektgruppsnumret ' . $s['andringar']['objektgruppsnummer'] . ' används redan.';
                }
                $skriv = $s['andringar'];
                $delar[] = ['typ' => $s['typ'], 'text' => $s['falt'] ? fas_import_faltlista($s['falt']) . '.' : ''];
            }
            $eg = fas_import_med_egenskaper($ctx, $rad, $ny ? null : $h['uuid'], $lage, $delar, $fel);
            $post = $h['uuid'];
            $utfor = static function () use ($ny, $post, $nya, $skriv, $eg): void {
                $ny ? fas_import_infoga('md_objektgrupp', $post, fas_import_nya_falt($nya)) : fas_import_uppdatera('md_objektgrupp', $post, $skriv);
                foreach ($eg as $e => $v) {
                    kc_egenskap_varde_skriv($e, $post, (string)$v);
                }
            };
            return fas_import_resultat($nyckel, $ny, $fel, $delar, $utfor);
        },
        'export'     => static function (): array {
            $eg = kc_egenskap_import_export('objektgrupp');
            $tg = kc_tabell('md_objektgrupp');
            $tt = kc_tabell('md_objektgruppstyp');
            return array_map(static fn($r) => $r + ($eg[$r['uuid']] ?? []), kc_hamta_alla(
                "SELECT g.uuid, g.objektgruppsnummer, g.namn, t.namn AS objektgruppstyp, g.beskrivning
                   FROM `$tg` g LEFT JOIN `$tt` t ON t.uuid = g.objektgruppstyp_uuid
                  WHERE g.is_deleted = 0 ORDER BY g.objektgruppsnummer"
            ));
        },
    ];
}

/* ---------- Kopplingar för objektgrupper ---------- */

function fas_import_def_koppling(): array
{
    return [
        'kolumner' => ['uuid', 'objektgruppsnummer', 'kopplingstyp', 'fastighetsnummer', 'byggnadsnummer', 'objektnummer'],
        'kravs'    => ['objektgruppsnummer', 'kopplingstyp'],
        'hjalp'    => '<p>En rad per koppling. <code>kopplingstyp</code> är <code>resurs</code> (själva saken, t.ex. soprummet) eller
                       <code>användare</code> (de som använder den). Fyll i <strong>ett</strong> av <code>fastighetsnummer</code>,
                       <code>byggnadsnummer</code> och <code>objektnummer</code>. En koppling har inga fält att ändra: raden blir ny
                       eller oförändrad (mjuk och hård import fungerar likadant). En tidigare borttagen koppling återaktiveras med samma uuid.
                       Import tar aldrig bort kopplingar – det görs på objektgruppens sida.</p>',
        'forbered' => fas_import_forbered(null, []),
        'planera'  => static function (array $rad, string $lage, array &$ctx): array {
            $ctx['rad'] = $rad['_rad'];
            $fel = [];
            $tk = kc_tabell('md_objektgrupp_koppling');
            $tg = kc_tabell('md_objektgrupp');
            [$uuid, $f] = fas_import_uuid(kc_import_cell($rad, 'uuid'));
            if ($f) { $fel[] = $f; }

            $gnr = kc_import_cell($rad, 'objektgruppsnummer');
            $grupp = null;
            if ($gnr === null || $gnr === KC_IMPORT_TOMT) {
                $fel[] = 'Objektgruppsnummer måste fyllas i.';
            } else {
                $grupp = kc_hamta_varde("SELECT uuid FROM `$tg` WHERE objektgruppsnummer = ? AND is_deleted = 0 LIMIT 1", [$gnr]);
                if ($grupp === null) { $fel[] = "Objektgruppen $gnr finns inte."; }
            }
            $kt = mb_strtolower((string)kc_import_cell($rad, 'kopplingstyp'));
            $kopplingstyp = match ($kt) {
                'resurs' => 'resurs',
                'användare', 'anvandare' => 'anvandare',
                default => null,
            };
            if ($kopplingstyp === null) { $fel[] = 'Kopplingstyp ska vara resurs eller användare.'; }

            $mal = [];
            foreach (['fastighet' => 'fastighetsnummer', 'byggnad' => 'byggnadsnummer', 'objekt' => 'objektnummer'] as $maltyp => $kol) {
                $c = kc_import_cell($rad, $kol);
                if ($c !== null && $c !== KC_IMPORT_TOMT) {
                    $mal[$maltyp] = $c;
                }
            }
            $maltyp = null;
            $mal_uuid = null;
            $malnr = '';
            if (count($mal) !== 1) {
                $fel[] = 'Fyll i exakt ett av fastighetsnummer, byggnadsnummer och objektnummer.';
            } else {
                $maltyp = array_key_first($mal);
                $malnr = $mal[$maltyp];
                $mal_uuid = fas_mal_hitta($maltyp, $malnr);
                if ($mal_uuid === null) { $fel[] = FAS_MALTYPER[$maltyp]['etikett'] . " $malnr finns inte."; }
            }
            $nyckel = trim(($gnr ?? '') . ' → ' . $malnr);
            if ($fel !== []) {
                return fas_import_resultat($nyckel, false, $fel, [], null);
            }
            if ($d = fas_import_dubblett($ctx, 'kop:' . $grupp . '|' . $maltyp . '|' . $mal_uuid . '|' . $kopplingstyp)) {
                return fas_import_resultat($nyckel, false, [$d], [], null);
            }

            // Befintlig koppling: med uuid, annars med samma innehåll (aktiv först)
            $befintlig = null;
            if ($uuid !== null) {
                $befintlig = kc_hamta_en("SELECT * FROM `$tk` WHERE uuid = ? LIMIT 1", [$uuid]);
                if ($befintlig !== null && ($befintlig['objektgrupp_uuid'] !== $grupp || $befintlig['maltyp'] !== $maltyp
                    || $befintlig['mal_uuid'] !== $mal_uuid || $befintlig['kopplingstyp'] !== $kopplingstyp)) {
                    return fas_import_resultat($nyckel, false, ['uuid:t tillhör en annan koppling (kopplingar kan inte ändras, bara läggas till och tas bort).'], [], null);
                }
            }
            if ($befintlig === null) {
                $befintlig = kc_hamta_en(
                    "SELECT * FROM `$tk` WHERE objektgrupp_uuid = ? AND maltyp = ? AND mal_uuid = ? AND kopplingstyp = ?
                      ORDER BY is_deleted ASC, id ASC LIMIT 1",
                    [$grupp, $maltyp, $mal_uuid, $kopplingstyp]
                );
            }
            $typtext = FAS_KOPPLINGSTYPER[$kopplingstyp] . ': ' . mb_strtolower(FAS_MALTYPER[$maltyp]['etikett']) . " $malnr";
            if ($befintlig !== null && (int)$befintlig['is_deleted'] === 0) {
                return fas_import_resultat($nyckel, false, [], [], null);   // oförändrad
            }
            if ($befintlig !== null) {
                $post = $befintlig['uuid'];
                $utfor = static function () use ($tk, $post): void {
                    kc_kor("UPDATE `$tk` SET is_deleted = 0, last_changed = ?, source_tool = 'fas' WHERE uuid = ?", [kc_nu(), $post]);
                };
                return fas_import_resultat($nyckel, true, [], [['typ' => null, 'text' => "$typtext (återaktiveras)."]], $utfor);
            }
            $post = $uuid ?? kc_uuid();
            $utfor = static function () use ($post, $grupp, $maltyp, $mal_uuid, $kopplingstyp): void {
                fas_import_infoga('md_objektgrupp_koppling', $post, [
                    'objektgrupp_uuid' => $grupp, 'maltyp' => $maltyp, 'mal_uuid' => $mal_uuid, 'kopplingstyp' => $kopplingstyp,
                ]);
            };
            return fas_import_resultat($nyckel, true, [], [['typ' => null, 'text' => "$typtext."]], $utfor);
        },
        'export'   => static function (): array {
            $tk = kc_tabell('md_objektgrupp_koppling');
            $tg = kc_tabell('md_objektgrupp');
            $tf = kc_tabell('md_fastighet');
            $tb = kc_tabell('md_byggnad');
            $to = kc_tabell('md_objekt');
            return array_map(static function ($r) {
                $r['kopplingstyp'] = $r['kopplingstyp'] === 'anvandare' ? 'användare' : $r['kopplingstyp'];
                return $r;
            }, kc_hamta_alla(
                "SELECT k.uuid, g.objektgruppsnummer, k.kopplingstyp,
                        IF(k.maltyp = 'fastighet', f.fastighetsnummer, '') AS fastighetsnummer,
                        IF(k.maltyp = 'byggnad', b.byggnadsnummer, '') AS byggnadsnummer,
                        IF(k.maltyp = 'objekt', o.objektnummer, '') AS objektnummer
                   FROM `$tk` k
                   JOIN `$tg` g ON g.uuid = k.objektgrupp_uuid AND g.is_deleted = 0
                   LEFT JOIN `$tf` f ON f.uuid = k.mal_uuid AND k.maltyp = 'fastighet' AND f.is_deleted = 0
                   LEFT JOIN `$tb` b ON b.uuid = k.mal_uuid AND k.maltyp = 'byggnad' AND b.is_deleted = 0
                   LEFT JOIN `$to` o ON o.uuid = k.mal_uuid AND k.maltyp = 'objekt' AND o.is_deleted = 0
                  WHERE k.is_deleted = 0
                    AND COALESCE(f.uuid, b.uuid, o.uuid) IS NOT NULL
                  ORDER BY g.objektgruppsnummer, k.kopplingstyp DESC, k.maltyp, k.id"
            ));
        },
    ];
}

/* ---------- Typlistor ---------- */

function fas_import_def_typlista(string $lista): array
{
    $def = fas_typlista($lista);
    $md = $def['tabell'];
    return [
        'kolumner' => ['uuid', 'namn', 'beskrivning', 'sortering', 'aktiv'],
        'kravs'    => ['namn'],
        'hjalp'    => '<p>En rad per ' . kc_h($def['etikett']) . '. Matchas på uuid eller namn (skiftlägesokänsligt). <code>sortering</code> är
                       ett heltal (lägre visas först; utelämnat = sist), <code>aktiv</code> är <code>ja</code> eller <code>nej</code>.
                       Byt namn genom att ange uuid och det nya namnet (hård import). Import tar aldrig bort typer.</p>',
        'forbered' => fas_import_forbered(null, []),
        'planera'  => static function (array $rad, string $lage, array &$ctx) use ($md, $def): array {
            $ctx['rad'] = $rad['_rad'];
            $t = kc_tabell($md);
            $fel = [];
            [$uuid, $f] = fas_import_uuid(kc_import_cell($rad, 'uuid'));
            if ($f) { $fel[] = $f; }
            $namn = kc_import_cell($rad, 'namn');
            $nyckel = $namn ?? (string)$uuid;
            if ($namn === KC_IMPORT_TOMT) { $fel[] = 'Namn kan inte tömmas.'; }
            if ($fel !== []) {
                return fas_import_resultat($nyckel, false, $fel, [], null);
            }
            $befintlig = null;
            if ($uuid !== null) {
                $befintlig = kc_hamta_en("SELECT * FROM `$t` WHERE uuid = ? LIMIT 1", [$uuid]);
                if ($befintlig !== null && (int)$befintlig['is_deleted'] === 1) {
                    return fas_import_resultat($nyckel, false, ['Typen med detta uuid är raderad.'], [], null);
                }
            }
            if ($befintlig === null && $namn !== null) {
                $hittad = kc_hamta_en("SELECT * FROM `$t` WHERE is_deleted = 0 AND LOWER(namn) = LOWER(?) ORDER BY id LIMIT 1", [$namn]);
                if ($hittad !== null && $uuid !== null) {
                    return fas_import_resultat($nyckel, false, ["Namnet $namn används redan av en annan typ (annat uuid)."], [], null);
                }
                $befintlig = $hittad;
            }
            if ($befintlig === null && $namn === null) {
                return fas_import_resultat($nyckel, false, ['Namn måste fyllas i.'], [], null);
            }
            $post = $befintlig['uuid'] ?? $uuid ?? kc_uuid();
            if ($d = fas_import_dubblett($ctx, 'typ:' . $post, 'typnamn:' . fas_import_nr($namn))) {
                return fas_import_resultat($nyckel, false, [$d], [], null);
            }
            $nya = [];
            [$nya['namn'], $f] = fas_import_text($namn, 100, 'Namn');
            if ($f) { $fel[] = $f; }
            [$nya['beskrivning'], $f] = fas_import_text(kc_import_cell($rad, 'beskrivning'), 255, 'Beskrivning');
            if ($f) { $fel[] = $f; }
            $c = kc_import_cell($rad, 'sortering');
            $nya['sortering'] = null;
            if ($c !== null && $c !== KC_IMPORT_TOMT) {
                if (preg_match('/^-?\d{1,6}$/', $c)) {
                    $nya['sortering'] = (string)(int)$c;
                } else {
                    $fel[] = 'Sortering ska vara ett heltal.';
                }
            }
            $c = kc_import_cell($rad, 'aktiv');
            $nya['is_active'] = null;
            if ($c !== null && $c !== KC_IMPORT_TOMT) {
                $nya['is_active'] = match (mb_strtolower($c)) {
                    'ja', 'j', '1', 'x' => '1',
                    'nej', 'n', '0'     => '0',
                    default             => null,
                };
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
                if (isset($s['andringar']['namn']) && mb_strtolower($s['andringar']['namn']) !== mb_strtolower($befintlig['namn'])
                    && (int)kc_hamta_varde("SELECT COUNT(*) FROM `$t` WHERE is_deleted = 0 AND LOWER(namn) = LOWER(?) AND uuid <> ?",
                                           [$s['andringar']['namn'], $post]) > 0) {
                    $fel[] = 'Namnet ' . $s['andringar']['namn'] . ' används redan.';
                }
                $skriv = $s['andringar'];
                $delar[] = ['typ' => $s['typ'], 'text' => $s['falt']
                    ? str_replace('is_active', 'aktiv', implode(', ', $s['falt'])) . '.' : ''];
            } else {
                // Ny typ hamnar sist om sortering saknas; aktiv om inget annat anges
                if ($nya['sortering'] === null) {
                    $ctx['max_sort'] ??= (int)kc_hamta_varde("SELECT COALESCE(MAX(sortering), 0) FROM `$t` WHERE is_deleted = 0");
                    $ctx['max_sort'] += 10;
                    $nya['sortering'] = (string)$ctx['max_sort'];
                }
                $nya['is_active'] ??= '1';
            }
            $utfor = static function () use ($ny, $md, $post, $nya, $skriv): void {
                $ny ? fas_import_infoga($md, $post, fas_import_nya_falt($nya)) : fas_import_uppdatera($md, $post, $skriv);
            };
            return fas_import_resultat($nyckel, $ny, $fel, $delar, $utfor);
        },
        'export'   => static function () use ($md): array {
            $t = kc_tabell($md);
            return array_map(static function ($r) {
                $r['aktiv'] = (int)$r['aktiv'] === 1 ? 'ja' : 'nej';
                return $r;
            }, kc_hamta_alla("SELECT uuid, namn, beskrivning, sortering, is_active AS aktiv FROM `$t` WHERE is_deleted = 0 ORDER BY sortering, namn"));
        },
    ];
}
