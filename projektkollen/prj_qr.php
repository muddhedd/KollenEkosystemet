<?php
/**
 * ProjektKollen – prj_qr.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Egen QR-kodare utan externa bibliotek (ISO/IEC 18004): byte-läge, felrättningsnivå M,
 * version 1–10 (upp till 213 byte). Ger en matris av moduler (true = mörk) utan tyst zon.
 * Kandidat att flyttas till kärnpaketet när fler verktyg behöver QR-koder.
 *
 * Användning: $m = prj_qr_matris('1301-01-0001_Temp_11');  // array av rader med bool
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

/** Nivå M, version 1–10: [totalt antal kodord, felrättningskodord per block, antal block]. */
const PRJ_QR_VERSIONER = [
    1 => [26, 10, 1],  2 => [44, 16, 1],  3 => [70, 26, 1],  4 => [100, 18, 2],  5 => [134, 24, 2],
    6 => [172, 16, 4], 7 => [196, 18, 4], 8 => [242, 22, 4], 9 => [292, 22, 5], 10 => [346, 26, 5],
];
/** Justeringsmönstrens positioner per version. */
const PRJ_QR_JUSTERING = [
    1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
    7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
];

/** Multiplikation i GF(256) med polynomet 0x11D. */
function prj_qr_gf_mul(int $x, int $y): int
{
    $z = 0;
    for ($i = 7; $i >= 0; $i--) {
        $z = ($z << 1) ^ (($z >> 7) * 0x11D);
        $z ^= (($y >> $i) & 1) * $x;
    }
    return $z & 0xFF;
}

/** Reed–Solomon: felrättningskodord för ett datablock. */
function prj_qr_rs(array $data, int $grad): array
{
    // Generatorpolynom
    $div = array_fill(0, $grad, 0);
    $div[$grad - 1] = 1;
    $rot = 1;
    for ($i = 0; $i < $grad; $i++) {
        for ($j = 0; $j < $grad; $j++) {
            $div[$j] = prj_qr_gf_mul($div[$j], $rot);
            if ($j + 1 < $grad) {
                $div[$j] ^= $div[$j + 1];
            }
        }
        $rot = prj_qr_gf_mul($rot, 0x02);
    }
    // Rest vid division
    $rest = array_fill(0, $grad, 0);
    foreach ($data as $b) {
        $faktor = $b ^ array_shift($rest);
        $rest[] = 0;
        for ($i = 0; $i < $grad; $i++) {
            $rest[$i] ^= prj_qr_gf_mul($div[$i], $faktor);
        }
    }
    return $rest;
}

