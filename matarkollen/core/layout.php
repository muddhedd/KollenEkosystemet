<?php
/**
 * Kollen kärnpaket – layout.php
 * Variant: GEMENSAM
 * Sidhuvud och sidfot. Menyn läses från verktygets meny.php om den finns:
 *   return [
 *     ['etikett' => 'Start',      'fil' => 'index.php'],
 *     ['etikett' => 'Användare',  'fil' => 'anvandare.php', 'inloggning' => true],
 *   ];
 */
declare(strict_types=1);
if (!defined('KC_BOOT')) { http_response_code(403); exit; }

function kc_sidhuvud(string $titel, ?array $meny = null): void
{
    if ($meny === null) {
        $kc_menyfil = KC_ROT . '/meny.php';
        $meny = is_file($kc_menyfil) ? (array)require $kc_menyfil : [];
    }
    $kc_anv     = kc_inloggad();
    $kc_aktuell = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

    echo '<!doctype html><html lang="sv"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . kc_h($titel . ' – ' . KOLL_NAMN) . '</title>'
       . '<link rel="stylesheet" href="' . kc_h(kc_url('core/kollen.css') . '?v=' . KC_VERSION) . '">'
       . '</head><body>';

    echo '<header class="kc-huvud"><div class="kc-huvud-inre">';
    echo '<a class="kc-logo" href="' . kc_h(kc_url()) . '">' . kc_h(KOLL_NAMN) . '</a>';
    if (KOLL_VARIANT !== 'github') {
        echo '<span class="kc-etikett">' . kc_h(strtoupper(KOLL_VARIANT)) . '</span>';
    }

    echo '<nav class="kc-meny">';
    foreach ($meny as $kc_m) {
        if (!empty($kc_m['inloggning']) && $kc_anv === null) {
            continue;
        }
        $kc_fil = (string)($kc_m['fil'] ?? '');
        echo '<a href="' . kc_h(kc_url($kc_fil)) . '"' . ($kc_fil === $kc_aktuell ? ' class="aktiv"' : '') . '>'
           . kc_h((string)($kc_m['etikett'] ?? $kc_fil)) . '</a>';
    }
    echo '</nav>';

    echo '<div class="kc-anvandare">';
    if ($kc_anv !== null) {
        echo '<span>' . kc_h($kc_anv['namn'] !== '' ? $kc_anv['namn'] : $kc_anv['anvandarnamn']) . '</span>'
           . '<form method="post" class="kc-inline">' . kc_csrf_falt()
           . '<input type="hidden" name="kc_atgard" value="logga_ut">'
           . '<button type="submit" class="kc-lank-knapp">Logga ut</button></form>';
    } else {
        echo '<a href="' . kc_h(kc_url('login.php')) . '">Logga in</a>';
    }
    echo '</div></div></header>';

    echo '<main class="kc-innehall"><h1>' . kc_h($titel) . '</h1>';
    foreach (kc_flash_hamta() as $kc_f) {
        $kc_typ = in_array($kc_f['typ'] ?? '', ['ok', 'fel', 'info'], true) ? $kc_f['typ'] : 'info';
        echo '<div class="kc-flash kc-flash-' . $kc_typ . '">' . kc_h($kc_f['text'] ?? '') . '</div>';
    }
}

function kc_sidfot(): void
{
    echo '</main><footer class="kc-fot">'
       . kc_h(KOLL_NAMN) . ' · Kollen-kärna v' . kc_h(KC_VERSION)
       . ' · tider visas i ' . kc_h(KOLL_TIDSZON)
       . '</footer></body></html>';
}
