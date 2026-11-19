<?php
/**
 * MätarKollen – egenskap_redigera.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Skapa, ändra och radera en egenskapsdefinition och dess val. Sidan finns i kärnpaketet
 * (core/egenskap.php, från 1.8.0). Kräver inloggning. Parametrar: uuid, annars ny (valfritt niva).
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/mat.php';

kc_egenskap_sida_definition();
