<?php
/**
 * ProjektKollen – matare.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * En mätare i lagret: uppgifter, var den används (projekt och mätpunkt, sätts vid provisionering),
 * varning om mätarId redan finns i MätarKollen, nycklar (bara för inloggade, maskerade med
 * kopieraknapp) och radering av felregistrerad mätare som inte är provisionerad.
 * Läsning öppen för alla; radering kräver inloggning och uttrycklig bekräftelse.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/prj.php';

$inloggad = prj_inloggad() !== null;
$uuid = kc_get('uuid');
$m = prj_matare_hamta($uuid);
if ($m === null) {
    throw new KcFel('Mätaren finns inte.', 404);
}
$provisionerad = $m['matpunkt_uuid'] !== '';

if (kc_ar_post()) {
    prj_krav_inloggning();
    if (kc_post('atgard') === 'radera') {
        if ($provisionerad) {
            kc_flash('fel', 'Mätaren är provisionerad och kan inte raderas.');
        } elseif (kc_post('bekrafta') !== '1' || prj_matar_id_normalisera(kc_post('matar_id')) !== $m['matar_id']) {
            kc_flash('fel', 'Skriv in mätarId exakt och bocka i rutan för att radera.');
        } else {
            kc_transaktion(fn() => prj_matare_radera($m));
            kc_flash('ok', 'Mätaren ' . $m['matar_id'] . ' och dess nycklar är raderade.');
            kc_omdirigera('matarlista.php');
        }
    }
    kc_omdirigera('matare.php?uuid=' . rawurlencode($uuid));
}

$projekt = $m['projekt_uuid'] !== '' ? prj_projekt_hamta($m['projekt_uuid']) : null;
$matpunkt = $provisionerad
    ? kc_hamta_en('SELECT * FROM `' . kc_tabell('md_matpunkt') . '` WHERE uuid = ? AND is_deleted = 0', [$m['matpunkt_uuid']])
    : null;
$i_mat = prj_matar_id_i_mat($m['matar_id']);
$nycklar = $inloggad ? prj_nycklar($uuid) : [];

kc_sidhuvud('Mätare ' . $m['matar_id']);
?>
<p><a href="<?= kc_h(kc_url('matarlista.php')) ?>">« Alla mätare</a></p>

<?php if ($i_mat !== []): ?>
  <div class="kc-flash kc-flash-info"><strong>Obs:</strong> samma mätarId finns redan i MätarKollen
    (<?= kc_h(implode(', ', $i_mat)) ?>). Kontrollera att det inte är en dubblett.</div>
<?php endif; ?>

<div class="kc-ruta" style="max-width:52rem">
  <h2>Uppgifter</h2>
  <div class="kc-tabell-wrap"><table class="kc-tabell"><tbody>
    <tr><th>MätarId</th><td><strong><?= kc_h($m['matar_id']) ?></strong></td></tr>
    <tr><th>Fabrikat</th><td><?= kc_h($m['fabrikat']) ?></td></tr>
    <tr><th>Modell</th><td><?= kc_h($m['modell']) ?></td></tr>
    <tr><th>Mätartyp</th><td><?= kc_h($m['matartyp']) ?></td></tr>
    <tr><th>Kommunikation</th><td><?= kc_h($m['kommunikation']) ?></td></tr>
    <tr><th>Storheter</th><td><?= kc_h($m['storheter']) ?></td></tr>
    <tr><th>Gateway</th><td><?= kc_h($m['gateway']) ?></td></tr>
    <tr><th>Inköp</th><td><?= kc_h($m['inkop']) ?></td></tr>
    <tr><th>Kommentar</th><td><?= kc_h($m['kommentar']) ?></td></tr>
    <tr><th>Status</th><td><?= $provisionerad ? '<strong>provisionerad</strong>' : '<span class="kc-dampad">ledig (i lager)</span>' ?></td></tr>
    <?php if ($projekt !== null): ?>
      <tr><th>Projekt</th><td><a href="<?= kc_h(kc_url('projekt.php?uuid=' . rawurlencode($projekt['uuid']))) ?>"><?= kc_h($projekt['projektnummer'] . ' ' . $projekt['namn']) ?></a></td></tr>
    <?php endif; ?>
    <?php if ($matpunkt !== null): ?>
      <tr><th>Mätpunkt</th><td><?= kc_h($matpunkt['matpunktsnamn']) ?></td></tr>
    <?php endif; ?>
    <tr><th>Senast ändrad</th><td><?= kc_h(kc_tid($m['last_changed'])) ?></td></tr>
  </tbody></table></div>
  <p class="kc-hjalp">Uppgifterna ändras via <a href="<?= kc_h(kc_url('import.php?tabell=matare')) ?>">importen</a>
     (hård import skriver över, mjuk kompletterar).</p>
</div>

<?php if ($inloggad): ?>
<div class="kc-ruta" style="max-width:52rem">
  <h2>Nycklar</h2>
  <?php if ($nycklar === []): ?>
    <p><em class="kc-dampad">Inga nycklar registrerade.</em></p>
  <?php else: ?>
    <div class="kc-tabell-wrap"><table class="kc-tabell"><tbody>
    <?php foreach ($nycklar as $typ => $n): ?>
      <tr><th><?= kc_h($typ) ?></th>
          <td><code class="kc-nyckel"><?= kc_h(prj_maskera($n['varde'])) ?></code></td>
          <td><button type="button" class="kc-lank-knapp" data-prj-kopiera="<?= kc_h($n['varde']) ?>">Kopiera</button></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <p class="kc-hjalp">Nycklarna visas bara för inloggade och ingår aldrig i listor, synk eller API.
       De lämnar ProjektKollen bara via exporterna och rensas när projektet avslutas.</p>
    <script>
      document.querySelectorAll('[data-prj-kopiera]').forEach(function (b) {
        b.addEventListener('click', function () {
          navigator.clipboard.writeText(b.getAttribute('data-prj-kopiera')).then(function () {
            b.textContent = 'Kopierad!';
            setTimeout(function () { b.textContent = 'Kopiera'; }, 1500);
          });
        });
      });
    </script>
  <?php endif; ?>
</div>

<?php if (!$provisionerad): ?>
<div class="kc-ruta" style="max-width:52rem">
  <h2>Radera felregistrerad mätare</h2>
  <p>Bara för mätare som registrerats av misstag. Mätaren och dess nycklar raderas.</p>
  <form method="post">
    <?= kc_csrf_falt() ?>
    <input type="hidden" name="atgard" value="radera">
    <div class="kc-falt">
      <label for="matar_id">Skriv in mätarId för att bekräfta</label>
      <input type="text" id="matar_id" name="matar_id" autocomplete="off" style="width:20rem">
    </div>
    <div class="kc-falt">
      <label><input type="checkbox" name="bekrafta" value="1"> Ja, radera mätaren och dess nycklar</label>
    </div>
    <button type="submit" class="kc-knapp-fara">Radera</button>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>
<?php kc_sidfot();
