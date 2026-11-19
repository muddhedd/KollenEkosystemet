<?php
/**
 * ProjektKollen – meny.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Läses av kärnpaketets kc_sidhuvud(). Läsning är öppen för alla.
 * OBS: körs inuti kc_sidhuvud() – använd bara prj_meny_-prefixade variabler.
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

return [
    ['etikett' => 'Start',          'fil' => 'index.php'],
    ['etikett' => 'Projekt',        'fil' => 'projektlista.php'],
    ['etikett' => 'Mätare',         'fil' => 'matarlista.php'],
    ['etikett' => 'Import',         'fil' => 'import.php'],
    ['etikett' => 'Exportmallar',   'fil' => 'exportmallar.php'],
    ['etikett' => 'Typer',          'fil' => 'typer.php'],
    ['etikett' => 'Fastighetsdata', 'fil' => 'fastighetsdata.php'],
    ['etikett' => 'Synk',           'fil' => 'synk.php'],
];
