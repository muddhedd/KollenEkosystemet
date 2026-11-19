<?php
/**
 * Kollen kärnpaket – egenskap.php (från 1.8.0)
 * Variant: GEMENSAM (identisk i LABB, DEMO och GITHUB)
 *
 * Egenskaper = fält som användarna definierar själva, per nivå (t.ex. byggnad, mätare).
 * Varje verktyg har egna tabeller med sitt prefix (<prefix>_md_egenskap, _egenskapsval,
 * _egenskapsvarde); bara koden är gemensam.
 *
 * Verktyget:
 *   1. lägger till tabellerna:  const <PREFIX>_TABELLER = [ ... ] + KC_EGENSKAP_TABELLER;
 *   2. deklarerar nivåerna i funktionen <prefix>_egenskap_nivaer(): array, t.ex.
 *        ['byggnad' => ['tabell' => 'md_byggnad', 'nummer' => 'byggnadsnummer',
 *                       'sida' => 'byggnad.php', 'etikett' => 'Byggnad']]
 *      Har posten en kolumn "namn" visas den efter numret.
 *   3. har sidorna egenskaper.php, egenskap_redigera.php och egenskapsvarde_redigera.php,
 *      som bara anropar kc_egenskap_sida_lista(), kc_egenskap_sida_definition() resp.
 *      kc_egenskap_sida_varden();
 *   4. visar kc_egenskaper_ruta($niva, $uuid, $inloggad) på postens sida och anropar
 *      kc_egenskapsvarden_radera($uuid, $nu) när en post raderas.
 *
 * Värden lagras normaliserat som text: text som den är, heltal "42", decimaltal med punkt
 * "72.5", datum "ÅÅÅÅ-MM-DD", ja/nej "1"/"0", val = valets uuid.
 * Nivå och datatyp låses när egenskapen har värden. Ett val som används kan inte raderas.
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

const KC_EGENSKAP_DATATYPER = [
    'text' => 'Text', 'heltal' => 'Heltal', 'decimal' => 'Decimaltal',
    'datum' => 'Datum', 'janej' => 'Ja/nej', 'val' => 'Val ur lista',
];

/** Tabelldefinitioner (för kc_inst_tabell); läggs till verktygets tabeller. */
const KC_EGENSKAP_TABELLER = [
    'md_egenskap' => [
        'beskrivning' => 'Egenskapsdefinitioner: namn, nivå, datatyp, enhet.',
        'kolumner' => [
            'namn'        => "VARCHAR(100) NOT NULL DEFAULT ''",
            'beskrivning' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'niva'        => "VARCHAR(20)  NOT NULL DEFAULT ''",     // verktygets nivåer
            'datatyp'     => "VARCHAR(20)  NOT NULL DEFAULT ''",     // se KC_EGENSKAP_DATATYPER
            'enhet'       => "VARCHAR(20)  NOT NULL DEFAULT ''",
            'sortering'   => "INT NOT NULL DEFAULT 0",
            'is_active'   => "TINYINT(1) NOT NULL DEFAULT 1",
        ],
        'index' => ['niva'],
    ],
    'md_egenskapsval' => [
        'beskrivning' => 'Valen för egenskaper med datatypen val.',
        'kolumner' => [
            'egenskap_uuid' => "CHAR(36)     NOT NULL DEFAULT ''",
            'namn'          => "VARCHAR(100) NOT NULL DEFAULT ''",
            'sortering'     => "INT NOT NULL DEFAULT 0",
            'is_active'     => "TINYINT(1) NOT NULL DEFAULT 1",
        ],
        'index' => ['egenskap_uuid'],
    ],
    'md_egenskapsvarde' => [
        'beskrivning' => 'Egenskapsvärden (egenskap_uuid, post_uuid, varde som normaliserad text).',
        'kolumner' => [
            'egenskap_uuid' => "CHAR(36)     NOT NULL DEFAULT ''",
            'post_uuid'     => "CHAR(36)     NOT NULL DEFAULT ''",
            'varde'         => "VARCHAR(255) NOT NULL DEFAULT ''",
        ],
        'index' => ['egenskap_uuid', 'post_uuid'],
    ],
];

/* ---------- Nivåer och poster ---------- */

/** Verktygets nivåer: [nivå => [tabell, nummer, sida, etikett]]. */
function kc_egenskap_nivaer(): array
{
    $kc_f = KOLL_PREFIX . '_egenskap_nivaer';
    return function_exists($kc_f) ? $kc_f() : [];
}

/** Nivåns definition, eller KcFel 404. */
function kc_egenskap_niva(string $niva): array
{
    $kc_n = kc_egenskap_nivaer();
    if (!array_key_exists($niva, $kc_n)) {
        throw new KcFel('Okänd nivå för egenskaper.', 404);
    }
    return $kc_n[$niva] + ['nyckel' => $niva];
}

/** Posten som egenskaperna gäller, eller null. */
function kc_egenskap_post_hamta(string $niva, string $uuid): ?array
{
    $kc_def = kc_egenskap_niva($niva);
    if (!kc_ar_uuid($uuid)) {
        return null;
    }
    $kc_t = kc_tabell($kc_def['tabell']);
    return kc_hamta_en("SELECT * FROM `$kc_t` WHERE uuid = ? AND is_deleted = 0 LIMIT 1", [$uuid]);
}

/** "0101 Hus A" för en post (nummer + ev. namn). */
function kc_egenskap_post_text(string $niva, array $post): string
{
    return trim((string)$post[kc_egenskap_niva($niva)['nummer']] . ' ' . ($post['namn'] ?? ''));
}

/* ---------- Läsning ---------- */

function kc_egenskap_hamta(string $uuid): ?array
{
    if (!kc_ar_uuid($uuid)) {
        return null;
    }
    $kc_t = kc_tabell('md_egenskap');
    return kc_hamta_en("SELECT * FROM `$kc_t` WHERE uuid = ? AND is_deleted = 0 LIMIT 1", [$uuid]);
}

/** Aktiva egenskaper på en nivå, i visningsordning. */
function kc_egenskaper_aktiva(string $niva): array
{
    $kc_t = kc_tabell('md_egenskap');
    if (!kc_inst_tabell_finns($kc_t)) {
        return [];
    }
    return kc_hamta_alla(
        "SELECT * FROM `$kc_t` WHERE niva = ? AND is_deleted = 0 AND is_active = 1 ORDER BY sortering, namn",
        [$niva]
    );
}

