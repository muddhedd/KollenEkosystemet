<?php
/**
 * Kollen kärnpaket – bootstrap.php
 * Variant: GEMENSAM (identisk i LABB, DEMO och GITHUB)
 *
 * Inkluderas överst på varje sida i verktyget:
 *   require __DIR__ . '/core/bootstrap.php';
 * API-filer definierar först: define('KC_API', true);
 */
declare(strict_types=1);

if (defined('KC_BOOT')) {
    return;
}
define('KC_BOOT', true);
define('KC_DIR', __DIR__);
define('KC_ROT', dirname(__DIR__));

$kc_config = KC_ROT . '/config.php';
if (!is_file($kc_config)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('config.php saknas i verktygets rotmapp.');
}
require_once $kc_config;
unset($kc_config);

$kc_version = @file_get_contents(KC_DIR . '/VERSION');
define('KC_VERSION', $kc_version === false ? 'okänd' : trim($kc_version));
unset($kc_version);

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

require_once KC_DIR . '/fel.php';
require_once KC_DIR . '/hjalp.php';
require_once KC_DIR . '/db.php';
require_once KC_DIR . '/sakerhet.php';
require_once KC_DIR . '/lista.php';
require_once KC_DIR . '/layout.php';
require_once KC_DIR . '/installera.php';
require_once KC_DIR . '/verktyg.php';
require_once KC_DIR . '/import.php';
require_once KC_DIR . '/egenskap.php';

kc_fel_aktivera();
kc_config_validera();
kc_sakerhetshuvuden();

if (!defined('KC_API')) {
    ob_start();                      // buffrar sidan så att t.ex. export kan ersätta den
    kc_session_starta();
    if (kc_ar_post()) {
        kc_csrf_kontrollera();       // automatiskt på ALLA formulär
        kc_utloggning_hantera();     // utloggningsknappen i sidhuvudet
    }
}
