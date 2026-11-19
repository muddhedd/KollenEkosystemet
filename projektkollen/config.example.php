<?php
/**
 * ProjektKollen – config.php
 * Variant: GITHUB (exempel)
 * Kopiera till config.php och fyll i dina värden. config.php ska aldrig checkas in (se .gitignore).
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

// Verktyget
define('KOLL_PREFIX',  'prj');
define('KOLL_NAMN',    'ProjektKollen');
define('KOLL_VARIANT', 'github');   // labb | demo | github – 'demo' ger prefixet demo_ på alla tabeller
define('KOLL_URL',     'https://exempel.se/kollen/projektkollen/');   // slutar med /

// Databas (samma som CentralKollen)
define('DB_HOST',      'ÄNDRA');
define('DB_NAMN',      'ÄNDRA');
define('DB_ANVANDARE', 'ÄNDRA');
define('DB_LOSENORD',  'ÄNDRA');

// Koppling till CentralKollen – kopiera raderna från ProjektKollens sida i CentralKollen
define('CEN_URL',         'https://exempel.se/kollen/centralkollen/');
define('NYCKEL_TILL_CEN', 'ÄNDRA');   // genereras i CentralKollen, visas en gång
define('NYCKEL_FRAN_CEN', 'ÄNDRA');   // genereras i CentralKollen (för verktygets API)

// Installation: true = install.php är öppen. Sätt till false när du är klar.
define('KOLL_INSTALL', true);

// Övrigt
define('KOLL_DEBUG',           false);                // visar feldetaljer – false i DEMO/GITHUB
define('KOLL_TIDSZON',         'Europe/Stockholm');  // visning; lagring alltid UTC
define('KOLL_SESSION_MINUTER', 240);                 // utloggning vid inaktivitet