/** Egenskapens val: [uuid => namn]. $bara_aktiva = false tar med inaktiva (för visning). */
function kc_egenskapsval(string $egenskap_uuid, bool $bara_aktiva = true): array
{
    $kc_t = kc_tabell('md_egenskapsval');
    $kc_r = kc_hamta_alla(
        "SELECT uuid, namn FROM `$kc_t` WHERE egenskap_uuid = ? AND is_deleted = 0"
        . ($bara_aktiva ? ' AND is_active = 1' : '') . ' ORDER BY sortering, namn',
        [$egenskap_uuid]
    );
    return array_column($kc_r, 'namn', 'uuid');
}

/** Postens aktiva värden: [egenskap_uuid => varde]. */
function kc_egenskap_varden(string $post_uuid): array
{
    $kc_t = kc_tabell('md_egenskapsvarde');
    $kc_r = kc_hamta_alla("SELECT egenskap_uuid, varde FROM `$kc_t` WHERE post_uuid = ? AND is_deleted = 0", [$post_uuid]);
    return array_column($kc_r, 'varde', 'egenskap_uuid');
}

/** Antal aktiva värden för en egenskap. */
function kc_egenskap_antal_varden(string $egenskap_uuid): int
{
    $kc_t = kc_tabell('md_egenskapsvarde');
    return (int)kc_hamta_varde("SELECT COUNT(*) FROM `$kc_t` WHERE egenskap_uuid = ? AND is_deleted = 0", [$egenskap_uuid]);
}

/** Antal aktiva värden som använder ett val. */
function kc_egenskapsval_anvand(string $egenskap_uuid, string $val_uuid): int
{
    $kc_t = kc_tabell('md_egenskapsvarde');
    return (int)kc_hamta_varde(
        "SELECT COUNT(*) FROM `$kc_t` WHERE egenskap_uuid = ? AND varde = ? AND is_deleted = 0",
        [$egenskap_uuid, $val_uuid]
    );
}

/* ---------- Tolkning och visning ---------- */

/**
 * Tolkar inmatning för en egenskap. Returnerar [normaliserat värde ('' = tomt), fel|null].
 * $val = tillåtna val [uuid => namn] för datatypen val.
 */
function kc_egenskap_tolka(array $egenskap, string $in, array $val = []): array
{
    $in = trim($in);
    if ($in === '') {
        return ['', null];
    }
    $kc_namn = $egenskap['namn'];
    switch ($egenskap['datatyp']) {
        case 'text':
            return mb_strlen($in) > 255 ? ['', "$kc_namn får vara max 255 tecken."] : [$in, null];
        case 'heltal':
            $kc_s = str_replace(' ', '', $in);
            return preg_match('/^-?\d{1,15}$/', $kc_s)
                ? [(string)(int)$kc_s, null]
                : ['', "$kc_namn ska vara ett heltal."];
        case 'decimal':
            $kc_s = str_replace([' ', ','], ['', '.'], $in);
            if (!preg_match('/^-?\d{1,15}(\.\d{1,6})?$/', $kc_s)) {
                return ['', "$kc_namn ska vara ett tal, t.ex. 72,5 (högst sex decimaler)."];
            }
            if (str_contains($kc_s, '.')) {
                $kc_s = rtrim(rtrim($kc_s, '0'), '.');
            }
            $kc_s = preg_replace('/^(-?)0+(?=\d)/', '$1', $kc_s) ?? $kc_s;
            return [$kc_s === '-0' ? '0' : $kc_s, null];
        case 'datum':
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $in, $kc_m) && checkdate((int)$kc_m[2], (int)$kc_m[3], (int)$kc_m[1])) {
                return [$in, null];
            }
            return ['', "$kc_namn ska vara ett datum (ÅÅÅÅ-MM-DD)."];
        case 'janej':
            return in_array($in, ['0', '1'], true) ? [$in, null] : ['', "$kc_namn ska vara ja eller nej."];
        case 'val':
            return array_key_exists($in, $val) ? [$in, null] : ['', "Välj ett giltigt alternativ för $kc_namn."];
    }
    return ['', "$kc_namn har en okänd datatyp."];
}

/** Ett lagrat värde för visning, med enhet. $valnamn = alla val [uuid => namn] för datatypen val. */
function kc_egenskap_visa(array $egenskap, string $varde, array $valnamn = []): string
{
    if ($varde === '') {
        return '';
    }
    $kc_text = match ($egenskap['datatyp']) {
        'decimal' => str_replace('.', ',', $varde),
        'janej'   => $varde === '1' ? 'Ja' : 'Nej',
        'val'     => $valnamn[$varde] ?? '(okänt val)',
        default   => $varde,
    };
    return $egenskap['enhet'] !== '' ? $kc_text . ' ' . $egenskap['enhet'] : $kc_text;
}

/** Värde i formulärfältet (decimalkomma för decimaltal). */
function kc_egenskap_formvarde(array $egenskap, string $varde): string
{
    return $egenskap['datatyp'] === 'decimal' ? str_replace('.', ',', $varde) : $varde;
}

/** <option>-taggar. $tom = text för tomt val. */
function kc_egenskap_alternativ(array $val, string $valt, string $tom = '– välj –'): string
{
    $kc_h = '<option value="">' . kc_h($tom) . '</option>';
    foreach ($val as $kc_u => $kc_n) {
        $kc_h .= '<option value="' . kc_h((string)$kc_u) . '"' . ((string)$kc_u === $valt ? ' selected' : '') . '>' . kc_h($kc_n) . '</option>';
    }
    return $kc_h;
}

