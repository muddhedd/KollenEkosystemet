<?php
/**
 * Kollen kärnpaket – fel.php
 * Variant: GEMENSAM
 * Global felhantering. AJAX/API får alltid JSON, vanliga sidor en felsida.
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

/** Fel vars meddelande är säkert att visa för användaren. */
class KcFel extends RuntimeException
{
    public function __construct(string $meddelande, private int $kc_status = 400)
    {
        parent::__construct($meddelande);
    }

    public function status(): int
    {
        return $this->kc_status;
    }
}

function kc_ar_api_anrop(): bool
{
    if (defined('KC_API')) {
        return true;
    }
    if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
        return true;
    }
    return str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

/** Skickar standardsvaret {ok, data, error} och avslutar. */
function kc_json(bool $ok, mixed $data = null, ?string $fel = null, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(
        ['ok' => $ok, 'data' => $data, 'error' => $fel],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function kc_fel_aktivera(): void
{
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');

    set_error_handler(static function (int $niva, string $text, string $fil, int $rad): bool {
        if (!(error_reporting() & $niva)) {
            return false;
        }
        if ($niva === E_DEPRECATED || $niva === E_USER_DEPRECATED) {
            error_log("[Kollen] Deprecated: $text ($fil:$rad)");
            return true;
        }
        throw new ErrorException($text, 0, $niva, $fil, $rad);
    });

    set_exception_handler('kc_fel_hantera');

    register_shutdown_function(static function (): void {
        $kc_e = error_get_last();
        if ($kc_e !== null && in_array($kc_e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            kc_fel_hantera(new ErrorException($kc_e['message'], 0, $kc_e['type'], $kc_e['file'], $kc_e['line']));
        }
    });
}

function kc_fel_hantera(Throwable $e): void
{
    $kc_status = $e instanceof KcFel ? $e->status() : 500;
    $kc_debug  = defined('KOLL_DEBUG') && KOLL_DEBUG;
    $kc_verktyg = defined('KOLL_PREFIX') ? KOLL_PREFIX : '???';

    if (!$e instanceof KcFel || $kc_status >= 500) {
        error_log(sprintf('[Kollen %s] %s: %s (%s:%d)', $kc_verktyg, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    }

    if ($e instanceof KcFel) {
        $kc_visa = $e->getMessage();
    } elseif ($kc_debug) {
        $kc_visa = get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    } else {
        $kc_visa = 'Ett oväntat fel inträffade. Händelsen är loggad.';
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (kc_ar_api_anrop()) {
        kc_json(false, null, $kc_visa, $kc_status);
    }

    if (!headers_sent()) {
        http_response_code($kc_status);
        header('Content-Type: text/html; charset=utf-8');
    }
    $kc_h   = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $kc_url = defined('KOLL_URL') ? KOLL_URL : '';
    $kc_css = $kc_url !== '' ? '<link rel="stylesheet" href="' . $kc_h($kc_url . 'core/kollen.css') . '">' : '';
    echo '<!doctype html><html lang="sv"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Fel</title>' . $kc_css . '</head><body><main class="kc-innehall">'
       . '<div class="kc-ruta kc-flash kc-flash-fel"><strong>Något gick fel</strong><br>' . $kc_h($kc_visa) . '</div>'
       . ($kc_url !== '' ? '<p><a href="' . $kc_h($kc_url) . '">Till startsidan</a></p>' : '')
       . '</main></body></html>';
    exit;
}
