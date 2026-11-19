<?php
/**
 * CentralKollen – anvandare.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Lista över användare. Endast administratörer.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/cen.php';

cen_krav_admin();

kc_sidhuvud('Användare');
?>
<p><a class="kc-knapp kc-knapp-primar" href="<?= kc_h(kc_url('anvandare_redigera.php')) ?>">+ Ny användare</a></p>

<?= kc_lista([
    'tabell'            => kc_tabell('md_anvandare'),
    'kolumner'          => [
        'anvandarnamn' => 'Användarnamn',
        'fornamn'      => 'Förnamn',
        'efternamn'    => 'Efternamn',
        'epost'        => 'E-post',
        'telefon'      => 'Telefon',
        'is_active'    => 'Status',
    ],
    'extra_kolumner'    => ['uuid'],
    'villkor'           => 'is_deleted = 0',
    'standardsortering' => 'anvandarnamn',
    'format'            => [
        'epost'     => fn($v) => $v === '' ? '<em class="kc-dampad">saknas</em>' : kc_h($v),
        'is_active' => fn($v) => (int)$v === 1 ? 'Aktiv' : '<strong>Spärrad</strong>',
    ],
    'radlank'           => fn($rad) => kc_url('anvandare_redigera.php?uuid=' . rawurlencode($rad['uuid'])),
    'tom_text'          => 'Inga användare hittades.',
]) ?>
<?php kc_sidfot();
