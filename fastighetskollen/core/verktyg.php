<?php
/**
 * Kollen kärnpaket – verktyg.php (fr.o.m. 1.3.0, utökad i 1.4.0, 1.5.0 och 1.7.0)
 * Variant: GEMENSAM
 *
 * API-klient och synk mellan verktyg och CentralKollen:
 *  - kc_api_post()             gemensam HTTP-klient för kontrakt v1 (används åt båda hållen)
 *  - kc_cen_anrop()            verktyget anropar CentralKollens API
 *  - kc_anvandare_synka()      hämtar ändrade användare till <prefix>_ld_cen_anvandare
 *  - kc_verktyg_logga_in()     inloggning mot lokal kopia, uppdaterad från CentralKollen först
 *  - kc_anvandare_kontrollera() kontrollerar inloggad användare mot lokal kopia vid varje sidvisning
 *  - kc_synk_status_*()        <prefix>_sys_synkstatus
 *  - kc_kontrakt_rad()         en databasrad i kontraktets radformat
 *  - kc_verktyg_api()          verktygets egna endpoints (ping, tabeller, export) som CentralKollen anropar
 *  - kc_push_*()               skickar ändrade masterrader till CentralKollen (push)
 *  - kc_pull_*()               hämtar prenumererade tabeller till <prefix>_ld_<master>_<tabell> (pull)
 * CentralKollen använder bara kc_api_post() (för anrop till verktygen).
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

const KC_KONTRAKT        = 1;
const KC_SYNK_MAX_VARV   = 100;    // max antal sidor (à 500 rader) per synk
const KC_SYNK_SPARR_SEK  = 10;     // minsta tid mellan två manuella synkar
const KC_PUSH_MARGINAL_SEK = 5;    // push börjar så här långt före senast skickade ändring

/** Bcrypt-hash av ett slumpat lösenord; jämförs mot när användaren saknas (lika svarstid). */
const KC_DUMMY_HASH = '$2y$12$PWIc1GAXVzSFJoO4pmkepOXJU5OkoJbqO.id4h/aJC4wT3ftODZQC';

/* ---------- Anrop till CentralKollen ---------- */

function kc_cen_konfigurerad(): bool
{
    return defined('CEN_URL') && defined('NYCKEL_TILL_CEN')
        && str_ends_with((string)CEN_URL, '/')
        && preg_match('/^[0-9a-f]{64}$/', (string)NYCKEL_TILL_CEN) === 1;
}

/**
 * POST enligt API-kontrakt v1. Returnerar svarets "data".
 * $motpart används i felmeddelanden (t.ex. "CentralKollen" eller verktygets namn).
 * Kastar KcFel med begripligt meddelande vid alla fel.
 */
function kc_api_post(string $url, string $prefix, string $nyckel, array $data, int $timeout, string $motpart): mixed
{
    $kc_body = json_encode(['kontrakt' => KC_KONTRAKT] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $kc_head = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Kollen-Prefix: ' . $prefix,
        'X-Kollen-Nyckel: ' . $nyckel,
    ];

    if (function_exists('curl_init')) {
        $kc_ch = curl_init($url);
        curl_setopt_array($kc_ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $kc_body,
            CURLOPT_HTTPHEADER     => $kc_head,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        ]);
        $kc_svar   = curl_exec($kc_ch);
        $kc_status = (int)curl_getinfo($kc_ch, CURLINFO_RESPONSE_CODE);
        $kc_fel    = $kc_svar === false ? curl_error($kc_ch) : '';
    } else {
        $kc_ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $kc_head), 'content' => $kc_body,
            'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        $kc_svar = @file_get_contents($url, false, $kc_ctx);
        $kc_status = 0;
        foreach ($http_response_header ?? [] as $kc_h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $kc_h, $kc_m)) {
                $kc_status = (int)$kc_m[1];
            }
        }
        $kc_fel = $kc_svar === false ? 'anropet misslyckades' : '';
    }

    if ($kc_svar === false || $kc_svar === '') {
        throw new KcFel("$motpart svarar inte" . ($kc_fel !== '' ? ': ' . $kc_fel : '') . '.', 503);
    }
    $kc_json = json_decode((string)$kc_svar, true);
    if (!is_array($kc_json) || !array_key_exists('ok', $kc_json)) {
        throw new KcFel("Oväntat svar från $motpart (HTTP $kc_status). Kontrollera adressen och att api/ finns.", 502);
    }
    if ($kc_json['ok'] !== true) {
        $kc_msg = is_string($kc_json['error'] ?? null) ? $kc_json['error'] : 'okänt fel';
        throw new KcFel("$motpart: " . $kc_msg, $kc_status >= 400 ? $kc_status : 502);
    }
    return $kc_json['data'] ?? null;
}