/** Kodar text (UTF-8) till en QR-matris. Kastar KcFel om texten är för lång. */
function prj_qr_matris(string $text): array
{
    $bytes = array_values(unpack('C*', $text) ?: []);
    $n = count($bytes);

    // 1. Minsta version som rymmer datat
    $version = 0;
    foreach (PRJ_QR_VERSIONER as $v => [$totalt, $ecc, $block]) {
        $kapacitet = ($totalt - $ecc * $block) * 8;
        if (4 + ($v <= 9 ? 8 : 16) + 8 * $n <= $kapacitet) {
            $version = $v;
            break;
        }
    }
    if ($version === 0) {
        throw new KcFel('Texten är för lång för QR-koden (max 213 byte).', 400);
    }
    [$totalt, $ecc, $antal_block] = PRJ_QR_VERSIONER[$version];
    $datakodord = $totalt - $ecc * $antal_block;

    // 2. Bitström: läge (byte), längd, data, avslut, utfyllnad
    $bitar = '0100' . str_pad(decbin($n), $version <= 9 ? 8 : 16, '0', STR_PAD_LEFT);
    foreach ($bytes as $b) {
        $bitar .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
    }
    $bitar .= str_repeat('0', min(4, $datakodord * 8 - strlen($bitar)));
    $bitar .= str_repeat('0', (8 - strlen($bitar) % 8) % 8);
    $kodord = array_map('bindec', str_split($bitar, 8));
    for ($p = 0; count($kodord) < $datakodord; $p++) {
        $kodord[] = $p % 2 === 0 ? 0xEC : 0x11;
    }

    // 3. Block, felrättning och sammanflätning
    $korta = $antal_block - $totalt % $antal_block;
    $kort_langd = intdiv($totalt, $antal_block) - $ecc;
    $block = [];
    $felr = [];
    $pos = 0;
    for ($i = 0; $i < $antal_block; $i++) {
        $langd = $kort_langd + ($i < $korta ? 0 : 1);
        $block[$i] = array_slice($kodord, $pos, $langd);
        $felr[$i] = prj_qr_rs($block[$i], $ecc);
        $pos += $langd;
    }
    $ut = [];
    for ($i = 0; $i <= $kort_langd; $i++) {
        foreach ($block as $b) {
            if ($i < count($b)) {
                $ut[] = $b[$i];
            }
        }
    }
    for ($i = 0; $i < $ecc; $i++) {
        foreach ($felr as $f) {
            $ut[] = $f[$i];
        }
    }

    // 4. Funktionsmönster
    $storlek = 17 + 4 * $version;
    $m = array_fill(0, $storlek, array_fill(0, $storlek, false));
    $funk = array_fill(0, $storlek, array_fill(0, $storlek, false));
    $satt = static function (int $x, int $y, bool $mork) use (&$m, &$funk): void {
        $m[$y][$x] = $mork;
        $funk[$y][$x] = true;
    };
    for ($i = 0; $i < $storlek; $i++) {
        $satt(6, $i, $i % 2 === 0);
        $satt($i, 6, $i % 2 === 0);
    }
    foreach ([[3, 3], [$storlek - 4, 3], [3, $storlek - 4]] as [$cx, $cy]) {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $cx + $dx;
                $y = $cy + $dy;
                if ($x >= 0 && $x < $storlek && $y >= 0 && $y < $storlek) {
                    $d = max(abs($dx), abs($dy));
                    $satt($x, $y, $d !== 2 && $d !== 4);
                }
            }
        }
    }
    $jp = PRJ_QR_JUSTERING[$version];
    $sista = count($jp) - 1;
    foreach ($jp as $i => $ax) {
        foreach ($jp as $j => $ay) {
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $sista) || ($i === $sista && $j === 0)) {
                continue;
            }
            for ($dy = -2; $dy <= 2; $dy++) {
                for ($dx = -2; $dx <= 2; $dx++) {
                    $satt($ax + $dx, $ay + $dy, max(abs($dx), abs($dy)) !== 1);
                }
            }
        }
    }
    $formatbitar = static function (int $mask) use ($satt, $storlek): void {
        $data = (0 << 3) | $mask;   // nivå M = 00
        $rest = $data;
        for ($i = 0; $i < 10; $i++) {
            $rest = ($rest << 1) ^ (($rest >> 9) * 0x537);
        }
        $b = (($data << 10) | $rest) ^ 0x5412;
        $bit = static fn(int $i): bool => (($b >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) {
            $satt(8, $i, $bit($i));
        }
        $satt(8, 7, $bit(6));
        $satt(8, 8, $bit(7));
        $satt(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $satt(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $satt($storlek - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $satt(8, $storlek - 15 + $i, $bit($i));
        }
        $satt(8, $storlek - 8, true);
    };
    $formatbitar(0);   // reservera
    if ($version >= 7) {
        $rest = $version;
        for ($i = 0; $i < 12; $i++) {
            $rest = ($rest << 1) ^ (($rest >> 11) * 0x1F25);
        }
        $b = ($version << 12) | $rest;
        for ($i = 0; $i < 18; $i++) {
            $bit = (($b >> $i) & 1) === 1;
            $a = $storlek - 11 + $i % 3;
            $c = intdiv($i, 3);
            $satt($a, $c, $bit);
            $satt($c, $a, $bit);
        }
    }

    // 5. Data i sicksack
    $i = 0;
    $antal_bitar = count($ut) * 8;
    for ($hoger = $storlek - 1; $hoger >= 1; $hoger -= 2) {
        if ($hoger === 6) {
            $hoger = 5;
        }
        for ($v = 0; $v < $storlek; $v++) {
            for ($j = 0; $j < 2; $j++) {
                $x = $hoger - $j;
                $upp = (($hoger + 1) & 2) === 0;
                $y = $upp ? $storlek - 1 - $v : $v;
                if (!$funk[$y][$x] && $i < $antal_bitar) {
                    $m[$y][$x] = (($ut[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                    $i++;
                }
            }
        }
    }

    // 6. Mask med lägst straffpoäng
    $basta = null;
    $basta_poang = PHP_INT_MAX;
    $grund = $m;
    for ($mask = 0; $mask < 8; $mask++) {
        $m = $grund;
        for ($y = 0; $y < $storlek; $y++) {
            for ($x = 0; $x < $storlek; $x++) {
                if ($funk[$y][$x]) {
                    continue;
                }
                $vand = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    7 => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
                };
                if ($vand) {
                    $m[$y][$x] = !$m[$y][$x];
                }
            }
        }
        $formatbitar($mask);
        $poang = prj_qr_straff($m);
        if ($poang < $basta_poang) {
            $basta_poang = $poang;
            $basta = $m;
        }
    }
    return $basta;
}

/** Straffpoäng för en maskad matris (regel 1–4 i standarden). */
function prj_qr_straff(array $m): int
{
    $n = count($m);
    $p = 0;
    $morka = 0;
    $rader = [];
    for ($y = 0; $y < $n; $y++) {
        $rad = '';
        $kol = '';
        for ($x = 0; $x < $n; $x++) {
            $rad .= $m[$y][$x] ? '1' : '0';
            $kol .= $m[$x][$y] ? '1' : '0';
            $morka += $m[$y][$x] ? 1 : 0;
        }
        $rader[] = $rad;
        $rader[] = $kol;
    }
    foreach ($rader as $r) {
        // Regel 1: fem eller fler i rad av samma färg
        if (preg_match_all('/0{5,}|1{5,}/', $r, $t)) {
            foreach ($t[0] as $s) {
                $p += 3 + strlen($s) - 5;
            }
        }
        // Regel 3: sökarliknande mönster
        $p += 40 * (substr_count('0000' . $r, '00001011101') + substr_count($r . '0000', '10111010000'));
    }
    // Regel 2: 2×2-block
    for ($y = 0; $y < $n - 1; $y++) {
        for ($x = 0; $x < $n - 1; $x++) {
            $c = $m[$y][$x];
            if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                $p += 3;
            }
        }
    }
    // Regel 4: balans mellan mörkt och ljust
    $p += 10 * intdiv(abs($morka * 20 - $n * $n * 10), $n * $n);
    return $p;
}
