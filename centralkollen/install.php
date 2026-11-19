<?php
/**
 * CentralKollen – install.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 *
 * Skapar och uppdaterar CentralKollens tabeller samt grunddata.
 * Idempotent: kan köras hur många gånger som helst.
 *  - Saknade tabeller skapas, saknade kolumner läggs till.
 *  - Verktygsregistret fylls på med verktyg som saknas.
 *  - Standardkontot admin skapas ENDAST om det inte finns några användare.
 *
 * Öppnas bara när config.php innehåller:  define('KOLL_INSTALL', true);
 * Sätt tillbaka till false när installationen är klar.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/cen.php';

$installation_oppen = defined('KOLL_INSTALL') && KOLL_INSTALL === true;

/* ---------- Tabelldefinitioner ---------- */

/** Kolumner utöver standardfälten (id, uuid, last_changed, is_deleted, source_tool). */
$cen_tabeller = [
    'md_anvandare' => [
        'kolumner' => [
            'anvandarnamn'  => "VARCHAR(100) NOT NULL DEFAULT ''",
            'fornamn'       => "VARCHAR(100) NOT NULL DEFAULT ''",
            'efternamn'     => "VARCHAR(100) NOT NULL DEFAULT ''",
            'epost'         => "VARCHAR(255) NOT NULL DEFAULT ''",
            'telefon'       => "VARCHAR(50)  NOT NULL DEFAULT ''",
            'losenord_hash' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'is_active'     => "TINYINT(1)   NOT NULL DEFAULT 1",
            'sync_seq'      => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
            'seq_tid'       => "DATETIME(6) NULL DEFAULT NULL",
        ],
        'index' => ['anvandarnamn', 'epost', 'sync_seq'],
    ],
    'md_verktyg' => [
        'kolumner' => [
            'prefix'               => "CHAR(3)      NOT NULL DEFAULT ''",
            'namn'                 => "VARCHAR(100) NOT NULL DEFAULT ''",
            'bas_url'              => "VARCHAR(255) NOT NULL DEFAULT ''",
            'nyckel_till_cen_hash' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'nyckel_fran_cen'      => "VARCHAR(64)  NOT NULL DEFAULT ''",
            'is_active'            => "TINYINT(1)   NOT NULL DEFAULT 0",
        ],
        'index' => ['prefix'],
    ],
    'md_behorighet' => [
        'kolumner' => [
            'anvandare_uuid' => "CHAR(36) NOT NULL DEFAULT ''",
            'verktyg_uuid'   => "CHAR(36) NOT NULL DEFAULT ''",
            'sync_seq'       => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
            'seq_tid'        => "DATETIME(6) NULL DEFAULT NULL",
        ],
        'index' => ['anvandare_uuid', 'verktyg_uuid', 'sync_seq'],
    ],
    'md_tabell' => [
        'kolumner' => [
            'verktyg_uuid' => "CHAR(36)     NOT NULL DEFAULT ''",
            'tabellnamn'   => "VARCHAR(64)  NOT NULL DEFAULT ''",
            'beskrivning'  => "VARCHAR(255) NOT NULL DEFAULT ''",
            'is_active'    => "TINYINT(1)   NOT NULL DEFAULT 1",
        ],
        'index' => ['verktyg_uuid'],
    ],
    'md_prenumeration' => [
        'kolumner' => [
            'verktyg_uuid' => "CHAR(36) NOT NULL DEFAULT ''",
            'tabell_uuid'  => "CHAR(36) NOT NULL DEFAULT ''",
        ],
        'index' => ['verktyg_uuid', 'tabell_uuid'],
    ],
];

