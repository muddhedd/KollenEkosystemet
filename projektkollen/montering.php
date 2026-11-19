<?php
/**
 * ProjektKollen – montering.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Monteringskontroll på plats:
 *  Steg 1: läs objektnumret – etikettens QR-kod (mätpunktsnamnet ger objektet), iPhones "Skanna text"
 *          i textfältet eller manuell inmatning. Objektets mätpunkter visas.
 *  Steg 2: läs mätarens QR-kod. Rätt matchning (mätaren är provisionerad till en mätpunkt i objektet)
 *          sparar dagens datum och användaren med grön bock; fel ger röd text och inget sparas.
 *  Objektet ligger kvar så att flera mätare i samma objekt kan läsas i följd. Läses en annan etikett
 *  eller ett annat objektnummer byts objektet.
 * "Demontera" (med bekräftelse) tömmer monteringen så att mätaren kan kopplas loss under Provisionering.
 * Läsning öppen för alla; montering och demontering kräver inloggning. Sparning utan omladdning (JSON).
 * Parametrar: projekt, status (ej_monterad | monterad), demontera (mätpunktens uuid, visar bekräftelse).
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/prj.php';

$anvandare = prj_inloggad();
$inloggad = $anvandare !== null;
$p = prj_projekt_hamta(kc_get('projekt'));
if ($p === null) {
    throw new KcFel('Projektet finns inte.', 404);
}
$kan_andra = $inloggad && !prj_projekt_last($p);
$sida = 'montering.php?projekt=' . rawurlencode($p['uuid']);

if (kc_ar_post()) {
    $anvandare = prj_krav_inloggning();
    $atgard = kc_post('atgard');

    // Läsarens anrop (JSON): objekt eller mätare
    if ($atgard === 'las') {
        if (!$kan_andra) {
            kc_json(false, null, 'Projektet är avslutat.', 409);
        }
        $kod = trim(kc_post('kod'));
        $objekt = prj_objekt_fran_kod($p['uuid'], $kod);
        if ($objekt !== null) {
            kc_json(true, ['typ' => 'objekt', 'objektnummer' => $objekt, 'matpunkter' => prj_objekt_matpunkter($p['uuid'], $objekt)]);
        }
        $aktuellt = kc_post('objektnummer');
        if ($aktuellt === '') {
            kc_json(false, null, "\"$kod\" är inget objekt i projektet. Läs etiketten eller objektnumret först.", 404);
        }
        $r = kc_transaktion(fn() => prj_montera($p, $aktuellt, $kod, (string)($anvandare['anvandarnamn'] ?? '')));
        [$t, $k, $mo] = prj_provisionering_antal($p['uuid']);
        kc_json(true, ['typ' => 'matare', 'objektnummer' => $aktuellt, 'antal' => [$t, $k, $mo]] + $r);
    }

    // Formulär: demontera
    if ($atgard === 'demontera') {
        if (kc_post('bekrafta') !== '1') {
            kc_flash('fel', 'Bocka i rutan för att demontera.');
            kc_omdirigera($sida . '&demontera=' . rawurlencode(kc_post('matpunkt_uuid')));
        }
        try {
            kc_flash('ok', kc_transaktion(fn() => prj_demontera($p, kc_post('matpunkt_uuid'))));
        } catch (KcFel $e) {
            kc_flash('fel', $e->getMessage());
        }
        kc_omdirigera($sida);
    }
    kc_omdirigera($sida);
}

[$totalt, $kopplade, $monterade] = prj_provisionering_antal($p['uuid']);
$status = in_array(kc_get('status'), ['ej_monterad', 'monterad'], true) ? kc_get('status') : '';
$demontera = null;
if ($kan_andra && kc_get('demontera') !== '') {
    $mp_d = kc_hamta_en('SELECT * FROM `' . kc_tabell('md_matpunkt') . '` WHERE uuid = ? AND projekt_uuid = ? AND is_deleted = 0',
        [kc_get('demontera'), $p['uuid']]);
    if ($mp_d !== null) {
        $demontera = prj_matpunkt_info($mp_d) + ['monterad_datum' => $mp_d['monterad_datum']];
    }
}

$tm = kc_tabell('md_matpunkt');
$ta = kc_tabell('md_matare');

kc_sidhuvud('Montering: ' . $p['projektnummer'] . ' ' . $p['namn']);
?>
<p><a href="<?= kc_h(kc_url('projekt.php?uuid=' . rawurlencode($p['uuid']))) ?>">« Till projektet</a> ·
   <a href="<?= kc_h(kc_url('provisionering.php?projekt=' . rawurlencode($p['uuid']))) ?>">Provisionering</a></p>

<p id="prj-antal"><strong><?= kc_h($monterade) ?></strong> av <?= kc_h($kopplade) ?> kopplade mätare är monterade
   (<?= kc_h($totalt) ?> mätpunkter).</p>

<?php if ($demontera !== null): ?>
<div class="kc-ruta" style="max-width:40rem">
  <h2>Demontera mätare</h2>
  <?php if (!$demontera['monterad']): ?>
    <p><?= kc_h($demontera['namn']) ?> är inte monterad.</p>
  <?php else: ?>
    <form method="post">
      <?= kc_csrf_falt() ?>
      <input type="hidden" name="atgard" value="demontera">
      <input type="hidden" name="matpunkt_uuid" value="<?= kc_h($demontera['uuid']) ?>">
      <p>Demontera <strong><?= kc_h($demontera['matar_id']) ?></strong> från <strong><?= kc_h($demontera['namn']) ?></strong>
         (monterad <?= kc_h($demontera['monterad_datum']) ?>)? Monteringsdatumet tas bort; mätaren är fortfarande kopplad
         och kan sedan kopplas loss under Provisionering.</p>
      <div class="kc-falt"><label><input type="checkbox" name="bekrafta" value="1"> Ja, demontera</label></div>
      <button type="submit" class="kc-knapp-fara">Demontera</button>
      <a href="<?= kc_h(kc_url($sida)) ?>">Avbryt</a>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$inloggad): ?>
  <p><em class="kc-dampad">Logga in för att kontrollera monteringar.</em></p>
<?php elseif (!$kan_andra): ?>
  <p><em class="kc-dampad">Projektet är avslutat.</em></p>
<?php elseif ($kopplade === 0): ?>
  <p><em class="kc-dampad">Inga mätare är kopplade än. Provisionera först.</em></p>
<?php else: ?>
<div class="kc-ruta" style="max-width:34rem">
  <h2>Läs in</h2>
  <div class="prj-steg" id="prj-steg"></div>
  <div id="prj-resultat" class="prj-resultat info" hidden></div>
  <ul id="prj-objekt-mp"></ul>
  <?= prj_lasare_html('Objektnummer eller mätarId') ?>
  <p><button type="button" id="prj-nytt-objekt">Nytt objekt</button></p>
  <p class="kc-hjalp">Objektnummer som text: håll fingret i textfältet och välj "Skanna text" (iPhone).</p>
  <div id="prj-logg-ram" hidden>
    <h3>Monterade nu</h3>
    <ol id="prj-logg" reversed></ol>
    <p><a href="<?= kc_h(kc_url($sida)) ?>">Uppdatera listan</a></p>
  </div>
</div>
<script>
(function () {
  'use strict';
  const URL_SIDA = <?= json_encode(kc_url($sida), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const CSRF = <?= json_encode(kc_csrf_token(), JSON_HEX_TAG) ?>;
  let objekt = '';      // aktuellt objektnummer (steg 2)
  let upptagen = false;

  const $ = id => document.getElementById(id);
  function steg() {
    const s = $('prj-steg');
    s.innerHTML = '';
    const b = document.createElement('strong');
    b.textContent = objekt === '' ? 'Steg 1: läs objektnumret (etikett eller text)' : 'Steg 2: läs mätarens QR-kod';
    s.appendChild(b);
    if (objekt !== '') {
      s.appendChild(document.createElement('br'));
      s.appendChild(document.createTextNode('Objekt: ' + objekt));
    }
  }
  function visaMatpunkter(lista) {
    const ul = $('prj-objekt-mp');
    ul.innerHTML = '';
    (lista || []).forEach(function (m) {
      const li = document.createElement('li');
      li.textContent = m.namn + ' – ' + (m.matar_id === '' ? 'ingen mätare kopplad'
        : m.matar_id + (m.monterad_datum !== '' ? ' ✓ monterad ' + m.monterad_datum : ' (ej monterad)'));
      ul.appendChild(li);
    });
  }
  function resultat(typ, text, smatext) {
    const r = $('prj-resultat');
    r.className = 'prj-resultat ' + typ;
    r.textContent = text;
    (smatext || []).forEach(function (t) {
      if (!t) return;
      const s = document.createElement('small');
      s.textContent = t;
      r.appendChild(s);
    });
    r.hidden = false;
  }
  function anrop(data) {
    const fd = new FormData();
    Object.keys(data).forEach(k => fd.append(k, data[k]));
    return fetch(URL_SIDA, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF, 'Accept': 'application/json' }
    }).then(r => r.json()).catch(() => ({ ok: false, error: 'Nätverksfel – försök igen.' }));
  }

  const lasare = PrjLasare.skapa({ onKod: function (kod) {
    if (upptagen) return;
    upptagen = true;
    lasare.paus();
    anrop({ atgard: 'las', kod: kod, objektnummer: objekt }).then(function (s) {
      if (!s.ok) {
        resultat('fel', '✗ ' + s.error, objekt !== '' ? ['Inget sparades. Läs rätt mätare, eller tryck Nytt objekt.'] : []);
        return;
      }
      if (s.data.typ === 'objekt') {
        const bytt = objekt !== '' && objekt !== s.data.objektnummer;
        objekt = s.data.objektnummer;
        resultat('info', 'Objekt ' + objekt, [bytt ? 'Objektet byttes.' : '']);
        visaMatpunkter(s.data.matpunkter);
      } else {
        resultat('ok', '✓ ' + s.data.text);
        visaMatpunkter(s.data.matpunkter);
        if (s.data.ny) {
          const li = document.createElement('li');
          li.textContent = s.data.text;
          $('prj-logg').prepend(li);
          $('prj-logg-ram').hidden = false;
          const a = s.data.antal;
          $('prj-antal').textContent = a[2] + ' av ' + a[1] + ' kopplade mätare är monterade (' + a[0] + ' mätpunkter).';
        }
      }
      steg();
    }).finally(function () { upptagen = false; lasare.fortsatt(); });
  } });

  $('prj-nytt-objekt').addEventListener('click', function () {
    objekt = '';
    $('prj-resultat').hidden = true;
    visaMatpunkter([]);
    steg();
    lasare.fortsatt();
  });
  steg();
})();
</script>
<?php endif; ?>

<h2>Mätpunkter med mätare</h2>
<p>
  <?php if ($status !== ''): ?>
    <a href="<?= kc_h(kc_url($sida)) ?>">« Alla</a>
  <?php else: ?>
    Visa: <a href="<?= kc_h(kc_url($sida . '&status=ej_monterad')) ?>">ej monterade</a> ·
    <a href="<?= kc_h(kc_url($sida . '&status=monterad')) ?>">monterade</a>
  <?php endif; ?>
</p>
<?= kc_lista([
    'kalla' => "SELECT m.id, m.uuid, m.matpunktsnamn, m.objektnummer, a.matar_id, m.monterad_datum, m.monterad_av,
                       CASE WHEN m.monterad_datum IS NOT NULL THEN 'monterad' ELSE 'ej_monterad' END AS status
                  FROM `$tm` m
                  JOIN `$ta` a ON a.matpunkt_uuid = m.uuid AND a.is_deleted = 0
                 WHERE m.is_deleted = 0 AND m.projekt_uuid = ?",
    'kalla_params'      => [$p['uuid']],
    'villkor'           => $status !== '' ? 'status = ?' : '',
    'villkor_params'    => $status !== '' ? [$status] : [],
    'kolumner'          => [
        'matpunktsnamn'  => 'Mätpunkt',
        'objektnummer'   => 'Objekt',
        'matar_id'       => 'Mätare',
        'status'         => 'Status',
        'monterad_datum' => 'Monterad',
        'monterad_av'    => 'Av',
    ],
    'extra_kolumner'    => ['uuid'],
    'standardsortering' => 'matpunktsnamn',
    'format'            => [
        'status' => fn($v, $rad) => $v === 'monterad'
            ? '<strong>monterad</strong>' . ($kan_andra
                ? ' <a href="' . kc_h(kc_url($sida . '&demontera=' . rawurlencode($rad['uuid']))) . '">demontera</a>' : '')
            : '<span class="kc-dampad">ej monterad</span>',
    ],
    'export_namn'       => 'prj_montering_' . $p['projektnummer'],
    'tom_text'          => 'Inga mätare är kopplade.',
]) ?>
<?php kc_sidfot();