/** Rutan "Egenskaper" på postens sida. */
function kc_egenskaper_ruta(string $niva, string $post_uuid, bool $inloggad): string
{
    $kc_eg = kc_egenskaper_aktiva($niva);
    $kc_v  = $kc_eg === [] ? [] : kc_egenskap_varden($post_uuid);
    $kc_h  = '<div class="kc-ruta" style="max-width:52rem"><h2>Egenskaper</h2>';
    if ($kc_eg === []) {
        $kc_h .= '<p><em class="kc-dampad">Inga egenskaper är definierade för nivån '
               . kc_h(mb_strtolower(kc_egenskap_niva($niva)['etikett'])) . '.</em></p>';
    } else {
        $kc_h .= '<div class="kc-tabell-wrap"><table class="kc-tabell"><tbody>';
        foreach ($kc_eg as $kc_e) {
            $kc_visa = kc_egenskap_visa($kc_e, $kc_v[$kc_e['uuid']] ?? '',
                $kc_e['datatyp'] === 'val' ? kc_egenskapsval($kc_e['uuid'], false) : []);
            $kc_h .= '<tr><th>' . kc_h($kc_e['namn']) . '</th><td>'
                   . ($kc_visa !== '' ? kc_h($kc_visa) : '<span class="kc-dampad">–</span>') . '</td></tr>';
        }
        $kc_h .= '</tbody></table></div>';
    }
    $kc_h .= '<p style="margin-top:.75rem">';
    if ($inloggad && $kc_eg !== []) {
        $kc_h .= '<a class="kc-knapp" href="' . kc_h(kc_url('egenskapsvarde_redigera.php?niva=' . rawurlencode($niva)
               . '&uuid=' . rawurlencode($post_uuid))) . '">Ändra egenskaper</a> ';
    }
    $kc_h .= '<a href="' . kc_h(kc_url('egenskaper.php?niva=' . rawurlencode($niva))) . '">Definiera egenskaper »</a></p></div>';
    return $kc_h;
}

/* ---------- Skrivning ---------- */

/**
 * Sparar ett egenskapsvärde ('' = ta bort, mjukt). Ett borttaget värde återaktiveras med samma uuid.
 * Returnerar true om något ändrades. Anropas inuti en transaktion.
 */
function kc_egenskap_varde_skriv(string $egenskap_uuid, string $post_uuid, string $varde): bool
{
    $kc_t  = kc_tabell('md_egenskapsvarde');
    $kc_nu = kc_nu();
    $kc_r  = kc_hamta_en(
        "SELECT uuid, varde, is_deleted FROM `$kc_t` WHERE egenskap_uuid = ? AND post_uuid = ? ORDER BY is_deleted ASC, id ASC LIMIT 1",
        [$egenskap_uuid, $post_uuid]
    );
    $kc_aktiv = $kc_r !== null && (int)$kc_r['is_deleted'] === 0;
    if ($varde === '') {
        if (!$kc_aktiv) {
            return false;
        }
        kc_kor("UPDATE `$kc_t` SET is_deleted = 1, last_changed = ?, source_tool = ? WHERE uuid = ?", [$kc_nu, KOLL_PREFIX, $kc_r['uuid']]);
        return true;
    }
    if ($kc_r === null) {
        kc_kor(
            "INSERT INTO `$kc_t` (uuid, last_changed, is_deleted, source_tool, egenskap_uuid, post_uuid, varde) VALUES (?, ?, 0, ?, ?, ?, ?)",
            [kc_uuid(), $kc_nu, KOLL_PREFIX, $egenskap_uuid, $post_uuid, $varde]
        );
        return true;
    }
    if ($kc_aktiv && $kc_r['varde'] === $varde) {
        return false;
    }
    kc_kor("UPDATE `$kc_t` SET varde = ?, is_deleted = 0, last_changed = ?, source_tool = ? WHERE uuid = ?",
           [$varde, $kc_nu, KOLL_PREFIX, $kc_r['uuid']]);
    return true;
}

/** Raderar (mjukt) alla egenskapsvärden för en post. Anropas när posten raderas. Returnerar antal. */
function kc_egenskapsvarden_radera(string $post_uuid, string $nu): int
{
    $kc_t = kc_tabell('md_egenskapsvarde');
    if (!kc_inst_tabell_finns($kc_t)) {
        return 0;
    }
    return kc_kor(
        "UPDATE `$kc_t` SET is_deleted = 1, last_changed = ?, source_tool = ? WHERE post_uuid = ? AND is_deleted = 0",
        [$nu, KOLL_PREFIX, $post_uuid]
    );
}

/* ---------- Import och export (kolumner "egenskap:<namn>") ---------- */

/** Mallens egenskapskolumner för en nivå. */
function kc_egenskap_import_kolumner(string $niva): array
{
    return array_map(static fn($e) => 'egenskap:' . mb_strtolower($e['namn']), kc_egenskaper_aktiva($niva));
}

/** Förbereder egenskapskolumner: ['karta' => [rubrik => egenskap + 'val' => [namn gemener => uuid]], 'fel' => [...]]. */
function kc_egenskap_import_forbered(string $niva, array $rubriker): array
{
    $kc_finns = [];
    foreach (kc_egenskaper_aktiva($niva) as $kc_e) {
        $kc_finns[mb_strtolower($kc_e['namn'])] = $kc_e;
    }
    $kc_karta = [];
    $kc_fel = [];
    foreach ($rubriker as $kc_r) {
        if (!str_starts_with($kc_r, 'egenskap:')) {
            continue;
        }
        $kc_namn = trim(mb_substr($kc_r, 9));
        $kc_e = $kc_finns[$kc_namn] ?? null;
        if ($kc_e === null) {
            $kc_fel[] = "Okänd egenskap \"$kc_namn\" för nivån " . mb_strtolower(kc_egenskap_niva($niva)['etikett'])
                      . ' (definiera den under Egenskaper eller ta bort kolumnen).';
            continue;
        }
        if ($kc_e['datatyp'] === 'val') {
            $kc_e['val'] = [];
            foreach (kc_egenskapsval($kc_e['uuid']) as $kc_u => $kc_n) {
                $kc_e['val'][mb_strtolower($kc_n)] = $kc_u;
            }
        }
        $kc_karta[$kc_r] = $kc_e;
    }
    return ['karta' => $kc_karta, 'fel' => $kc_fel];
}

/**
 * Planerar egenskapsvärden för en importrad.
 * Returnerar ['typ', 'text', 'fel' => [...], 'skriv' => [egenskap_uuid => värde|'']].
 */
