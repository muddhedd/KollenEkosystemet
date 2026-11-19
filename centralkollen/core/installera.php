<?php
/**
 * Kollen kärnpaket – installera.php
 * Variant: GEMENSAM
 * Skapar kärnpaketets egna tabeller och erbjuder generella hjälpare för
 * verktygens install.php. Allt är idempotent.
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

/** Kärnpaketets tabeller som alla verktyg har (inkl. CentralKollen). */
function kc_core_tabeller_skapa(): void
{
    $kc_t = kc_tabell('sys_inloggning');
    kc_db()->query("CREATE TABLE IF NOT EXISTS `$kc_t` (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        anvandarnamn VARCHAR(100) NOT NULL DEFAULT '',
        ip           VARCHAR(45)  NOT NULL DEFAULT '',
        tid          DATETIME(6)  NOT NULL,
        lyckad       TINYINT(1)   NOT NULL DEFAULT 0,
        KEY idx_anvandare_tid (anvandarnamn, tid),
        KEY idx_ip_tid (ip, tid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function kc_inst_tabell_finns(string $tabell): bool
{
    return (int)kc_hamta_varde(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        [$tabell]
    ) === 1;
}

/**
 * Skapar en md_- eller ld_-tabell med standardfält, eller lägger till
 * saknade kolumner och index i en befintlig.
 * $def = ['kolumner' => ['namn' => "VARCHAR(100) NOT NULL DEFAULT ''", ...],
 *         'index'    => ['namn', ...],
 *         'unik'     => [...]]   // valfritt: unika index utöver uuid
 */
function kc_inst_tabell(string $namn, array $def, array &$logg): void
{
    $kc_t    = kc_tabell($namn);
    $kc_kol  = $def['kolumner'] ?? [];
    $kc_idx  = $def['index'] ?? [];
    $kc_unik = $def['unik'] ?? [];

    if (!kc_inst_tabell_finns($kc_t)) {
        $kc_rader = [
            'id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'uuid         CHAR(36)     NOT NULL',
            'last_changed DATETIME(6)  NOT NULL',
            'is_deleted   TINYINT(1)   NOT NULL DEFAULT 0',
            "source_tool  CHAR(3)      NOT NULL DEFAULT ''",
        ];
        foreach ($kc_kol as $k => $typ) {
            $kc_rader[] = "`$k` $typ";
        }
        $kc_rader[] = 'UNIQUE KEY uq_uuid (uuid)';
        $kc_rader[] = 'KEY idx_last_changed (last_changed)';
        foreach ($kc_idx as $k) {
            $kc_rader[] = "KEY `idx_$k` (`$k`)";
        }
        foreach ($kc_unik as $k) {
            $kc_rader[] = "UNIQUE KEY `uq_$k` (`$k`)";
        }
        kc_db()->query(
            "CREATE TABLE `$kc_t` (\n  " . implode(",\n  ", $kc_rader) . "\n)"
            . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $logg[] = "Skapade tabellen $kc_t.";
        return;
    }

    $kc_finns = array_map('strtolower', array_column(kc_hamta_alla(
        'SELECT column_name AS k FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?',
        [$kc_t]
    ), 'k'));
    foreach ($kc_kol as $k => $typ) {
        if (!in_array($k, $kc_finns, true)) {
            kc_db()->query("ALTER TABLE `$kc_t` ADD COLUMN `$k` $typ");
            $logg[] = "Lade till kolumnen $k i $kc_t.";
        }
    }
    foreach (array_merge(array_map(fn($k) => ['idx', $k], $kc_idx), array_map(fn($k) => ['uq', $k], $kc_unik)) as [$typ, $k]) {
        $kc_har = (int)kc_hamta_varde(
            'SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$kc_t, "{$typ}_$k"]
        ) > 0;
        if (!$kc_har) {
            kc_db()->query("ALTER TABLE `$kc_t` ADD " . ($typ === 'uq' ? 'UNIQUE ' : '') . "KEY `{$typ}_$k` (`$k`)");
            $logg[] = "Lade till index på $k i $kc_t.";
        }
    }
}

/** Skapar en teknisk sys_-tabell om den saknas. $kolumner = SQL-definitionen. */
function kc_inst_sys_tabell(string $namn, string $kolumner, array &$logg): void
{
    $kc_t = kc_tabell($namn);
    if (kc_inst_tabell_finns($kc_t)) {
        return;
    }
    kc_db()->query(
        "CREATE TABLE `$kc_t` ($kolumner\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $logg[] = "Skapade tabellen $kc_t.";
}

/**
 * Tabeller som alla verktyg utom CentralKollen behöver för att synka:
 * användarkopian (ld_cen_anvandare) och synkstatus (sys_synkstatus, med senaste_lc från 1.5.0).
 */
function kc_verktyg_tabeller_skapa(array &$logg): void
{
    kc_inst_tabell('ld_cen_anvandare', [
        'kolumner' => [
            'anvandarnamn'  => "VARCHAR(100) NOT NULL DEFAULT ''",
            'fornamn'       => "VARCHAR(100) NOT NULL DEFAULT ''",
            'efternamn'     => "VARCHAR(100) NOT NULL DEFAULT ''",
            'epost'         => "VARCHAR(255) NOT NULL DEFAULT ''",
            'telefon'       => "VARCHAR(50)  NOT NULL DEFAULT ''",
            'losenord_hash' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'is_active'     => "TINYINT(1)   NOT NULL DEFAULT 1",
        ],
        'index' => ['anvandarnamn'],
    ], $logg);

    kc_inst_sys_tabell('sys_synkstatus', "
        id             INT UNSIGNED      NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tabell         VARCHAR(64)       NOT NULL DEFAULT '',
        riktning       VARCHAR(4)        NOT NULL DEFAULT '',
        senaste_tid    DATETIME(6)       NULL DEFAULT NULL,
        senaste_ok_tid DATETIME(6)       NULL DEFAULT NULL,
        senaste_seq    BIGINT UNSIGNED   NOT NULL DEFAULT 0,
        antal          INT UNSIGNED      NOT NULL DEFAULT 0,
        status         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        meddelande     VARCHAR(255)      NOT NULL DEFAULT '',
        senaste_lc     DATETIME(6)       NULL DEFAULT NULL,
        UNIQUE KEY uq_tabell_riktning (tabell, riktning)", $logg);

    // Från kärna 1.5.0: senast skickade last_changed för push
    $kc_t = kc_tabell('sys_synkstatus');
    $kc_har = (int)kc_hamta_varde(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'senaste_lc'",
        [$kc_t]
    ) > 0;
    if (!$kc_har) {
        kc_db()->query("ALTER TABLE `$kc_t` ADD COLUMN senaste_lc DATETIME(6) NULL DEFAULT NULL");
        $logg[] = "Lade till kolumnen senaste_lc i $kc_t.";
    }
}

/**
 * Lägger in en rad om ingen ej raderad rad har samma värde i $nyckel.
 * $varden = kolumn => värde (utan standardfält). Returnerar 1 om raden lades in.
 */
function kc_inst_seed(string $namn, string $nyckel, array $varden): int
{
    $kc_t = kc_tabell($namn);
    $kc_kol = array_keys($varden);
    $kc_sql = "INSERT INTO `$kc_t` (uuid, last_changed, is_deleted, source_tool, `"
        . implode('`, `', $kc_kol) . '`) SELECT ?, ?, 0, ?, ' . implode(', ', array_fill(0, count($kc_kol), '?'))
        . " FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `$kc_t` WHERE `$nyckel` = ? AND is_deleted = 0)";
    return kc_kor($kc_sql, array_merge(
        [kc_uuid(), kc_nu(), KOLL_PREFIX],
        array_values($varden),
        [$varden[$nyckel]]
    ));
}
