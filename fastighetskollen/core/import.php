<?php
/**
 * Kollen kärnpaket – import.php (fr.o.m. 1.6.0; radgräns per import från 1.9.0)
 * Variant: GEMENSAM
 *
 * Generell CSV-import med mall, förhandsvisning och genomförande. Verktyget anger
 * per tabell vilka kolumner som finns och hur en rad tolkas (kc_import_kor($def)).
 *
 * Regler (gemensamma för hela ekosystemet):
 *  - Mjuk import (förval): nya rader läggs till, tomma fält kompletteras, inget skrivs över.
 *  - Hård import: nya läggs till, befintliga värden skrivs över med filens värden.
 *  - En tom cell ändrar aldrig något. "(tom)" i en cell tömmer fältet vid hård import.
 *  - Ingen import raderar rader. Bara inloggade får importera; mall och export får alla hämta.
 *  - Alltid förhandsvisning först. Filens innehåll skickas med i formuläret (signerat),
 *    inget sparas på servern. Genomförandet sker i en transaktion.
 *
 * $def = [
 *   'tabell'      => 'objekt',                         // nyckel i URL:en och filnamn
 *   'rubrik'      => 'Objekt',
 *   'kolumner'    => ['uuid', 'objektnummer', ...],    // mallens kolumner i ordning
 *   'kravs'       => ['objektnummer'],                 // kolumner som måste finnas i filen
 *   'extra'       => '/^egenskap:.+$/u',               // valfritt: mönster för extra kolumner
 *   'hjalp'       => 'text om kolumnerna',             // visas på sidan
 *   'forbered'    => fn(array $rubriker): array        // ['fel' => [...], 'varningar' => [...], 'ctx' => [...]]
 *   'planera'     => fn(array $rad, string $lage, array &$ctx): array
 *                    // ['status' => ny|kompletterad|uppdaterad|oforandrad|fel, 'nyckel' => text,
 *                    //  'text' => detaljer, 'utfor' => ?callable]
 *   'export'      => fn(): array                       // rader [kolumn => värde] för "Exportera för import"
 *   'tillbaka'    => 'objektlista.php',
 *   'max_rader'   => 100,                              // valfritt (1.9.0): lägre radgräns än KC_IMPORT_MAX_RADER
 * ]
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

const KC_IMPORT_MAX_RADER = 1000;
const KC_IMPORT_MAX_BYTE  = 2097152;          // 2 MB
const KC_IMPORT_TOM       = '(tom)';          // i en cell: töm fältet (hård import)
const KC_IMPORT_TOMT      = "\u{0}tom\u{0}";  // internt värde för "(tom)"

const KC_IMPORT_STATUS = [
    'ny'          => 'Ny',
    'kompletterad' => 'Kompletterad',
    'uppdaterad'  => 'Uppdaterad',
    'oforandrad'  => 'Oförändrad',
    'fel'         => 'Fel',
];

/**
 * Läser CSV (semikolon, komma eller tab; UTF-8 eller Windows-1252, med eller utan BOM).
 * Returnerar ['rubriker' => [...], 'rader' => [[rubrik => värde, '_rad' => radnr], ...]].
 * Rubriker normaliseras till gemener utan omgivande blanksteg. Helt tomma rader hoppas över.
 */