function kc_egenskap_import_planera(array $karta, array $rad, ?string $post_uuid, string $lage): array
{
    $kc_bef = $post_uuid !== null ? kc_egenskap_varden($post_uuid) : [];
    $kc_fel = [];
    $kc_befintlig = [];
    $kc_nya = [];
    $kc_namn = [];
    foreach ($karta as $kc_rubrik => $kc_e) {
        $kc_cell = kc_import_cell($rad, $kc_rubrik);
        if ($kc_cell === null) {
            continue;
        }
        $kc_befintlig[$kc_e['uuid']] = $kc_bef[$kc_e['uuid']] ?? '';
        $kc_namn[$kc_e['uuid']] = $kc_e['namn'];
        if ($kc_cell === KC_IMPORT_TOMT) {
            $kc_nya[$kc_e['uuid']] = KC_IMPORT_TOMT;
            continue;
        }
        if ($kc_e['datatyp'] === 'janej') {
            $kc_cell = match (mb_strtolower($kc_cell)) {
                'ja', 'j', '1', 'x', 'sant', 'true' => '1',
                'nej', 'n', '0', 'falskt', 'false'  => '0',
                default                             => $kc_cell,
            };
        } elseif ($kc_e['datatyp'] === 'val') {
            $kc_cell = $kc_e['val'][mb_strtolower($kc_cell)] ?? ('okänt:' . $kc_cell);
        }
        [$kc_v, $kc_f] = kc_egenskap_tolka($kc_e, $kc_cell, array_flip($kc_e['val'] ?? []));
        if ($kc_f !== null) {
            $kc_fel[] = str_starts_with($kc_cell, 'okänt:') ? 'Okänt val "' . substr($kc_cell, 7) . '" för ' . $kc_e['namn'] . '.' : $kc_f;
            continue;
        }
        $kc_nya[$kc_e['uuid']] = $kc_v;
    }
    $kc_s = kc_import_sammanfoga($kc_befintlig, $kc_nya, $lage);
    return [
        'typ'   => $kc_s['typ'],
        'text'  => $kc_s['falt'] !== [] ? 'egenskaper: ' . implode(', ', array_map(static fn($u) => $kc_namn[$u], $kc_s['falt'])) . '.' : '',
        'fel'   => $kc_fel,
        'skriv' => $kc_s['andringar'],
    ];
}

/** Egenskapsvärden för export: [post_uuid => ['egenskap:<namn>' => text]]. */
function kc_egenskap_import_export(string $niva): array
{
    $kc_ut = [];
    $kc_t = kc_tabell('md_egenskapsvarde');
    foreach (kc_egenskaper_aktiva($niva) as $kc_e) {
        $kc_val = $kc_e['datatyp'] === 'val' ? kc_egenskapsval($kc_e['uuid'], false) : [];
        $kc_kol = 'egenskap:' . mb_strtolower($kc_e['namn']);
        foreach (kc_hamta_alla("SELECT post_uuid, varde FROM `$kc_t` WHERE egenskap_uuid = ? AND is_deleted = 0", [$kc_e['uuid']]) as $kc_v) {
            $kc_ut[$kc_v['post_uuid']][$kc_kol] = match ($kc_e['datatyp']) {
                'decimal' => str_replace('.', ',', $kc_v['varde']),
                'janej'   => $kc_v['varde'] === '1' ? 'ja' : 'nej',
                'val'     => $kc_val[$kc_v['varde']] ?? '',
                default   => $kc_v['varde'],
            };
        }
    }
    return $kc_ut;
}

/* ---------- Sidor (anropas från verktygets egenskaper.php m.fl.) ---------- */

/** Fel-ruta för formulär. */
function kc_egenskap_felruta(array $fel): string
{
    if ($fel === []) {
        return '';
    }
    $kc_h = '<div class="kc-flash kc-flash-fel"><strong>Kunde inte spara:</strong><ul style="margin:.25rem 0 0">';
    foreach ($fel as $kc_f) {
        $kc_h .= '<li>' . kc_h($kc_f) . '</li>';
    }
    return $kc_h . '</ul></div>';
}

/** egenskaper.php: lista över egenskapsdefinitioner. Läsning öppen för alla. Valfri parameter niva. */
function kc_egenskap_sida_lista(): void
{
    $kc_inloggad = kc_anvandare_kontrollera() !== null;
    $kc_nivaer = kc_egenskap_nivaer();
    $kc_te = kc_tabell('md_egenskap');
    $kc_tv = kc_tabell('md_egenskapsvarde');
    $kc_niva = kc_get('niva');
    if (!array_key_exists($kc_niva, $kc_nivaer)) {
        $kc_niva = '';
    }
    $kc_etiketter = array_map(static fn($d) => mb_strtolower($d['etikett']), $kc_nivaer);

    kc_sidhuvud('Egenskaper');
    echo '<p class="kc-dampad">Egenskaper är fält som ni definierar själva. Värdena fylls i på respektive '
       . kc_h(implode(', ', $kc_etiketter)) . '.</p>';
    $kc_delar = ['<a href="' . kc_h(kc_url('egenskaper.php')) . '">' . ($kc_niva === '' ? '<strong>alla</strong>' : 'alla') . '</a>'];
    foreach ($kc_nivaer as $kc_k => $kc_d) {
        $kc_delar[] = '<a href="' . kc_h(kc_url('egenskaper.php?niva=' . rawurlencode($kc_k))) . '">'
                    . ($kc_niva === $kc_k ? '<strong>' . kc_h($kc_d['etikett']) . '</strong>' : kc_h($kc_d['etikett'])) . '</a>';
    }
    echo '<p>Visa nivå: ' . implode(' · ', $kc_delar) . '</p>';
    if ($kc_inloggad) {
        echo '<p><a class="kc-knapp" href="' . kc_h(kc_url('egenskap_redigera.php' . ($kc_niva !== '' ? '?niva=' . rawurlencode($kc_niva) : '')))
           . '">+ Ny egenskap</a></p>';
    }
    echo kc_lista([
        'kalla' => "SELECT e.id, e.uuid, e.namn, e.niva, e.datatyp, e.enhet, e.sortering, e.is_active, e.beskrivning,
                           (SELECT COUNT(*) FROM `$kc_tv` v WHERE v.egenskap_uuid = e.uuid AND v.is_deleted = 0) AS antal_varden
                      FROM `$kc_te` e
                     WHERE e.is_deleted = 0",
        'villkor'           => $kc_niva !== '' ? 'niva = ?' : '',
        'villkor_params'    => $kc_niva !== '' ? [$kc_niva] : [],
        'kolumner'          => [
            'namn' => 'Namn', 'niva' => 'Nivå', 'datatyp' => 'Datatyp', 'enhet' => 'Enhet',
            'sortering' => 'Sortering', 'is_active' => 'Status', 'antal_varden' => 'Värden',
        ],
        'extra_kolumner'    => ['uuid', 'beskrivning'],
        'standardsortering' => 'niva',
        'format'            => [
            'niva'      => fn($v) => kc_h($kc_nivaer[$v]['etikett'] ?? $v),
            'datatyp'   => fn($v) => kc_h(KC_EGENSKAP_DATATYPER[$v] ?? $v),
            'is_active' => fn($v) => (int)$v === 1 ? 'Aktiv' : '<span class="kc-dampad">Inaktiv</span>',
        ],
        'radlank'           => fn($rad) => kc_url('egenskap_redigera.php?uuid=' . rawurlencode($rad['uuid'])),
        'export_namn'       => KOLL_PREFIX . '_egenskap',
        'tom_text'          => 'Inga egenskaper definierade.',
    ]);
    echo '<p class="kc-hjalp">Inaktiva egenskaper visas inte på posterna, men deras värden finns kvar. '
       . 'Nivå och datatyp låses när egenskapen har värden.</p>';
    kc_sidfot();
}

