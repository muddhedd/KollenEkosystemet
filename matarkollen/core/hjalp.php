<?php
/**
 * Kollen kärnpaket – hjalp.php
 * Variant: GEMENSAM
 * Små hjälpfunktioner: escaping, uuid, tid, URL:er, indata, flash.
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

function kc_config_validera(): void
{
    $kc_saknas = [];
    foreach (['KOLL_PREFIX', 'KOLL_NAMN', 'KOLL_VARIANT', 'KOLL_URL', 'DB_HOST', 'DB_NAMN', 'DB_ANVANDARE', 'DB_LOSENORD'] as $kc_k) {
        if (!defined($kc_k)) {
            $kc_saknas[] = $kc_k;
        }
    }
    if ($kc_saknas !== []) {
        throw new KcFel('config.php saknar: ' . implode(', ', $kc_saknas), 500);
    }
    if (!preg_match('/^[a-z]{3}$/', KOLL_PREFIX)) {
        throw new KcFel('KOLL_PREFIX måste vara exakt tre bokstäver a–z.', 500);
    }
    if (!in_array(KOLL_VARIANT, ['labb', 'demo', 'github'], true)) {
        throw new KcFel('KOLL_VARIANT måste vara labb, demo eller github.', 500);
    }
    if (!str_ends_with(KOLL_URL, '/')) {
        throw new KcFel('KOLL_URL måste sluta med /.', 500);
    }
    if (!defined('KOLL_DEBUG'))           { define('KOLL_DEBUG', false); }
    if (!defined('KOLL_TIDSZON'))         { define('KOLL_TIDSZON', 'Europe/Stockholm'); }
    if (!defined('KOLL_SESSION_MINUTER')) { define('KOLL_SESSION_MINUTER', 240); }
}

/** Escapar för HTML-utdata. Använd på ALL data som skrivs ut. */
function kc_h(mixed $v): string
{
    if ($v === null) {
        return '';
    }
    if (is_bool($v)) {
        $v = $v ? '1' : '0';
    }
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Ny UUID v4. */
function kc_uuid(): string
{
    $kc_b = random_bytes(16);
    $kc_b[6] = chr((ord($kc_b[6]) & 0x0f) | 0x40);
    $kc_b[8] = chr((ord($kc_b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($kc_b), 4));
}

function kc_ar_uuid(string $v): bool
{
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v);
}

/** Aktuell tid i UTC, format för DATETIME(6). */
function kc_nu(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
}

/** UTC-tid från databasen → lokal visningstid. */
function kc_tid(?string $utc, string $format = 'Y-m-d H:i'): string
{
    if ($utc === null || $utc === '') {
        return '';
    }
    try {
        $kc_d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    } catch (Exception) {
        return '';
    }
    return $kc_d->setTimezone(new DateTimeZone(KOLL_TIDSZON))->format($format);
}

/** Full URL inom verktyget, t.ex. kc_url('login.php'). */
function kc_url(string $sokvag = ''): string
{
    return KOLL_URL . ltrim($sokvag, '/');
}

function kc_omdirigera(string $sokvag): never
{
    $kc_mal = preg_match('#^https?://#i', $sokvag) ? $sokvag : kc_url($sokvag);
    header('Location: ' . $kc_mal, true, 303);
    exit;
}

/** Säker returadress inom verktyget (skydd mot öppen omdirigering). */
function kc_saker_retur(string $till, string $standard = 'index.php'): string
{
    if ($till !== ''
        && preg_match('/^[a-z0-9_\-]+\.php(\?\S*)?$/i', $till)
        && !str_contains($till, '//')
        && !str_contains($till, '\\')) {
        return $till;
    }
    return $standard;
}

function kc_get(string $namn, string $standard = ''): string
{
    $kc_v = $_GET[$namn] ?? null;
    return is_string($kc_v) ? trim($kc_v) : $standard;
}

function kc_post(string $namn, string $standard = ''): string
{
    $kc_v = $_POST[$namn] ?? null;
    return is_string($kc_v) ? trim($kc_v) : $standard;
}

/** Meddelande som visas på nästa sida. Typ: ok | fel | info */
function kc_flash(string $typ, string $text): void
{
    $_SESSION['kc_flash'][] = ['typ' => $typ, 'text' => $text];
}

function kc_flash_hamta(): array
{
    $kc_f = $_SESSION['kc_flash'] ?? [];
    unset($_SESSION['kc_flash']);
    return is_array($kc_f) ? $kc_f : [];
}

/** Ny API-nyckel: 64 hextecken. */
function kc_nyckel_generera(): string
{
    return bin2hex(random_bytes(32));
}

function kc_klient_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}
