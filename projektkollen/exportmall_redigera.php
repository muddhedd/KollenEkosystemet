<?php
/**
 * ProjektKollen – exportmall_redigera.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Visa, skapa, ändra, kopiera och ta bort en exportmall med dess kolumner.
 * Parametrar: uuid (befintlig mall), kopiera (uuid för mallen som kopieras till en ny).
 * Låsta mallar visas bara (med knappen Kopiera). Ändringar kräver inloggning.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/prj.php';
require __DIR__ . '/prj_export.php';

$inloggad = prj_inloggad() !== null;
$uuid = kc_get('uuid');
$mall = $uuid !== '' ? prj_export_mall($uuid) : null;
if ($uuid !== '' && $mall === null) {
    throw new KcFel('Mallen finns inte.', 404);
}
$kalla_mall = kc_get('kopiera') !== '' ? prj_export_mall(kc_get('kopiera')) : null;
$last = $mall !== null && (int)$mall['last'] === 1;

const PRJ_EXPORT_TOMMA_RADER = 5;

$fel = [];
$m = $mall ?? ($kalla_mall !== null
    ? ['namn' => 'Kopia av ' . $kalla_mall['namn']] + $kalla_mall
    : ['namn' => '', 'beskrivning' => '', 'avgransare' => 'semikolon', 'kodning' => 'utf8bom', 'rubrikrad' => 1,
       'max_rader' => 0, 'urval' => 'alla', 'med_nycklar' => 0]);
$kolumner = $mall !== null ? prj_export_kolumner($mall['uuid']) : ($kalla_mall !== null ? prj_export_kolumner($kalla_mall['uuid']) : []);

if (kc_ar_post()) {
    prj_krav_inloggning();
    if (kc_post('atgard') === 'radera') {
        if ($mall === null || $last) {
            kc_flash('fel', 'Den här mallen kan inte tas bort.');
        } elseif (kc_post('bekrafta') !== '1') {
            kc_flash('fel', 'Bocka i rutan för att ta bort mallen.');
            kc_omdirigera('exportmall_redigera.php?uuid=' . rawurlencode($mall['uuid']));
        } else {
            kc_transaktion(fn() => prj_export_mall_radera($mall));
            kc_flash('ok', 'Mallen "' . $mall['namn'] . '" är borttagen. Tidigare exporter finns kvar.');
        }
        kc_omdirigera('exportmallar.php');
    }
    if ($last) {
        kc_flash('fel', 'Mallen är låst. Kopiera den till en egen mall för att ändra.');
        kc_omdirigera('exportmall_redigera.php?uuid=' . rawurlencode($mall['uuid']));
    }
    $m = [
        'namn' => kc_post('namn'), 'beskrivning' => kc_post('beskrivning'), 'avgransare' => kc_post('avgransare'),
        'kodning' => kc_post('kodning'), 'rubrikrad' => kc_post('rubrikrad') === '1' ? 1 : 0, 'max_rader' => kc_post('max_rader', '0'),
        'urval' => kc_post('urval'), 'med_nycklar' => kc_post('med_nycklar') === '1' ? 1 : 0,
    ];
    $inkommande = [];
    $poster = is_array($_POST['kol'] ?? null) ? $_POST['kol'] : [];
    foreach ($poster as $k) {
        if (!is_array($k)) {
            continue;
        }
        $rad = [
            'ordning' => (int)($k['ordning'] ?? 0), 'rubrik' => trim((string)($k['rubrik'] ?? '')),
            'kalla' => (string)($k['kalla'] ?? ''), 'fast_varde' => (string)($k['fast_varde'] ?? ''),
        ];
        if (($k['bort'] ?? '') === '1' || ($rad['rubrik'] === '' && $rad['kalla'] === '')) {
            continue;
        }
        $inkommande[] = $rad;
    }
    usort($inkommande, static fn($a, $b) => $a['ordning'] <=> $b['ordning']);
    $kolumner = $inkommande;
    $fel = prj_export_mall_fel($m, $kolumner, $mall['uuid'] ?? null);
    if ($fel === []) {
        $ny = kc_transaktion(fn() => prj_export_mall_spara($mall['uuid'] ?? null, $m, $kolumner));
        kc_flash('ok', 'Mallen "' . trim($m['namn']) . '" är sparad.');
        kc_omdirigera('exportmall_redigera.php?uuid=' . rawurlencode($ny));
    }
}

// Källor till rullistan: fälten, nyckeltyperna (kända + de som används i mallen) och fast värde
$nyckeltyper = array_keys(PRJ_NYCKELTYPER);
foreach ($kolumner as $k) {
    if (str_starts_with((string)$k['kalla'], 'nyckel:') && !in_array(mb_substr($k['kalla'], 7), $nyckeltyper, true)) {
        $nyckeltyper[] = mb_substr($k['kalla'], 7);
    }
}
$kalla_val = static function (string $valt) use ($nyckeltyper): string {
    $ut = '<option value="">(välj)</option>';
    $grupper = [];
    foreach (PRJ_EXPORT_KALLOR as $k => [$etikett, $grupp]) {
        $grupper[$grupp][$k] = $etikett;
    }
    foreach ($nyckeltyper as $t) {
        $grupper['Nycklar']['nyckel:' . $t] = $t;
    }
    $grupper['Övrigt']['fast'] = 'Fast värde';
    foreach ($grupper as $grupp => $val) {
        $ut .= '<optgroup label="' . kc_h($grupp) . '">';
        foreach ($val as $k => $e) {
            $ut .= '<option value="' . kc_h($k) . '"' . ($k === $valt ? ' selected' : '') . '>' . kc_h($e) . '</option>';
        }
        $ut .= '</optgroup>';
    }
    return $ut;
};

kc_sidhuvud($mall === null ? 'Ny exportmall' : 'Exportmall: ' . $mall['namn']);
?>
<p><a href="<?= kc_h(kc_url('exportmallar.php')) ?>">« Alla mallar</a></p>

<?php if ($fel !== []): ?>
  <div class="kc-flash kc-flash-fel"><strong>Mallen sparades inte:</strong><ul><?php foreach ($fel as $f): ?><li><?= kc_h($f) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($last || !$inloggad): ?>
  <div class="kc-ruta" style="max-width:52rem">
    <?php if ($last): ?><p><strong>Låst mall.</strong> Den kan inte ändras eller tas bort, men kopieras till en egen mall.</p><?php endif; ?>
    <?php if ($mall !== null): ?>
    <div class="kc-tabell-wrap"><table class="kc-tabell"><tbody>
      <tr><th>Namn</th><td><?= kc_h($m['namn']) ?></td></tr>
      <tr><th>Beskrivning</th><td><?= kc_h($m['beskrivning']) ?></td></tr>
      <tr><th>Avgränsare</th><td><?= kc_h(PRJ_EXPORT_AVGRANSARE[$m['avgransare']][0] ?? $m['avgransare']) ?></td></tr>
      <tr><th>Teckenkodning</th><td><?= kc_h(PRJ_EXPORT_KODNING[$m['kodning']] ?? $m['kodning']) ?></td></tr>
      <tr><th>Rubrikrad</th><td><?= (int)$m['rubrikrad'] === 1 ? 'ja' : 'nej' ?></td></tr>
      <tr><th>Rader per fil</th><td><?= (int)$m['max_rader'] === 0 ? 'ingen delning' : kc_h($m['max_rader']) ?></td></tr>
      <tr><th>Urval</th><td><?= kc_h(PRJ_EXPORT_URVAL[$m['urval']] ?? $m['urval']) ?></td></tr>
      <tr><th>Nycklar får ingå</th><td><?= (int)$m['med_nycklar'] === 1 ? '<strong>ja</strong> (kräver inloggning vid nedladdning)' : 'nej' ?></td></tr>
    </tbody></table></div>
    <h3>Kolumner</h3>
    <div class="kc-tabell-wrap"><table class="kc-tabell">
      <thead><tr><th>#</th><th>Rubrik i filen</th><th>Källa</th></tr></thead>
      <tbody>
      <?php foreach ($kolumner as $i => $k): ?>
        <tr><td><?= $i + 1 ?></td><td><code><?= kc_h($k['rubrik']) ?></code></td><td><?= kc_h(prj_export_kalla_etikett($k['kalla'], $k['fast_varde'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
    <?php if ($inloggad && $mall !== null): ?>
      <p><a class="kc-knapp" href="<?= kc_h(kc_url('exportmall_redigera.php?kopiera=' . rawurlencode($mall['uuid']))) ?>">Kopiera till ny mall</a></p>
    <?php elseif (!$inloggad): ?>
      <p><em class="kc-dampad">Logga in för att skapa eller ändra mallar.</em></p>
    <?php endif; ?>
  </div>
<?php else: ?>
<form method="post" class="kc-ruta" style="max-width:60rem">
  <?= kc_csrf_falt() ?>
  <input type="hidden" name="atgard" value="spara">
  <h2>Inställningar</h2>
  <div class="kc-falt"><label for="namn">Namn</label>
    <input type="text" id="namn" name="namn" value="<?= kc_h($m['namn']) ?>" maxlength="100" required style="width:30rem"></div>
  <div class="kc-falt"><label for="beskrivning">Beskrivning</label>
    <input type="text" id="beskrivning" name="beskrivning" value="<?= kc_h($m['beskrivning']) ?>" maxlength="255" style="width:100%"></div>
  <div class="kc-falt"><label for="avgransare">Avgränsare</label>
    <select id="avgransare" name="avgransare">
      <?php foreach (PRJ_EXPORT_AVGRANSARE as $k => [$e]): ?><option value="<?= kc_h($k) ?>"<?= $m['avgransare'] === $k ? ' selected' : '' ?>><?= kc_h($e) ?></option><?php endforeach; ?>
    </select></div>
  <div class="kc-falt"><label for="kodning">Teckenkodning</label>
    <select id="kodning" name="kodning">
      <?php foreach (PRJ_EXPORT_KODNING as $k => $e): ?><option value="<?= kc_h($k) ?>"<?= $m['kodning'] === $k ? ' selected' : '' ?>><?= kc_h($e) ?></option><?php endforeach; ?>
    </select></div>
  <div class="kc-falt"><label><input type="checkbox" name="rubrikrad" value="1"<?= (int)$m['rubrikrad'] === 1 ? ' checked' : '' ?>> Rubrikrad först i filen</label></div>
  <div class="kc-falt"><label for="max_rader">Max rader per fil (0 = ingen delning)</label>
    <input type="number" id="max_rader" name="max_rader" value="<?= kc_h($m['max_rader']) ?>" min="0" max="100000" style="width:8rem"></div>
  <div class="kc-falt"><label for="urval">Urval</label>
    <select id="urval" name="urval">
      <?php foreach (PRJ_EXPORT_URVAL as $k => $e): ?><option value="<?= kc_h($k) ?>"<?= $m['urval'] === $k ? ' selected' : '' ?>><?= kc_h($e) ?></option><?php endforeach; ?>
    </select></div>
  <div class="kc-falt"><label><input type="checkbox" name="med_nycklar" value="1"<?= (int)$m['med_nycklar'] === 1 ? ' checked' : '' ?>> Nycklar får ingå
    <span class="kc-dampad">(nedladdning kräver då inloggning)</span></label></div>

  <h2>Kolumner</h2>
  <p class="kc-hjalp">Ordningen styrs av siffran. Tomma rader ignoreras; bocka i "Ta bort" för att ta bort en kolumn.
     "Fast värde" skriver samma text på varje rad (t.ex. status = drifttagen).</p>
  <div class="kc-tabell-wrap"><table class="kc-tabell">
    <thead><tr><th>Ordning</th><th>Rubrik i filen</th><th>Källa</th><th>Fast värde</th><th>Ta bort</th></tr></thead>
    <tbody>
    <?php
    $rader = array_values($kolumner);
    for ($i = 0; $i < count($rader) + PRJ_EXPORT_TOMMA_RADER; $i++):
        $k = $rader[$i] ?? ['rubrik' => '', 'kalla' => '', 'fast_varde' => ''];
    ?>
      <tr>
        <td><input type="number" name="kol[<?= $i ?>][ordning]" value="<?= ($i + 1) * 10 ?>" style="width:5rem"></td>
        <td><input type="text" name="kol[<?= $i ?>][rubrik]" value="<?= kc_h($k['rubrik']) ?>" maxlength="100" style="width:14rem"></td>
        <td><select name="kol[<?= $i ?>][kalla]"><?= $kalla_val((string)$k['kalla']) ?></select></td>
        <td><input type="text" name="kol[<?= $i ?>][fast_varde]" value="<?= kc_h($k['fast_varde']) ?>" maxlength="255" style="width:10rem"></td>
        <td><?php if ($i < count($rader)): ?><input type="checkbox" name="kol[<?= $i ?>][bort]" value="1"><?php endif; ?></td>
      </tr>
    <?php endfor; ?>
    </tbody>
  </table></div>
  <p><button type="submit" class="kc-knapp-primar">Spara mallen</button></p>
</form>

<?php if ($mall !== null): ?>
<div class="kc-ruta" style="max-width:40rem">
  <p><a class="kc-knapp" href="<?= kc_h(kc_url('exportmall_redigera.php?kopiera=' . rawurlencode($mall['uuid']))) ?>">Kopiera till ny mall</a></p>
  <h2>Ta bort mallen</h2>
  <form method="post">
    <?= kc_csrf_falt() ?>
    <input type="hidden" name="atgard" value="radera">
    <p>Mallen och dess kolumner tas bort. Exporter som redan gjorts finns kvar och kan laddas ner igen.</p>
    <div class="kc-falt"><label><input type="checkbox" name="bekrafta" value="1"> Ja, ta bort mallen</label></div>
    <button type="submit" class="kc-knapp-fara">Ta bort</button>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>
<?php kc_sidfot();
