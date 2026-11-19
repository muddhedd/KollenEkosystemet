<?php
/**
 * Kollen kärnpaket – sakerhet.php
 * Variant: GEMENSAM
 * Säkerhetshuvuden, session per verktyg, CSRF, inloggningsstatus,
 * inloggningsspärr och lösenordsregler.
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

const KC_SPARR_MINUTER        = 15;
const KC_SPARR_PER_ANVANDARE  = 5;
const KC_SPARR_PER_IP         = 20;

function kc_sakerhetshuvuden(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
}

function kc_ar_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function kc_ar_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Egen session per verktyg, cookie begränsad till verktygets mapp. */
function kc_session_starta(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $kc_sokvag = parse_url(KOLL_URL, PHP_URL_PATH) ?: '/';

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('kollen_' . (KOLL_VARIANT === 'demo' ? 'demo_' : '') . KOLL_PREFIX);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $kc_sokvag,
        'secure'   => kc_ar_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $kc_nu = time();
    if (isset($_SESSION['kc_anvandare'], $_SESSION['kc_senast'])
        && $kc_nu - (int)$_SESSION['kc_senast'] > KOLL_SESSION_MINUTER * 60) {
        unset($_SESSION['kc_anvandare']);
        kc_flash('info', 'Du har loggats ut efter en tids inaktivitet.');
    }
    $_SESSION['kc_senast'] = $kc_nu;
}

/* ---------- CSRF ---------- */

function kc_csrf_token(): string
{
    if (empty($_SESSION['kc_csrf']) || !is_string($_SESSION['kc_csrf'])) {
        $_SESSION['kc_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['kc_csrf'];
}

/** Dolt fält som ska finnas i varje POST-formulär. */
function kc_csrf_falt(): string
{
    return '<input type="hidden" name="kc_csrf" value="' . kc_h(kc_csrf_token()) . '">';
}

/** Anropas automatiskt av bootstrap vid varje POST. */
function kc_csrf_kontrollera(): void
{
    $kc_token = $_POST['kc_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($kc_token)
        || empty($_SESSION['kc_csrf'])
        || !is_string($_SESSION['kc_csrf'])
        || !hash_equals($_SESSION['kc_csrf'], $kc_token)) {
        throw new KcFel('Formuläret har gått ut eller är ogiltigt. Ladda om sidan och försök igen.', 403);
    }
}

/* ---------- Inloggningsstatus ---------- */

/** ['uuid', 'anvandarnamn', 'namn'] eller null. */
function kc_inloggad(): ?array
{
    $kc_a = $_SESSION['kc_anvandare'] ?? null;
    return is_array($kc_a) ? $kc_a : null;
}

/** Anropas av verktygets login.php efter godkänt lösenord. */
function kc_logga_in(array $anvandare): void
{
    session_regenerate_id(true);
    $_SESSION['kc_anvandare'] = [
        'uuid'         => (string)$anvandare['uuid'],
        'anvandarnamn' => (string)$anvandare['anvandarnamn'],
        'namn'         => trim(($anvandare['fornamn'] ?? '') . ' ' . ($anvandare['efternamn'] ?? '')),
    ];
    $_SESSION['kc_csrf'] = bin2hex(random_bytes(32));
}

function kc_logga_ut(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

function kc_utloggning_hantera(): void
{
    if (kc_post('kc_atgard') === 'logga_ut') {
        kc_logga_ut();
        kc_flash('ok', 'Du är utloggad.');
        kc_omdirigera('index.php');
    }
}

/** Kräver inloggning; skickar annars till login.php (eller 401 för AJAX). */
function kc_krav_inloggning(): array
{
    $kc_a = kc_inloggad();
    if ($kc_a !== null) {
        return $kc_a;
    }
    if (kc_ar_api_anrop()) {
        kc_json(false, null, 'Inloggning krävs.', 401);
    }
    $kc_har = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    $kc_q   = (string)($_SERVER['QUERY_STRING'] ?? '');
    $kc_till = kc_saker_retur($kc_har . ($kc_q !== '' ? '?' . $kc_q : ''));
    kc_flash('info', 'Logga in för att fortsätta.');
    kc_omdirigera('login.php?till=' . rawurlencode($kc_till));
}

/* ---------- Inloggningsspärr (tabell <prefix>_sys_inloggning) ---------- */

function kc_inloggning_sparrad(string $anvandarnamn): bool
{
    $kc_t = kc_tabell('sys_inloggning');
    $kc_grans = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('-' . KC_SPARR_MINUTER . ' minutes')->format('Y-m-d H:i:s.u');

    $kc_per_anv = (int)kc_hamta_varde(
        "SELECT COUNT(*) FROM `$kc_t` WHERE lyckad = 0 AND tid > ? AND anvandarnamn = ?",
        [$kc_grans, mb_strtolower(mb_substr($anvandarnamn, 0, 100))]
    );
    $kc_per_ip = (int)kc_hamta_varde(
        "SELECT COUNT(*) FROM `$kc_t` WHERE lyckad = 0 AND tid > ? AND ip = ?",
        [$kc_grans, kc_klient_ip()]
    );
    return $kc_per_anv >= KC_SPARR_PER_ANVANDARE || $kc_per_ip >= KC_SPARR_PER_IP;
}

function kc_inloggning_registrera(string $anvandarnamn, bool $lyckad): void
{
    $kc_t = kc_tabell('sys_inloggning');
    $kc_namn = mb_strtolower(mb_substr($anvandarnamn, 0, 100));

    kc_kor(
        "INSERT INTO `$kc_t` (anvandarnamn, ip, tid, lyckad) VALUES (?, ?, ?, ?)",
        [$kc_namn, kc_klient_ip(), kc_nu(), $lyckad ? 1 : 0]
    );
    if ($lyckad) {
        kc_kor("DELETE FROM `$kc_t` WHERE lyckad = 0 AND anvandarnamn = ?", [$kc_namn]);
    }
    $kc_gammal = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('-30 days')->format('Y-m-d H:i:s.u');
    kc_kor("DELETE FROM `$kc_t` WHERE tid < ?", [$kc_gammal]);
}

/* ---------- Lösenord ---------- */

/** Returnerar felmeddelande, eller null om lösenordet är godkänt. */
function kc_losenord_fel(string $losenord): ?string
{
    if (mb_strlen($losenord) < 8) {
        return 'Lösenordet måste vara minst 8 tecken.';
    }
    if (strlen($losenord) > 72) {
        return 'Lösenordet är för långt (max 72 byte, å/ä/ö räknas som 2).';
    }
    if (!preg_match('/\p{Lu}/u', $losenord)) {
        return 'Lösenordet måste innehålla minst en versal.';
    }
    if (!preg_match('/\p{Ll}/u', $losenord)) {
        return 'Lösenordet måste innehålla minst en gemen.';
    }
    if (!preg_match('/\d/', $losenord)) {
        return 'Lösenordet måste innehålla minst en siffra.';
    }
    return null;
}

function kc_losenord_hash(string $losenord): string
{
    return password_hash($losenord, PASSWORD_BCRYPT);
}
