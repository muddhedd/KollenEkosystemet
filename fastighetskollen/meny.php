<?php
/**
 * FastighetsKollen – meny.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Läses av kärnpaketets kc_sidhuvud(). Läsning är öppen för alla.
 * OBS: körs inuti kc_sidhuvud() – använd bara fas_meny_-prefixade variabler.
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

return [
    ['etikett' => 'Start',       'fil' => 'index.php'],
    ['etikett' => 'Fastigheter', 'fil' => 'fastigheter.php'],
    ['etikett' => 'Byggnader',   'fil' => 'byggnader.php'],
    ['etikett' => 'Objekt',      'fil' => 'objektlista.php'],
    ['etikett' => 'Objektgrupper', 'fil' => 'objektgrupper.php'],
    ['etikett' => 'Egenskaper',  'fil' => 'egenskaper.php'],
    ['etikett' => 'Typer',       'fil' => 'typer.php'],
    ['etikett' => 'Import',      'fil' => 'import.php'],
    ['etikett' => 'Synk',        'fil' => 'synk.php'],
];
