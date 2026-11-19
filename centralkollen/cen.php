<?php
/**
 * CentralKollen – cen.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * CentralKollens egna hjälpfunktioner (prefix cen_).
 * Inkluderas direkt efter kärnpaketet:
 *   require __DIR__ . '/core/bootstrap.php';
 *   require __DIR__ . '/cen.php';
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

/** Standardkonto som install.php skapar om databasen saknar användare. */
const CEN_STANDARD_ANVANDARE = 'admin';
const CEN_STANDARD_LOSENORD  = '123Qwe!!';

/** Bcrypt-hash av ett slumpat lösenord. Används när användaren inte finns,
 *  så att inloggningen tar lika lång tid oavsett (försvårar gissning av namn). */
const CEN_DUMMY_HASH = '$2y$12$PWIc1GAXVzSFJoO4pmkepOXJU5OkoJbqO.id4h/aJC4wT3ftODZQC';

/* ---------- Användare ---------- */

/** Ej raderad användare via uuid, eller null. */
function cen_anvandare_hamta(string $uuid): ?array
{
    $t = kc_tabell('md_anvandare');
    return kc_hamta_en("SELECT * FROM `$t` WHERE uuid = ? AND is_deleted = 0 LIMIT 1", [$uuid]);
}

/** Ej raderad användare via användarnamn (skiftlägesokänsligt), eller null. */
function cen_anvandare_via_namn(string $anvandarnamn): ?array
{
    $t = kc_tabell('md_anvandare');
    return kc_hamta_en(
        "SELECT * FROM `$t` WHERE anvandarnamn = ? AND is_deleted = 0 ORDER BY id LIMIT 1",
        [$anvandarnamn]
    );
}

/**
 * Validerar användaruppgifter. Returnerar en lista med felmeddelanden (tom = ok).
 * $egen_uuid = användaren som redigeras (null vid ny), undantas i unikhetskontrollen.
 */
function cen_anvandare_validera(array $v, ?string $egen_uuid): array
{
    $fel = [];

    if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $v['anvandarnamn'])) {
        $fel[] = 'Användarnamnet måste vara 3–100 tecken och får bara innehålla a–z, siffror, punkt, bindestreck och understreck.';
    }
    if ($v['fornamn'] === '') {
        $fel[] = 'Förnamn måste fyllas i.';
    } elseif (mb_strlen($v['fornamn']) > 100) {
        $fel[] = 'Förnamnet är för långt (max 100 tecken).';
    }
    if (mb_strlen($v['efternamn']) > 100) {
        $fel[] = 'Efternamnet är för långt (max 100 tecken).';
    }
    if ($v['epost'] === '') {
        $fel[] = 'E-post måste fyllas i.';
    } elseif (mb_strlen($v['epost']) > 255 || filter_var($v['epost'], FILTER_VALIDATE_EMAIL) === false) {
        $fel[] = 'E-postadressen är inte giltig.';
    }
    if (!preg_match('/^[0-9 +()\-]{0,50}$/', $v['telefon'])) {
        $fel[] = 'Telefonnumret får bara innehålla siffror, mellanslag, +, -, ( och ) (max 50 tecken).';
    }

    // Unikhet bland ej raderade användare (kollationen gör jämförelsen skiftlägesokänslig)
    $t = kc_tabell('md_anvandare');
    $undantag = $egen_uuid !== null ? ' AND uuid <> ?' : '';
    foreach (['anvandarnamn' => 'Användarnamnet', 'epost' => 'E-postadressen'] as $falt => $etikett) {
        if ($v[$falt] === '') {
            continue;
        }
        $params = [$v[$falt]];
        if ($egen_uuid !== null) {
            $params[] = $egen_uuid;
        }
        $antal = (int)kc_hamta_varde(
            "SELECT COUNT(*) FROM `$t` WHERE is_deleted = 0 AND `$falt` = ?$undantag",
            $params
        );
        if ($antal > 0) {
            $fel[] = "$etikett används redan av en annan användare.";
        }
    }
    return $fel;
}