/** Delar upp en textruta i unika, icke-tomma rader. */
function kc_egenskap_rader(string $s): array
{
    return array_values(array_unique(array_filter(
        array_map('trim', preg_split('/\r\n|\r|\n/', $s) ?: []), static fn($x) => $x !== ''
    )));
}

/** Lägger till val (hoppar över namn som redan finns bland ej raderade). Returnerar antal nya. Anropas i transaktion. */
function kc_egenskapsval_lagg_till(string $egenskap_uuid, array $namnlista): int
{
    $kc_ts = kc_tabell('md_egenskapsval');
    $kc_n = 0;
    $kc_max = (int)kc_hamta_varde("SELECT COALESCE(MAX(sortering), 0) FROM `$kc_ts` WHERE egenskap_uuid = ? AND is_deleted = 0", [$egenskap_uuid]);
    foreach ($namnlista as $kc_namn) {
        if ((int)kc_hamta_varde("SELECT COUNT(*) FROM `$kc_ts` WHERE egenskap_uuid = ? AND namn = ? AND is_deleted = 0", [$egenskap_uuid, $kc_namn]) === 0) {
            $kc_max += 10;
            kc_kor(
                "INSERT INTO `$kc_ts` (uuid, last_changed, is_deleted, source_tool, egenskap_uuid, namn, sortering, is_active)
                 VALUES (?, ?, 0, ?, ?, ?, ?, 1)",
                [kc_uuid(), kc_nu(), KOLL_PREFIX, $egenskap_uuid, $kc_namn, $kc_max]
            );
            $kc_n++;
        }
    }
    return $kc_n;
}

/**
 * egenskap_redigera.php: skapa, ändra och radera en egenskapsdefinition och dess val.
 * Kräver inloggning. Parametrar: uuid (befintlig), annars ny (valfritt niva förväljer nivån).
 * Radering är mjuk och tar med egenskapens värden och val (efter bekräftelse).
 */
