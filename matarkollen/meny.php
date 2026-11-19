<?php
/**
 * MätarKollen – meny.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Läses av kärnpaketets kc_sidhuvud(). Läsning är öppen för alla.
 * OBS: körs inuti kc_sidhuvud() – använd bara mat_meny_-prefixade variabler.
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

return [
    ['etikett' => 'Start',            'fil' => 'index.php'],
    ['etikett' => 'Mätpunkter',       'fil' => 'matpunkter.php'],
    ['etikett' => 'Mätare',           'fil' => 'matarlista.php'],
    ['etikett' => 'Typer',            'fil' => 'typer.php'],
    ['etikett' => 'Egenskaper',       'fil' => 'egenskaper.php'],
    ['etikett' => 'Import',           'fil' => 'import.php'],
    ['etikett' => 'Fastighetsdata',   'fil' => 'fastighetsdata.php'],
    ['etikett' => 'Synk',             'fil' => 'synk.php'],
];