/** Tekniska tabeller (sys_): inga standardfält, synkas aldrig. */
$cen_sys_tabeller = [
    // Låsta räknare som delar ut sync_seq (en rad per räknare)
    'sys_sekvens' => "
        raknare VARCHAR(64)     NOT NULL PRIMARY KEY,
        varde   BIGINT UNSIGNED NOT NULL DEFAULT 0",
    // Historik över API-anrop
    'sys_synklogg' => "
        id             INT UNSIGNED      NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tid            DATETIME(6)       NOT NULL,
        verktyg_prefix VARCHAR(3)        NOT NULL DEFAULT '',
        endpoint       VARCHAR(20)       NOT NULL DEFAULT '',
        tabell         VARCHAR(64)       NOT NULL DEFAULT '',
        antal          INT UNSIGNED      NOT NULL DEFAULT 0,
        status         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        meddelande     VARCHAR(255)      NOT NULL DEFAULT '',
        ip             VARCHAR(45)       NOT NULL DEFAULT '',
        ms             INT UNSIGNED      NOT NULL DEFAULT 0,
        KEY idx_tid (tid),
        KEY idx_verktyg_tid (verktyg_prefix, tid)",
    // Senaste status per verktyg + tabell + riktning
    'sys_synkstatus' => "
        id             INT UNSIGNED      NOT NULL AUTO_INCREMENT PRIMARY KEY,
        verktyg_prefix VARCHAR(3)        NOT NULL DEFAULT '',
        tabell         VARCHAR(64)       NOT NULL DEFAULT '',
        riktning       VARCHAR(4)        NOT NULL DEFAULT '',
        senaste_tid    DATETIME(6)       NULL DEFAULT NULL,
        senaste_ok_tid DATETIME(6)       NULL DEFAULT NULL,
        senaste_seq    BIGINT UNSIGNED   NOT NULL DEFAULT 0,
        antal          INT UNSIGNED      NOT NULL DEFAULT 0,
        status         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        meddelande     VARCHAR(255)      NOT NULL DEFAULT '',
        UNIQUE KEY uq_verktyg_tabell_riktning (verktyg_prefix, tabell, riktning)",
];

/** Verktygsregistret enligt projektinstruktionerna. */
$cen_verktyg = [
    ['cen', 'CentralKollen'],
    ['avt', 'AvtalsKollen'],
    ['tek', 'TeknikKollen'],
    ['inf', 'InfoKollen'],
    ['are', 'ÄrendeKollen'],
    ['mvd', 'MätvärdesKollen'],
    ['pri', 'PrissättningsKollen'],
    ['prc', 'ProcessKollen'],
    ['ans', 'AnsvarsKollen'],
    ['org', 'OrganisationsKollen'],
    ['res', 'ResursKollen'],
    ['duk', 'DUKollen'],
    ['nyk', 'NyckelKollen'],
    ['dok', 'DokumentKollen'],
    ['fas', 'FastighetsKollen'],
    ['mat', 'MätarKollen'],
    ['prj', 'ProjektKollen'],
    ['bud', 'BudgetKollen'],
    ['prg', 'PrognosKollen'],
    ['frb', 'FörbrukningsKollen'],
    ['hyr', 'HyresKollen'],
    ['per', 'PersonalKollen'],
    ['tpk', 'TemperaturKollen'],
    ['tjk', 'TjänsteKollen'],
    ['pdk', 'ProduktKollen'],
    ['egk', 'EgenKollen'],
    ['dtk', 'DataKollen'],
];

/* ---------- Hjälpfunktioner ---------- */

function cen_inst_tabell_finns(string $tabell): bool
{
    return (int)kc_hamta_varde(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        [$tabell]
    ) === 1;
}

