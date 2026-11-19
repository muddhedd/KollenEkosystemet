<?php
/**
 * ProjektKollen – provisionering.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Provisionering: koppla mätare ur lagret till projektets mätpunkter med kameran.
 *  Steg 1: läs etikettens QR-kod (mätpunktsnamnet) – mätpunkten visas.
 *  Steg 2: läs mätarens QR-kod (LoRaWAN-identitet) – kopplingen sparas direkt (grön bock) eller
 *          stoppas med röd text. Läsaren går sedan tillbaka till steg 1.
 * Manuell inmatning, "Ta foto" och iPhones "Skanna text" fungerar som reserv.
 * Regler: en mätare per mätpunkt; mätaren måste vara ledig; namnet låses vid koppling; varning om
 * mätarens storheter inte täcker mätpunktens. "Koppla loss" (med bekräftelse) för felkopplingar;
 * en monterad mätare måste demonteras först (etapp 5b).
 * Läsning öppen för alla; koppla och koppla loss kräver inloggning. Sparning sker utan omladdning
 * (POST med X-Requested-With och CSRF-header, svar i JSON).
 * Parametrar: projekt, status (ej_kopplad | kopplad | monterad), loss (mätpunktens uuid, visar bekräftelse).
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
$sida = 'provisionering.php?projekt=' . rawurlencode($p['uuid']);

if (kc_ar_post()) {
    prj_krav_inloggning();
    $atgard = kc_post('atgard');

    // Läsarens anrop (JSON)
    if ($atgard === 'matpunkt') {
        $kod = trim(kc_post('kod'));
        $mp = prj_matpunkt_med_namn($p['uuid'], $kod);
        if ($mp === null) {
            $som_matare = prj_matare_med_id(prj_qr_till_matar_id($kod));
            kc_json(false, null, $som_matare !== null
                ? 'Det där är en mätare. Läs mätpunktens etikett först.'
                : "Mätpunkten \"$kod\" finns inte i projektet.", 404);
        }
        kc_json(true, prj_matpunkt_info($mp));
    }
    if ($atgard === 'koppla') {
        if (!$kan_andra) {
            kc_json(false, null, 'Projektet är avslutat.', 409);
        }
        $r = kc_transaktion(fn() => prj_provisionera($p, kc_post('matpunkt_uuid'), kc_post('kod')));
        kc_json(true, $r + ['antal' => prj_provisionering_antal($p['uuid'])]);
    }

    // Formulär: koppla loss
    if ($atgard === 'loss') {
        if (kc_post('bekrafta') !== '1') {
            kc_flash('fel', 'Bocka i rutan för att koppla loss.');
            kc_omdirigera($sida . '&loss=' . rawurlencode(kc_post('matpunkt_uuid')));
        }
        try {
            kc_flash('ok', kc_transaktion(fn() => prj_koppla_loss($p, kc_post('matpunkt_uuid'))));
        } catch (KcFel $e) {
            kc_flash('fel', $e->getMessage());
        }
        kc_omdirigera($sida);
    }
    kc_omdirigera($sida);
}

[$totalt, $kopplade, $monterade] = prj_provisionering_antal($p['uuid']);
$status = in_array(kc_get('status'), ['ej_kopplad', 'kopplad', 'monterad'], true) ? kc_get('status') : '';
$loss = null;
if ($kan_andra && kc_get('loss') !== '') {
    $mp_loss = kc_hamta_en('SELECT * FROM `' . kc_tabell('md_matpunkt') . '` WHERE uuid = ? AND projekt_uuid = ? AND is_deleted = 0',
        [kc_get('loss'), $p['uuid']]);
    if ($mp_loss !== null) {
        $loss = prj_matpunkt_info($mp_loss);
    }
}

$tm = kc_tabell('md_matpunkt');
$ta = kc_tabell('md_matare');

kc_sidhuvud('Provisionering: ' . $p['projektnummer'] . ' ' . $p['namn']);
?>
<p><a href="<?= kc_h(kc_url('projekt.php?uuid=' . rawurlencode($p['uuid']))) ?>">« Till projektet</a> ·
   <a href="<?= kc_h(kc_url('matpunktslista.php?projekt=' . rawurlencode($p['uuid']))) ?>">Mätpunktslista</a> ·
   <a href="<?= kc_h(kc_url('matarlista.php?status=ledig')) ?>">Lediga mätare</a> ·
   <a href="<?= kc_h(kc_url('montering.php?projekt=' . rawurlencode($p['uuid']))) ?>">Montering</a></p>

<p id="prj-antal"><strong><?= kc_h($kopplade) ?></strong> av <?= kc_h($totalt) ?> mätpunkter har mätare
   (<?= kc_h($monterade) ?> monterade).</p>

<?php if ($loss !== null): ?>
<div class="kc-ruta" style="max-width:40rem">
  <h2>Koppla loss mätare</h2>
  <?php if ($loss['matar_id'] === ''): ?>
    <p><?= kc_h($loss['namn']) ?> har ingen kopplad mätare.</p>
  <?php elseif ($loss['monterad']): ?>
    <p><?= kc_h($loss['namn']) ?> är monterad. Demontera mätaren först under
       <a href="<?= kc_h(kc_url('montering.php?projekt=' . rawurlencode($p['uuid']) . '&demontera=' . rawurlencode($loss['uuid']))) ?>">Montering</a>.</p>
  <?php else: ?>
    <form method="post">
      <?= kc_csrf_falt() ?>
      <input type="hidden" name="atgard" value="loss">
      <input type="hidden" name="matpunkt_uuid" value="<?= kc_h($loss['uuid']) ?>">
      <p>Koppla loss <strong><?= kc_h($loss['matar_id']) ?></strong> från <strong><?= kc_h($loss['namn']) ?></strong>?
         Mätaren blir ledig i lagret. Mätpunktens namn förblir låst.</p>
      <div class="kc-falt"><label><input type="checkbox" name="bekrafta" value="1"> Ja, koppla loss</label></div>
      <button type="submit" class="kc-knapp-fara">Koppla loss</button>
      <a href="<?= kc_h(kc_url($sida)) ?>">Avbryt</a>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$inloggad): ?>
  <p><em class="kc-dampad">Logga in för att koppla mätare.</em></p>
<?php elseif (!$kan_andra): ?>
  <p><em class="kc-dampad">Projektet är avslutat.</em></p>
<?php elseif ($totalt === 0): ?>
  <p><em class="kc-dampad">Projektet har inga mätpunkter än.</em></p>
<?php else: ?>
<div class="kc-ruta" style="max-width:34rem">
  <h2>Läs in</h2>
  <div class="prj-steg" id="prj-steg"></div>
  <div id="prj-resultat" class="prj-resultat info" hidden></div>
  <?= prj_lasare_html('Mätpunktsnamn eller mätarId') ?>
  <p><button type="button" id="prj-borja-om">Börja om</button></p>
  <div id="prj-logg-ram" hidden>
    <h3>Kopplade nu</h3>
    <ol id="prj-logg" reversed></ol>
    <p><a href="<?= kc_h(kc_url($sida)) ?>">Uppdatera listan</a></p>
  </div>
</div>
<script>
(function () {
  'use strict';
  const URL_SIDA = <?= json_encode(kc_url($sida), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const CSRF = <?= json_encode(kc_csrf_token(), JSON_HEX_TAG) ?>;
  let mp = null;        // vald mätpunkt (steg 2)
  let upptagen = false;

  const $ = id => document.getElementById(id);
  function steg() {
    $('prj-steg').innerHTML = '';
    const b = document.createElement('strong');
    if (mp === null) {
      b.textContent = 'Steg 1: läs mätpunktens etikett';
      $('prj-steg').appendChild(b);
    } else {
      b.textContent = 'Steg 2: läs mätarens QR-kod';
      $('prj-steg').appendChild(b);
      $('prj-steg').appendChild(document.createElement('br'));
      $('prj-steg').appendChild(document.createTextNode('Mätpunkt: ' + mp.namn));
    }
  }
  function resultat(typ, text, smatext) {
    const r = $('prj-resultat');
    r.className = 'prj-resultat ' + typ;
    r.textContent = text;
    (smatext || []).forEach(function (t) {
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
    if (mp !== null && kod === mp.namn) return;      // etiketten syns fortfarande i bild
    upptagen = true;
    lasare.paus();
    if (mp === null) {
      anrop({ atgard: 'matpunkt', kod: kod }).then(function (s) {
        if (!s.ok) {
          resultat('fel', '✗ ' + s.error);
        } else if (s.data.matar_id !== '') {
          resultat('fel', '✗ ' + s.data.namn + ' har redan mätaren ' + s.data.matar_id + '.',
                   ['Koppla loss den i listan nedan om kopplingen är fel.']);
        } else {
          mp = s.data;
          resultat('info', mp.namn, [mp.storheter ? 'Storheter: ' + mp.storheter : '']);
        }
        steg();
      }).finally(function () { upptagen = false; lasare.fortsatt(); });
    } else {
      anrop({ atgard: 'koppla', matpunkt_uuid: mp.uuid, kod: kod }).then(function (s) {
        if (!s.ok) {
          resultat('fel', '✗ ' + s.error, ['Läs en annan mätare eller tryck Börja om.']);
          return;
        }
        resultat('ok', '✓ ' + s.data.text, s.data.varningar.map(v => '⚠ ' + v));
        if (s.data.ny) {
          const li = document.createElement('li');
          li.textContent = s.data.text + (s.data.varningar.length ? ' (varning)' : '');
          $('prj-logg').prepend(li);
          $('prj-logg-ram').hidden = false;
          const a = s.data.antal;
          $('prj-antal').textContent = a[1] + ' av ' + a[0] + ' mätpunkter har mätare (' + a[2] + ' monterade).';
        }
        mp = null;
        steg();
      }).finally(function () { upptagen = false; lasare.fortsatt(); });
    }
  } });

  $('prj-borja-om').addEventListener('click', function () {
    mp = null;
    $('prj-resultat').hidden = true;
    steg();
    lasare.fortsatt();
  });
  steg();
})();
</script>
<?php endif; ?>

<h2>Mätpunkter</h2>
<p>
  <?php if ($status !== ''): ?>
    <a href="<?= kc_h(kc_url($sida)) ?>">« Alla</a>
  <?php else: ?>
    Visa: <a href="<?= kc_h(kc_url($sida . '&status=ej_kopplad')) ?>">ej kopplade</a> ·
    <a href="<?= kc_h(kc_url($sida . '&status=kopplad')) ?>">kopplade</a> ·
    <a href="<?= kc_h(kc_url($sida . '&status=monterad')) ?>">monterade</a>
  <?php endif; ?>
</p>
<?= kc_lista([
    'kalla' => "SELECT m.id, m.uuid, m.matpunktsnamn, m.objektnummer, m.funktion, m.monterad_datum,
                       COALESCE(a.matar_id, '') AS matar_id,
                       CASE WHEN m.monterad_datum IS NOT NULL THEN 'monterad'
                            WHEN a.uuid IS NOT NULL THEN 'kopplad' ELSE 'ej_kopplad' END AS status
                  FROM `$tm` m
                  LEFT JOIN `$ta` a ON a.matpunkt_uuid = m.uuid AND a.is_deleted = 0
                 WHERE m.is_deleted = 0 AND m.projekt_uuid = ?",
    'kalla_params'      => [$p['uuid']],
    'villkor'           => $status !== '' ? 'status = ?' : '',
    'villkor_params'    => $status !== '' ? [$status] : [],
    'kolumner'          => [
        'matpunktsnamn'  => 'Mätpunkt',
        'objektnummer'   => 'Objekt',
        'funktion'       => 'Funktion',
        'matar_id'       => 'Mätare',
        'status'         => 'Status',
        'monterad_datum' => 'Monterad',
    ],
    'extra_kolumner'    => ['uuid'],
    'standardsortering' => 'matpunktsnamn',
    'format'            => [
        'status' => fn($v) => match ($v) {
            'monterad' => '<strong>monterad</strong>',
            'kopplad'  => 'kopplad',
            default    => '<span class="kc-dampad">ej kopplad</span>',
        },
        'matar_id' => fn($v, $rad) => $v === '' ? '' : kc_h($v) . ($kan_andra && $rad['status'] === 'kopplad'
            ? ' <a href="' . kc_h(kc_url($sida . '&loss=' . rawurlencode($rad['uuid']))) . '">koppla loss</a>' : ''),
    ],
    'export_namn'       => 'prj_provisionering_' . $p['projektnummer'],
    'tom_text'          => 'Inga mätpunkter.',
]) ?>
<?php kc_sidfot();
