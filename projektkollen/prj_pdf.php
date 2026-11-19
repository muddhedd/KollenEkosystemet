<?php
/**
 * ProjektKollen – prj_pdf.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Minimal PDF-skrivare (PDF 1.4) utan externa bibliotek: A4-sidor, text i Courier/Courier-Bold
 * (inbyggda typsnitt, WinAnsi), fyllda och ritade rektanglar samt QR-koder.
 * Koordinater i millimeter från sidans övre vänstra hörn. Sidinnehållet komprimeras (FlateDecode).
 * Kandidat att flyttas till kärnpaketet när fler verktyg behöver PDF.
 *
 * Användning:
 *   $pdf = prj_pdf_ny();
 *   prj_pdf_sida($pdf);
 *   prj_pdf_text($pdf, 10, 20, 9, 'Hej', true);
 *   prj_pdf_qr($pdf, 10, 30, 24, prj_qr_matris('...'));
 *   $data = prj_pdf_ut($pdf, 'Titel');
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

const PRJ_PDF_PT = 72 / 25.4;          // punkter per millimeter
const PRJ_PDF_A4 = [210.0, 297.0];     // bredd, höjd i mm
const PRJ_PDF_COURIER_BREDD = 0.6;     // teckenbredd i Courier, andel av teckenstorleken

function prj_pdf_ny(): array
{
    return ['sidor' => [], 'aktuell' => -1];
}

function prj_pdf_sida(array &$pdf): void
{
    $pdf['sidor'][] = '';
    $pdf['aktuell'] = count($pdf['sidor']) - 1;
}

/** Talformat för PDF (punkt som decimaltecken, högst tre decimaler). */
function prj_pdf_tal(float $v): string
{
    $s = rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
    return $s === '-0' || $s === '' ? '0' : $s;
}

function prj_pdf_skriv(array &$pdf, string $op): void
{
    if ($pdf['aktuell'] < 0) {
        prj_pdf_sida($pdf);
    }
    $pdf['sidor'][$pdf['aktuell']] .= $op . "\n";
}

/** Bredd i mm för en text i Courier med given storlek (pt). */
function prj_pdf_textbredd(string $text, float $storlek): float
{
    return mb_strlen($text, 'UTF-8') * PRJ_PDF_COURIER_BREDD * $storlek / PRJ_PDF_PT;
}

/** Text med baslinjen i y (mm). Tecken utanför Windows-1252 blir '?'. */
function prj_pdf_text(array &$pdf, float $x, float $y, float $storlek, string $text, bool $fet = false): void
{
    $kodad = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    $kodad = strtr($kodad, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    prj_pdf_skriv($pdf, sprintf('BT /%s %s Tf %s %s Td (%s) Tj ET',
        $fet ? 'F2' : 'F1', prj_pdf_tal($storlek),
        prj_pdf_tal($x * PRJ_PDF_PT), prj_pdf_tal((PRJ_PDF_A4[1] - $y) * PRJ_PDF_PT), $kodad));
}

/** Rektangel med övre vänstra hörnet i (x, y). Fylld (svart) eller ritad med tunn linje. */
function prj_pdf_rekt(array &$pdf, float $x, float $y, float $b, float $h, bool $fylld = true): void
{
    $op = sprintf('%s %s %s %s re',
        prj_pdf_tal($x * PRJ_PDF_PT), prj_pdf_tal((PRJ_PDF_A4[1] - $y - $h) * PRJ_PDF_PT),
        prj_pdf_tal($b * PRJ_PDF_PT), prj_pdf_tal($h * PRJ_PDF_PT));
    prj_pdf_skriv($pdf, $fylld ? $op . ' f' : '0.2 w ' . $op . ' S');
}

/**
 * QR-kod i en kvadrat med sidan $sida mm, inklusive tyst zon på $zon moduler.
 * Mörka moduler slås ihop radvis till rektanglar (mindre fil, inga glipor).
 */
function prj_pdf_qr(array &$pdf, float $x, float $y, float $sida, array $matris, int $zon = 2): void
{
    $n = count($matris);
    $modul = $sida / ($n + 2 * $zon);
    $ops = [];
    foreach ($matris as $r => $rad) {
        $c = 0;
        while ($c < $n) {
            if (!$rad[$c]) {
                $c++;
                continue;
            }
            $start = $c;
            while ($c < $n && $rad[$c]) {
                $c++;
            }
            $rx = $x + ($zon + $start) * $modul;
            $ry = $y + ($zon + $r) * $modul;
            $ops[] = sprintf('%s %s %s %s re',
                prj_pdf_tal($rx * PRJ_PDF_PT), prj_pdf_tal((PRJ_PDF_A4[1] - $ry - $modul) * PRJ_PDF_PT),
                prj_pdf_tal(($c - $start) * $modul * PRJ_PDF_PT), prj_pdf_tal($modul * PRJ_PDF_PT));
        }
    }
    if ($ops !== []) {
        prj_pdf_skriv($pdf, implode("\n", $ops) . "\nf");
    }
}

/** Bygger hela PDF-filen och returnerar den som sträng. */
function prj_pdf_ut(array $pdf, string $titel = ''): string
{
    if ($pdf['sidor'] === []) {
        prj_pdf_sida($pdf);
    }
    $obj = [];   // objektnummer => innehåll (1-baserat)
    $obj[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $obj[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>';
    $obj[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>';
    $tit = strtr(mb_convert_encoding($titel, 'Windows-1252', 'UTF-8'), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    $obj[5] = '<< /Title (' . $tit . ') /Producer (ProjektKollen) /CreationDate (D:' . gmdate('YmdHis') . "Z) >>";
    $kids = [];
    $nr = 6;
    $bredd = prj_pdf_tal(PRJ_PDF_A4[0] * PRJ_PDF_PT);
    $hojd = prj_pdf_tal(PRJ_PDF_A4[1] * PRJ_PDF_PT);
    foreach ($pdf['sidor'] as $innehall) {
        $sidnr = $nr++;
        $strnr = $nr++;
        $kids[] = "$sidnr 0 R";
        $obj[$sidnr] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $bredd $hojd] "
            . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $strnr 0 R >>";
        $z = gzcompress($innehall, 6);
        $obj[$strnr] = '<< /Length ' . strlen($z) . " /Filter /FlateDecode >>\nstream\n" . $z . "\nendstream";
    }
    $obj[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($obj);

    $ut = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $pos = [];
    foreach ($obj as $i => $o) {
        $pos[$i] = strlen($ut);
        $ut .= "$i 0 obj\n$o\nendobj\n";
    }
    $xref = strlen($ut);
    $antal = count($obj) + 1;
    $ut .= "xref\n0 $antal\n0000000000 65535 f \n";
    for ($i = 1; $i < $antal; $i++) {
        $ut .= sprintf("%010d 00000 n \n", $pos[$i]);
    }
    $ut .= "trailer\n<< /Size $antal /Root 1 0 R /Info 5 0 R >>\nstartxref\n$xref\n%%EOF\n";
    return $ut;
}
