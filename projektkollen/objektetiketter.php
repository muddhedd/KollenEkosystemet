<?php
/**
 * ProjektKollen – objektetiketter.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Objektetiketter som PDF för projektets objekt: samma ark som mätpunktsetiketterna (A4, 3 × 8 à
 * 70 × 37 mm). QR-koden innehåller objektnumret och klartexten är objektnumret i stor fetstil,
 * valfritt med gatuadressen. Etiketten kan läsas i steg 1 i monteringskontrollen.
 * Bonusfunktion: ändrar ingenting (objekten ägs av FastighetsKollen), låser inget och sparar ingen
 * utskriftstid. Öppen för alla. Ska på sikt flyttas till FastighetsKollen.
 * "Exportera alla": objekt i objektlistan med minst en mätpunkt (eller alla, med kryssruta).
 * "Exportera urval": byggnad och/eller lista med objektnummer. 1–4 etiketter per objekt.
 * Parameter: projekt.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/prj.php';
require __DIR__ . '/prj_qr.php';
require __DIR__ . '/prj_pdf.php';

const PRJ_OBJEKTETIKETTER_MAX = 2400;   // 100 ark per PDF

$p = prj_projekt_hamta(kc_get('projekt'));
if ($p === null) {
    throw new KcFel('Projektet finns inte.', 404);
}
$sida = 'objektetiketter.php?projekt=' . rawurlencode($p['uuid']);

$tpo = kc_tabell('md_projektobjekt');
$tm = kc_tabell('md_matpunkt');
$to = kc_tabell('ld_fas_objekt');
$tb = kc_tabell('ld_fas_byggnad');
$ta = kc_tabell('ld_fas_adress');
$grund = "SELECT po.objektnummer, COALESCE(b.byggnadsnummer, '') AS byggnad, COALESCE(a.gatuadress, '') AS adress,
                 (SELECT COUNT(*) FROM `$tm` m WHERE m.projekt_uuid = po.projekt_uuid AND m.objekt_uuid = po.objekt_uuid
                     AND m.is_deleted = 0) AS matpunkter
            FROM `$tpo` po
            LEFT JOIN `$to` o ON o.uuid = po.objekt_uuid
            LEFT JOIN `$tb` b ON b.uuid = o.byggnad_uuid
            LEFT JOIN `$ta` a ON a.uuid = o.adress_uuid
           WHERE po.projekt_uuid = ? AND po.is_deleted = 0 AND po.objektnummer <> ''";

// Utskrift: POST (CSRF-skyddat formulär) men ingen inloggning krävs – inget sparas.
if (kc_ar_post()) {
    $urval = kc_post('urval') === 'urval';
    $prov = kc_post('prov') === '1';
    $ramar = $prov || kc_post('ramar') === '1';
    $med_adress = kc_post('adress') === '1';
    $per_objekt = (int)kc_post('antal', '1');
    $start = (int)kc_post('start', '1');
    if ($start < 1 || $start > 24 || $per_objekt < 1 || $per_objekt > 4) {
        kc_flash('fel', 'Startpositionen ska vara 1–24 och antalet per objekt 1–4.');
        kc_omdirigera($sida);
    }

    $sql = "SELECT * FROM ($grund) x WHERE 1 = 1";
    $params = [$p['uuid']];
    if (kc_post('alla_objekt') !== '1') {
        $sql .= ' AND matpunkter > 0';
    }
    $lista = [];
    if ($urval) {
        if (kc_post('byggnad') !== '') {
            $sql .= ' AND byggnad = ?';
            $params[] = kc_post('byggnad');
        }
        $lista = array_values(array_unique(array_filter(
            preg_split('/[\s,;]+/u', kc_post('nummer')) ?: [], static fn($n) => $n !== '')));
        if (count($lista) > PRJ_OBJEKTETIKETTER_MAX) {
            kc_flash('fel', 'För många objektnummer i listan.');
            kc_omdirigera($sida);
        }
        if ($lista !== []) {
            $sql .= ' AND objektnummer IN (' . implode(',', array_fill(0, count($lista), '?')) . ')';
            array_push($params, ...$lista);
        }
    }
    $rader = kc_hamta_alla($sql . ' ORDER BY objektnummer', $params);

    if ($lista !== []) {
        $saknas = array_diff($lista, array_column($rader, 'objektnummer'));
        if ($saknas !== []) {
            kc_flash('fel', 'Dessa objektnummer finns inte i projektets objektlista (eller faller bort på övriga val): '
                . implode(', ', array_slice($saknas, 0, 10)) . (count($saknas) > 10 ? ' …' : '') . '. Inget skrevs ut.');
            kc_omdirigera($sida);
        }
    }
    if ($rader === []) {
        kc_flash('fel', 'Inga objekt matchar urvalet. Inget skrevs ut.');
        kc_omdirigera($sida);
    }
    if (count($rader) * $per_objekt > PRJ_OBJEKTETIKETTER_MAX) {
        kc_flash('fel', 'Urvalet ger ' . count($rader) * $per_objekt . ' etiketter; max ' . PRJ_OBJEKTETIKETTER_MAX
            . ' per PDF. Dela upp per byggnad eller med listan.');
        kc_omdirigera($sida);
    }

    $etiketter = [];
    foreach ($rader as $r) {
        for ($i = 0; $i < $per_objekt; $i++) {
            $etiketter[] = ['namn' => $r['objektnummer'], 'adress' => $med_adress ? $r['adress'] : ''];
        }
    }
    $data = prj_etiketter_pdf($etiketter, $start, $ramar, $prov, 'Objektetiketter ' . $p['projektnummer'], 14.0, 'PROV');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $tid = (new DateTimeImmutable('now', new DateTimeZone(KOLL_TIDSZON)))->format('Ymd-Hi');
    $fil = 'objektetiketter_' . preg_replace('/[^A-Za-z0-9_-]/', '', $p['projektnummer']) . ($prov ? '_prov' : '') . '_' . $tid . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fil . '"');
    header('Content-Length: ' . strlen($data));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $data;
    exit;
}

$per_byggnad = kc_hamta_alla("SELECT byggnad, COUNT(*) AS antal, SUM(CASE WHEN matpunkter > 0 THEN 1 ELSE 0 END) AS med_matpunkt
                                FROM ($grund) x GROUP BY byggnad ORDER BY byggnad", [$p['uuid']]);
$totalt = array_sum(array_column($per_byggnad, 'antal'));
$med_mp = array_sum(array_column($per_byggnad, 'med_matpunkt'));

kc_sidhuvud('Objektetiketter: ' . $p['projektnummer'] . ' ' . $p['namn']);
?>
<p><a href="<?= kc_h(kc_url('projekt.php?uuid=' . rawurlencode($p['uuid']))) ?>">« Till projektet</a> ·
   <a href="<?= kc_h(kc_url('etiketter.php?projekt=' . rawurlencode($p['uuid']))) ?>">Mätpunktsetiketter »</a></p>

<p><?= kc_h($totalt) ?> objekt i objektlistan, varav <?= kc_h($med_mp) ?> med minst en mätpunkt.</p>

<?php if ($per_byggnad !== []): ?>
<div class="kc-tabell-wrap" style="max-width:40rem"><table class="kc-tabell">
  <thead><tr><th>Byggnad</th><th>Objekt</th><th>Med mätpunkt</th></tr></thead>
  <tbody>
  <?php foreach ($per_byggnad as $r): ?>
    <tr><td><?= $r['byggnad'] !== '' ? kc_h($r['byggnad']) : '<span class="kc-dampad">(okänd)</span>' ?></td>
        <td><?= kc_h($r['antal']) ?></td><td><?= kc_h($r['med_matpunkt']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<?php if ($totalt === 0): ?>
  <p><em class="kc-dampad">Objektlistan är tom. Lägg till omfattning på projektet.</em></p>
<?php else: ?>
<form method="post" class="kc-ruta" style="max-width:52rem">
  <?= kc_csrf_falt() ?>
  <h2>Skriv ut objektetiketter</h2>
  <p class="kc-hjalp">A4-ark med 3 × 8 etiketter à 70 × 37 mm. QR-koden innehåller objektnumret och kan läsas i
     monteringskontrollen. Skriv ut i 100 % (inte "anpassa till sidan"). Utskriften ändrar och låser ingenting.</p>

  <h3>Innehåll</h3>
  <div class="kc-falt"><label><input type="checkbox" name="alla_objekt" value="1"> Ta med alla objekt i objektlistan
    <span class="kc-dampad">(annars bara objekt med minst en mätpunkt)</span></label></div>
  <div class="kc-falt"><label><input type="checkbox" name="adress" value="1"> Visa gatuadressen under objektnumret</label></div>
  <div class="kc-falt"><label for="antal">Etiketter per objekt</label>
    <select id="antal" name="antal"><?php for ($i = 1; $i <= 4; $i++): ?><option value="<?= $i ?>"><?= $i ?></option><?php endfor; ?></select></div>

  <h3>Urval <span class="kc-dampad">(gäller bara "Exportera urval")</span></h3>
  <div class="kc-falt"><label for="byggnad">Byggnad</label>
    <select id="byggnad" name="byggnad">
      <option value="">(alla)</option>
      <?php foreach ($per_byggnad as $r): if ($r['byggnad'] === '') { continue; } ?>
        <option value="<?= kc_h($r['byggnad']) ?>"><?= kc_h($r['byggnad']) ?> (<?= kc_h($r['antal']) ?>)</option>
      <?php endforeach; ?>
    </select></div>
  <div class="kc-falt"><label for="nummer">Bara dessa objekt (valfritt, ett objektnummer per rad)</label>
    <textarea id="nummer" name="nummer" rows="3" style="width:100%" placeholder="1301-01-0001"></textarea></div>

  <h3>Ark</h3>
  <div class="kc-falt"><label for="start">Första lediga etikett på första arket (1–24, räknat radvis från övre vänstra hörnet)</label>
    <input type="number" id="start" name="start" value="1" min="1" max="24" style="width:6rem"></div>
  <div class="kc-falt"><label><input type="checkbox" name="ramar" value="1"> Rita etikettkanter</label></div>
  <div class="kc-falt"><label><input type="checkbox" name="prov" value="1"> Provutskrift: kanter ritas och etiketterna märks PROV</label></div>

  <p>
    <button type="submit" name="urval" value="alla">Exportera alla</button>
    <button type="submit" name="urval" value="urval" class="kc-knapp-primar">Exportera urval</button>
  </p>
</form>
<?php endif; ?>
<?php kc_sidfot();