/** POST till CentralKollens API. Returnerar svarets "data". */
function kc_cen_anrop(string $endpoint, array $data = [], int $timeout = 15): mixed
{
    if (!kc_cen_konfigurerad()) {
        throw new KcFel('Kopplingen till CentralKollen är inte konfigurerad (CEN_URL och NYCKEL_TILL_CEN i config.php).', 503);
    }
    if (!preg_match('/^[a-z]+$/', $endpoint)) {
        throw new KcFel('Ogiltig endpoint.', 500);
    }
    return kc_api_post(CEN_URL . 'api/?e=' . $endpoint, KOLL_PREFIX, NYCKEL_TILL_CEN, $data, $timeout, 'CentralKollen');
}

/* ---------- Synkstatus (<prefix>_sys_synkstatus) ---------- */

function kc_synk_status(string $tabell, string $riktning): ?array
{
    return kc_hamta_en(
        'SELECT * FROM `' . kc_tabell('sys_synkstatus') . '` WHERE tabell = ? AND riktning = ? LIMIT 1',
        [$tabell, $riktning]
    );
}

/**
 * Sparar senaste synkstatus för tabell + riktning.
 * $senaste_lc (push): senast skickade last_changed; sparas bara vid OK och bara om det anges
 * (kolumnen senaste_lc finns från kärna 1.5.0, skapas av install.php).
 */
function kc_synk_status_satt(string $tabell, string $riktning, int $status, int $antal, ?int $seq, string $meddelande,
                             ?string $senaste_lc = null): void
{
    $kc_nu = kc_nu();
    $kc_ok = $status === 200;
    if ($senaste_lc !== null) {
        kc_kor(
            'INSERT INTO `' . kc_tabell('sys_synkstatus') . '`
                (tabell, riktning, senaste_tid, senaste_ok_tid, senaste_seq, senaste_lc, antal, status, meddelande)
             VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                senaste_tid    = VALUES(senaste_tid),
                senaste_ok_tid = IF(VALUES(status) = 200, VALUES(senaste_tid), senaste_ok_tid),
                senaste_lc     = IF(VALUES(status) = 200, VALUES(senaste_lc), senaste_lc),
                antal          = VALUES(antal),
                status         = VALUES(status),
                meddelande     = VALUES(meddelande)',
            [$tabell, $riktning, $kc_nu, $kc_ok ? $kc_nu : null, $senaste_lc, $antal, $status, mb_substr($meddelande, 0, 255)]
        );
        return;
    }
    kc_kor(
        'INSERT INTO `' . kc_tabell('sys_synkstatus') . '`
            (tabell, riktning, senaste_tid, senaste_ok_tid, senaste_seq, antal, status, meddelande)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            senaste_tid    = VALUES(senaste_tid),
            senaste_ok_tid = IF(VALUES(status) = 200, VALUES(senaste_tid), senaste_ok_tid),
            senaste_seq    = IF(VALUES(status) = 200, VALUES(senaste_seq), senaste_seq),
            antal          = VALUES(antal),
            status         = VALUES(status),
            meddelande     = VALUES(meddelande)',
        [$tabell, $riktning, $kc_nu, $kc_ok ? $kc_nu : null, (int)($seq ?? 0), $antal, $status, mb_substr($meddelande, 0, 255)]
    );
}

/** Spärr mot att samma synk startas om och om igen (t.ex. av en besökare). */
function kc_synk_for_tat(string $tabell, string $riktning): bool
{
    $kc_s = kc_synk_status($tabell, $riktning);
    if ($kc_s === null || $kc_s['senaste_tid'] === null) {
        return false;
    }
    $kc_senast = new DateTimeImmutable($kc_s['senaste_tid'], new DateTimeZone('UTC'));
    return (time() - $kc_senast->getTimestamp()) < KC_SYNK_SPARR_SEK;
}

/* ---------- Användare ---------- */