function kc_import_las(string $innehall, int $max_rader = KC_IMPORT_MAX_RADER): array
{
    $max_rader = max(1, min($max_rader, KC_IMPORT_MAX_RADER));
    if (strlen($innehall) > KC_IMPORT_MAX_BYTE) {
        throw new KcFel('Filen är för stor (max 2 MB).', 400);
    }
    if (str_starts_with($innehall, "\xEF\xBB\xBF")) {
        $innehall = substr($innehall, 3);
    }
    if (!mb_check_encoding($innehall, 'UTF-8')) {
        $innehall = mb_convert_encoding($innehall, 'UTF-8', 'Windows-1252');
    }
    if (trim($innehall) === '') {
        throw new KcFel('Filen är tom.', 400);
    }
    $forsta = strtok($innehall, "\r\n") ?: '';
    $antal = [';' => substr_count($forsta, ';'), ',' => substr_count($forsta, ','), "\t" => substr_count($forsta, "\t")];
    $kc_sep = ';';
    if ($antal["\t"] > $antal[';'] && $antal["\t"] >= $antal[',']) {
        $kc_sep = "\t";
    } elseif ($antal[','] > $antal[';']) {
        $kc_sep = ',';
    }

    $fh = fopen('php://memory', 'r+b');
    fwrite($fh, $innehall);
    rewind($fh);
    $rubriker = fgetcsv($fh, null, $kc_sep, '"', '');
    if (!is_array($rubriker) || $rubriker === [null]) {
        throw new KcFel('Filen saknar rubrikrad.', 400);
    }
    $rubriker = array_map(static fn($r) => mb_strtolower(trim((string)$r)), $rubriker);
    $sedda = [];
    foreach ($rubriker as $i => $r) {
        if ($r === '') {
            throw new KcFel('Kolumn ' . ($i + 1) . ' saknar rubrik.', 400);
        }
        if (isset($sedda[$r])) {
            throw new KcFel("Kolumnen $r finns två gånger.", 400);
        }
        $sedda[$r] = true;
    }

    $rader = [];
    $radnr = 1;
    while (($f = fgetcsv($fh, null, $kc_sep, '"', '')) !== false) {
        $radnr++;
        if ($f === [null] || implode('', array_map('trim', array_map('strval', $f))) === '') {
            continue;
        }
        if (count($rader) >= $max_rader) {
            throw new KcFel('Filen har fler än ' . $max_rader . ' rader. Dela upp den.', 400);
        }
        $rad = ['_rad' => $radnr];
        foreach ($rubriker as $i => $r) {
            $v = trim((string)($f[$i] ?? ''));
            // Kollens export skyddar formler med apostrof – ta bort den igen
            if (strlen($v) > 1 && $v[0] === "'" && strpbrk($v[1], "=+-@") !== false) {
                $v = substr($v, 1);
            }
            $rad[$r] = $v;
        }
        $rader[] = $rad;
    }
    fclose($fh);
    if ($rader === []) {
        throw new KcFel('Filen har inga datarader.', 400);
    }
    return ['rubriker' => $rubriker, 'rader' => $rader];
}

/**
 * En cell: null om kolumnen saknas eller är tom, KC_IMPORT_TOMT för "(tom)", annars värdet.
 */
function kc_import_cell(array $rad, string $kolumn): ?string
{
    $v = $rad[$kolumn] ?? '';
    if ($v === '') {
        return null;
    }
    return mb_strtolower($v) === KC_IMPORT_TOM ? KC_IMPORT_TOMT : $v;
}

/**
 * Slår ihop befintliga värden med filens enligt läget.
 * $befintlig = [fält => nuvarande värde] (normaliserat), $nya = [fält => null (ingen uppgift) |
 * KC_IMPORT_TOMT | normaliserat värde], $tomt = [fält => värdet som betyder tomt ('' eller null)].
 * Returnerar ['andringar' => [fält => nytt värde], 'typ' => 'kompletterad'|'uppdaterad'|null, 'falt' => [fält...]].
 */
function kc_import_sammanfoga(array $befintlig, array $nya, string $lage, array $tomt = []): array
{
    $andringar = [];
    $typ = null;
    foreach ($nya as $k => $v) {
        if ($v === null) {
            continue;
        }
        $nu = $befintlig[$k] ?? null;
        $nu_tom = $nu === null || $nu === '';
        if ($v === KC_IMPORT_TOMT) {
            if ($lage === 'hard' && !$nu_tom) {
                $andringar[$k] = array_key_exists($k, $tomt) ? $tomt[$k] : '';
                $typ = 'uppdaterad';
            }
            continue;
        }
        if ((string)$nu === (string)$v) {
            continue;
        }
        if ($nu_tom) {
            $andringar[$k] = $v;
            $typ ??= 'kompletterad';
        } elseif ($lage === 'hard') {
            $andringar[$k] = $v;
            $typ = 'uppdaterad';
        }
    }
    return ['andringar' => $andringar, 'typ' => $andringar === [] ? null : $typ, 'falt' => array_keys($andringar)];
}