/** Skapar tabellen om den saknas, lägger annars till saknade kolumner. */
function cen_inst_tabell(string $namn, array $def, array &$logg): void
{
    $t = kc_tabell($namn);

    if (!cen_inst_tabell_finns($t)) {
        $rader = [
            'id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'uuid         CHAR(36)     NOT NULL',
            'last_changed DATETIME(6)  NOT NULL',
            'is_deleted   TINYINT(1)   NOT NULL DEFAULT 0',
            "source_tool  CHAR(3)      NOT NULL DEFAULT 'cen'",
        ];
        foreach ($def['kolumner'] as $kolumn => $typ) {
            $rader[] = "`$kolumn` $typ";
        }
        $rader[] = 'UNIQUE KEY uq_uuid (uuid)';
        $rader[] = 'KEY idx_last_changed (last_changed)';
        foreach ($def['index'] as $kolumn) {
            $rader[] = "KEY idx_$kolumn (`$kolumn`)";
        }
        kc_db()->query(
            "CREATE TABLE `$t` (\n  " . implode(",\n  ", $rader) . "\n)"
            . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $logg[] = "Skapade tabellen $t.";
        return;
    }

    $finns = array_map('strtolower', array_column(kc_hamta_alla(
        'SELECT column_name AS k FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?',
        [$t]
    ), 'k'));
    foreach ($def['kolumner'] as $kolumn => $typ) {
        if (!in_array($kolumn, $finns, true)) {
            kc_db()->query("ALTER TABLE `$t` ADD COLUMN `$kolumn` $typ");
            $logg[] = "Lade till kolumnen $kolumn i $t.";
        }
    }
    foreach ($def['index'] as $kolumn) {
        $har_index = (int)kc_hamta_varde(
            'SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$t, "idx_$kolumn"]
        ) > 0;
        if (!$har_index) {
            kc_db()->query("ALTER TABLE `$t` ADD KEY `idx_$kolumn` (`$kolumn`)");
            $logg[] = "Lade till index på $kolumn i $t.";
        }
    }
}

/** Skapar en teknisk tabell om den saknas. */
function cen_inst_sys_tabell(string $namn, string $kolumner, array &$logg): void
{
    $t = kc_tabell($namn);
    if (cen_inst_tabell_finns($t)) {
        return;
    }
    kc_db()->query(
        "CREATE TABLE `$t` ($kolumner\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $logg[] = "Skapade tabellen $t.";
}

/* ---------- Kör installationen ---------- */

if (kc_ar_post() && kc_post('atgard') === 'installera') {
    if (!$installation_oppen) {
        throw new KcFel('Installationen är låst i config.php.', 403);
    }
    $logg = [];

    // 1. Tabeller (DDL körs utanför transaktion)
    kc_core_tabeller_skapa();
    foreach ($cen_tabeller as $namn => $def) {
        cen_inst_tabell($namn, $def, $logg);
    }
    foreach ($cen_sys_tabeller as $namn => $kolumner) {
        cen_inst_sys_tabell($namn, $kolumner, $logg);
    }

    // 2. Grunddata
    kc_transaktion(function () use ($cen_verktyg, &$logg): void {
        $tv = kc_tabell('md_verktyg');
        $ta = kc_tabell('md_anvandare');
        $tb = kc_tabell('md_behorighet');

        // Verktygsregistret: lägg till de som saknas (CentralKollen aktiv, övriga inaktiva)
        $nya = 0;
        foreach ($cen_verktyg as [$prefix, $namn]) {
            $ar_cen = $prefix === 'cen';
            $nya += kc_kor(
                "INSERT INTO `$tv` (uuid, last_changed, is_deleted, source_tool, prefix, namn, bas_url, is_active)
                 SELECT ?, ?, 0, 'cen', ?, ?, ?, ? FROM DUAL
                 WHERE NOT EXISTS (SELECT 1 FROM `$tv` WHERE prefix = ? AND is_deleted = 0)",
                [kc_uuid(), kc_nu(), $prefix, $namn, $ar_cen ? KOLL_URL : '', $ar_cen ? 1 : 0, $prefix]
            );
        }
        $logg[] = $nya > 0 ? "Lade till $nya verktyg i registret." : 'Verktygsregistret var redan komplett.';

        // Standardkonto: bara om det inte finns några användare alls
        $antal = (int)kc_hamta_varde("SELECT COUNT(*) FROM `$ta` WHERE is_deleted = 0");
        if ($antal === 0) {
            $anv_uuid = kc_uuid();
            kc_kor(
                "INSERT INTO `$ta` (uuid, last_changed, is_deleted, source_tool,
                                    anvandarnamn, fornamn, efternamn, epost, telefon, losenord_hash, is_active)
                 VALUES (?, ?, 0, 'cen', ?, 'Administratör', '', '', '', ?, 1)",
                [$anv_uuid, kc_nu(), CEN_STANDARD_ANVANDARE, kc_losenord_hash(CEN_STANDARD_LOSENORD)]
            );
            $cen_uuid = (string)kc_hamta_varde(
                "SELECT uuid FROM `$tv` WHERE prefix = 'cen' AND is_deleted = 0 ORDER BY id LIMIT 1"
            );
            kc_kor(
                "INSERT INTO `$tb` (uuid, last_changed, is_deleted, source_tool, anvandare_uuid, verktyg_uuid)
                 VALUES (?, ?, 0, 'cen', ?, ?)",
                [kc_uuid(), kc_nu(), $anv_uuid, $cen_uuid]
            );
            $logg[] = 'Skapade standardkontot ' . CEN_STANDARD_ANVANDARE . ' med administratörsbehörighet.';
        } else {
            $logg[] = "Det finns redan $antal användare – inget standardkonto skapades.";
        }
    });

    $_SESSION['cen_install_logg'] = $logg;
    kc_flash('ok', 'Installationen är klar.');
    kc_omdirigera('install.php');
}

/* ---------- Visning ---------- */

$logg = $_SESSION['cen_install_logg'] ?? [];
unset($_SESSION['cen_install_logg']);

$status = [];
foreach (array_merge(['sys_inloggning'], array_keys($cen_tabeller), array_keys($cen_sys_tabeller)) as $namn) {
    $t = kc_tabell($namn);
    $finns = cen_inst_tabell_finns($t);
    $status[] = [
        'tabell' => $t,
        'finns'  => $finns,
        'rader'  => $finns ? (int)kc_hamta_varde("SELECT COUNT(*) FROM `$t`") : null,
    ];
}

kc_sidhuvud('Installation');
?>
<?php if (!$installation_oppen): ?>
<div class="kc-ruta">
  <h2>Installationen är låst</h2>
  <p>För att köra installationen, ändra i <code>config.php</code> till:</p>
  <p><code>define('KOLL_INSTALL', true);</code></p>
  <p class="kc-dampad">Sätt tillbaka till <code>false</code> när du är klar.</p>
</div>
<?php else: ?>

<?php if ($logg !== []): ?>
<div class="kc-ruta">
  <h2>Resultat</h2>
  <ul>
    <?php foreach ($logg as $rad): ?><li><?= kc_h($rad) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="kc-ruta">
  <h2>Status</h2>
  <p>Variant: <code><?= kc_h(KOLL_VARIANT) ?></code> · Databas: <code><?= kc_h(DB_NAMN) ?></code></p>
  <div class="kc-tabell-wrap">
    <table class="kc-tabell">
      <thead><tr><th>Tabell</th><th>Finns</th><th>Rader</th></tr></thead>
      <tbody>
      <?php foreach ($status as $s): ?>
        <tr>
          <td><code><?= kc_h($s['tabell']) ?></code></td>
          <td><?= $s['finns'] ? 'Ja' : 'Nej' ?></td>
          <td><?= $s['rader'] === null ? '–' : kc_h($s['rader']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="kc-ruta">
  <h2>Installera / uppdatera</h2>
  <p>Skapar saknade tabeller och kolumner, fyller på verktygsregistret och skapar
     standardkontot om databasen saknar användare. Befintlig data påverkas inte.</p>
  <form method="post">
    <?= kc_csrf_falt() ?>
    <input type="hidden" name="atgard" value="installera">
    <button type="submit" class="kc-knapp-primar">Kör installationen</button>
  </form>
  <p class="kc-hjalp" style="margin-top:.75rem">
    Standardkonto: <code><?= kc_h(CEN_STANDARD_ANVANDARE) ?></code> /
    <code><?= kc_h(CEN_STANDARD_LOSENORD) ?></code> – byt lösenordet direkt efter första inloggningen.
  </p>
</div>
<?php endif; ?>
<?php kc_sidfot();
