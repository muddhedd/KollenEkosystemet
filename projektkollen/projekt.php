<?php
/**
 * ProjektKollen – projekt.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Ett projekt: uppgifter, omfattning (fastigheter, byggnader, objekt, objektgrupper) och
 * sammanfattning av objektlista, mätpunkter, etiketter, provisionering, montering, export och avslut.
 * Läsning öppen för alla; ändring av omfattningen kräver inloggning (omfattning.php).
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/prj.php';

$inloggad = prj_inloggad() !== null;
$uuid = kc_get('uuid');
$p = prj_projekt_hamta($uuid);
if ($p === null) {
    throw new KcFel('Projektet finns inte.', 404);
}
$typ = prj_typ_hamta('projekttyp', $p['projekttyp_uuid']);
$funktion = prj_projekttyp_funktion($p['projekttyp_uuid']);
$kan_andra = $inloggad && !prj_projekt_last($p);
$omfattning = prj_omfattning($uuid);
$sum = prj_projekt_sammanfattning($uuid);

kc_sidhuvud('Projekt ' . $p['projektnummer'] . ': ' . $p['namn']);
?>
<p><a href="<?= kc_h(kc_url('projektlista.php')) ?>">« Alla projekt</a></p>

<div class="kc-ruta" style="max-width:52rem">
  <h2>Uppgifter</h2>
  <div class="kc-tabell-wrap"><table class="kc-tabell"><tbody>
    <tr><th>Projektnummer</th><td><strong><?= kc_h($p['projektnummer']) ?></strong></td></tr>
    <tr><th>Namn</th><td><?= kc_h($p['namn']) ?></td></tr>
    <tr><th>Projekttyp</th><td><?= kc_h($typ['namn'] ?? '') ?><?= $funktion !== null ? ' <span class="kc-dampad">(funktion ' . kc_h($funktion['namn']) . ')</span>' : '' ?></td></tr>
    <tr><th>Status</th><td><strong><?= kc_h(PRJ_STATUS[$p['status']] ?? $p['status']) ?></strong></td></tr>
    <tr><th>Projektledare</th><td><?= kc_h($p['projektledare']) ?></td></tr>
    <tr><th>Start</th><td><?= kc_h($p['startdatum'] ?? '') ?></td></tr>
    <tr><th>Slut</th><td><?= kc_h($p['slutdatum'] ?? '') ?></td></tr>
    <tr><th>Beskrivning</th><td><?= nl2br(kc_h($p['beskrivning'] ?? '')) ?></td></tr>
    <tr><th>Senast ändrad</th><td><?= kc_h(kc_tid($p['last_changed'])) ?></td></tr>
  </tbody></table></div>
  <?php if ($inloggad): ?>
    <p style="margin-top:.75rem"><a class="kc-knapp" href="<?= kc_h(kc_url('projekt_redigera.php?uuid=' . rawurlencode($uuid))) ?>">Ändra</a>
      <?php if ($p['status'] === 'avslutat'): ?>
        <a class="kc-knapp" href="<?= kc_h(kc_url('avslut.php?projekt=' . rawurlencode($uuid))) ?>">Återöppna projekt</a>
      <?php else: ?>
        <a class="kc-knapp" href="<?= kc_h(kc_url('avslut.php?projekt=' . rawurlencode($uuid))) ?>">Avsluta projekt</a>
      <?php endif; ?></p>
  <?php endif; ?>
</div>

<div class="kc-ruta" style="max-width:52rem" id="omfattning">
  <h2>Omfattning</h2>
  <?php if ($funktion === null): ?>
    <div class="kc-flash kc-flash-fel">Projekttypen saknar funktion. Ange den under <a href="<?= kc_h(kc_url('typer.php?lista=projekttyp')) ?>">Typer</a>.</div>
  <?php endif; ?>
  <?php if ($omfattning === []): ?>
    <p><em class="kc-dampad">Inget valt än.</em></p>
  <?php else: ?>
    <div class="kc-tabell-wrap"><table class="kc-tabell">
      <thead><tr><th>Nivå</th><th>Nummer</th><th>Namn</th><?= $kan_andra ? '<th></th>' : '' ?></tr></thead>
      <tbody>
      <?php foreach ($omfattning as $o): ?>
        <tr>
          <td><?= kc_h(PRJ_NIVAER[$o['niva']]['etikett'] ?? $o['niva']) ?></td>
          <td><?= kc_h($o['nummer']) ?><?= $o['finns'] ? '' : ' <strong style="color:var(--kc-fel)">saknas</strong>' ?></td>
          <td><?= kc_h($o['namn']) ?></td>
          <?php if ($kan_andra): ?>
          <td>
            <form method="post" class="kc-inline" action="<?= kc_h(kc_url('omfattning.php?projekt=' . rawurlencode($uuid))) ?>"
                  onsubmit="return confirm('Ta bort <?= kc_h(addslashes($o['nummer'])) ?> ur omfattningen? Preliminära mätpunkter för dess objekt tas bort.');">
              <?= kc_csrf_falt() ?>
              <input type="hidden" name="atgard" value="ta_bort">
              <input type="hidden" name="omfattning" value="<?= kc_h($o['uuid']) ?>">
              <button type="submit" class="kc-lank-knapp">ta bort</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>

  <?php if ($kan_andra && $funktion !== null): ?>
  <form method="post" action="<?= kc_h(kc_url('omfattning.php?projekt=' . rawurlencode($uuid))) ?>" style="margin-top:.75rem">
    <?= kc_csrf_falt() ?>
    <input type="hidden" name="atgard" value="lagg_till">
    <div class="kc-falt">
      <label for="niva">Lägg till</label>
      <select id="niva" name="niva"><?= prj_alternativ(array_map(static fn($d) => $d['etikett'], PRJ_NIVAER), 'byggnad') ?></select>
    </div>
    <div class="kc-falt">
      <label for="nummer">Nummer (ett per rad, eller kommaseparerade)</label>
      <textarea id="nummer" name="nummer" rows="3" style="width:100%" placeholder="1301-01&#10;1301-02"></textarea>
      <div class="kc-hjalp"><a href="<?= kc_h(kc_url('fastighetsdata.php')) ?>" target="_blank" rel="noopener">Sök i fastighetsdata »</a>
        En objektgrupp ger alla objekt som gruppen är kopplad till (direkt eller via byggnad/fastighet).</div>
    </div>
    <button type="submit">Lägg till</button>
  </form>
  <?php endif; ?>
</div>

<div class="kc-ruta" style="max-width:52rem">
  <h2>Objekt och mätpunkter</h2>
  <p>Objekt: <strong><?= kc_h($sum['objekt']) ?></strong><?= $sum['objekt_utan'] > 0 ? ' (varav ' . kc_h($sum['objekt_utan']) . ' utan givare)' : '' ?>
     · <a href="<?= kc_h(kc_url('objektlista.php?projekt=' . rawurlencode($uuid))) ?>">Objektlista »</a></p>
  <p>Mätpunkter: <strong><?= kc_h($sum['matpunkter']) ?></strong>
     <?= $sum['lasta'] > 0 ? '(' . kc_h($sum['lasta']) . ' med låst namn)' : '' ?>
     <?= $sum['med_info'] > 0 ? '· ' . kc_h($sum['med_info']) . ' med infotext' : '' ?>
     · <a href="<?= kc_h(kc_url('matpunktslista.php?projekt=' . rawurlencode($uuid))) ?>">Mätpunktslista »</a></p>
  <p>Etiketter: <strong><?= kc_h($sum['utskrivna']) ?></strong> av <?= kc_h($sum['matpunkter']) ?> utskrivna
     · <a href="<?= kc_h(kc_url('etiketter.php?projekt=' . rawurlencode($uuid))) ?>">Etiketter »</a>
     · <a href="<?= kc_h(kc_url('objektetiketter.php?projekt=' . rawurlencode($uuid))) ?>">Objektetiketter »</a></p>
  <?php [$prov_totalt, $prov_kopplade, $prov_monterade] = prj_provisionering_antal($uuid); ?>
  <p>Mätare: <strong><?= kc_h($prov_kopplade) ?></strong> av <?= kc_h($prov_totalt) ?> kopplade,
     <?= kc_h($prov_monterade) ?> monterade
     · <a href="<?= kc_h(kc_url('provisionering.php?projekt=' . rawurlencode($uuid))) ?>">Provisionering »</a>
     · <a href="<?= kc_h(kc_url('montering.php?projekt=' . rawurlencode($uuid))) ?>">Montering »</a>
     · <a href="<?= kc_h(kc_url('export.php?projekt=' . rawurlencode($uuid))) ?>">Export »</a></p>
  <?php if ($funktion !== null): ?>
    <p class="kc-hjalp">Namnstandard: <code>&lt;objektnummer&gt;_<?= kc_h($funktion['namn']) ?>_&lt;X&gt;&lt;Y&gt;</code> –
       X = givarens löpnummer i objektet, Y = antal givare i objektet (11 = en av en, 34 = tre av fyra).</p>
  <?php endif; ?>
</div>
<?php kc_sidfot();