/** Strömmar en CSV i importformat (semikolon, UTF-8 med BOM, formelskydd) och avslutar. */
function kc_import_csv_ut(string $filnamn, array $kolumner, array $rader): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $filnamn = preg_replace('/[^a-z0-9_]/', '', strtolower($filnamn)) ?: 'import';
    $tid = (new DateTimeImmutable('now', new DateTimeZone(KOLL_TIDSZON)))->format('Ymd-Hi');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filnamn . '_' . $tid . '.csv"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $ut = fopen('php://output', 'wb');
    fwrite($ut, "\xEF\xBB\xBF");
    fputcsv($ut, $kolumner, ';', '"', '');
    foreach ($rader as $rad) {
        $rad_ut = [];
        foreach ($kolumner as $k) {
            $rad_ut[] = kc_lista_csv_varde($rad[$k] ?? '');
        }
        fputcsv($ut, $rad_ut, ';', '"', '');
    }
    fclose($ut);
    exit;
}

/** Signatur för filinnehållet mellan förhandsvisning och genomförande (bunden till sessionen). */
function kc_import_signatur(string $tabell, string $lage, string $innehall): string
{
    return hash_hmac('sha256', $tabell . '|' . $lage . '|' . $innehall, kc_csrf_token());
}

/** Kör förberedelse och planering för alla rader. Returnerar ['plan' => [...], 'fel' => [...], 'varningar' => [...]]. */
function kc_import_planera(array $def, array $fil, string $lage): array
{
    $kanda = array_flip($def['kolumner']);
    $varningar = [];
    $fel = [];
    foreach ($def['kravs'] ?? [] as $k) {
        if (!in_array($k, $fil['rubriker'], true)) {
            $fel[] = "Kolumnen $k saknas i filen. Utgå från mallen.";
        }
    }
    $okanda = [];
    foreach ($fil['rubriker'] as $r) {
        if (!isset($kanda[$r]) && !(isset($def['extra']) && preg_match($def['extra'], $r))) {
            $okanda[] = $r;
        }
    }
    if ($okanda !== []) {
        $varningar[] = 'Okända kolumner som inte läses: ' . implode(', ', $okanda) . '.';
    }
    $ctx = [];
    if ($fel === [] && isset($def['forbered'])) {
        $f = ($def['forbered'])($fil['rubriker']);
        $fel = array_merge($fel, $f['fel'] ?? []);
        $varningar = array_merge($varningar, $f['varningar'] ?? []);
        $ctx = $f['ctx'] ?? [];
    }
    $plan = [];
    if ($fel === []) {
        foreach ($fil['rader'] as $rad) {
            try {
                $p = ($def['planera'])($rad, $lage, $ctx);
            } catch (KcFel $e) {
                $p = ['status' => 'fel', 'nyckel' => '', 'text' => $e->getMessage(), 'utfor' => null];
            }
            $p['rad'] = $rad['_rad'];
            $plan[] = $p;
        }
    }
    return ['plan' => $plan, 'fel' => $fel, 'varningar' => $varningar];
}

/** Antal per status. */
function kc_import_summera(array $plan): array
{
    $n = array_fill_keys(array_keys(KC_IMPORT_STATUS), 0);
    foreach ($plan as $p) {
        $n[$p['status']] = ($n[$p['status']] ?? 0) + 1;
    }
    return $n;
}

/**
 * Hela importsidan för en tabell: mall, export för import, uppladdning, förhandsvisning, genomförande.
 */
