<?php
/**
 * ProjektKollen – matpunktslista.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Projektets planerade mätpunkter enligt namnstandarden <objektnummer>_<funktion>_<X><Y>.
 * Listan genereras från objektlistan; namnen räknas om så länge de inte är låsta. Namnen låses
 * när etiketterna skrivs ut eller mätpunkterna exporteras – eller med knappen "Lås namnen".
 * Läsning öppen för alla; låsning kräver inloggning. Parameter: projekt.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/prj.php';

$inloggad = prj_inloggad() !== null;
$p = prj_projekt_hamta(kc_get('projekt'));
if ($p === null) {
    throw new KcFel('Projektet finns inte.', 404);
}
$kan_andra = $inloggad && !prj_projekt_last($p);
$sida = 'matpunktslista.php?projekt=' . rawurlencode($p['uuid']);

if (kc_ar_post()) {
    prj_krav_inloggning();
    if (!$kan_andra) {
        kc_flash('fel', 'Projektet är avslutat och kan inte ändras.');
        kc_omdirigera($sida);
    }
    if (kc_post('atgard') === 'generera') {
        $sum = kc_transaktion(fn() => prj_objektlista_uppdatera($p));
        kc_flash('ok', "Uppdaterad från fastighetsdatan. Objekt: {$sum['obj_nya']} nya, {$sum['obj_borttagna']} borttagna. "
            . "Mätpunkter: {$sum['skapade']} nya, {$sum['andrade']} ändrade, {$sum['borttagna']} borttagna.");
    } elseif (kc_post('atgard') === 'las') {
        if (kc_post('bekrafta') !== '1') {
            kc_flash('fel', 'Bocka i rutan för att låsa namnen.');
        } else {
            $n = kc_transaktion(fn() => prj_matpunkter_las($p['uuid']));
            kc_flash('ok', "Låste $n mätpunktsnamn.");
        }
    }
    kc_omdirigera($sida);
}

$tm = kc_tabell('md_matpunkt');
$to = kc_tabell('ld_fas_objekt');
$tb = kc_tabell('ld_fas_byggnad');
$ta = kc_tabell('ld_fas_adress');
$sum = prj_projekt_sammanfattning($p['uuid']);

kc_sidhuvud('Mätpunktslista: ' . $p['projektnummer'] . ' ' . $p['namn']);
?>
<p><a href="<?= kc_h(kc_url('projekt.php?uuid=' . rawurlencode($p['uuid']))) ?>">« Till projektet</a> ·
   <a href="<?= kc_h(kc_url('objektlista.php?projekt=' . rawurlencode($p['uuid']))) ?>">Objektlista »</a> ·
   <a href="<?= kc_h(kc_url('etiketter.php?projekt=' . rawurlencode($p['uuid']))) ?>">Etiketter »</a></p>

<p><?= kc_h($sum['matpunkter']) ?> mätpunkter, varav <?= kc_h($sum['lasta']) ?> med låst namn.
   <?= $sum['med_info'] > 0 ? kc_h($sum['med_info']) . ' har en infotext (befintlig mätpunkt i MätarKollen eller namnkrock).' : '' ?></p>

<?= kc_lista([
    'kalla' => "SELECT m.id, m.uuid, m.projekt_uuid, m.matpunktsnamn, m.objektnummer, m.funktion, m.lopnummer, m.antal_i_objekt,
                       m.namn_last, m.storheter, m.info, m.etikett_tid, COALESCE(b.byggnadsnummer, '') AS byggnad, COALESCE(a.gatuadress, '') AS adress
                  FROM `$tm` m
                  LEFT JOIN `$to` o ON o.uuid = m.objekt_uuid
                  LEFT JOIN `$tb` b ON b.uuid = o.byggnad_uuid
                  LEFT JOIN `$ta` a ON a.uuid = o.adress_uuid
                 WHERE m.is_deleted = 0",
    'villkor'           => 'projekt_uuid = ?',
    'villkor_params'    => [$p['uuid']],
    'kolumner'          => [
        'matpunktsnamn' => 'Mätpunkt',
        'objektnummer'  => 'Objekt',
        'byggnad'       => 'Byggnad',
        'adress'        => 'Adress',
        'storheter'     => 'Storheter',
        'namn_last'     => 'Namn',
        'etikett_tid'   => 'Etikett',
        'info'          => 'Info',
    ],
    'extra_kolumner'    => ['uuid', 'funktion', 'lopnummer', 'antal_i_objekt'],
    'standardsortering' => 'matpunktsnamn',
    'format'            => [
        'namn_last'   => fn($v) => (int)$v === 1 ? '<strong>låst</strong>' : '<span class="kc-dampad">preliminärt</span>',
        'etikett_tid' => fn($v) => $v !== null && $v !== '' ? kc_h(kc_tid($v)) : '',
    ],
    'export_namn'       => 'prj_matpunkt_' . $p['projektnummer'],
    'tom_text'          => 'Inga mätpunkter. Lägg till omfattning och sätt antal i objektlistan.',
]) ?>

<?php if ($kan_andra): ?>
<div class="kc-ruta" style="max-width:52rem">
  <h2>Uppdatera</h2>
  <p>Räkna om objektlistan och mätpunkterna från fastighetsdatan, t.ex. efter att nya objekt hämtats
     eller en mätpunkt lagts till i MätarKollen.</p>
  <form method="post">
    <?= kc_csrf_falt() ?>
    <input type="hidden" name="atgard" value="generera">
    <button type="submit">Uppdatera från fastighetsdatan</button>
  </form>
</div>
<?php if ($sum['matpunkter'] > $sum['lasta']): ?>
<div class="kc-ruta" style="max-width:52rem">
  <h2>Lås namnen</h2>
  <p>Preliminära namn räknas om när antalet ändras (t.ex. blir <code>…_Temp_11</code> <code>…_Temp_12</code> när en andra givare läggs till).
     Namnen låses automatiskt när etiketterna skrivs ut eller mätpunkterna exporteras. Lås dem här om de redan används någon annanstans.
     Låsta namn kan inte låsas upp.</p>
  <form method="post">
    <?= kc_csrf_falt() ?>
    <input type="hidden" name="atgard" value="las">
    <label><input type="checkbox" name="bekrafta" value="1"> Lås alla <?= kc_h($sum['matpunkter'] - $sum['lasta']) ?> preliminära namn</label>
    <button type="submit" class="kc-knapp-fara">Lås namnen</button>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>
<?php kc_sidfot();
