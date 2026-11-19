<?php
/**
 * ProjektKollen – etiketter.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Etiketter för projektets mätpunkter som PDF: A4-ark med 3 × 8 etiketter à 70 × 37 mm,
 * QR-kod med mätpunktsnamnet; klartext: mätpunktsnamn, objektnummer och adress. Ingen extern kod (prj_qr.php, prj_pdf.php).
 *
 * "Exportera alla" tar alla mätpunkter; "Exportera urval" filtrerar på byggnad, ej utskrivna
 * och/eller en lista med namn. Utskrift låser namnen och sätter etikett_tid. Provutskrift
 * ritar etikettkanter, märks PROV och låser inget.
 * Läsning öppen för alla; utskrift kräver inloggning (POST). Parameter: projekt.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/prj.php';
require __DIR__ . '/prj_qr.php';
require __DIR__ . '/prj_pdf.php';

const PRJ_ETIKETTER_MAX = 2400;   // 100 ark per PDF

$inloggad = prj_inloggad() !== null;
$p = prj_projekt_hamta(kc_get('projekt'));
if ($p === null) {
    throw new KcFel('Projektet finns inte.', 404);
}
$kan_andra = $inloggad && !prj_projekt_last($p);
$sida = 'etiketter.php?projekt=' . rawurlencode($p['uuid']);

$tm = kc_tabell('md_matpunkt');
$to = kc_tabell('ld_fas_objekt');
$tb = kc_tabell('ld_fas_byggnad');
$ta = kc_tabell('ld_fas_adress');
$grund = "SELECT m.uuid, m.matpunktsnamn, m.objektnummer, m.etikett_tid, COALESCE(b.byggnadsnummer, '') AS byggnad,
                 COALESCE(a.gatuadress, '') AS adress
            FROM `$tm` m
            LEFT JOIN `$to` o ON o.uuid = m.objekt_uuid
            LEFT JOIN `$tb` b ON b.uuid = o.byggnad_uuid
            LEFT JOIN `$ta` a ON a.uuid = o.adress_uuid
           WHERE m.projekt_uuid = ? AND m.is_deleted = 0 AND m.matpunktsnamn <> ''";

if (kc_ar_post()) {
    prj_krav_inloggning();
    if (!$kan_andra) {
        kc_flash('fel', 'Projektet är avslutat. Etiketter skrivs inte ut för avslutade projekt.');
        kc_omdirigera($sida);
    }
    $urval = kc_post('urval') === 'urval';
    $prov = kc_post('prov') === '1';
    $ramar = $prov || kc_post('ramar') === '1';
    $start = (int)kc_post('start', '1');
    if ($start < 1 || $start > 24) {
        kc_flash('fel', 'Startpositionen ska vara 1–24.');
        kc_omdirigera($sida);
    }

    $sql = $grund;
    $params = [$p['uuid']];
    $namnlista = [];
    if ($urval) {
        if (kc_post('byggnad') !== '') {
            $sql .= " AND COALESCE(b.byggnadsnummer, '') = ?";
            $params[] = kc_post('byggnad');
        }
        if (kc_post('ej_utskrivna') === '1') {
            $sql .= ' AND m.etikett_tid IS NULL';
        }
        $namnlista = array_values(array_unique(array_filter(
            preg_split('/[\s,;]+/u', kc_post('namn')) ?: [], static fn($n) => $n !== '')));
        if (count($namnlista) > PRJ_ETIKETTER_MAX) {
            kc_flash('fel', 'För många namn i listan (max ' . PRJ_ETIKETTER_MAX . ').');
            kc_omdirigera($sida);
        }
        if ($namnlista !== []) {
            $sql .= ' AND m.matpunktsnamn IN (' . implode(',', array_fill(0, count($namnlista), '?')) . ')';
            array_push($params, ...$namnlista);
        }
    }
    $rader = kc_hamta_alla($sql . ' ORDER BY m.matpunktsnamn', $params);

    if ($namnlista !== []) {
        $saknas = array_diff($namnlista, array_column($rader, 'matpunktsnamn'));
        if ($saknas !== []) {
            kc_flash('fel', 'Dessa namn finns inte i projektet (eller faller bort på övriga val): '
                . implode(', ', array_slice($saknas, 0, 10)) . (count($saknas) > 10 ? ' …' : '') . '. Inget skrevs ut.');
            kc_omdirigera($sida);
        }
    }
    if ($rader === []) {
        kc_flash('fel', 'Inga mätpunkter matchar urvalet. Inget skrevs ut.');
        kc_omdirigera($sida);
    }
    if (count($rader) > PRJ_ETIKETTER_MAX) {
        kc_flash('fel', 'Urvalet ger ' . count($rader) . ' etiketter; max ' . PRJ_ETIKETTER_MAX
            . ' per PDF. Dela upp per byggnad eller med namnlistan.');
        kc_omdirigera($sida);
    }

    // Bygg PDF:en först, så att inget låses om något går fel.
    $etiketter = array_map(static fn($r) => ['namn' => $r['matpunktsnamn'], 'objektnummer' => $r['objektnummer'], 'adress' => $r['adress']], $rader);
    $data = prj_etiketter_pdf($etiketter, $start, $ramar, $prov, 'Etiketter ' . $p['projektnummer']);

    if (!$prov) {
        $uuids = array_column($rader, 'uuid');
        kc_transaktion(static function () use ($p, $uuids, $tm): void {
            prj_matpunkter_las($p['uuid'], $uuids);
            $nu = kc_nu();
            foreach (array_chunk($uuids, 500) as $del) {
                kc_kor("UPDATE `$tm` SET etikett_tid = ?, last_changed = ?, source_tool = 'prj'
                         WHERE projekt_uuid = ? AND is_deleted = 0 AND uuid IN (" . implode(',', array_fill(0, count($del), '?')) . ')',
                    [$nu, $nu, $p['uuid'], ...$del]);
            }
        });
    }

    if (headers_sent()) {
        throw new KcFel('PDF:en kunde inte skickas eftersom sidan redan skickat innehåll.', 500);
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $tid = (new DateTimeImmutable('now', new DateTimeZone(KOLL_TIDSZON)))->format('Ymd-Hi');
    $fil = 'etiketter_' . preg_replace('/[^A-Za-z0-9_-]/', '', $p['projektnummer']) . ($prov ? '_prov' : '') . '_' . $tid . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fil . '"');
    header('Content-Length: ' . strlen($data));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $data;
    exit;
}

// Översikt per byggnad
$per_byggnad = kc_hamta_alla("SELECT byggnad, COUNT(*) AS antal, SUM(CASE WHEN etikett_tid IS NULL THEN 1 ELSE 0 END) AS ej_utskrivna,
                                     MAX(etikett_tid) AS senast
                                FROM ($grund) x GROUP BY byggnad ORDER BY byggnad", [$p['uuid']]);
$totalt = array_sum(array_column($per_byggnad, 'antal'));
$ej_utskrivna = array_sum(array_column($per_byggnad, 'ej_utskrivna'));

kc_sidhuvud('Etiketter: ' . $p['projektnummer'] . ' ' . $p['namn']);
?>
<p><a href="<?= kc_h(kc_url('projekt.php?uuid=' . rawurlencode($p['uuid']))) ?>">« Till projektet</a> ·
   <a href="<?= kc_h(kc_url('matpunktslista.php?projekt=' . rawurlencode($p['uuid']))) ?>">Mätpunktslista »</a></p>

<p><?= kc_h($totalt) ?> mätpunkter, varav <?= kc_h($ej_utskrivna) ?> utan utskriven etikett.</p>

<?php if ($per_byggnad !== []): ?>
<div class="kc-tabell-wrap" style="max-width:52rem"><table class="kc-tabell">
  <thead><tr><th>Byggnad</th><th>Mätpunkter</th><th>Ej utskrivna</th><th>Senast utskrivet</th></tr></thead>
  <tbody>
  <?php foreach ($per_byggnad as $r): ?>
    <tr>
      <td><?= $r['byggnad'] !== '' ? kc_h($r['byggnad']) : '<span class="kc-dampad">(okänd)</span>' ?></td>
      <td><?= kc_h($r['antal']) ?></td>
      <td><?= kc_h($r['ej_utskrivna']) ?></td>
      <td><?= $r['senast'] !== null ? kc_h(kc_tid($r['senast'])) : '' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<?php if (!$inloggad): ?>
  <p><em class="kc-dampad">Logga in för att skriva ut etiketter.</em></p>
<?php elseif (!$kan_andra): ?>
  <p><em class="kc-dampad">Projektet är avslutat. Etiketter skrivs inte ut för avslutade projekt.</em></p>
<?php elseif ($totalt === 0): ?>
  <p><em class="kc-dampad">Inga mätpunkter än. Sätt antal givare i objektlistan.</em></p>
<?php else: ?>
<form method="post" class="kc-ruta" style="max-width:52rem">
  <?= kc_csrf_falt() ?>
  <h2>Skriv ut etiketter</h2>
  <p class="kc-hjalp">A4-ark med 3 × 8 etiketter à 70 × 37 mm. Skriv ut i 100 % (inte "anpassa till sidan").
     Utskrift låser mätpunktsnamnen – de räknas inte om längre när antalet givare ändras.</p>

  <h3>Urval <span class="kc-dampad">(gäller bara "Exportera urval")</span></h3>
  <div class="kc-falt">
    <label for="byggnad">Byggnad</label>
    <select id="byggnad" name="byggnad">
      <option value="">(alla)</option>
      <?php foreach ($per_byggnad as $r): if ($r['byggnad'] === '') { continue; } ?>
        <option value="<?= kc_h($r['byggnad']) ?>"><?= kc_h($r['byggnad']) ?> (<?= kc_h($r['antal']) ?>)</option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="kc-falt">
    <label><input type="checkbox" name="ej_utskrivna" value="1" checked> Bara mätpunkter utan utskriven etikett</label>
  </div>
  <div class="kc-falt">
    <label for="namn">Bara dessa mätpunkter (valfritt, ett namn per rad)</label>
    <textarea id="namn" name="namn" rows="3" style="width:100%" placeholder="1301-01-0001_Temp_11"></textarea>
    <div class="kc-hjalp">T.ex. för att skriva ut en skadad etikett igen. Avbocka då rutan ovan.</div>
  </div>

  <h3>Ark</h3>
  <div class="kc-falt">
    <label for="start">Första lediga etikett på första arket (1–24, räknat radvis från övre vänstra hörnet)</label>
    <input type="number" id="start" name="start" value="1" min="1" max="24" style="width:6rem">
  </div>
  <div class="kc-falt">
    <label><input type="checkbox" name="ramar" value="1"> Rita etikettkanter</label>
  </div>
  <div class="kc-falt">
    <label><input type="checkbox" name="prov" value="1"> Provutskrift: kanter ritas, etiketterna märks PROV och inget låses</label>
    <div class="kc-hjalp">Skriv ut på vanligt papper och lägg mot ett etikettark i motljus för att kontrollera passningen.</div>
  </div>

  <p>
    <button type="submit" name="urval" value="alla">Exportera alla (<?= kc_h($totalt) ?>)</button>
    <button type="submit" name="urval" value="urval" class="kc-knapp-primar">Exportera urval</button>
  </p>
</form>
<?php endif; ?>
<?php kc_sidfot();
