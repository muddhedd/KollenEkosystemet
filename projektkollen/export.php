<?php
/**
 * ProjektKollen – export.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Exporter för ett projekt med exportmallarna.
 *  - "Skapa export" (inloggad) skapar en exportomgång: mallens urval, förval bara mätpunkter som inte
 *    exporterats med mallen tidigare. Raderna delas i delar om mallens max rader; indelningen ändras
 *    aldrig. Exporterade mätpunkters namn låses.
 *  - Varje del laddas ner för sig, eller alla som en zip. Filerna byggs med aktuella data.
 *  - Nedladdningar loggas; senaste nedladdning visas per del. Mallar med nycklar kräver inloggning
 *    och kan inte laddas ner för avslutade projekt (nycklarna är rensade).
 *  - "Ångra export" (bekräftelse) gör omgångens mätpunkter oexporterade igen.
 * Parametrar: projekt; hamta=<omgång>&del=<n> (0 = alla som zip); angra=<omgång> visar bekräftelse.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/prj.php';
require __DIR__ . '/prj_export.php';
require __DIR__ . '/prj_zip.php';

$anvandare = prj_inloggad();
$inloggad = $anvandare !== null;
$p = prj_projekt_hamta(kc_get('projekt'));
if ($p === null) {
    throw new KcFel('Projektet finns inte.', 404);
}
$kan_andra = $inloggad && !prj_projekt_last($p);
$sida = 'export.php?projekt=' . rawurlencode($p['uuid']);

// Nedladdning
if (kc_get('hamta') !== '') {
    $omgang = kc_get('hamta');
    $del = max(0, (int)kc_get('del', '0'));
    $forsta = kc_hamta_en('SELECT mall_uuid, omgang_tid, antal_delar FROM `' . kc_tabell('md_exportrad') . '`
                            WHERE projekt_uuid = ? AND omgang_uuid = ? AND is_deleted = 0 LIMIT 1', [$p['uuid'], $omgang]);
    if ($forsta === null) {
        throw new KcFel('Exporten finns inte.', 404);
    }
    $mall = prj_export_mall($forsta['mall_uuid'], true) ?? throw new KcFel('Mallen finns inte.', 404);
    if ((int)$mall['med_nycklar'] === 1) {
        if (prj_projekt_last($p)) {
            kc_flash('fel', 'Projektet är avslutat och nycklarna är rensade. Mallar med nycklar kan inte laddas ner – återöppna projektet och läs in nycklarna igen om filen behövs.');
            kc_omdirigera($sida);
        }
        $anvandare = prj_krav_inloggning();
    }
    $kolumner = prj_export_kolumner($mall['uuid']);
    $delar = (int)$forsta['antal_delar'];
    $rader = prj_export_rader($p['uuid'], $omgang, $del);
    if ($rader === []) {
        throw new KcFel('Delen finns inte.', 404);
    }
    if ($del === 0 && $delar > 1) {
        $filer = [];
        foreach ($rader as $r) {
            $filer[(int)$r['del_nr']][] = $r;
        }
        $zip = [];
        foreach ($filer as $d => $rd) {
            $zip[prj_export_filnamn($p, $mall, $forsta['omgang_tid'], $d, $delar)] = prj_export_csv($mall, $kolumner, $rd);
        }
        $data = prj_zip($zip);
        $filnamn = prj_export_filnamn($p, $mall, $forsta['omgang_tid'], 0, $delar, 'zip');
        $typ = 'application/zip';
    } else {
        $data = prj_export_csv($mall, $kolumner, $rader);
        $filnamn = prj_export_filnamn($p, $mall, $forsta['omgang_tid'], $del, $delar);
        $typ = 'text/csv; charset=' . ($mall['kodning'] === 'cp1252' ? 'windows-1252' : 'utf-8');
    }
    prj_export_logga($p, $mall, $omgang, $del, count($rader), (string)($anvandare['anvandarnamn'] ?? ''));
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $typ);
    header('Content-Disposition: attachment; filename="' . $filnamn . '"');
    header('Content-Length: ' . strlen($data));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $data;
    exit;
}

if (kc_ar_post()) {
    $anvandare = prj_krav_inloggning();
    if (!$kan_andra) {
        kc_flash('fel', 'Projektet är avslutat.');
        kc_omdirigera($sida);
    }
    $atgard = kc_post('atgard');
    if ($atgard === 'skapa') {
        $mall = prj_export_mall(kc_post('mall'));
        if ($mall === null) {
            kc_flash('fel', 'Välj en mall.');
            kc_omdirigera($sida);
        }
        try {
            $r = kc_transaktion(fn() => prj_export_skapa_omgang($mall, $p, kc_post('aven_tidigare') === '1', (string)($anvandare['anvandarnamn'] ?? '')));
            kc_flash('ok', 'Exporten är skapad: ' . $r['antal'] . ' mätpunkter i ' . $r['delar'] . ($r['delar'] === 1 ? ' fil' : ' filer')
                . '. Mätpunkternas namn är låsta. Ladda ner filerna nedan.');
        } catch (KcFel $e) {
            kc_flash('fel', $e->getMessage());
        }
        kc_omdirigera($sida);
    }
    if ($atgard === 'angra') {
        if (kc_post('bekrafta') !== '1') {
            kc_flash('fel', 'Bocka i rutan för att ångra exporten.');
            kc_omdirigera($sida . '&angra=' . rawurlencode(kc_post('omgang')));
        }
        $n = kc_transaktion(fn() => prj_export_angra_omgang($p['uuid'], kc_post('omgang')));
        kc_flash($n > 0 ? 'ok' : 'fel', $n > 0 ? "Exporten är ångrad: $n mätpunkter räknas som ej exporterade igen." : 'Exporten finns inte.');
        kc_omdirigera($sida);
    }
    kc_omdirigera($sida);
}

$mallar = prj_export_mallar();
$omgangar = prj_export_omgangar($p['uuid']);
$angra = kc_get('angra');

kc_sidhuvud('Export: ' . $p['projektnummer'] . ' ' . $p['namn']);
?>
<p><a href="<?= kc_h(kc_url('projekt.php?uuid=' . rawurlencode($p['uuid']))) ?>">« Till projektet</a> ·
   <a href="<?= kc_h(kc_url('exportmallar.php')) ?>">Exportmallar</a></p>

<?php if ($kan_andra && $angra !== ''):
    $o_angra = array_values(array_filter($omgangar, static fn($o) => $o['omgang_uuid'] === $angra))[0] ?? null; ?>
  <?php if ($o_angra !== null): ?>
  <div class="kc-ruta" style="max-width:40rem">
    <h2>Ångra export</h2>
    <form method="post">
      <?= kc_csrf_falt() ?>
      <input type="hidden" name="atgard" value="angra">
      <input type="hidden" name="omgang" value="<?= kc_h($o_angra['omgang_uuid']) ?>">
      <p>Ångra exporten <strong><?= kc_h($o_angra['mallnamn']) ?></strong> från <?= kc_h(kc_tid($o_angra['omgang_tid'])) ?>
         (<?= kc_h($o_angra['antal']) ?> mätpunkter)? Mätpunkterna räknas som ej exporterade och kommer med i nästa export.
         Filer som redan laddats ner påverkas inte, och namnen förblir låsta.</p>
      <div class="kc-falt"><label><input type="checkbox" name="bekrafta" value="1"> Ja, ångra exporten</label></div>
      <button type="submit" class="kc-knapp-fara">Ångra export</button>
      <a href="<?= kc_h(kc_url($sida)) ?>">Avbryt</a>
    </form>
  </div>
  <?php endif; ?>
<?php endif; ?>

<div class="kc-ruta" style="max-width:56rem">
  <h2>Ny export</h2>
  <?php if ($mallar === []): ?>
    <p><em class="kc-dampad">Inga exportmallar. Kör install.php eller skapa en mall.</em></p>
  <?php else: ?>
  <div class="kc-tabell-wrap"><table class="kc-tabell">
    <thead><tr><th>Mall</th><th>Urval</th><th>Rader per fil</th><th>Matchar</th><th>Ej exporterade</th></tr></thead>
    <tbody>
    <?php foreach ($mallar as $x): [$alla, $nya] = prj_export_antal($x, $p['uuid']); ?>
      <tr>
        <td><a href="<?= kc_h(kc_url('exportmall_redigera.php?uuid=' . rawurlencode($x['uuid']))) ?>"><?= kc_h($x['namn']) ?></a>
            <?= (int)$x['med_nycklar'] === 1 ? ' <span class="kc-dampad">(nycklar)</span>' : '' ?></td>
        <td><?= kc_h(PRJ_EXPORT_URVAL[$x['urval']] ?? $x['urval']) ?></td>
        <td><?= (int)$x['max_rader'] === 0 ? 'alla i en fil' : kc_h($x['max_rader']) ?></td>
        <td><?= kc_h($alla) ?></td>
        <td><strong><?= kc_h($nya) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($kan_andra): ?>
  <form method="post">
    <?= kc_csrf_falt() ?>
    <input type="hidden" name="atgard" value="skapa">
    <div class="kc-falt"><label for="mall">Mall</label>
      <select id="mall" name="mall">
        <?php foreach ($mallar as $x): ?><option value="<?= kc_h($x['uuid']) ?>"><?= kc_h($x['namn']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="kc-falt"><label><input type="checkbox" name="aven_tidigare" value="1"> Ta med även mätpunkter som redan exporterats med mallen</label></div>
    <button type="submit" class="kc-knapp-primar">Skapa export</button>
    <p class="kc-hjalp">Exporten delas i filer när den skapas, och indelningen ändras inte efteråt. Filerna innehåller
       aktuella data när de laddas ner. Exporterade mätpunkters namn låses.</p>
  </form>
  <?php elseif (!$inloggad): ?>
    <p><em class="kc-dampad">Logga in för att skapa exporter.</em></p>
  <?php else: ?>
    <p><em class="kc-dampad">Projektet är avslutat.</em></p>
  <?php endif; ?>
  <?php endif; ?>
</div>

<h2>Exporter</h2>
<?php if ($omgangar === []): ?>
  <p><em class="kc-dampad">Inga exporter än.</em></p>
<?php else: ?>
<div class="kc-tabell-wrap"><table class="kc-tabell">
  <thead><tr><th>Skapad</th><th>Mall</th><th>Av</th><th>Mätpunkter</th><th>Filer (senast nedladdad)</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($omgangar as $o):
      $bas = $sida . '&hamta=' . rawurlencode($o['omgang_uuid']); ?>
    <tr>
      <td><?= kc_h(kc_tid($o['omgang_tid'])) ?></td>
      <td><?= kc_h($o['mallnamn']) ?></td>
      <td><?= kc_h($o['exporterad_av']) ?></td>
      <td><?= kc_h($o['antal']) ?></td>
      <td>
        <?php foreach ($o['delar'] as $d => $info): ?>
          <div><a href="<?= kc_h(kc_url($bas . '&del=' . $d)) ?>"><?= count($o['delar']) > 1 ? 'Del ' . $d . ' av ' . count($o['delar']) : 'Ladda ner' ?></a>
            (<?= kc_h($info['antal']) ?> rader)
            <span class="kc-dampad"><?= $info['senast'] ? kc_h(kc_tid($info['senast'])) : 'ej nedladdad' ?></span></div>
        <?php endforeach; ?>
        <?php if (count($o['delar']) > 1): ?>
          <div><a href="<?= kc_h(kc_url($bas . '&del=0')) ?>"><strong>Ladda ner alla (zip)</strong></a></div>
        <?php endif; ?>
      </td>
      <td><?php if ($kan_andra): ?><a href="<?= kc_h(kc_url($sida . '&angra=' . rawurlencode($o['omgang_uuid']))) ?>">ångra</a><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php kc_sidfot();