function kc_egenskap_sida_definition(): void
{
    kc_anvandare_kontrollera();
    kc_krav_inloggning();
    $kc_nivaer = kc_egenskap_nivaer();
    $kc_te = kc_tabell('md_egenskap');
    $kc_ts = kc_tabell('md_egenskapsval');
    $kc_tv = kc_tabell('md_egenskapsvarde');
    $kc_p  = KOLL_PREFIX;

    $uuid = kc_get('uuid');
    $ny   = $uuid === '';
    $rad  = null;
    if (!$ny) {
        $rad = kc_egenskap_hamta($uuid);
        if ($rad === null) {
            throw new KcFel('Egenskapen finns inte eller har raderats.', 404);
        }
    }
    $sida = 'egenskap_redigera.php?uuid=' . rawurlencode($uuid);
    $falt = ['namn', 'beskrivning', 'niva', 'datatyp', 'enhet', 'sortering', 'is_active'];
    $varden = $ny
        ? ['namn' => '', 'beskrivning' => '', 'niva' => kc_get('niva'), 'datatyp' => 'text', 'enhet' => '', 'sortering' => '0', 'is_active' => '1']
        : array_map('strval', array_intersect_key($rad, array_flip($falt)));
    $antal_varden = $ny ? 0 : kc_egenskap_antal_varden($uuid);
    $last = $antal_varden > 0;   // nivå och datatyp låsta
    $nya_val = '';
    $fel = [];

    /* Radera egenskapen */
    if (kc_ar_post() && kc_post('atgard') === 'radera' && !$ny) {
        $n = 0;
        kc_transaktion(function () use ($kc_te, $kc_ts, $kc_tv, $kc_p, $uuid, &$n): void {
            $nu = kc_nu();
            kc_kor("UPDATE `$kc_te` SET is_deleted = 1, last_changed = ?, source_tool = ? WHERE uuid = ? AND is_deleted = 0", [$nu, $kc_p, $uuid]);
            $n = kc_kor("UPDATE `$kc_tv` SET is_deleted = 1, last_changed = ?, source_tool = ? WHERE egenskap_uuid = ? AND is_deleted = 0", [$nu, $kc_p, $uuid]);
            kc_kor("UPDATE `$kc_ts` SET is_deleted = 1, last_changed = ?, source_tool = ? WHERE egenskap_uuid = ? AND is_deleted = 0", [$nu, $kc_p, $uuid]);
        });
        kc_flash('ok', 'Egenskapen ' . $rad['namn'] . ' är raderad' . ($n > 0 ? " tillsammans med $n värden." : '.'));
        kc_omdirigera('egenskaper.php?niva=' . rawurlencode($rad['niva']));
    }

    /* Val: lägg till, spara, radera */
    if (kc_ar_post() && !$ny && $rad['datatyp'] === 'val') {
        $atgard = kc_post('atgard');
        if ($atgard === 'val_lagg_till') {
            $n = kc_transaktion(fn() => kc_egenskapsval_lagg_till($uuid, kc_egenskap_rader(kc_post('nya_val'))));
            kc_flash($n > 0 ? 'ok' : 'info', $n > 0 ? "Lade till $n val." : 'Inga nya val (tomt eller fanns redan).');
            kc_omdirigera($sida);
        }
        if ($atgard === 'val_spara' || $atgard === 'val_radera') {
            $val = kc_hamta_en("SELECT * FROM `$kc_ts` WHERE uuid = ? AND egenskap_uuid = ? AND is_deleted = 0", [kc_post('val'), $uuid]);
            if ($val === null) {
                kc_flash('fel', 'Valet finns inte längre.');
                kc_omdirigera($sida);
            }
            if ($atgard === 'val_radera') {
                $anvand = kc_egenskapsval_anvand($uuid, $val['uuid']);
                if ($anvand > 0) {
                    kc_flash('fel', "Valet {$val['namn']} används av $anvand poster och kan inte raderas. Gör det inaktivt i stället.");
                } else {
                    kc_kor("UPDATE `$kc_ts` SET is_deleted = 1, last_changed = ?, source_tool = ? WHERE uuid = ?", [kc_nu(), $kc_p, $val['uuid']]);
                    kc_flash('ok', "Valet {$val['namn']} är raderat.");
                }
                kc_omdirigera($sida);
            }
            $namn = kc_post('namn');
            $sort = kc_post('sortering');
            $aktiv = kc_post('is_active') === '1' ? 1 : 0;
            if ($namn === '' || mb_strlen($namn) > 100) {
                kc_flash('fel', 'Valets namn måste fyllas i (max 100 tecken).');
            } elseif (!preg_match('/^-?\d{1,6}$/', $sort)) {
                kc_flash('fel', 'Sortering ska vara ett heltal.');
            } elseif ((int)kc_hamta_varde("SELECT COUNT(*) FROM `$kc_ts` WHERE egenskap_uuid = ? AND namn = ? AND uuid <> ? AND is_deleted = 0",
                                          [$uuid, $namn, $val['uuid']]) > 0) {
                kc_flash('fel', "Det finns redan ett val som heter $namn.");
            } elseif ($namn !== $val['namn'] || (int)$sort !== (int)$val['sortering'] || $aktiv !== (int)$val['is_active']) {
                kc_kor("UPDATE `$kc_ts` SET namn = ?, sortering = ?, is_active = ?, last_changed = ?, source_tool = ? WHERE uuid = ?",
                       [$namn, (int)$sort, $aktiv, kc_nu(), $kc_p, $val['uuid']]);
                kc_flash('ok', 'Valet är sparat.');
            } else {
                kc_flash('info', 'Inget var ändrat.');
            }
            kc_omdirigera($sida);
        }
    }

    /* Spara egenskapen */
    if (kc_ar_post() && kc_post('atgard') === 'spara') {
        foreach ($falt as $k) {
            $varden[$k] = kc_post($k);
        }
        $varden['is_active'] = $varden['is_active'] === '1' ? '1' : '0';
        if ($last) {
            $varden['niva'] = $rad['niva'];
            $varden['datatyp'] = $rad['datatyp'];
        }
        $nya_val = kc_post('nya_val');

        if ($varden['namn'] === '' || mb_strlen($varden['namn']) > 100) {
            $fel[] = 'Namn måste fyllas i (max 100 tecken).';
        }
        if (!array_key_exists($varden['niva'], $kc_nivaer)) {
            $fel[] = 'Välj nivå.';
        }
        if (!array_key_exists($varden['datatyp'], KC_EGENSKAP_DATATYPER)) {
            $fel[] = 'Välj datatyp.';
        }
        if ($fel === []) {
            $params = [$varden['niva'], $varden['namn']];
            $undantag = '';
            if (!$ny) {
                $undantag = ' AND uuid <> ?';
                $params[] = $uuid;
            }
            if ((int)kc_hamta_varde("SELECT COUNT(*) FROM `$kc_te` WHERE is_deleted = 0 AND niva = ? AND namn = ?$undantag", $params) > 0) {
                $fel[] = 'Det finns redan en egenskap med det namnet på nivån ' . mb_strtolower($kc_nivaer[$varden['niva']]['etikett']) . '.';
            }
        }
        if (mb_strlen($varden['beskrivning']) > 255) {
            $fel[] = 'Beskrivningen är för lång (max 255 tecken).';
        }
        if (mb_strlen($varden['enhet']) > 20) {
            $fel[] = 'Enheten får vara max 20 tecken.';
        }
        if (!preg_match('/^-?\d{1,6}$/', $varden['sortering'])) {
            $fel[] = 'Sortering ska vara ett heltal.';
        }

        if ($fel === []) {
            $spara = [
                'namn' => $varden['namn'], 'beskrivning' => $varden['beskrivning'], 'niva' => $varden['niva'],
                'datatyp' => $varden['datatyp'], 'enhet' => $varden['enhet'],
                'sortering' => (int)$varden['sortering'], 'is_active' => (int)$varden['is_active'],
            ];
            if ($ny) {
                $uuid = kc_uuid();
                kc_transaktion(function () use ($kc_te, $kc_p, $uuid, $spara, $varden, $nya_val): void {
                    kc_kor(
                        "INSERT INTO `$kc_te` (uuid, last_changed, is_deleted, source_tool, " . implode(', ', array_keys($spara)) . ")
                         VALUES (?, ?, 0, ?, " . implode(', ', array_fill(0, count($spara), '?')) . ')',
                        array_merge([$uuid, kc_nu(), $kc_p], array_values($spara))
                    );
                    if ($varden['datatyp'] === 'val') {
                        kc_egenskapsval_lagg_till($uuid, kc_egenskap_rader($nya_val));
                    }
                });
                kc_flash('ok', 'Egenskapen är skapad.' . ($varden['datatyp'] === 'val' ? ' Kontrollera valen nedan.' : ''));
                kc_omdirigera('egenskap_redigera.php?uuid=' . rawurlencode($uuid));
            }
            $andrat = false;
            foreach ($spara as $k => $v) {
                if ((string)$rad[$k] !== (string)$v) {
                    $andrat = true;
                    break;
                }
            }
            if ($andrat) {
                $set = implode(', ', array_map(static fn($k) => "`$k` = ?", array_keys($spara)));
                kc_kor("UPDATE `$kc_te` SET $set, last_changed = ?, source_tool = ? WHERE uuid = ? AND is_deleted = 0",
                       array_merge(array_values($spara), [kc_nu(), $kc_p, $uuid]));
            }
            kc_flash('ok', $andrat ? 'Ändringarna är sparade.' : 'Inget var ändrat.');
            kc_omdirigera($spara['datatyp'] === 'val' ? $sida : 'egenskaper.php?niva=' . rawurlencode($spara['niva']));
        }
    }

    /* Visning */
    $valrader = [];
    if (!$ny && $rad['datatyp'] === 'val') {
        $valrader = kc_hamta_alla("SELECT * FROM `$kc_ts` WHERE egenskap_uuid = ? AND is_deleted = 0 ORDER BY sortering, namn", [$uuid]);
    }
    $tillbaka = 'egenskaper.php' . ($varden['niva'] !== '' ? '?niva=' . rawurlencode($varden['niva']) : '');

    kc_sidhuvud($ny ? 'Ny egenskap' : 'Ändra egenskap: ' . $rad['namn']);
    ?>
<p><a href="<?= kc_h(kc_url($tillbaka)) ?>">« Tillbaka</a></p>
<?= kc_egenskap_felruta($fel) ?>
<form method="post">
  <?= kc_csrf_falt() ?>
  <input type="hidden" name="atgard" value="spara">
  <div class="kc-ruta" style="max-width:40rem">
    <div class="kc-falt">
      <label for="namn">Namn *</label>
      <input type="text" id="namn" name="namn" maxlength="100" required style="width:100%" value="<?= kc_h($varden['namn']) ?>">
    </div>
    <div class="kc-falt">
      <label for="niva">Nivå *</label>
      <select id="niva" name="niva" required <?= $last ? 'disabled' : '' ?>>
        <?= kc_egenskap_alternativ(array_map(static fn($d) => $d['etikett'], $kc_nivaer), $varden['niva']) ?>
      </select>
    </div>
    <div class="kc-falt">
      <label for="datatyp">Datatyp *</label>
      <select id="datatyp" name="datatyp" required <?= $last ? 'disabled' : '' ?>>
        <?php foreach (KC_EGENSKAP_DATATYPER as $k => $etikett): ?>
          <option value="<?= kc_h($k) ?>" <?= $k === $varden['datatyp'] ? 'selected' : '' ?>><?= kc_h($etikett) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($last): ?>
        <div class="kc-hjalp">Nivå och datatyp är låsta eftersom egenskapen har <?= kc_h($antal_varden) ?> värden.</div>
      <?php endif; ?>
    </div>
    <?php if ($ny): ?>
    <div class="kc-falt">
      <label for="nya_val">Val (bara för datatypen Val ur lista)</label>
      <textarea id="nya_val" name="nya_val" rows="4" style="width:100%" placeholder="Ett val per rad"><?= kc_h($nya_val) ?></textarea>
    </div>
    <?php endif; ?>
    <div class="kc-falt">
      <label for="enhet">Enhet</label>
      <input type="text" id="enhet" name="enhet" maxlength="20" style="width:8rem" value="<?= kc_h($varden['enhet']) ?>" placeholder="m²">
    </div>
    <div class="kc-falt">
      <label for="beskrivning">Beskrivning</label>
      <input type="text" id="beskrivning" name="beskrivning" maxlength="255" style="width:100%" value="<?= kc_h($varden['beskrivning']) ?>">
      <div class="kc-hjalp">Visas som hjälptext när värdet fylls i.</div>
    </div>
    <div class="kc-falt">
      <label for="sortering">Sortering</label>
      <input type="text" id="sortering" name="sortering" maxlength="7" style="width:6rem" value="<?= kc_h($varden['sortering']) ?>">
      <div class="kc-hjalp">Lägre tal visas först.</div>
    </div>
    <div class="kc-falt">
      <label><input type="checkbox" name="is_active" value="1" <?= $varden['is_active'] === '1' ? 'checked' : '' ?>> Aktiv</label>
      <div class="kc-hjalp">En inaktiv egenskap visas inte på posterna, men värdena finns kvar.</div>
    </div>
    <button type="submit" class="kc-knapp-primar"><?= $ny ? 'Skapa egenskap' : 'Spara' ?></button>
  </div>
</form>

<?php if (!$ny && $rad['datatyp'] === 'val'): ?>
<div class="kc-ruta" style="max-width:40rem">
  <h2>Val</h2>
  <?php if ($valrader === []): ?>
    <p><em class="kc-dampad">Inga val än. Lägg till nedan.</em></p>
  <?php else: ?>
    <div class="kc-tabell-wrap"><table class="kc-tabell">
      <thead><tr><th>Namn</th><th>Sortering</th><th>Aktiv</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($valrader as $v):
          $formid = 'val_' . $v['id']; ?>
        <tr>
          <td><input type="text" name="namn" form="<?= kc_h($formid) ?>" maxlength="100" value="<?= kc_h($v['namn']) ?>"></td>
          <td><input type="text" name="sortering" form="<?= kc_h($formid) ?>" maxlength="7" style="width:5rem" value="<?= kc_h($v['sortering']) ?>"></td>
          <td><input type="checkbox" name="is_active" form="<?= kc_h($formid) ?>" value="1" <?= (int)$v['is_active'] === 1 ? 'checked' : '' ?>></td>
          <td>
            <form method="post" id="<?= kc_h($formid) ?>" class="kc-inline">
              <?= kc_csrf_falt() ?>
              <input type="hidden" name="atgard" value="val_spara">
              <input type="hidden" name="val" value="<?= kc_h($v['uuid']) ?>">
              <button type="submit" class="kc-lank-knapp">spara</button>
            </form>
            ·
            <form method="post" class="kc-inline" onsubmit="return confirm('Radera valet <?= kc_h(addslashes($v['namn'])) ?>?');">
              <?= kc_csrf_falt() ?>
              <input type="hidden" name="atgard" value="val_radera">
              <input type="hidden" name="val" value="<?= kc_h($v['uuid']) ?>">
              <button type="submit" class="kc-lank-knapp">radera</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <form method="post" style="margin-top:.75rem">
    <?= kc_csrf_falt() ?>
    <input type="hidden" name="atgard" value="val_lagg_till">
    <div class="kc-falt">
      <label for="nya_val2">Lägg till val</label>
      <textarea id="nya_val2" name="nya_val" rows="3" style="width:100%" placeholder="Ett val per rad"></textarea>
    </div>
    <button type="submit">Lägg till</button>
  </form>
  <p class="kc-hjalp">Ett val som används kan inte raderas, bara göras inaktivt. Byter du namn på ett val följer alla värden med.</p>
</div>
<?php endif; ?>

<?php if (!$ny):
        $fraga = 'Radera egenskapen ' . $rad['namn'] . ($antal_varden > 0 ? " och dess $antal_varden värden" : '') . '?'; ?>
<div class="kc-ruta" style="max-width:40rem">
  <h2>Radera</h2>
  <p>Egenskapen tas bort<?= $antal_varden > 0 ? ' tillsammans med <strong>' . kc_h($antal_varden) . ' värden</strong>' : '' ?>.
     Vill du behålla värdena, gör egenskapen inaktiv i stället.</p>
  <form method="post" onsubmit="return confirm('<?= kc_h(addslashes($fraga)) ?>');">
    <?= kc_csrf_falt() ?>
    <input type="hidden" name="atgard" value="radera">
    <button type="submit" class="kc-knapp-fara">Radera egenskapen</button>
  </form>
</div>
<?php endif;
    kc_sidfot();
}

/**
 * egenskapsvarde_redigera.php: fyll i egenskapsvärden för en post. Kräver inloggning.
 * Tomt fält = värdet tas bort (mjukt). Bara verkliga ändringar får ny last_changed.
 * Parametrar: niva, uuid (postens).
 */
function kc_egenskap_sida_varden(): void
{
    kc_anvandare_kontrollera();
    kc_krav_inloggning();
    $niva = kc_get('niva');
    $def  = kc_egenskap_niva($niva);
    $uuid = kc_get('uuid');
    $post = kc_egenskap_post_hamta($niva, $uuid);
    if ($post === null) {
        throw new KcFel('Posten finns inte eller har raderats.', 404);
    }
    $postsida   = $def['sida'] . '?uuid=' . rawurlencode($uuid);
    $egenskaper = kc_egenskaper_aktiva($niva);
    $lagrade    = kc_egenskap_varden($uuid);

    // Val per egenskap: aktiva val, plus det valda även om det är inaktivt
    $valmap = [];
    foreach ($egenskaper as $e) {
        if ($e['datatyp'] === 'val') {
            $aktiva = kc_egenskapsval($e['uuid']);
            $alla   = kc_egenskapsval($e['uuid'], false);
            $valt   = $lagrade[$e['uuid']] ?? '';
            if ($valt !== '' && !isset($aktiva[$valt]) && isset($alla[$valt])) {
                $aktiva[$valt] = $alla[$valt] . ' (inaktiv)';
            }
            $valmap[$e['uuid']] = $aktiva;
        }
    }
    $form = [];
    foreach ($egenskaper as $e) {
        $form[$e['uuid']] = kc_egenskap_formvarde($e, $lagrade[$e['uuid']] ?? '');
    }
    $fel = [];

    if (kc_ar_post() && kc_post('atgard') === 'spara') {
        $in = $_POST['e'] ?? [];
        $in = is_array($in) ? $in : [];
        $nya = [];
        foreach ($egenskaper as $e) {
            $form[$e['uuid']] = is_string($in[$e['uuid']] ?? null) ? $in[$e['uuid']] : '';
            [$v, $f] = kc_egenskap_tolka($e, $form[$e['uuid']], $valmap[$e['uuid']] ?? []);
            if ($f !== null) {
                $fel[] = $f;
            }
            $nya[$e['uuid']] = $v;
        }
        if ($fel === []) {
            $andrade = 0;
            kc_transaktion(function () use ($uuid, $nya, &$andrade): void {
                foreach ($nya as $egenskap_uuid => $v) {
                    $andrade += kc_egenskap_varde_skriv($egenskap_uuid, $uuid, $v) ? 1 : 0;
                }
            });
            kc_flash('ok', $andrade > 0 ? "Egenskaperna är sparade ($andrade ändrade)." : 'Inget var ändrat.');
            kc_omdirigera($postsida);
        }
    }

    kc_sidhuvud('Egenskaper: ' . $def['etikett'] . ' ' . kc_egenskap_post_text($niva, $post));
    ?>
<p><a href="<?= kc_h(kc_url($postsida)) ?>">« Tillbaka</a></p>
<?= kc_egenskap_felruta($fel) ?>
<?php if ($egenskaper === []): ?>
  <div class="kc-flash kc-flash-info">Inga aktiva egenskaper finns för nivån <?= kc_h(mb_strtolower($def['etikett'])) ?>.
    <a href="<?= kc_h(kc_url('egenskap_redigera.php?niva=' . rawurlencode($niva))) ?>">Definiera en egenskap</a>.</div>
<?php else: ?>
<form method="post">
  <?= kc_csrf_falt() ?>
  <input type="hidden" name="atgard" value="spara">
  <div class="kc-ruta" style="max-width:44rem">
    <?php foreach ($egenskaper as $e):
        $id   = 'e_' . $e['id'];
        $namn = 'e[' . $e['uuid'] . ']';
        $v    = (string)$form[$e['uuid']]; ?>
      <div class="kc-falt">
        <label for="<?= kc_h($id) ?>"><?= kc_h($e['namn']) ?><?= $e['enhet'] !== '' ? ' (' . kc_h($e['enhet']) . ')' : '' ?></label>
        <?php switch ($e['datatyp']):
          case 'janej': ?>
            <select id="<?= kc_h($id) ?>" name="<?= kc_h($namn) ?>">
              <option value="">–</option>
              <option value="1" <?= $v === '1' ? 'selected' : '' ?>>Ja</option>
              <option value="0" <?= $v === '0' ? 'selected' : '' ?>>Nej</option>
            </select>
          <?php break;
          case 'val': ?>
            <select id="<?= kc_h($id) ?>" name="<?= kc_h($namn) ?>"><?= kc_egenskap_alternativ($valmap[$e['uuid']] ?? [], $v, '–') ?></select>
          <?php break;
          case 'datum': ?>
            <input type="date" id="<?= kc_h($id) ?>" name="<?= kc_h($namn) ?>" value="<?= kc_h($v) ?>">
          <?php break;
          case 'heltal':
          case 'decimal': ?>
            <input type="text" id="<?= kc_h($id) ?>" name="<?= kc_h($namn) ?>" maxlength="25" style="width:10rem"
                   inputmode="<?= $e['datatyp'] === 'heltal' ? 'numeric' : 'decimal' ?>" value="<?= kc_h($v) ?>">
          <?php break;
          default: ?>
            <input type="text" id="<?= kc_h($id) ?>" name="<?= kc_h($namn) ?>" maxlength="255" style="width:100%" value="<?= kc_h($v) ?>">
        <?php endswitch; ?>
        <?php if ($e['beskrivning'] !== ''): ?><div class="kc-hjalp"><?= kc_h($e['beskrivning']) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <button type="submit" class="kc-knapp-primar">Spara</button>
    <p class="kc-hjalp">Lämna ett fält tomt för att ta bort värdet.</p>
  </div>
</form>
<?php endif;
    kc_sidfot();
}
