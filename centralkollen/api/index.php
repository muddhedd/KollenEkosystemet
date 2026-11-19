<?php
/**
 * CentralKollen – api/index.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 *
 * API-kontrakt v1, CentralKollens sida. Verktygen anropar:
 *   POST <CEN_URL>api/?e=<endpoint>
 *   Headers: X-Kollen-Prefix, X-Kollen-Nyckel (NYCKEL_TILL_CEN)
 *   Body:    JSON med "kontrakt": 1
 * Svar alltid: {"ok": true|false, "data": ..., "error": null|"text"}
 *
 * Endpoints: ping, konfig, push, pull, anvandare.
 *
 * sync_seq: varje tabell som kan hämtas har kolumnerna sync_seq och seq_tid.
 * En rad vars last_changed skiljer sig från seq_tid är ändrad sedan senaste
 * stämpling och får nästa nummer från en låst räknare i sys_sekvens.
 * Stämpling sker före varje pull, så ingen skrivare behöver känna till sync_seq.
 */
declare(strict_types=1);
define('KC_API', true);
require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/cen.php';

const CEN_API_KONTRAKT     = 1;
const CEN_API_MAX_RADER    = 500;
const CEN_API_MAX_BYTE     = 8000000;   // hela anropet
const CEN_API_MAX_RAD_BYTE = 65535;     // data-objektet i en rad

/* ---------- Loggning ---------- */

$GLOBALS['cen_api_logg'] = [
    'start' => microtime(true), 'prefix' => '', 'endpoint' => '',
    'tabell' => '', 'antal' => 0, 'seq' => null,
];

/** Noterar vad anropet gällde, för logg och synkstatus. */
function cen_api_noter(?string $tabell = null, ?int $antal = null, ?int $seq = null): void
{
    if ($tabell !== null) { $GLOBALS['cen_api_logg']['tabell'] = substr($tabell, 0, 64); }
    if ($antal !== null)  { $GLOBALS['cen_api_logg']['antal']  = $antal; }
    if ($seq !== null)    { $GLOBALS['cen_api_logg']['seq']    = $seq; }
}

/** Skriver anropet till sys_synklogg och sys_synkstatus. Får aldrig fälla anropet. */
function cen_api_logga(int $status, string $meddelande): void
{
    $l = $GLOBALS['cen_api_logg'];
    try {
        $nu = kc_nu();
        $ms = (int)round((microtime(true) - $l['start']) * 1000);
        kc_kor(
            'INSERT INTO `' . kc_tabell('sys_synklogg') . '`
                (tid, verktyg_prefix, endpoint, tabell, antal, status, meddelande, ip, ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$nu, $l['prefix'], $l['endpoint'], $l['tabell'], $l['antal'], $status,
             mb_substr($meddelande, 0, 255), kc_klient_ip(), $ms]
        );

        $riktning = match ($l['endpoint']) {
            'push'              => 'push',
            'pull', 'anvandare' => 'pull',
            default             => null,
        };
        if ($riktning !== null && $l['prefix'] !== '' && $l['tabell'] !== '') {
            $ok = $status === 200;
            kc_kor(
                'INSERT INTO `' . kc_tabell('sys_synkstatus') . '`
                    (verktyg_prefix, tabell, riktning, senaste_tid, senaste_ok_tid, senaste_seq, antal, status, meddelande)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    senaste_tid    = VALUES(senaste_tid),
                    senaste_ok_tid = IF(VALUES(status) = 200, VALUES(senaste_tid), senaste_ok_tid),
                    senaste_seq    = IF(VALUES(status) = 200 AND VALUES(senaste_seq) > 0, VALUES(senaste_seq), senaste_seq),
                    antal          = VALUES(antal),
                    status         = VALUES(status),
                    meddelande     = VALUES(meddelande)',
                [$l['prefix'], $l['tabell'], $riktning, $nu, $ok ? $nu : null, (int)($l['seq'] ?? 0),
                 $l['antal'], $status, mb_substr($meddelande, 0, 255)]
            );
        }

        // Städa loggen ibland (rader äldre än 90 dagar)
        if (random_int(1, 100) === 1) {
            $grans = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-90 days')->format('Y-m-d H:i:s.u');
            kc_kor('DELETE FROM `' . kc_tabell('sys_synklogg') . '` WHERE tid < ?', [$grans]);
        }
    } catch (Throwable $e) {
        error_log('[Kollen cen] API-loggning misslyckades: ' . $e->getMessage());
    }
}

