<?php
/**
 * ProjektKollen – avslut.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Avsluta eller återöppna ett projekt.
 *  - Kontrollista (varnar, stoppar inte): mätpunkter utan mätare, kopplade men ej monterade, monterade
 *    som inte exporterats med nycklar, antal nycklar som rensas respektive behålls.
 *  - Avsluta (projektnumret skrivs in + kryssruta): status Avslutat; nycklarna rensas för projektets
 *    mätare som exporterats med en mall med nycklar (värdet töms, raden markeras raderad – kan inte
 *    ångras). Övriga mätare behåller sina nycklar. Projektet blir skrivskyddat.
 *  - Återöppna (kryssruta): status Pågående. Rensade nycklar kommer inte tillbaka – de läses in igen
 *    med mjuk import av inköpta mätare.
 *  - Ändringarna skickas till CentralKollen med knappen Skicka (inte automatiskt).
 * Läsning öppen för alla; åtgärderna kräver inloggning. Parameter: projekt.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/prj.php';

$inloggad = prj_inloggad() !== null;
$p = prj_projekt_hamta(kc_get('projekt'));
if ($p === null) {
    throw new KcFel('Projektet finns inte.', 404);
}
$sida = 'avslut.php?projekt=' . rawurlencode($p['uuid']);

if (kc_ar_post()) {
    prj_krav_inloggning();
    $atgard = kc_post('atgard');
    if ($atgard === 'avsluta') {
        if (kc_post('bekrafta_nummer') !== $p['projektnummer'] || kc_post('bekrafta') !== '1') {
            kc_flash('fel', 'Skriv in projektnumret ' . $p['projektnummer'] . ' och bocka i rutan för att avsluta.');
            kc_omdirigera($sida);
        }
        try {
            $n = kc_transaktion(fn() => prj_avsluta($p));
            kc_flash('ok', 'Projektet ' . $p['projektnummer'] . ' är avslutat. ' . ($n > 0 ? "Rensade nycklar: $n." : 'Inga nycklar behövde rensas.')
                . ' Ändringen är inte skickad till CentralKollen än – tryck "Skicka till CentralKollen".');
        } catch (KcFel $e) {
            kc_flash('fel', $e->getMessage());
        }
        kc_omdirigera($sida . '&klar=1');
    }
    if ($atgard === 'ateroppna') {
        if (kc_post('bekrafta') !== '1') {
            kc_flash('fel', 'Bocka i rutan för att återöppna projektet.');
            kc_omdirigera($sida);
        }
        try {
            kc_transaktion(fn() => prj_ateroppna($p));
            kc_flash('ok', 'Projektet ' . $p['projektnummer'] . ' är återöppnat med status Pågående. Rensade nycklar finns inte kvar – '
                . 'läs in dem igen med mjuk import under Import → Inköpta mätare. Ändringen är inte skickad till CentralKollen än.');
        } catch (KcFel $e) {
            kc_flash('fel', $e->getMessage());
        }
        kc_omdirigera($sida . '&klar=1');
    }
    kc_omdirigera($sida);
}

$k = prj_avslut_kontroll($p['uuid']);
$avslutat = prj_projekt_last($p);

kc_sidhuvud(($avslutat ? 'Återöppna: ' : 'Avsluta: ') . $p['projektnummer'] . ' ' . $p['namn']);
?>
<p><a href="<?= kc_h(kc_url('projekt.php?uuid=' . rawurlencode($p['uuid']))) ?>">« Till projektet</a></p>

<?php if (kc_get('klar') === '1' && $inloggad): ?>
<div class="kc-ruta" style="max-width:44rem">
  <h2>Skicka till CentralKollen</h2>
  <p>Statusen och övriga ändringar finns bara i ProjektKollen tills du skickar dem. Inget skickas automatiskt.</p>
  <form method="post" action="<?= kc_h(kc_url('synk.php')) ?>">
    <?= kc_csrf_falt() ?>
    <input type="hidden" name="atgard" value="push">
    <button type="submit" class="kc-knapp-primar">Skicka till CentralKollen</button>
  </form>
</div>
<?php endif; ?>

<div class="kc-ruta" style="max-width:44rem">
  <h2>Kontrollista</h2>
  <div class="kc-tabell-wrap"><table class="kc-tabell"><tbody>
    <tr><th>Mätpunkter</th><td><?= kc_h($k['matpunkter']) ?></td></tr>
    <tr><th>Utan mätare</th><td><?= $k['utan_matare'] > 0 ? '<strong>⚠ ' . kc_h($k['utan_matare']) . '</strong>' : '0' ?>
      · <a href="<?= kc_h(kc_url('provisionering.php?projekt=' . rawurlencode($p['uuid']) . '&status=ej_kopplad')) ?>">visa</a></td></tr>
    <tr><th>Kopplade men inte monterade</th><td><?= $k['ej_monterade'] > 0 ? '<strong>⚠ ' . kc_h($k['ej_monterade']) . '</strong>' : '0' ?>
      · <a href="<?= kc_h(kc_url('montering.php?projekt=' . rawurlencode($p['uuid']) . '&status=ej_monterad')) ?>">visa</a></td></tr>
    <tr><th>Monterade men inte exporterade med nycklar</th><td><?= $k['ej_exporterade'] > 0 ? '<strong>⚠ ' . kc_h($k['ej_exporterade']) . '</strong>' : '0' ?>
      · <a href="<?= kc_h(kc_url('export.php?projekt=' . rawurlencode($p['uuid']))) ?>">export</a></td></tr>
    <tr><th>Nycklar som rensas</th><td><?= kc_h($k['nycklar_rensas']) ?> <span class="kc-dampad">(mätare som exporterats med nycklar)</span></td></tr>
    <tr><th>Nycklar som behålls</th><td><?= kc_h($k['nycklar_behalls']) ?> <span class="kc-dampad">(mätare som inte exporterats med nycklar)</span></td></tr>
  </tbody></table></div>
  <p class="kc-hjalp">Kontrollistan varnar men stoppar inte. Nycklar rensas bara för mätare som har exporterats med en mall
     som innehåller nycklar (t.ex. MätarKollen-mallen); övriga behåller sina nycklar tills de exporterats och projektet avslutas igen.</p>
</div>

<?php if (!$inloggad): ?>
  <p><em class="kc-dampad">Logga in för att avsluta eller återöppna projektet.</em></p>
<?php elseif (!$avslutat): ?>
<div class="kc-ruta" style="max-width:44rem">
  <h2>Avsluta projektet</h2>
  <p>När projektet avslutas:</p>
  <ul>
    <li>blir statusen <strong>Avslutat</strong> och projektet skrivskyddat (inga kopplingar, monteringar eller exporter med nycklar);</li>
    <li>rensas <strong><?= kc_h($k['nycklar_rensas']) ?></strong> nycklar – värdena tas bort helt och <strong>kan inte återställas</strong>;</li>
    <li>ligger mätarna kvar som provisionerade och kopplade till projektet (historik);</li>
    <li>skickas ingenting till CentralKollen förrän du trycker Skicka.</li>
  </ul>
  <form method="post">
    <?= kc_csrf_falt() ?>
    <input type="hidden" name="atgard" value="avsluta">
    <div class="kc-falt"><label for="bekrafta_nummer">Skriv in projektnumret (<?= kc_h($p['projektnummer']) ?>) för att bekräfta</label>
      <input type="text" id="bekrafta_nummer" name="bekrafta_nummer" autocomplete="off" style="width:12rem"></div>
    <div class="kc-falt"><label><input type="checkbox" name="bekrafta" value="1"> Ja, avsluta projektet och rensa nycklarna</label></div>
    <button type="submit" class="kc-knapp-fara">Avsluta projektet</button>
  </form>
</div>
<?php else: ?>
<div class="kc-ruta" style="max-width:44rem">
  <h2>Återöppna projektet</h2>
  <p>Projektet får status <strong>Pågående</strong> och kan ändras igen. Nycklar som rensades vid avslutet
     <strong>kommer inte tillbaka</strong>: läs in dem igen med <strong>mjuk import</strong> under
     <a href="<?= kc_h(kc_url('import.php?tabell=matare')) ?>">Import → Inköpta mätare</a> (befintliga uppgifter skrivs inte över).</p>
  <form method="post">
    <?= kc_csrf_falt() ?>
    <input type="hidden" name="atgard" value="ateroppna">
    <div class="kc-falt"><label><input type="checkbox" name="bekrafta" value="1"> Ja, återöppna projektet</label></div>
    <button type="submit">Återöppna projektet</button>
  </form>
</div>
<?php endif; ?>
<?php kc_sidfot();