/* ---------- Verktyg ---------- */

/** Alla ej raderade verktyg: CentralKollen först, sedan aktiva, sedan namn. */
function cen_verktyg_alla(): array
{
    $t = kc_tabell('md_verktyg');
    return kc_hamta_alla(
        "SELECT uuid, prefix, namn, is_active FROM `$t`
          WHERE is_deleted = 0
          ORDER BY prefix = 'cen' DESC, is_active DESC, namn ASC"
    );
}

/** uuid för verktyget med givet prefix, eller null. */
function cen_verktyg_uuid(string $prefix): ?string
{
    $t = kc_tabell('md_verktyg');
    $uuid = kc_hamta_varde(
        "SELECT uuid FROM `$t` WHERE prefix = ? AND is_deleted = 0 ORDER BY id LIMIT 1",
        [$prefix]
    );
    return $uuid === null ? null : (string)$uuid;
}

/**
 * Anropar ett verktygs API (kontrakt v1) med verktygets NYCKEL_FRAN_CEN.
 * $verktyg = rad ur md_verktyg (prefix, namn, bas_url, nyckel_fran_cen).
 * Anropet loggas i sys_synklogg med endpoint "ut_<endpoint>". Returnerar svarets data.
 */
function cen_verktyg_anrop(array $verktyg, string $endpoint, array $data = [], int $timeout = 15): mixed
{
    if (!preg_match('/^[a-z]+$/', $endpoint)) {
        throw new KcFel('Ogiltig endpoint.', 500);
    }
    if ((string)$verktyg['bas_url'] === '') {
        throw new KcFel('Verktyget saknar bas-URL.', 400);
    }
    if (!preg_match('/^[0-9a-f]{64}$/', (string)$verktyg['nyckel_fran_cen'])) {
        throw new KcFel('Verktyget saknar NYCKEL_FRAN_CEN. Generera den nedan och lägg in den i verktygets config.php.', 400);
    }
    $start = microtime(true);
    $status = 200;
    $meddelande = 'ok';
    try {
        return kc_api_post($verktyg['bas_url'] . 'api/?e=' . $endpoint, 'cen', $verktyg['nyckel_fran_cen'],
                           $data, $timeout, $verktyg['namn']);
    } catch (KcFel $e) {
        $status = $e->status();
        $meddelande = $e->getMessage();
        throw $e;
    } finally {
        try {
            kc_kor(
                'INSERT INTO `' . kc_tabell('sys_synklogg') . '`
                    (tid, verktyg_prefix, endpoint, tabell, antal, status, meddelande, ip, ms)
                 VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)',
                [kc_nu(), $verktyg['prefix'], 'ut_' . $endpoint, substr((string)($data['tabell'] ?? ''), 0, 64),
                 $status, mb_substr($meddelande, 0, 255), 'ut', (int)round((microtime(true) - $start) * 1000)]
            );
        } catch (Throwable $e2) {
            error_log('[Kollen cen] Loggning av utgående anrop misslyckades: ' . $e2->getMessage());
        }
    }
}

/* ---------- Behörigheter ---------- */

/** Har användaren behörighet till verktyget cen (= administratör)? */
function cen_ar_admin(string $anvandare_uuid): bool
{
    $b = kc_tabell('md_behorighet');
    $v = kc_tabell('md_verktyg');
    return (int)kc_hamta_varde(
        "SELECT COUNT(*) FROM `$b` b
           JOIN `$v` v ON v.uuid = b.verktyg_uuid
          WHERE b.anvandare_uuid = ? AND b.is_deleted = 0
            AND v.prefix = 'cen' AND v.is_deleted = 0",
        [$anvandare_uuid]
    ) > 0;
}