/** Sparar en användarrad från CentralKollen i den lokala kopian (alltid över). */
function kc_anvandare_spara(array $r): void
{
    $kc_uuid = (string)($r['uuid'] ?? '');
    $kc_d    = is_array($r['data'] ?? null) ? $r['data'] : [];
    if (!kc_ar_uuid($kc_uuid)) {
        throw new KcFel('CentralKollen skickade en användare med ogiltigt uuid.', 502);
    }
    $kc_lc  = is_string($r['last_changed'] ?? null) ? $r['last_changed'] : kc_nu();
    $kc_del = (int)($r['is_deleted'] ?? 0) === 1 ? 1 : 0;
    $kc_v = [
        mb_substr((string)($kc_d['anvandarnamn'] ?? ''), 0, 100),
        mb_substr((string)($kc_d['fornamn'] ?? ''), 0, 100),
        mb_substr((string)($kc_d['efternamn'] ?? ''), 0, 100),
        mb_substr((string)($kc_d['epost'] ?? ''), 0, 255),
        mb_substr((string)($kc_d['telefon'] ?? ''), 0, 50),
        (string)($kc_d['losenord_hash'] ?? ''),
        (int)($kc_d['is_active'] ?? 0) === 1 ? 1 : 0,
    ];
    $kc_t = kc_tabell('ld_cen_anvandare');
    $kc_finns = kc_hamta_varde("SELECT id FROM `$kc_t` WHERE uuid = ? LIMIT 1", [$kc_uuid]);
    if ($kc_finns !== null) {
        kc_kor(
            "UPDATE `$kc_t` SET last_changed = ?, is_deleted = ?, source_tool = 'cen',
                    anvandarnamn = ?, fornamn = ?, efternamn = ?, epost = ?, telefon = ?, losenord_hash = ?, is_active = ?
              WHERE uuid = ?",
            array_merge([$kc_lc, $kc_del], $kc_v, [$kc_uuid])
        );
    } else {
        kc_kor(
            "INSERT INTO `$kc_t` (uuid, last_changed, is_deleted, source_tool,
                    anvandarnamn, fornamn, efternamn, epost, telefon, losenord_hash, is_active)
             VALUES (?, ?, ?, 'cen', ?, ?, ?, ?, ?, ?, ?)",
            array_merge([$kc_uuid, $kc_lc, $kc_del], $kc_v)
        );
    }
}

/**
 * Hämtar alla ändrade användare sedan förra synken. Returnerar antal rader.
 * Kastar KcFel vid fel (status sparas i sys_synkstatus).
 */
function kc_anvandare_synka(): int
{
    $kc_efter = (int)(kc_synk_status('cen_anvandare', 'pull')['senaste_seq'] ?? 0);
    $kc_antal = 0;
    try {
        for ($kc_varv = 0; $kc_varv < KC_SYNK_MAX_VARV; $kc_varv++) {
            $kc_d = kc_cen_anrop('anvandare', ['efter_seq' => $kc_efter]);
            $kc_rader = is_array($kc_d['rader'] ?? null) ? $kc_d['rader'] : [];
            kc_transaktion(function () use ($kc_rader): void {
                foreach ($kc_rader as $kc_r) {
                    kc_anvandare_spara(is_array($kc_r) ? $kc_r : []);
                }
            });
            $kc_antal += count($kc_rader);
            $kc_efter = max($kc_efter, (int)($kc_d['senaste_seq'] ?? $kc_efter));
            // Spara framsteg efter varje sida, så att ett avbrott inte gör om allt
            kc_synk_status_satt('cen_anvandare', 'pull', 200, $kc_antal, $kc_efter, 'ok');
            if (($kc_d['mer'] ?? false) !== true) {
                break;
            }
        }
    } catch (KcFel $e) {
        kc_synk_status_satt('cen_anvandare', 'pull', $e->status(), $kc_antal, null, $e->getMessage());
        throw $e;
    }
    return $kc_antal;
}

/**
 * Hämtar en enskild användare från CentralKollen (används vid inloggning).
 * Saknar användaren behörighet till verktyget markeras den lokala kopian som raderad.
 */
function kc_anvandare_hamta_en(string $anvandarnamn): void
{
    $kc_d = kc_cen_anrop('anvandare', ['anvandarnamn' => $anvandarnamn], 5);
    $kc_a = $kc_d['anvandare'] ?? null;
    if (is_array($kc_a)) {
        kc_anvandare_spara($kc_a);
        return;
    }
    kc_kor(
        'UPDATE `' . kc_tabell('ld_cen_anvandare') . "` SET is_deleted = 1, last_changed = ?
          WHERE anvandarnamn = ? AND is_deleted = 0",
        [kc_nu(), $anvandarnamn]
    );
}

