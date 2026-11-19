<?php
/**
 * ProjektKollen – api/index.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 *
 * Verktygets API enligt kontrakt v1. CentralKollen anropar:
 *   POST <KOLL_URL>api/?e=<ping|tabeller|export>
 *   Headers: X-Kollen-Prefix: cen, X-Kollen-Nyckel: NYCKEL_FRAN_CEN
 * Logiken finns i kärnpaketet (kc_verktyg_api); här anges bara vilka tabeller som delas
 * (projekt och typlistor; nycklar delas aldrig).
 */
declare(strict_types=1);
define('KC_API', true);
require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/prj.php';

kc_verktyg_api(prj_delade_tabeller());
