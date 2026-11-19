<?php
/**
 * Kollen kärnpaket – db.php
 * Variant: GEMENSAM
 * Databasanslutning (UTC, utf8mb4), tabellnamn och frågehjälpare.
 * ALLA frågor med indata går via prepared statements.
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

function kc_db(): mysqli
{
    static $kc_db = null;
    if ($kc_db instanceof mysqli) {
        return $kc_db;
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $kc_db = new mysqli(DB_HOST, DB_ANVANDARE, DB_LOSENORD, DB_NAMN);
    } catch (mysqli_sql_exception $e) {
        error_log('[Kollen ' . KOLL_PREFIX . '] DB-anslutning: ' . $e->getMessage());
        throw new KcFel('Kunde inte ansluta till databasen.', 500);
    }
    $kc_db->set_charset('utf8mb4');
    $kc_db->query("SET time_zone = '+00:00'");
    return $kc_db;
}

/** 'cen_' i LABB/GITHUB, 'demo_cen_' i DEMO. */
function kc_tabellprefix(): string
{
    return (KOLL_VARIANT === 'demo' ? 'demo_' : '') . KOLL_PREFIX . '_';
}

/** kc_tabell('md_anvandare') → 'cen_md_anvandare' / 'demo_cen_md_anvandare' */
function kc_tabell(string $namn): string
{
    if (!preg_match('/^(md|ld|sys)_[a-z0-9_]+$/', $namn)) {
        throw new KcFel("Ogiltigt tabellnamn: $namn", 500);
    }
    return kc_tabellprefix() . $namn;
}

/** Central kopia (används i CentralKollen): kc_ck_tabell('nyk', 'nycklar') → 'ck_nyk_nycklar' */
function kc_ck_tabell(string $prefix, string $tabell): string
{
    if (!preg_match('/^[a-z]{3}$/', $prefix) || !preg_match('/^[a-z0-9_]+$/', $tabell)) {
        throw new KcFel("Ogiltigt ck-tabellnamn: $prefix / $tabell", 500);
    }
    return (KOLL_VARIANT === 'demo' ? 'demo_' : '') . 'ck_' . $prefix . '_' . $tabell;
}

function kc_fraga(string $sql, array $params = []): mysqli_stmt
{
    $kc_stmt = kc_db()->prepare($sql);
    $kc_p = array_map(static fn($v) => is_bool($v) ? (int)$v : $v, array_values($params));
    $kc_stmt->execute($kc_p === [] ? null : $kc_p);
    return $kc_stmt;
}

/** Alla rader som associativa arrayer. */
function kc_hamta_alla(string $sql, array $params = []): array
{
    $kc_stmt = kc_fraga($sql, $params);
    $kc_res  = $kc_stmt->get_result();
    $kc_rader = $kc_res ? $kc_res->fetch_all(MYSQLI_ASSOC) : [];
    $kc_stmt->close();
    return $kc_rader;
}

/** Första raden eller null. */
function kc_hamta_en(string $sql, array $params = []): ?array
{
    return kc_hamta_alla($sql, $params)[0] ?? null;
}

/** Första kolumnen i första raden eller null. */
function kc_hamta_varde(string $sql, array $params = []): mixed
{
    $kc_rad = kc_hamta_en($sql, $params);
    return $kc_rad === null ? null : array_values($kc_rad)[0];
}

/** INSERT/UPDATE/DELETE. Returnerar antal påverkade rader. */
function kc_kor(string $sql, array $params = []): int
{
    $kc_stmt = kc_fraga($sql, $params);
    $kc_n = $kc_stmt->affected_rows;
    $kc_stmt->close();
    return (int)$kc_n;
}

/** Kör $fn i en transaktion; rullar tillbaka vid fel. */
function kc_transaktion(callable $fn): mixed
{
    $kc_db = kc_db();
    $kc_db->begin_transaction();
    try {
        $kc_res = $fn();
        $kc_db->commit();
        return $kc_res;
    } catch (Throwable $e) {
        $kc_db->rollback();
        throw $e;
    }
}