/**
 * Loggar in mot den lokala användarkopian. Först uppdateras kopian från
 * CentralKollen om den svarar; annars används den lokala kopian som den är.
 * Returnerar ['ok' => bool, 'fel' => ?string, 'kalla' => 'cen'|'lokal', 'anvandare' => ?array].
 */
function kc_verktyg_logga_in(string $anvandarnamn, string $losenord): array
{
    if (kc_inloggning_sparrad($anvandarnamn)) {
        return ['ok' => false, 'fel' => 'För många misslyckade försök. Vänta 15 minuter och försök igen.', 'kalla' => 'lokal', 'anvandare' => null];
    }
    $kc_kalla = 'lokal';
    if (kc_cen_konfigurerad()) {
        try {
            kc_anvandare_hamta_en($anvandarnamn);
            $kc_kalla = 'cen';
        } catch (KcFel $e) {
            error_log('[Kollen ' . KOLL_PREFIX . '] Inloggning: CentralKollen nåddes inte, lokal kopia används: ' . $e->getMessage());
        }
    }

    $kc_rad = kc_hamta_en(
        'SELECT * FROM `' . kc_tabell('ld_cen_anvandare') . '` WHERE anvandarnamn = ? AND is_deleted = 0 ORDER BY id LIMIT 1',
        [$anvandarnamn]
    );
    // Verifiera alltid mot en hash så att svarstiden inte avslöjar om namnet finns
    $kc_hash = $kc_rad !== null && $kc_rad['losenord_hash'] !== '' ? $kc_rad['losenord_hash'] : KC_DUMMY_HASH;
    $kc_ok   = password_verify($losenord, $kc_hash);

    if ($kc_rad === null || !$kc_ok) {
        kc_inloggning_registrera($anvandarnamn, false);
        return ['ok' => false, 'fel' => 'Fel användarnamn eller lösenord, eller saknad behörighet till ' . KOLL_NAMN . '.', 'kalla' => $kc_kalla, 'anvandare' => null];
    }
    if ((int)$kc_rad['is_active'] !== 1) {
        kc_inloggning_registrera($anvandarnamn, false);
        return ['ok' => false, 'fel' => 'Kontot är spärrat. Kontakta en administratör.', 'kalla' => $kc_kalla, 'anvandare' => null];
    }
    kc_inloggning_registrera($anvandarnamn, true);
    kc_logga_in($kc_rad);
    return ['ok' => true, 'fel' => null, 'kalla' => $kc_kalla, 'anvandare' => $kc_rad];
}

/**
 * Kontrollerar inloggad användare mot den lokala kopian. Raderad, spärrad
 * eller borttagen behörighet ger utloggning direkt. Returnerar raden eller null.
 */
function kc_anvandare_kontrollera(): ?array
{
    static $kc_klar = false;
    static $kc_rad  = null;
    if ($kc_klar) {
        return $kc_rad;
    }
    $kc_klar = true;

    $kc_session = kc_inloggad();
    if ($kc_session === null) {
        return null;
    }
    $kc_r = kc_hamta_en(
        'SELECT * FROM `' . kc_tabell('ld_cen_anvandare') . '` WHERE uuid = ? AND is_deleted = 0 LIMIT 1',
        [(string)$kc_session['uuid']]
    );
    if ($kc_r === null || (int)$kc_r['is_active'] !== 1) {
        kc_logga_ut();
        kc_flash('info', 'Din inloggning är inte längre giltig. Logga in igen.');
        return null;
    }
    $kc_rad = $kc_r;
    return $kc_rad;
}

/* ---------- Kontraktets radformat ---------- */

/** Standardfält som aldrig ingår i "data". */
const KC_STANDARDFALT = ['id', 'uuid', 'last_changed', 'is_deleted', 'source_tool'];

/**
 * En databasrad (SELECT *) i kontraktets radformat:
 * {uuid, last_changed (UTC, mikrosekunder), is_deleted (0|1), data: {övriga kolumner}}.
 */
