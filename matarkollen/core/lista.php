<?php
/**
 * Kollen kärnpaket – lista.php
 * Variant: GEMENSAM
 * Standardlista: sortering, filtrering, paginering och export (serverbaserat).
 *
 * Exempel:
 *   echo kc_lista([
 *     'tabell'            => kc_tabell('md_anvandare'),
 *     'kolumner'          => ['anvandarnamn' => 'Användarnamn', 'epost' => 'E-post'],
 *     'extra_kolumner'    => ['uuid'],                 // hämtas men visas inte (exporteras)
 *     'villkor'           => 'is_deleted = 0',         // fast villkor (valfritt)
 *     'villkor_params'    => [],
 *     'standardsortering' => 'anvandarnamn',
 *     'standardriktning'  => 'asc',
 *     'per_sida'          => 50,
 *     'format'            => ['is_active' => fn($v, $rad) => $v ? 'Aktiv' : 'Spärrad'],
 *     'radlank'           => fn($rad) => kc_url('anvandare.php?uuid=' . rawurlencode($rad['uuid'])),
 *     'tom_text'          => 'Inga användare.',
 *     // Export (fr.o.m. 1.2.0), allt valfritt:
 *     'export'            => true,                     // false = inga exportknappar
 *     'export_namn'       => 'anvandare',              // filnamnets mittdel (a–z, 0–9, _)
 *     'export_kolumner'   => null,                     // egen lista; standard = kolumner + extra_kolumner
 *     'export_uteslut'    => [],                       // kolumner som aldrig exporteras
 *   ]);
 * OBS: 'format'-funktioner returnerar färdig HTML – använd kc_h() på data.
 * En lista per sida (URL-parametrar kc_s, kc_o, kc_f, kc_p, kc_export).
 *
 * Lista över flera tabeller (fr.o.m. 1.1.0): ange 'kalla' i stället för 'tabell'.
 *   'kalla'        => "SELECT t.id, t.uuid, t.namn, v.prefix AS master FROM ... JOIN ...",
 *   'kalla_params' => [],
 * Källan måste innehålla kolumnen id och alla kolumner som listas (som alias).
 * 'kalla' får ALDRIG byggas av användarindata – bara av kod och kc_tabell().
 *
 * Export (fr.o.m. 1.2.0): CSV med semikolon och UTF-8 med BOM (svenska Excel).
 *   "Exportera urval" = aktuellt filter och sortering, alla sidor.
 *   "Exportera allt"  = alla rader listan kan visa (fasta villkor gäller, filter ignoreras).
 *   Råvärden (inte formaterade), rubrikrad = kolumnnamn, tider i UTC.
 *   Kräver att sidan inte har skickat ut något innan (bootstrap buffrar utdata).
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

const KC_EXPORT_SATS = 2000;   // rader per databasfråga vid export

function kc_lista(array $def): string
{
    $kalla_params = [];
    if (isset($def['kalla'])) {
        $from = '(' . (string)$def['kalla'] . ') AS kc_kalla';
        $kalla_params = array_values($def['kalla_params'] ?? []);
        $std_namn = 'lista';
    } else {
        $tabell = (string)($def['tabell'] ?? '');
        if (!preg_match('/^[a-z0-9_]+$/', $tabell)) {
            throw new KcFel('kc_lista: ogiltigt tabellnamn.', 500);
        }
        $from = "`$tabell`";
        $std_namn = $tabell;
    }
    $kolumner = $def['kolumner'] ?? [];
    $extra    = $def['extra_kolumner'] ?? [];
    if ($kolumner === []) {
        throw new KcFel('kc_lista: inga kolumner angivna.', 500);
    }
    $export_pa = ($def['export'] ?? true) !== false;
    $export_kol = $def['export_kolumner'] ?? array_values(array_unique(array_merge(array_keys($kolumner), $extra)));
    $export_kol = array_values(array_diff($export_kol, $def['export_uteslut'] ?? []));
    foreach (array_merge(array_keys($kolumner), $extra, $export_kol) as $k) {
        if (!is_string($k) || !preg_match('/^[a-z0-9_]+$/', $k)) {
            throw new KcFel('kc_lista: ogiltigt kolumnnamn.', 500);
        }
    }
    $per_sida = max(1, (int)($def['per_sida'] ?? 50));
    $format   = $def['format'] ?? [];
    $radlank  = $def['radlank'] ?? null;
    $tom_text = (string)($def['tom_text'] ?? 'Inga rader att visa.');

    // Indata (vitlistad)
    $std_sort = (string)($def['standardsortering'] ?? array_key_first($kolumner));
    if (!array_key_exists($std_sort, $kolumner)) {
        $std_sort = (string)array_key_first($kolumner);
    }
    $std_riktning = strtolower((string)($def['standardriktning'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
    $sort = kc_get('kc_s', $std_sort);
    if (!array_key_exists($sort, $kolumner)) {
        $sort = $std_sort;
    }
    $riktning = strtolower(kc_get('kc_o', $std_riktning)) === 'desc' ? 'desc' : 'asc';
    $filter   = mb_substr(kc_get('kc_f'), 0, 100);
    $sida     = max(1, (int)kc_get('kc_p', '1'));

    // Export – hanteras innan något HTML byggs, och avslutar anropet
    $export = kc_get('kc_export');
    if ($export_pa && in_array($export, ['urval', 'allt'], true)) {
        $allt = $export === 'allt';
        [$wsql, $params] = kc_lista_where($def, $kolumner, $kalla_params, $allt ? '' : $filter);
        kc_lista_exportera(
            $from, $wsql, $params, $export_kol,
            $allt ? $std_sort : $sort,
            $allt ? $std_riktning : $riktning,
            (string)($def['export_namn'] ?? $std_namn),
            $export
        );
    }

    [$wsql, $params] = kc_lista_where($def, $kolumner, $kalla_params, $filter);

    // Antal + sidor
    $totalt = (int)kc_hamta_varde("SELECT COUNT(*) FROM $from$wsql", $params);
    $sidor  = max(1, (int)ceil($totalt / $per_sida));
    $sida   = min($sida, $sidor);
    $offset = ($sida - 1) * $per_sida;

    // Rader
    $falt = array_unique(array_merge(array_keys($kolumner), $extra));
    $fsql = implode(', ', array_map(static fn($k) => "`$k`", $falt));
    $rader = kc_hamta_alla(
        "SELECT $fsql FROM $from$wsql ORDER BY `$sort` $riktning, `id` ASC LIMIT $per_sida OFFSET $offset",
        $params
    );

    // Filterformulär
    $h = '<div class="kc-lista"><form method="get" class="kc-filter">';
    foreach ($_GET as $n => $v) {
        if (is_string($n) && is_string($v) && !in_array($n, ['kc_f', 'kc_p', 'kc_export'], true)) {
            $h .= '<input type="hidden" name="' . kc_h($n) . '" value="' . kc_h($v) . '">';
        }
    }
    $h .= '<input type="search" name="kc_f" value="' . kc_h($filter) . '" placeholder="Filtrera…" aria-label="Filtrera">';
    $h .= '<button type="submit">Filtrera</button>';
    if ($filter !== '') {
        $h .= '<a href="' . kc_h(kc_lista_lank(['kc_f' => null, 'kc_p' => null])) . '">Rensa</a>';
    }
    $h .= '<span class="kc-lista-antal">';
    $h .= $totalt === 0
        ? 'Inga träffar'
        : 'Visar ' . ($offset + 1) . '–' . ($offset + count($rader)) . ' av ' . $totalt;
    $h .= '</span></form>';

    // Tabell
    $h .= '<div class="kc-tabell-wrap"><table class="kc-tabell"><thead><tr>';
    foreach ($kolumner as $k => $rubrik) {
        $ny_riktning = ($k === $sort && $riktning === 'asc') ? 'desc' : 'asc';
        $pil = $k === $sort ? ($riktning === 'asc' ? ' ▲' : ' ▼') : '';
        $h .= '<th><a class="' . ($k === $sort ? 'sorterad' : '') . '" href="'
            . kc_h(kc_lista_lank(['kc_s' => $k, 'kc_o' => $ny_riktning, 'kc_p' => null, 'kc_export' => null])) . '">'
            . kc_h($rubrik) . $pil . '</a></th>';
    }
    $h .= '</tr></thead><tbody>';

    if ($rader === []) {
        $h .= '<tr><td class="kc-tom" colspan="' . count($kolumner) . '">' . kc_h($tom_text) . '</td></tr>';
    }
    foreach ($rader as $rad) {
        $h .= '<tr>';
        $forsta = true;
        foreach (array_keys($kolumner) as $k) {
            $cell = isset($format[$k]) ? (string)($format[$k])($rad[$k], $rad) : kc_h($rad[$k]);
            if ($forsta && is_callable($radlank)) {
                $cell = '<a href="' . kc_h((string)$radlank($rad)) . '">' . ($cell !== '' ? $cell : '(tom)') . '</a>';
            }
            $h .= '<td>' . $cell . '</td>';
            $forsta = false;
        }
        $h .= '</tr>';
    }
    $h .= '</tbody></table></div>';

    if ($totalt > $per_sida) {
        $h .= kc_lista_sidor($sida, $sidor);
    }

    // Exportknappar
    if ($export_pa) {
        $h .= '<p class="kc-hjalp" style="margin-top:.6rem">Exportera (CSV): '
            . '<a href="' . kc_h(kc_lista_lank(['kc_export' => 'urval', 'kc_p' => null])) . '">urval'
            . ($filter !== '' ? ' (' . $totalt . ' rader)' : '') . '</a> · '
            . '<a href="' . kc_h(kc_lista_lank(['kc_export' => 'allt', 'kc_p' => null, 'kc_f' => null,
                                                'kc_s' => null, 'kc_o' => null])) . '">allt</a></p>';
    }
    return $h . '</div>';
}

/** Bygger WHERE-delen: källans parametrar först, sedan fast villkor, sedan filter. */
function kc_lista_where(array $def, array $kolumner, array $kalla_params, string $filter): array
{
    $where  = [];
    $params = $kalla_params;
    if (!empty($def['villkor'])) {
        $where[] = '(' . $def['villkor'] . ')';
        foreach (array_values($def['villkor_params'] ?? []) as $p) {
            $params[] = $p;
        }
    }
    if ($filter !== '') {
        $like = '%' . addcslashes($filter, '\\%_') . '%';
        $delar = [];
        foreach (array_keys($kolumner) as $k) {
            $delar[] = "CAST(`$k` AS CHAR) LIKE ?";
            $params[] = $like;
        }
        $where[] = '(' . implode(' OR ', $delar) . ')';
    }
    return [$where !== [] ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

/** Strömmar listan som CSV till webbläsaren och avslutar. */
function kc_lista_exportera(string $from, string $wsql, array $params, array $kol,
                            string $sort, string $riktning, string $namn, string $typ): never
{
    if (headers_sent()) {
        throw new KcFel('Exporten kunde inte starta eftersom sidan redan skickat innehåll.', 500);
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    @set_time_limit(300);

    $namn = preg_replace('/[^a-z0-9_]/', '', strtolower($namn)) ?: 'lista';
    $tid  = (new DateTimeImmutable('now', new DateTimeZone(KOLL_TIDSZON)))->format('Ymd-Hi');
    $fil  = $namn . '_' . $typ . '_' . $tid . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fil . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    $ut = fopen('php://output', 'wb');
    fwrite($ut, "\xEF\xBB\xBF");                          // BOM så att Excel läser UTF-8
    fputcsv($ut, $kol, ';', '"', '');

    $fsql = implode(', ', array_map(static fn($k) => "`$k`", $kol));
    $offset = 0;
    do {
        $rader = kc_hamta_alla(
            "SELECT $fsql FROM $from$wsql ORDER BY `$sort` $riktning, `id` ASC LIMIT "
            . KC_EXPORT_SATS . " OFFSET $offset",
            $params
        );
        foreach ($rader as $rad) {
            fputcsv($ut, array_map('kc_lista_csv_varde', array_values($rad)), ';', '"', '');
        }
        $offset += KC_EXPORT_SATS;
        flush();
    } while (count($rader) === KC_EXPORT_SATS);

    fclose($ut);
    exit;
}

/**
 * Ett värde i CSV. Text som börjar med = + - @ eller tab/radbrytning
 * (och inte är ett tal) får en apostrof först, så att Excel inte tolkar
 * den som en formel. Import i Kollen tar bort apostrofen igen.
 */
function kc_lista_csv_varde(mixed $v): string
{
    if ($v === null) {
        return '';
    }
    $s = (string)$v;
    if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false && !is_numeric($s)) {
        return "'" . $s;
    }
    return $s;
}

/** Länk till samma sida med ändrade parametrar (null = ta bort). */
function kc_lista_lank(array $andringar): string
{
    $q = [];
    foreach ($_GET as $n => $v) {
        if (is_string($n) && is_string($v) && $n !== 'kc_export') {
            $q[$n] = $v;
        }
    }
    foreach ($andringar as $n => $v) {
        if ($v === null) {
            unset($q[$n]);
        } else {
            $q[$n] = (string)$v;
        }
    }
    return '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
}

function kc_lista_sidor(int $sida, int $sidor): string
{
    $h = '<nav class="kc-sidor" aria-label="Sidor">';
    if ($sida > 1) {
        $h .= '<a href="' . kc_h(kc_lista_lank(['kc_p' => $sida - 1])) . '">« Föregående</a>';
    }
    $forra = 0;
    for ($i = 1; $i <= $sidor; $i++) {
        if ($i !== 1 && $i !== $sidor && abs($i - $sida) > 2) {
            continue;
        }
        if ($i - $forra > 1) {
            $h .= '<span class="lucka">…</span>';
        }
        $h .= $i === $sida
            ? '<span class="aktiv">' . $i . '</span>'
            : '<a href="' . kc_h(kc_lista_lank(['kc_p' => $i])) . '">' . $i . '</a>';
        $forra = $i;
    }
    if ($sida < $sidor) {
        $h .= '<a href="' . kc_h(kc_lista_lank(['kc_p' => $sida + 1])) . '">Nästa »</a>';
    }
    return $h . '</nav>';
}