/* ---------- Indata och autentisering ---------- */

function cen_api_las_body(): array
{
    $ra = file_get_contents('php://input', false, null, 0, CEN_API_MAX_BYTE + 1);
    if ($ra === false || $ra === '') {
        throw new KcFel('Anropet saknar JSON-body.', 400);
    }
    if (strlen($ra) > CEN_API_MAX_BYTE) {
        throw new KcFel('Anropet är för stort.', 400);
    }
    try {
        $in = json_decode($ra, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new KcFel('Ogiltig JSON.', 400);
    }
    if (!is_array($in) || array_is_list($in) && $in !== []) {
        throw new KcFel('JSON-bodyn måste vara ett objekt.', 400);
    }
    return $in;
}

/** Returnerar verktygsraden (utan nyckelhash) eller kastar 401. */
function cen_api_autentisera(): array
{
    $prefix = strtolower(trim((string)($_SERVER['HTTP_X_KOLLEN_PREFIX'] ?? '')));
    $nyckel = strtolower(trim((string)($_SERVER['HTTP_X_KOLLEN_NYCKEL'] ?? '')));

    if (!preg_match('/^[a-z]{3}$/', $prefix) || !preg_match('/^[0-9a-f]{64}$/', $nyckel)) {
        throw new KcFel('Saknad eller felaktig autentisering (X-Kollen-Prefix, X-Kollen-Nyckel).', 401);
    }
    $GLOBALS['cen_api_logg']['prefix'] = $prefix;

    $v = kc_hamta_en(
        'SELECT uuid, prefix, namn, is_active, nyckel_till_cen_hash FROM `' . kc_tabell('md_verktyg') . '`
          WHERE prefix = ? AND is_deleted = 0 ORDER BY id LIMIT 1',
        [$prefix]
    );
    $hash = hash('sha256', $nyckel);
    if ($v === null || $v['nyckel_till_cen_hash'] === '' || !hash_equals($v['nyckel_till_cen_hash'], $hash)) {
        throw new KcFel('Ogiltig nyckel.', 401);
    }
    unset($v['nyckel_till_cen_hash']);
    return $v;
}

function cen_api_tabellnamn(mixed $v): string
{
    if (!is_string($v) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $v)) {
        throw new KcFel('Fältet tabell saknas eller är ogiltigt (a–z, 0–9, _, max 40 tecken).', 400);
    }
    return $v;
}

function cen_api_seq(mixed $v): int
{
    if (is_int($v) && $v >= 0) {
        return $v;
    }
    if (is_string($v) && preg_match('/^\d{1,18}$/', $v)) {
        return (int)$v;
    }
    throw new KcFel('Fältet efter_seq måste vara ett heltal ≥ 0.', 400);
}

/* ---------- Hjälp för tabeller och sync_seq ---------- */

function cen_api_tabell_finns(string $tabell): bool
{
    return (int)kc_hamta_varde(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        [$tabell]
    ) === 1;
}

