<?php
/**
 * CentralKollen – config.php
 * Variant: GITHUB (exempel)
 * Kopiera till config.php och fyll i dina värden. config.php ska aldrig checkas in (se .gitignore).
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

// Verktyget
define('KOLL_PREFIX',  'cen');
define('KOLL_NAMN',    'CentralKollen');
define('KOLL_VARIANT', 'github');   // labb | demo | github – 'demo' ger prefixet demo_ på alla tabeller
define('KOLL_URL',     'https://exempel.se/kollen/centralkollen/');   // slutar med /

// Databas
define('DB_HOST',      'ÄNDRA');
define('DB_NAMN',      'ÄNDRA');
define('DB_ANVANDARE', 'ÄNDRA');
define('DB_LOSENORD',  'ÄNDRA');

// CentralKollen pekar på sig själv; nycklarna används inte här
define('CEN_URL', KOLL_URL);

// Installation: true = install.php och demo_data.php är öppna. Sätt till false när du är klar.
define('KOLL_INSTALL', true);

// Övrigt
define('KOLL_DEBUG',           false);                // visar feldetaljer – false i DEMO/GITHUB
define('KOLL_TIDSZON',         'Europe/Stockholm');  // visning; lagring alltid UTC
define('KOLL_SESSION_MINUTER', 240);                 // utloggning vid inaktivitet
