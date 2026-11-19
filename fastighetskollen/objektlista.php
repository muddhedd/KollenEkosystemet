<?php
/**
 * FastighetsKollen – objektlista.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Lista över alla objekt. Läsning öppen för alla.
 * Valfri parameter: byggnad=<uuid> visar bara den byggnadens objekt.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/fas.php';

$to = kc_tabell('md_objekt');
$tb = kc_tabell('md_byggnad');
$tf = kc_tabell('md_fastighet');
$ta = kc_tabell('md_adress');
$ot = kc_tabell('md_objekttyp');
$kt = kc_tabell('md_koktyp');

$byggnad = fas_byggnad_hamta(kc_get('byggnad'));

kc_sidhuvud($byggnad !== null ? 'Objekt i ' . trim($byggnad['byggnadsnummer'] . ' ' . $byggnad['namn']) : 'Objekt');
?>
<p class="kc-hjalp" style="text-align:right">Import: <a href="<?= kc_h(kc_url('import.php?tabell=objekt')) ?>">objekt</a></p>
<?php if ($byggnad !== null): ?>
  <p><a href="<?= kc_h(kc_url('byggnad.php?uuid=' . rawurlencode($byggnad['uuid']))) ?>">« Till byggnaden</a> ·
     <a href="<?= kc_h(kc_url('objektlista.php')) ?>">Visa alla objekt</a></p>
<?php else: ?>
  <p class="kc-dampad">Nya objekt läggs till från byggnadens sida.</p>
<?php endif; ?>

<?= kc_lista([
    'kalla' => "SELECT o.id, o.uuid, o.objektnummer, o.byggnad_uuid, o.kvadratmeter, o.antal_rum, o.vaningsplan,
                       t.namn AS typ, k.namn AS kok, a.gatuadress,
                       CONCAT_WS(' ', b.byggnadsnummer, NULLIF(b.namn, '')) AS byggnad,
                       f.fastighetsnummer AS fastighet
                  FROM `$to` o
                  LEFT JOIN `$tb` b ON b.uuid = o.byggnad_uuid
                  LEFT JOIN `$tf` f ON f.uuid = b.fastighet_uuid
                  LEFT JOIN `$ot` t ON t.uuid = o.objekttyp_uuid
                  LEFT JOIN `$kt` k ON k.uuid = o.koktyp_uuid
                  LEFT JOIN `$ta` a ON a.uuid = o.adress_uuid AND a.is_deleted = 0
                 WHERE o.is_deleted = 0",
    'villkor'           => $byggnad !== null ? 'byggnad_uuid = ?' : '',
    'villkor_params'    => $byggnad !== null ? [$byggnad['uuid']] : [],
    'kolumner'          => [
        'objektnummer' => 'Objektnr',
        'typ'          => 'Typ',
        'gatuadress'   => 'Adress',
        'vaningsplan'  => 'Våning',
        'antal_rum'    => 'Rum',
        'kok'          => 'Kök',
        'kvadratmeter' => 'm²',
        'byggnad'      => 'Byggnad',
        'fastighet'    => 'Fastighet',
    ],
    'extra_kolumner'    => ['uuid', 'byggnad_uuid'],
    'standardsortering' => 'objektnummer',
    'format'            => [
        'vaningsplan'  => fn($v) => kc_h(fas_vaning($v)),
        'antal_rum'    => fn($v) => kc_h(fas_tal($v)),
        'kvadratmeter' => fn($v) => kc_h(fas_tal($v)),
    ],
    'radlank'           => fn($rad) => kc_url('objekt.php?uuid=' . rawurlencode($rad['uuid'])),
    'export_namn'       => 'fas_objekt',
    'tom_text'          => 'Inga objekt registrerade.',
]) ?>
<?php kc_sidfot();