function kc_import_kor(array $def): never
{
    $tabell = $def['tabell'];
    $sida = 'import.php?tabell=' . rawurlencode($tabell);
    $max_rader = kc_import_max_rader($def);

    // Mall och export för import – öppet för alla (som export i listorna)
    if (kc_get('hamta') === 'mall') {
        kc_import_csv_ut(KOLL_PREFIX . '_' . $tabell . '_mall', kc_import_alla_kolumner($def), []);
    }
    if (kc_get('hamta') === 'data') {
        kc_import_csv_ut(KOLL_PREFIX . '_' . $tabell . '_import', kc_import_alla_kolumner($def), ($def['export'])());
    }

    $resultat = null;
    $lage = 'mjuk';
    $innehall = '';
    $fel_fil = null;

    if (kc_ar_post()) {
        kc_krav_inloggning();
        $lage = kc_post('lage') === 'hard' ? 'hard' : 'mjuk';
        $atgard = kc_post('atgard');
        try {
            if ($atgard === 'forhandsvisa') {
                $f = $_FILES['fil'] ?? null;
                if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    throw new KcFel('Välj en fil att importera.', 400);
                }
                if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                    throw new KcFel($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE
                        ? 'Filen är för stor (max 2 MB).' : 'Uppladdningen misslyckades.', 400);
                }
                if ((int)$f['size'] > KC_IMPORT_MAX_BYTE) {
                    throw new KcFel('Filen är för stor (max 2 MB).', 400);
                }
                $innehall = (string)file_get_contents($f['tmp_name']);
                $resultat = kc_import_planera($def, kc_import_las($innehall, $max_rader), $lage);
            } elseif ($atgard === 'genomfor') {
                $innehall = base64_decode(kc_post('innehall'), true);
                if ($innehall === false || !hash_equals(kc_import_signatur($tabell, $lage, $innehall), kc_post('signatur'))) {
                    throw new KcFel('Importen kunde inte verifieras. Ladda upp filen igen.', 400);
                }
                $fil = kc_import_las($innehall, $max_rader);
                $n = kc_transaktion(function () use ($def, $fil, $lage): array {
                    $r = kc_import_planera($def, $fil, $lage);
                    if ($r['fel'] !== []) {
                        throw new KcFel(implode(' ', $r['fel']), 400);
                    }
                    foreach ($r['plan'] as $p) {
                        if ($p['status'] !== 'fel' && $p['status'] !== 'oforandrad' && is_callable($p['utfor'] ?? null)) {
                            ($p['utfor'])();
                        }
                    }
                    return kc_import_summera($r['plan']);
                });
                kc_flash('ok', 'Importen är klar: ' . $n['ny'] . ' nya, ' . $n['kompletterad'] . ' kompletterade, '
                    . $n['uppdaterad'] . ' uppdaterade, ' . $n['oforandrad'] . ' oförändrade'
                    . ($n['fel'] > 0 ? ', ' . $n['fel'] . ' rader med fel hoppades över' : '') . '. Glöm inte att skicka ändringarna till CentralKollen.');
                kc_omdirigera($def['tillbaka']);
            }
        } catch (KcFel $e) {
            $fel_fil = $e->getMessage();
            $resultat = null;
        }
    }

    kc_sidhuvud('Import: ' . $def['rubrik']);
    $inloggad = kc_inloggad() !== null;
    echo '<p><a href="' . kc_h(kc_url('import.php')) . '">« Alla importer</a> · <a href="' . kc_h(kc_url($def['tillbaka']))
       . '">' . kc_h($def['rubrik']) . '</a></p>';

    if ($fel_fil !== null) {
        echo '<div class="kc-flash kc-flash-fel"><strong>Kunde inte importera:</strong> ' . kc_h($fel_fil) . '</div>';
    }

    if ($resultat !== null) {
        kc_import_visa_resultat($def, $resultat, $lage, $innehall);
    }

    echo '<div class="kc-ruta" style="max-width:52rem"><h2>1. Hämta mall</h2>'
       . '<p><a class="kc-knapp" href="' . kc_h(kc_url($sida . '&hamta=mall')) . '">Ladda ner mall</a> '
       . '<a class="kc-knapp" href="' . kc_h(kc_url($sida . '&hamta=data')) . '">Exportera för import</a></p>'
       . '<p class="kc-hjalp">Mallen är tom med rätt kolumner. "Exportera för import" ger befintliga data i samma format, '
       . 'så att de kan ändras i Excel och importeras tillbaka. Spara som CSV (semikolonavgränsad).</p>';
    if (!empty($def['hjalp'])) {
        echo '<div class="kc-hjalp" style="margin-top:.5rem">' . $def['hjalp'] . '</div>';
    }
    echo '</div>';

    echo '<div class="kc-ruta" style="max-width:52rem"><h2>2. Ladda upp och förhandsgranska</h2>';
    if (!$inloggad) {
        echo '<p class="kc-dampad">Logga in för att importera.</p></div>';
        kc_sidfot();
        exit;
    }
    echo '<form method="post" enctype="multipart/form-data">' . kc_csrf_falt()
       . '<input type="hidden" name="atgard" value="forhandsvisa">'
       . '<input type="hidden" name="MAX_FILE_SIZE" value="' . KC_IMPORT_MAX_BYTE . '">'
       . '<div class="kc-falt"><label for="fil">CSV-fil (max ' . $max_rader . ' rader, 2 MB)</label>'
       . '<input type="file" id="fil" name="fil" accept=".csv,.txt,text/csv" required></div>'
       . '<div class="kc-falt"><label><input type="radio" name="lage" value="mjuk"' . ($lage === 'mjuk' ? ' checked' : '') . '> '
       . '<strong>Mjuk import</strong> – lägger till nya och fyller i tomma fält, skriver aldrig över</label>'
       . '<label><input type="radio" name="lage" value="hard"' . ($lage === 'hard' ? ' checked' : '') . '> '
       . '<strong>Hård import</strong> – lägger till nya och skriver över med filens värden</label>'
       . '<div class="kc-hjalp">Tomma celler ändrar aldrig något. Skriv <code>(tom)</code> för att tömma ett fält vid hård import. '
       . 'Ingen import raderar något.</div></div>'
       . '<button type="submit" class="kc-knapp-primar">Förhandsgranska</button></form></div>';
    kc_sidfot();
    exit;
}