function kc_kontrakt_rad(array $rad): array
{
    $kc_lc = (new DateTimeImmutable((string)$rad['last_changed'], new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    $kc_data = array_diff_key($rad, array_flip(KC_STANDARDFALT));
    return [
        'uuid'         => (string)$rad['uuid'],
        'last_changed' => $kc_lc,
        'is_deleted'   => (int)$rad['is_deleted'] === 1 ? 1 : 0,
        'data'         => $kc_data === [] ? new stdClass() : $kc_data,
    ];
}

/* ---------- Verktygets API (CentralKollen anropar) ---------- */

const KC_API_MAX_RADER = 500;
const KC_API_MAX_BYTE  = 100000;

/**
 * Kör verktygets API och avslutar anropet. Anropas från <verktyg>/api/index.php:
 *   define('KC_API', true);
 *   require dirname(__DIR__) . '/core/bootstrap.php';
 *   require dirname(__DIR__) . '/<prefix>.php';
 *   kc_verktyg_api(<prefix>_delade_tabeller());
 *
 * $tabeller: [tabellnamn => ['tabell' => 'md_xxx', 'beskrivning' => 'text']]
 *   tabellnamn = namnet i CentralKollens register (utan prefix och md_).
 *
 * Endpoints (POST, JSON med "kontrakt": 1, headers X-Kollen-Prefix: cen och X-Kollen-Nyckel: NYCKEL_FRAN_CEN):
 *   ping      → {verktyg, namn, variant, tid, kontrakt, karna, antal_tabeller}
 *   tabeller  → {tabeller: [{tabell, beskrivning}]}
 *   export    {tabell, sida} → {tabell, sida, totalt, mer, rader: [...]}  (alla rader, även raderade, 500 per sida)
 */
function kc_verktyg_api(array $tabeller): never
{
    foreach ($tabeller as $kc_namn => $kc_def) {
        if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', (string)$kc_namn) || !preg_match('/^md_[a-z0-9_]+$/', (string)($kc_def['tabell'] ?? ''))) {
            throw new KcFel("Felaktig tabelldeklaration för $kc_namn.", 500);
        }
    }

    $kc_endpoint = is_string($_GET['e'] ?? null) ? $_GET['e'] : '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new KcFel('Endast POST är tillåtet.', 400);
    }
    if (!in_array($kc_endpoint, ['ping', 'tabeller', 'export'], true)) {
        throw new KcFel('Okänd endpoint. Giltiga: ping, tabeller, export.', 404);
    }

    // Body
    $kc_ra = file_get_contents('php://input', false, null, 0, KC_API_MAX_BYTE + 1);
    if ($kc_ra === false || $kc_ra === '') {
        throw new KcFel('Anropet saknar JSON-body.', 400);
    }
    if (strlen($kc_ra) > KC_API_MAX_BYTE) {
        throw new KcFel('Anropet är för stort.', 400);
    }
    try {
        $kc_in = json_decode($kc_ra, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new KcFel('Ogiltig JSON.', 400);
    }
    if (!is_array($kc_in) || (array_is_list($kc_in) && $kc_in !== [])) {
        throw new KcFel('JSON-bodyn måste vara ett objekt.', 400);
    }
    if (($kc_in['kontrakt'] ?? null) !== KC_KONTRAKT) {
        throw new KcFel('Fältet kontrakt saknas eller stöds inte (förväntat ' . KC_KONTRAKT . ').', 400);
    }

    // Autentisering: bara CentralKollen, med NYCKEL_FRAN_CEN
    $kc_egen = defined('NYCKEL_FRAN_CEN') ? strtolower((string)NYCKEL_FRAN_CEN) : '';
    if (!preg_match('/^[0-9a-f]{64}$/', $kc_egen)) {
        throw new KcFel('Verktygets API är inte konfigurerat (NYCKEL_FRAN_CEN i config.php).', 503);
    }
    $kc_prefix = strtolower(trim((string)($_SERVER['HTTP_X_KOLLEN_PREFIX'] ?? '')));
    $kc_nyckel = strtolower(trim((string)($_SERVER['HTTP_X_KOLLEN_NYCKEL'] ?? '')));
    if ($kc_prefix !== 'cen' || !hash_equals($kc_egen, $kc_nyckel)) {
        throw new KcFel('Ogiltig autentisering (X-Kollen-Prefix, X-Kollen-Nyckel).', 401);
    }

    if ($kc_endpoint === 'ping') {
        kc_json(true, [
            'verktyg'        => KOLL_PREFIX,
            'namn'           => KOLL_NAMN,
            'variant'        => KOLL_VARIANT,
            'tid'            => kc_nu(),
            'kontrakt'       => KC_KONTRAKT,
            'karna'          => KC_VERSION,
            'antal_tabeller' => count($tabeller),
        ]);
    }

    if ($kc_endpoint === 'tabeller') {
        ksort($tabeller);
        $kc_lista = [];
        foreach ($tabeller as $kc_namn => $kc_def) {
            $kc_lista[] = ['tabell' => (string)$kc_namn, 'beskrivning' => (string)($kc_def['beskrivning'] ?? '')];
        }
        kc_json(true, ['tabeller' => $kc_lista]);
    }

    // export
    $kc_namn = $kc_in['tabell'] ?? null;
    if (!is_string($kc_namn) || !array_key_exists($kc_namn, $tabeller)) {
        throw new KcFel('Fältet tabell saknas eller är inte en av verktygets delade tabeller.', 404);
    }
    $kc_sida = $kc_in['sida'] ?? 1;
    if (!is_int($kc_sida) || $kc_sida < 1 || $kc_sida > 100000) {
        throw new KcFel('Fältet sida måste vara ett heltal ≥ 1.', 400);
    }
    $kc_t = kc_tabell($tabeller[$kc_namn]['tabell']);
    if (!kc_inst_tabell_finns($kc_t)) {
        throw new KcFel("Tabellen $kc_namn är inte installerad i verktyget.", 404);
    }
    $kc_totalt = (int)kc_hamta_varde("SELECT COUNT(*) FROM `$kc_t`");
    $kc_offset = ($kc_sida - 1) * KC_API_MAX_RADER;
    $kc_rader = kc_hamta_alla("SELECT * FROM `$kc_t` ORDER BY id LIMIT " . KC_API_MAX_RADER . " OFFSET $kc_offset");
    kc_json(true, [
        'tabell' => $kc_namn,
        'sida'   => $kc_sida,
        'totalt' => $kc_totalt,
        'mer'    => $kc_offset + count($kc_rader) < $kc_totalt,
        'rader'  => array_map('kc_kontrakt_rad', $kc_rader),
    ]);
}

/* ---------- Push: egna masterrader till CentralKollen ---------- */

/** Senast skickade last_changed för en tabell, eller null om den aldrig skickats. */
function kc_push_senaste(string $namn): ?string
{
    $kc_s = kc_synk_status($namn, 'push');
    $kc_lc = $kc_s['senaste_lc'] ?? null;
    return is_string($kc_lc) && $kc_lc !== '' ? $kc_lc : null;
}

/** Antal rader (inkl. raderade) som ändrats efter senaste push. */
function kc_push_osynkade(string $namn, string $md_tabell): int
{
    $kc_t = kc_tabell($md_tabell);
    if (!kc_inst_tabell_finns($kc_t)) {
        return 0;
    }
    $kc_lc = kc_push_senaste($namn);
    return $kc_lc === null
        ? (int)kc_hamta_varde("SELECT COUNT(*) FROM `$kc_t`")
        : (int)kc_hamta_varde("SELECT COUNT(*) FROM `$kc_t` WHERE last_changed > ?", [$kc_lc]);
}

/**
 * Skickar en tabells ändrade rader (även raderade) till CentralKollen, 500 per anrop,
 * i ordning last_changed, id. Börjar KC_PUSH_MARGINAL_SEK före senast skickade ändring,
 * så att rader från samtidiga sparningar inte missas; dubbletter ignoreras av CentralKollen.
 * Framsteg sparas efter varje anrop. Returnerar ['skickade', 'sparade', 'ignorerade', 'mer'].
 * Kastar KcFel vid fel (status sparas i sys_synkstatus).
 */
function kc_push_tabell(string $namn, string $md_tabell): array
{
    $kc_t = kc_tabell($md_tabell);
    $kc_senaste = kc_push_senaste($namn);
    $kc_fran = $kc_senaste === null
        ? '1970-01-01 00:00:00.000000'
        : (new DateTimeImmutable($kc_senaste, new DateTimeZone('UTC')))
            ->modify('-' . KC_PUSH_MARGINAL_SEK . ' seconds')->format('Y-m-d H:i:s.u');
    $kc_cur_lc = $kc_fran;
    $kc_cur_id = 0;
    $kc_max_lc = $kc_senaste ?? $kc_fran;
    $kc_res = ['skickade' => 0, 'sparade' => 0, 'ignorerade' => 0, 'mer' => false];

    try {
        for ($kc_varv = 0; $kc_varv < KC_SYNK_MAX_VARV; $kc_varv++) {
            $kc_rader = kc_hamta_alla(
                "SELECT * FROM `$kc_t`
                  WHERE last_changed > ? OR (last_changed = ? AND id > ?)
                  ORDER BY last_changed, id
                  LIMIT " . KC_API_MAX_RADER,
                [$kc_cur_lc, $kc_cur_lc, $kc_cur_id]
            );
            if ($kc_rader === []) {
                break;
            }
            $kc_d = kc_cen_anrop('push', [
                'tabell' => $namn,
                'rader'  => array_map('kc_kontrakt_rad', $kc_rader),
            ], 30);
            $kc_sista = end($kc_rader);
            $kc_cur_lc = (new DateTimeImmutable((string)$kc_sista['last_changed'], new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            $kc_cur_id = (int)$kc_sista['id'];
            $kc_max_lc = max($kc_max_lc, $kc_cur_lc);
            $kc_res['skickade']   += count($kc_rader);
            $kc_res['sparade']    += (int)($kc_d['sparade'] ?? 0);
            $kc_res['ignorerade'] += (int)($kc_d['ignorerade'] ?? 0);
            kc_synk_status_satt($namn, 'push', 200, $kc_res['skickade'], null, 'ok', $kc_max_lc);
            if (count($kc_rader) < KC_API_MAX_RADER) {
                break;
            }
            if ($kc_varv === KC_SYNK_MAX_VARV - 1) {
                $kc_res['mer'] = true;
            }
        }
        if ($kc_res['skickade'] === 0) {
            kc_synk_status_satt($namn, 'push', 200, 0, null, 'ok', $kc_max_lc);
        }
    } catch (KcFel $e) {
        kc_synk_status_satt($namn, 'push', $e->status(), $kc_res['skickade'], null, $e->getMessage(), $kc_max_lc);
        throw $e;
    }
    return $kc_res;
}

/**
 * Skickar alla delade tabeller. $tabeller i samma format som till kc_verktyg_api().
 * Fortsätter med nästa tabell om en misslyckas. Returnerar [tabellnamn => resultat | ['fel' => text]].
 */
function kc_push_alla(array $tabeller): array
{
    $kc_ut = [];
    foreach ($tabeller as $kc_namn => $kc_def) {
        try {
            $kc_ut[$kc_namn] = kc_push_tabell((string)$kc_namn, $kc_def['tabell']);
        } catch (KcFel $e) {
            $kc_ut[$kc_namn] = ['fel' => $e->getMessage()];
        }
    }
    return $kc_ut;
}

/* ---------- Pull: prenumererade tabeller till lokala kopior ---------- */

/** Kolumnnamn i en tabell (cachas per anrop). */
function kc_tabell_kolumner(string $tabell): array
{
    static $kc_cache = [];
    return $kc_cache[$tabell] ??= array_column(kc_hamta_alla(
        'SELECT column_name AS k FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position',
        [$tabell]
    ), 'k');
}

/**
 * Hämtar ändrade rader i en prenumererad tabell från CentralKollen (efter senast hämtade sync_seq)
 * och sparar dem i den lokala kopian $ld_tabell (t.ex. 'ld_fas_objekt').
 * Kolumner med samma namn som fält i data fylls; en ev. kolumn "data" får hela objektet som JSON.
 * Upsert på uuid; en rad som inte är nyare än den lagrade (last_changed) lämnas orörd.
 * source_tool = mastern. Framsteg sparas efter varje anrop. Returnerar antal mottagna rader.
 */
function kc_pull_tabell(string $master, string $tabell, string $ld_tabell): int
{
    $kc_nyckel = $master . '_' . $tabell;
    $kc_t = kc_tabell($ld_tabell);
    if (!kc_inst_tabell_finns($kc_t)) {
        throw new KcFel("Den lokala tabellen $kc_t saknas – kör install.php.", 500);
    }
    $kc_kol = array_values(array_diff(kc_tabell_kolumner($kc_t), KC_STANDARDFALT));
    $kc_efter = (int)(kc_synk_status($kc_nyckel, 'pull')['senaste_seq'] ?? 0);
    $kc_antal = 0;

    // INSERT … ON DUPLICATE KEY UPDATE – last_changed sist, eftersom MariaDB använder de nya värdena från vänster till höger
    $kc_alla = array_merge(['uuid', 'last_changed', 'is_deleted', 'source_tool'], $kc_kol);
    $kc_nyare = 'VALUES(last_changed) > last_changed';
    $kc_upd = [];
    foreach (array_merge(['is_deleted', 'source_tool'], $kc_kol) as $k) {
        $kc_upd[] = "`$k` = IF($kc_nyare, VALUES(`$k`), `$k`)";
    }
    $kc_upd[] = "last_changed = IF($kc_nyare, VALUES(last_changed), last_changed)";
    $kc_sql = "INSERT INTO `$kc_t` (`" . implode('`, `', $kc_alla) . '`) VALUES ('
            . implode(', ', array_fill(0, count($kc_alla), '?')) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $kc_upd);

    try {
        for ($kc_varv = 0; $kc_varv < KC_SYNK_MAX_VARV; $kc_varv++) {
            $kc_d = kc_cen_anrop('pull', ['master' => $master, 'tabell' => $tabell, 'efter_seq' => $kc_efter], 30);
            $kc_rader = is_array($kc_d['rader'] ?? null) ? $kc_d['rader'] : [];
            kc_transaktion(function () use ($kc_rader, $kc_kol, $kc_sql, $master): void {
                foreach ($kc_rader as $kc_r) {
                    $kc_uuid = is_string($kc_r['uuid'] ?? null) ? strtolower($kc_r['uuid']) : '';
                    $kc_lc = is_string($kc_r['last_changed'] ?? null) ? $kc_r['last_changed'] : '';
                    if (!kc_ar_uuid($kc_uuid) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $kc_lc)) {
                        continue;   // ogiltig rad från CentralKollen – hoppa över
                    }
                    $kc_data = is_array($kc_r['data'] ?? null) ? $kc_r['data'] : [];
                    $kc_varden = [$kc_uuid, $kc_lc, (int)($kc_r['is_deleted'] ?? 0) === 1 ? 1 : 0, $master];
                    foreach ($kc_kol as $k) {
                        if ($k === 'data') {
                            $kc_varden[] = json_encode($kc_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        } else {
                            $kc_v = $kc_data[$k] ?? null;
                            $kc_varden[] = is_array($kc_v) ? json_encode($kc_v, JSON_UNESCAPED_UNICODE) : (is_bool($kc_v) ? (int)$kc_v : $kc_v);
                        }
                    }
                    kc_kor($kc_sql, $kc_varden);
                }
            });
            $kc_antal += count($kc_rader);
            $kc_efter = max($kc_efter, (int)($kc_d['senaste_seq'] ?? $kc_efter));
            kc_synk_status_satt($kc_nyckel, 'pull', 200, $kc_antal, $kc_efter, 'ok');
            if (($kc_d['mer'] ?? false) !== true) {
                break;
            }
        }
    } catch (KcFel $e) {
        kc_synk_status_satt($kc_nyckel, 'pull', $e->status(), $kc_antal, null, $e->getMessage());
        throw $e;
    }
    return $kc_antal;
}

/**
 * Hämtar alla prenumererade tabeller. $prenumerationer: [nyckel => ['master', 'tabell', 'ld']],
 * t.ex. 'fas_objekt' => ['master' => 'fas', 'tabell' => 'objekt', 'ld' => 'ld_fas_objekt'].
 * Fortsätter med nästa tabell om en misslyckas. Returnerar [nyckel => antal | ['fel' => text]].
 */
function kc_pull_alla(array $prenumerationer): array
{
    $kc_ut = [];
    foreach ($prenumerationer as $kc_n => $kc_p) {
        try {
            $kc_ut[$kc_n] = kc_pull_tabell($kc_p['master'], $kc_p['tabell'], $kc_p['ld']);
        } catch (KcFel $e) {
            $kc_ut[$kc_n] = ['fel' => $e->getMessage()];
        }
    }
    return $kc_ut;
}

/** Äldsta lyckade hämtning bland prenumerationerna (UTC), eller null om någon aldrig hämtats. */
function kc_pull_aldsta(array $prenumerationer): ?string
{
    $kc_aldsta = null;
    foreach (array_keys($prenumerationer) as $kc_n) {
        $kc_t = kc_synk_status((string)$kc_n, 'pull')['senaste_ok_tid'] ?? null;
        if ($kc_t === null) {
            return null;
        }
        $kc_aldsta = $kc_aldsta === null ? $kc_t : min($kc_aldsta, $kc_t);
    }
    return $kc_aldsta;
}
