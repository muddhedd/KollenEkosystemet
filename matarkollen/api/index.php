<?php
/**
 * MätarKollen – api/index.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 *
 * Verktygets API enligt kontrakt v1. CentralKollen anropar:
 *   POST <KOLL_URL>api/?e=<ping|tabeller|export>
 *   Headers: X-Kollen-Prefix: cen, X-Kollen-Nyckel: NYCKEL_FRAN_CEN
 * Logiken finns i kärnpaketet (kc_verktyg_api); här anges bara vilka tabeller som delas
 * (inga i etapp 1 – egna mastertabeller kommer från etapp 2).
 */
declare(strict_types=1);
define('KC_API', true);
require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/mat.php';

kc_verktyg_api(mat_delade_tabeller());
