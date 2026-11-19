<?php
/**
 * ProjektKollen – prj_zip.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Minimal zip-skrivare utan bibliotek och utan temporära filer: bygger arkivet i minnet
 * (deflate via gzdeflate, CRC-32, UTF-8-filnamn). Kandidat att flyttas till kärnpaketet.
 *
 * Användning: $zip = prj_zip(['del1.csv' => $innehall1, 'del2.csv' => $innehall2]);
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

/** Bygger ett zip-arkiv av [filnamn => innehåll] och returnerar det som sträng. */
function prj_zip(array $filer, ?DateTimeInterface $tid = null): string
{
    $tid ??= new DateTimeImmutable('now', new DateTimeZone(KOLL_TIDSZON));
    $dostid = ((int)$tid->format('G') << 11) | ((int)$tid->format('i') << 5) | intdiv((int)$tid->format('s'), 2);
    $dosdatum = (((int)$tid->format('Y') - 1980) << 9) | ((int)$tid->format('n') << 5) | (int)$tid->format('j');
    $ut = '';
    $katalog = '';
    $antal = 0;
    foreach ($filer as $namn => $data) {
        $namn = (string)$namn;
        $data = (string)$data;
        $crc = crc32($data);
        $komp = gzdeflate($data, 6);
        $metod = 8;
        if ($komp === false || strlen($komp) >= strlen($data)) {
            $komp = $data;
            $metod = 0;
        }
        $flaggor = 0x0800;   // filnamn i UTF-8
        $offset = strlen($ut);
        $ut .= pack('VvvvvvVVVvv', 0x04034b50, 20, $flaggor, $metod, $dostid, $dosdatum,
                    $crc, strlen($komp), strlen($data), strlen($namn), 0) . $namn . $komp;
        $katalog .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, $flaggor, $metod, $dostid, $dosdatum,
                    $crc, strlen($komp), strlen($data), strlen($namn), 0, 0, 0, 0, 0, $offset) . $namn;
        $antal++;
    }
    return $ut . $katalog . pack('VvvvvVVv', 0x06054b50, 0, 0, $antal, $antal, strlen($katalog), strlen($ut), 0);
}