/** Skapar en ck_-tabell om den saknas. DDL – anropas utanför transaktion. */
function cen_api_ck_skapa(string $ck): void
{
    kc_db()->query("CREATE TABLE IF NOT EXISTS `$ck` (
        id           INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
        uuid         CHAR(36)        NOT NULL,
        last_changed DATETIME(6)     NOT NULL,
        is_deleted   TINYINT(1)      NOT NULL DEFAULT 0,
        source_tool  CHAR(3)         NOT NULL DEFAULT '',
        sync_seq     BIGINT UNSIGNED NOT NULL DEFAULT 0,
        seq_tid      DATETIME(6)     NULL DEFAULT NULL,
        data         LONGTEXT        NOT NULL,
        UNIQUE KEY uq_uuid (uuid),
        KEY idx_sync_seq (sync_seq),
        KEY idx_last_changed (last_changed)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Ger varje ändrad rad (last_changed ≠ seq_tid) nästa sync_seq.
 * Räknaren låses under hela stämplingen, så två anrop kan aldrig
 * dela ut nummer i fel ordning eller lämna luckor som en läsare hoppar över.
 */
function cen_api_stampla(array $tabeller, string $raknare): void
{
    $ts = kc_tabell('sys_sekvens');
    kc_transaktion(function () use ($tabeller, $raknare, $ts): void {
        kc_kor("INSERT IGNORE INTO `$ts` (raknare, varde) VALUES (?, 0)", [$raknare]);
        $varde = (int)kc_hamta_varde("SELECT varde FROM `$ts` WHERE raknare = ? FOR UPDATE", [$raknare]);
        $start = $varde;
        foreach ($tabeller as $t) {
            $idn = array_column(kc_hamta_alla(
                "SELECT id FROM `$t` WHERE seq_tid IS NULL OR seq_tid <> last_changed
                  ORDER BY last_changed, id FOR UPDATE"
            ), 'id');
            foreach ($idn as $id) {
                $varde++;
                kc_kor("UPDATE `$t` SET sync_seq = ?, seq_tid = last_changed WHERE id = ?", [$varde, $id]);
            }
        }
        if ($varde !== $start) {
            kc_kor("UPDATE `$ts` SET varde = ? WHERE raknare = ?", [$varde, $raknare]);
        }
    });
}

/** Validerar och normaliserar en inkommande rad. */
function cen_api_rad(mixed $r, int $nr): array
{
    if (!is_array($r)) {
        throw new KcFel("Rad $nr: måste vara ett objekt.", 400);
    }
    $uuid = is_string($r['uuid'] ?? null) ? strtolower($r['uuid']) : '';
    if (!kc_ar_uuid($uuid)) {
        throw new KcFel("Rad $nr: uuid saknas eller är ogiltigt.", 400);
    }
    $lc = $r['last_changed'] ?? null;
    if (!is_string($lc) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $lc)) {
        throw new KcFel("Rad $nr: last_changed måste ha formatet YYYY-MM-DD HH:MM:SS.ffffff (UTC).", 400);
    }
    try {
        $lc = (new DateTimeImmutable($lc, new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    } catch (Exception) {
        throw new KcFel("Rad $nr: last_changed är inte ett giltigt datum.", 400);
    }
    $del = $r['is_deleted'] ?? null;
    if (!in_array($del, [0, 1, true, false, '0', '1'], true)) {
        throw new KcFel("Rad $nr: is_deleted måste vara 0 eller 1.", 400);
    }
    $data = $r['data'] ?? null;
    if (!is_array($data) || ($data !== [] && array_is_list($data))) {
        throw new KcFel("Rad $nr: data måste vara ett objekt.", 400);
    }
    $json = $data === [] ? '{}' : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new KcFel("Rad $nr: data kunde inte tolkas.", 400);
    }
    if (strlen($json) > CEN_API_MAX_RAD_BYTE) {
        throw new KcFel("Rad $nr: data är för stort (max " . CEN_API_MAX_RAD_BYTE . ' byte).', 400);
    }
    return ['uuid' => $uuid, 'last_changed' => $lc, 'is_deleted' => (int)(bool)(int)$del, 'data' => $json];
}

/** Paketerar rader till pull-svaret: max 500, flaggan mer, senaste_seq. */
function cen_api_paketera(array $rader, int $efter): array
{
    $mer = count($rader) > CEN_API_MAX_RADER;
    $rader = array_slice($rader, 0, CEN_API_MAX_RADER);
    $senaste = $rader === [] ? $efter : (int)end($rader)['sync_seq'];
    cen_api_noter(null, count($rader), $senaste);
    return ['rader' => array_values($rader), 'mer' => $mer, 'senaste_seq' => $senaste];
}

/* ---------- Endpoints ---------- */

function cen_api_ping(array $v): array
{
    return [
        'verktyg'  => $v['prefix'],
        'namn'     => $v['namn'],
        'aktiv'    => (int)$v['is_active'] === 1,
        'tid'      => kc_nu(),
        'kontrakt' => CEN_API_KONTRAKT,
    ];
}

function cen_api_konfig(array $v): array
{
    $tt = kc_tabell('md_tabell');
    $tv = kc_tabell('md_verktyg');
    $tp = kc_tabell('md_prenumeration');

    $master = kc_hamta_alla(
        "SELECT tabellnamn AS tabell, beskrivning, is_active FROM `$tt`
          WHERE verktyg_uuid = ? AND is_deleted = 0 ORDER BY tabellnamn",
        [$v['uuid']]
    );
    $pren = kc_hamta_alla(
        "SELECT m.prefix AS master, t.tabellnamn AS tabell, t.is_active
           FROM `$tp` p
           JOIN `$tt` t ON t.uuid = p.tabell_uuid AND t.is_deleted = 0
           JOIN `$tv` m ON m.uuid = t.verktyg_uuid AND m.is_deleted = 0
          WHERE p.verktyg_uuid = ? AND p.is_deleted = 0
          ORDER BY m.prefix, t.tabellnamn",
        [$v['uuid']]
    );
    $till_bool = static function (array $r): array { $r['is_active'] = (int)$r['is_active'] === 1; return $r; };
    return [
        'verktyg'         => $v['prefix'],
        'master'          => array_map($till_bool, $master),
        'prenumerationer' => array_map($till_bool, $pren),
        'max_rader'       => CEN_API_MAX_RADER,
    ];
}

function cen_api_push(array $v, array $in): array
{
    $namn = cen_api_tabellnamn($in['tabell'] ?? null);
    cen_api_noter($v['prefix'] . '_' . $namn);

    $reg = kc_hamta_en(
        'SELECT uuid, is_active FROM `' . kc_tabell('md_tabell') . '`
          WHERE verktyg_uuid = ? AND tabellnamn = ? AND is_deleted = 0 LIMIT 1',
        [$v['uuid'], $namn]
    );
    if ($reg === null) {
        throw new KcFel("Tabellen $namn är inte registrerad som masterdata för " . $v['prefix'] . ' i CentralKollen.', 404);
    }
    if ((int)$reg['is_active'] !== 1) {
        throw new KcFel("Tabellen $namn är inaktiv i CentralKollen.", 403);
    }

    $in_rader = $in['rader'] ?? null;
    if (!is_array($in_rader) || ($in_rader !== [] && !array_is_list($in_rader))) {
        throw new KcFel('Fältet rader måste vara en lista.', 400);
    }
    if (count($in_rader) > CEN_API_MAX_RADER) {
        throw new KcFel('Max ' . CEN_API_MAX_RADER . ' rader per anrop.', 400);
    }
    // Validera allt innan något skrivs
    $rader = [];
    foreach ($in_rader as $i => $r) {
        $rader[] = cen_api_rad($r, $i + 1);
    }
    cen_api_noter(null, count($rader));
    if ($rader === []) {
        return ['mottagna' => 0, 'sparade' => 0, 'ignorerade' => 0];
    }

    $ck = kc_ck_tabell($v['prefix'], $namn);
    cen_api_ck_skapa($ck);

    [$sparade, $ignorerade] = kc_transaktion(function () use ($ck, $rader, $v): array {
        $sparade = 0;
        $ignorerade = 0;
        foreach ($rader as $r) {
            $lagrad = kc_hamta_en("SELECT last_changed FROM `$ck` WHERE uuid = ? FOR UPDATE", [$r['uuid']]);
            if ($lagrad !== null && $r['last_changed'] <= $lagrad['last_changed']) {
                $ignorerade++;     // inte nyare än det vi redan har
                continue;
            }
            if ($lagrad !== null) {
                kc_kor(
                    "UPDATE `$ck` SET last_changed = ?, is_deleted = ?, source_tool = ?, data = ? WHERE uuid = ?",
                    [$r['last_changed'], $r['is_deleted'], $v['prefix'], $r['data'], $r['uuid']]
                );
            } else {
                kc_kor(
                    "INSERT INTO `$ck` (uuid, last_changed, is_deleted, source_tool, data) VALUES (?, ?, ?, ?, ?)",
                    [$r['uuid'], $r['last_changed'], $r['is_deleted'], $v['prefix'], $r['data']]
                );
            }
            $sparade++;
        }
        return [$sparade, $ignorerade];
    });

    if ($sparade > 0) {
        cen_api_stampla([$ck], $ck);
    }
    return ['mottagna' => count($rader), 'sparade' => $sparade, 'ignorerade' => $ignorerade];
}

function cen_api_pull(array $v, array $in): array
{
    $master = is_string($in['master'] ?? null) ? strtolower($in['master']) : '';
    if (!preg_match('/^[a-z]{3}$/', $master)) {
        throw new KcFel('Fältet master saknas eller är ogiltigt (tre bokstäver a–z).', 400);
    }
    $namn  = cen_api_tabellnamn($in['tabell'] ?? null);
    $efter = cen_api_seq($in['efter_seq'] ?? 0);
    cen_api_noter($master . '_' . $namn);

    $reg = kc_hamta_en(
        'SELECT t.uuid, t.is_active FROM `' . kc_tabell('md_tabell') . '` t
           JOIN `' . kc_tabell('md_verktyg') . '` m ON m.uuid = t.verktyg_uuid AND m.is_deleted = 0
          WHERE m.prefix = ? AND t.tabellnamn = ? AND t.is_deleted = 0 LIMIT 1',
        [$master, $namn]
    );
    if ($reg === null) {
        throw new KcFel("Tabellen {$master}_$namn är inte registrerad i CentralKollen.", 404);
    }
    $prenumererar = (int)kc_hamta_varde(
        'SELECT COUNT(*) FROM `' . kc_tabell('md_prenumeration') . '`
          WHERE verktyg_uuid = ? AND tabell_uuid = ? AND is_deleted = 0',
        [$v['uuid'], $reg['uuid']]
    ) > 0;
    if (!$prenumererar) {
        throw new KcFel("Verktyget prenumererar inte på {$master}_$namn.", 403);
    }
    if ((int)$reg['is_active'] !== 1) {
        throw new KcFel("Tabellen {$master}_$namn är inaktiv i CentralKollen.", 403);
    }

    $ck = kc_ck_tabell($master, $namn);
    if (!cen_api_tabell_finns($ck)) {
        return cen_api_paketera([], $efter);    // mastern har inte pushat något ännu
    }
    cen_api_stampla([$ck], $ck);

    $rader = kc_hamta_alla(
        "SELECT uuid, last_changed, is_deleted, sync_seq, data FROM `$ck`
          WHERE sync_seq > ? ORDER BY sync_seq LIMIT " . (CEN_API_MAX_RADER + 1),
        [$efter]
    );
    $rader = array_map(static fn(array $r): array => [
        'uuid'         => $r['uuid'],
        'last_changed' => $r['last_changed'],
        'is_deleted'   => (int)$r['is_deleted'],
        'sync_seq'     => (int)$r['sync_seq'],
        'data'         => json_decode($r['data']) ?? new stdClass(),   // objekt, så att {} förblir {}
    ], $rader);
    return cen_api_paketera($rader, $efter);
}

function cen_api_anvandare(array $v, array $in): array
{
    $ta = kc_tabell('md_anvandare');
    $tb = kc_tabell('md_behorighet');
    cen_api_noter('cen_anvandare');

    $format = static function (array $r, bool $raderad): array {
        return [
            'uuid'         => $r['uuid'],
            'last_changed' => $r['lc'],
            'is_deleted'   => $raderad ? 1 : 0,
            'sync_seq'     => (int)$r['seq'],
            'data'         => [
                'anvandarnamn'  => $r['anvandarnamn'],
                'fornamn'       => $r['fornamn'],
                'efternamn'     => $r['efternamn'],
                'epost'         => $r['epost'],
                'telefon'       => $r['telefon'],
                'losenord_hash' => $raderad ? '' : $r['losenord_hash'],
                'is_active'     => $raderad ? 0 : (int)$r['is_active'],
            ],
        ];
    };

    // En enskild användare, t.ex. vid inloggning i verktyget
    if (array_key_exists('anvandarnamn', $in)) {
        $namn = is_string($in['anvandarnamn']) ? trim($in['anvandarnamn']) : '';
        if ($namn === '' || mb_strlen($namn) > 100) {
            throw new KcFel('Fältet anvandarnamn är ogiltigt.', 400);
        }
        $r = kc_hamta_en(
            "SELECT a.*, GREATEST(a.last_changed, b.last_changed) AS lc, GREATEST(a.sync_seq, b.sync_seq) AS seq
               FROM `$ta` a
               JOIN `$tb` b ON b.anvandare_uuid = a.uuid AND b.verktyg_uuid = ? AND b.is_deleted = 0
              WHERE a.anvandarnamn = ? AND a.is_deleted = 0
              ORDER BY a.id LIMIT 1",
            [$v['uuid'], $namn]
        );
        cen_api_noter(null, $r === null ? 0 : 1);
        return ['anvandare' => $r === null ? null : $format($r, false)];
    }

    // Alla ändringar efter efter_seq. Användare och behörigheter delar räknare,
    // så att GREATEST() av deras sync_seq är jämförbar.
    $efter = cen_api_seq($in['efter_seq'] ?? 0);
    cen_api_stampla([$ta, $tb], $ta);

    $rader = kc_hamta_alla(
        "SELECT a.uuid, a.anvandarnamn, a.fornamn, a.efternamn, a.epost, a.telefon, a.losenord_hash, a.is_active,
                a.is_deleted AS a_raderad, b.is_deleted AS b_raderad,
                GREATEST(a.last_changed, b.last_changed) AS lc,
                GREATEST(a.sync_seq, b.sync_seq) AS seq
           FROM `$tb` b
           JOIN `$ta` a ON a.uuid = b.anvandare_uuid
          WHERE b.verktyg_uuid = ? AND GREATEST(a.sync_seq, b.sync_seq) > ?
          ORDER BY seq, a.id
          LIMIT " . (CEN_API_MAX_RADER + 1),
        [$v['uuid'], $efter]
    );
    $rader = array_map(
        static fn(array $r): array => $format($r, (int)$r['a_raderad'] === 1 || (int)$r['b_raderad'] === 1),
        $rader
    );
    return cen_api_paketera($rader, $efter);
}

/* ---------- Körning ---------- */

try {
    $endpoint = is_string($_GET['e'] ?? null) ? $_GET['e'] : '';
    $GLOBALS['cen_api_logg']['endpoint'] = substr($endpoint, 0, 20);

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new KcFel('Endast POST är tillåtet.', 400);
    }
    if (!in_array($endpoint, ['ping', 'konfig', 'push', 'pull', 'anvandare'], true)) {
        throw new KcFel('Okänd endpoint. Giltiga: ping, konfig, push, pull, anvandare.', 404);
    }
    $in = cen_api_las_body();
    if (($in['kontrakt'] ?? null) !== CEN_API_KONTRAKT) {
        throw new KcFel('Fältet kontrakt saknas eller stöds inte (förväntat 1).', 400);
    }
    $verktyg = cen_api_autentisera();
    if ($endpoint !== 'ping' && (int)$verktyg['is_active'] !== 1) {
        throw new KcFel('Verktyget är inaktivt i CentralKollen.', 403);
    }

    $svar = match ($endpoint) {
        'ping'      => cen_api_ping($verktyg),
        'konfig'    => cen_api_konfig($verktyg),
        'push'      => cen_api_push($verktyg, $in),
        'pull'      => cen_api_pull($verktyg, $in),
        'anvandare' => cen_api_anvandare($verktyg, $in),
    };
    cen_api_logga(200, 'ok');
    kc_json(true, $svar);
} catch (Throwable $e) {
    $status = $e instanceof KcFel ? $e->status() : 500;
    cen_api_logga($status, $e instanceof KcFel ? $e->getMessage() : 'Internt fel: ' . get_class($e));
    throw $e;   // kärnpaketets felhantering svarar med JSON
}
