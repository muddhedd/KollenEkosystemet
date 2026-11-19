<?php
/**
 * FastighetsKollen – egenskapsvarde_redigera.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Fyll i egenskapsvärden för en post. Sidan finns i kärnpaketet (core/egenskap.php, från 1.8.0).
 * Kräver inloggning. Parametrar: niva, uuid (postens).
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/fas.php';

kc_egenskap_sida_varden();
