<?php
/**
 * CentralKollen – meny.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Läses av kärnpaketets kc_sidhuvud(). 'inloggning' => true döljer
 * punkten för icke inloggade. Administratörspunkter visas bara för admin.
 * OBS: körs inuti kc_sidhuvud() – använd bara cen_meny_-prefixade variabler.
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

$cen_meny_anv   = function_exists('cen_inloggad') ? cen_inloggad() : null;
$cen_meny_admin = $cen_meny_anv !== null && $cen_meny_anv['ar_admin'];

$cen_meny = [['etikett' => 'Start', 'fil' => 'index.php']];
if ($cen_meny_admin) {
    $cen_meny[] = ['etikett' => 'Användare', 'fil' => 'anvandare.php'];
    $cen_meny[] = ['etikett' => 'Verktyg',   'fil' => 'verktyg.php'];
    $cen_meny[] = ['etikett' => 'Dataflöde', 'fil' => 'dataflode.php'];
    $cen_meny[] = ['etikett' => 'Synkstatus', 'fil' => 'synkstatus.php'];
    $cen_meny[] = ['etikett' => 'Synklogg',   'fil' => 'synklogg.php'];
    if (KOLL_VARIANT === 'labb' && is_file(KC_ROT . '/api_test.php')) {
        $cen_meny[] = ['etikett' => 'API-test', 'fil' => 'api_test.php'];
    }
}
$cen_meny[] = ['etikett' => 'Byt lösenord', 'fil' => 'losenord.php', 'inloggning' => true];

return $cen_meny;