/** Importens radgräns: 'max_rader' i definitionen (1.9.0), aldrig över KC_IMPORT_MAX_RADER. */
function kc_import_max_rader(array $def): int
{
    return max(1, min((int)($def['max_rader'] ?? KC_IMPORT_MAX_RADER), KC_IMPORT_MAX_RADER));
}

/** Mallens kolumner: verktygets kolumner plus ev. extra (t.ex. egenskaper) från 'mall_extra'. */
function kc_import_alla_kolumner(array $def): array
{
    return array_merge($def['kolumner'], isset($def['mall_extra']) ? ($def['mall_extra'])() : []);
}

/** Förhandsvisningen: summering, varningar, rader och knappen Genomför. */
function kc_import_visa_resultat(array $def, array $r, string $lage, string $innehall): void
{
    echo '<div class="kc-ruta"><h2>Förhandsvisning – ' . ($lage === 'hard' ? 'hård' : 'mjuk') . ' import</h2>';
    foreach ($r['fel'] as $f) {
        echo '<div class="kc-flash kc-flash-fel">' . kc_h($f) . '</div>';
    }
    foreach ($r['varningar'] as $v) {
        echo '<div class="kc-flash kc-flash-info">' . kc_h($v) . '</div>';
    }
    if ($r['fel'] !== []) {
        echo '<p>Rätta filen och ladda upp den igen.</p></div>';
        return;
    }
    $n = kc_import_summera($r['plan']);
    $delar = [];
    foreach (KC_IMPORT_STATUS as $k => $etikett) {
        $delar[] = $etikett . ': <strong>' . kc_h($n[$k]) . '</strong>';
    }
    echo '<p>' . implode(' · ', $delar) . '</p>';
    $andras = $n['ny'] + $n['kompletterad'] + $n['uppdaterad'];

    echo '<div class="kc-tabell-wrap"><table class="kc-tabell"><thead><tr><th>Rad</th><th>Nyckel</th><th>Resultat</th><th>Detaljer</th></tr></thead><tbody>';
    $oforandrade = 0;
    foreach ($r['plan'] as $p) {
        if ($p['status'] === 'oforandrad') {
            $oforandrade++;
            continue;
        }
        $stil = $p['status'] === 'fel' ? ' style="color:var(--kc-fel)"' : '';
        echo '<tr><td>' . kc_h($p['rad']) . '</td><td>' . kc_h($p['nyckel'] ?? '') . '</td>'
           . '<td' . $stil . '><strong>' . kc_h(KC_IMPORT_STATUS[$p['status']] ?? $p['status']) . '</strong></td>'
           . '<td>' . kc_h($p['text'] ?? '') . '</td></tr>';
    }
    echo '</tbody></table></div>';
    if ($oforandrade > 0) {
        echo '<p class="kc-hjalp">' . kc_h($oforandrade) . ' oförändrade rader visas inte.</p>';
    }

    if ($andras === 0) {
        echo '<p><strong>Inget att importera.</strong></p></div>';
        return;
    }
    echo '<form method="post" style="margin-top:.75rem">' . kc_csrf_falt()
       . '<input type="hidden" name="atgard" value="genomfor">'
       . '<input type="hidden" name="lage" value="' . kc_h($lage) . '">'
       . '<input type="hidden" name="innehall" value="' . kc_h(base64_encode($innehall)) . '">'
       . '<input type="hidden" name="signatur" value="' . kc_h(kc_import_signatur($def['tabell'], $lage, $innehall)) . '">'
       . '<button type="submit" class="kc-knapp-primar">Genomför: ' . kc_h($andras) . ' rader'
       . ($n['fel'] > 0 ? ' (' . kc_h($n['fel']) . ' med fel hoppas över)' : '') . '</button></form></div>';
}