/** Lista med verktyg_uuid som användaren har behörighet till. */
function cen_behorigheter(string $anvandare_uuid): array
{
    $t = kc_tabell('md_behorighet');
    return array_column(kc_hamta_alla(
        "SELECT DISTINCT verktyg_uuid FROM `$t` WHERE anvandare_uuid = ? AND is_deleted = 0",
        [$anvandare_uuid]
    ), 'verktyg_uuid');
}

/**
 * Sätter eller tar bort en behörighet. Rör bara databasen om något ändras,
 * så att last_changed bara uppdateras vid verkliga ändringar.
 * En tidigare borttagen behörighet återaktiveras (samma uuid) i stället för att dupliceras.
 * Returnerar true om något ändrades.
 */
function cen_behorighet_satt(string $anvandare_uuid, string $verktyg_uuid, bool $ska_ha): bool
{
    $t = kc_tabell('md_behorighet');
    $rader = kc_hamta_alla(
        "SELECT uuid, is_deleted FROM `$t` WHERE anvandare_uuid = ? AND verktyg_uuid = ?
          ORDER BY is_deleted ASC, id ASC",
        [$anvandare_uuid, $verktyg_uuid]
    );
    $har = $rader !== [] && (int)$rader[0]['is_deleted'] === 0;

    if ($ska_ha) {
        if ($har) {
            return false;
        }
        if ($rader !== []) {
            kc_kor(
                "UPDATE `$t` SET is_deleted = 0, last_changed = ?, source_tool = 'cen' WHERE uuid = ?",
                [kc_nu(), $rader[0]['uuid']]
            );
            return true;
        }
        kc_kor(
            "INSERT INTO `$t` (uuid, last_changed, is_deleted, source_tool, anvandare_uuid, verktyg_uuid)
             VALUES (?, ?, 0, 'cen', ?, ?)",
            [kc_uuid(), kc_nu(), $anvandare_uuid, $verktyg_uuid]
        );
        return true;
    }

    if (!$har) {
        return false;
    }
    kc_kor(
        "UPDATE `$t` SET is_deleted = 1, last_changed = ?, source_tool = 'cen'
          WHERE anvandare_uuid = ? AND verktyg_uuid = ? AND is_deleted = 0",
        [kc_nu(), $anvandare_uuid, $verktyg_uuid]
    );
    return true;
}

/* ---------- Inloggning ---------- */

/**
 * Inloggad användare, kontrollerad mot databasen vid varje sidvisning.
 * Raderad eller spärrad användare loggas ut direkt.
 * Returnerar användarraden + 'ar_admin', eller null.
 */
function cen_inloggad(): ?array
{
    static $kontrollerad = false;
    static $anvandare = null;

    if ($kontrollerad) {
        return $anvandare;
    }
    $kontrollerad = true;

    $session = kc_inloggad();
    if ($session === null) {
        return null;
    }
    $rad = cen_anvandare_hamta((string)$session['uuid']);
    if ($rad === null || (int)$rad['is_active'] !== 1) {
        kc_logga_ut();
        kc_flash('info', 'Din inloggning är inte längre giltig. Logga in igen.');
        return null;
    }
    $rad['ar_admin'] = cen_ar_admin($rad['uuid']);
    $anvandare = $rad;
    return $anvandare;
}

/** Kräver inloggning (vilken aktiv användare som helst). */
function cen_krav_inloggning(): array
{
    kc_krav_inloggning();
    $anvandare = cen_inloggad();
    if ($anvandare === null) {
        kc_omdirigera('login.php');
    }
    return $anvandare;
}

/** Kräver inloggning med behörighet till cen (administratör). */
function cen_krav_admin(): array
{
    $anvandare = cen_krav_inloggning();
    if (!$anvandare['ar_admin']) {
        throw new KcFel('Du saknar behörighet till den här sidan.', 403);
    }
    return $anvandare;
}

/** Använder kontot fortfarande standardlösenordet? */
function cen_har_standardlosenord(array $anvandare): bool
{
    return password_verify(CEN_STANDARD_LOSENORD, (string)($anvandare['losenord_hash'] ?? ''));
}
