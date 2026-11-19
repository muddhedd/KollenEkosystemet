<?php
/**
 * ProjektKollen – objektlista.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Projektets objektlista: alla objekt som omfattningen ger, med antal givare per objekt (0–9).
 * Filter på byggnad och objekttyp; högst 300 objekt per sida (gränsen för formulärfält).
 * "Sätt antal för hela urvalet" ändrar alla objekt som matchar filtret, på alla sidor.
 * Varje sparning genererar om mätpunktslistan (låsta namn ändras aldrig).
 * Läsning öppen för alla; ändring kräver inloggning. Parametrar: projekt, byggnad, typ, sida.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/prj.php';

const PRJ_OBJEKT_PER_SIDA = 300;

$inloggad = prj_inloggad() !== null;
$p = prj_projekt_hamta(kc_get('projekt'));
if ($p === null) {
    throw new KcFel('Projektet finns inte.', 404);
}
$kan_andra = $inloggad && !prj_projekt_last($p);
$tpo = kc_tabell('md_projektobjekt');
$to  = kc_tabell('ld_fas_objekt');
$tb  = kc_tabell('ld_fas_byggnad');
$tot = kc_tabell('ld_fas_objekttyp');
$ta  = kc_tabell('ld_fas_adress');
$tm  = kc_tabell('md_matpunkt');

$f_byggnad = kc_get('byggnad');
$f_typ     = kc_get('typ');
$sida      = max(1, (int)kc_get('sida', '1'));

/** Villkor för filtret (gemensamt för visning och "hela urvalet"). */
$villkor = 'po.projekt_uuid = ? AND po.is_deleted = 0';
$params = [$p['uuid']];
if ($f_byggnad !== '') {
    $villkor .= ' AND o.byggnad_uuid = ?';
    $params[] = $f_byggnad;
}
if ($f_typ !== '') {
    $villkor .= ' AND o.objekttyp_uuid = ?';
    $params[] = $f_typ;
}
$fran = "FROM `$tpo` po LEFT JOIN `$to` o ON o.uuid = po.objekt_uuid";
$sidlank = static fn(array $extra = []) => 'objektlista.php?' . http_build_query(array_filter(
    ['projekt' => $p['uuid'], 'byggnad' => $f_byggnad, 'typ' => $f_typ] + $extra, static fn($v) => $v !== '' && $v !== null));

/* ---------- Spara ---------- */

if (kc_ar_post()) {
    prj_krav_inloggning();
    if (!$kan_andra) {
        kc_flash('fel', 'Projektet är avslutat och kan inte ändras.');
        kc_omdirigera($sidlank());
    }
    $atgard = kc_post('atgard');
    $antal = [];
    if ($atgard === 'spara') {
        $in = $_POST['antal'] ?? [];
        foreach (is_array($in) ? $in : [] as $u => $v) {
            if (is_string($u) && kc_ar_uuid($u) && is_string($v) && preg_match('/^[0-9]$/', trim($v))) {
                $antal[$u] = (int)trim($v);
            }
        }
    } elseif ($atgard === 'satt_alla') {
        $v = kc_post('alla_antal');
        if (!preg_match('/^[0-9]$/', $v)) {
            kc_flash('fel', 'Antal ska vara 0–9.');
            kc_omdirigera($sidlank());
        }
        foreach (kc_hamta_alla("SELECT po.uuid $fran WHERE $villkor AND po.utanfor = 0", $params) as $r) {
            $antal[$r['uuid']] = (int)$v;
        }
    }
    $andrade = 0;
    $sum = null;
    kc_transaktion(function () use ($p, $antal, &$andrade, &$sum): void {
        $andrade = prj_objekt_antal_spara($p['uuid'], $antal);
        $sum = prj_matpunkter_generera($p);
    });
    kc_flash($andrade > 0 ? 'ok' : 'info', ($andrade > 0 ? "Antalet ändrades för $andrade objekt." : 'Inget antal ändrades.')
        . " Mätpunkter: {$sum['skapade']} nya, {$sum['andrade']} ändrade, {$sum['borttagna']} borttagna."
        . ($sum['over_antal'] > 0 ? " {$sum['over_antal']} objekt har fler låsta mätpunkter än antalet – de ligger kvar." : ''));
    kc_omdirigera($sidlank($atgard === 'spara' ? ['sida' => (string)$sida] : []));
}

/* ---------- Visning ---------- */

$totalt = (int)kc_hamta_varde("SELECT COUNT(*) $fran WHERE $villkor", $params);
$sidor = max(1, (int)ceil($totalt / PRJ_OBJEKT_PER_SIDA));
$sida = min($sida, $sidor);
$rader = kc_hamta_alla(
    "SELECT po.uuid, po.objektnummer, po.antal, po.utanfor, COALESCE(t.namn, '') AS typ, COALESCE(b.byggnadsnummer, '') AS byggnad,
            COALESCE(a.gatuadress, '') AS adress, o.vaningsplan, o.uuid AS objekt_finns,
            (SELECT GROUP_CONCAT(m.matpunktsnamn ORDER BY m.lopnummer SEPARATOR ', ') FROM `$tm` m
              WHERE m.projekt_uuid = po.projekt_uuid AND m.objekt_uuid = po.objekt_uuid AND m.is_deleted = 0) AS matpunkter,
            (SELECT COUNT(*) FROM `$tm` m2 WHERE m2.projekt_uuid = po.projekt_uuid AND m2.objekt_uuid = po.objekt_uuid
              AND m2.is_deleted = 0 AND m2.namn_last = 1) AS lasta
       $fran
       LEFT JOIN `$tb` b ON b.uuid = o.byggnad_uuid
       LEFT JOIN `$tot` t ON t.uuid = o.objekttyp_uuid
       LEFT JOIN `$ta` a ON a.uuid = o.adress_uuid
      WHERE $villkor
      ORDER BY po.objektnummer
      LIMIT " . PRJ_OBJEKT_PER_SIDA . ' OFFSET ' . (($sida - 1) * PRJ_OBJEKT_PER_SIDA),
    $params
);
// Filtervalen: byggnader och objekttyper som förekommer i projektet
$byggnader = array_column(kc_hamta_alla(
    "SELECT DISTINCT b.uuid, b.byggnadsnummer FROM `$tpo` po JOIN `$to` o ON o.uuid = po.objekt_uuid JOIN `$tb` b ON b.uuid = o.byggnad_uuid
      WHERE po.projekt_uuid = ? AND po.is_deleted = 0 ORDER BY b.byggnadsnummer", [$p['uuid']]), 'byggnadsnummer', 'uuid');
$typer = array_column(kc_hamta_alla(
    "SELECT DISTINCT t.uuid, t.namn FROM `$tpo` po JOIN `$to` o ON o.uuid = po.objekt_uuid JOIN `$tot` t ON t.uuid = o.objekttyp_uuid
      WHERE po.projekt_uuid = ? AND po.is_deleted = 0 ORDER BY t.namn", [$p['uuid']]), 'namn', 'uuid');

kc_sidhuvud('Objektlista: ' . $p['projektnummer'] . ' ' . $p['namn']);
?>
<p><a href="<?= kc_h(kc_url('projekt.php?uuid=' . rawurlencode($p['uuid']))) ?>">« Till projektet</a> ·
   <a href="<?= kc_h(kc_url('matpunktslista.php?projekt=' . rawurlencode($p['uuid']))) ?>">Mätpunktslista »</a></p>

<form method="get" class="kc-ruta" style="max-width:60rem">
  <input type="hidden" name="projekt" value="<?= kc_h($p['uuid']) ?>">
  <label for="byggnad" style="display:inline">Byggnad</label>
  <select id="byggnad" name="byggnad"><?= prj_alternativ($byggnader, $f_byggnad, '– alla –') ?></select>
  <label for="typ" style="display:inline;margin-left:1rem">Objekttyp</label>
  <select id="typ" name="typ"><?= prj_alternativ($typer, $f_typ, '– alla –') ?></select>
  <button type="submit">Filtrera</button>
  <span class="kc-dampad" style="margin-left:1rem"><?= kc_h($totalt) ?> objekt i urvalet</span>
</form>

<?php if ($kan_andra && $totalt > 0): ?>
<form method="post" class="kc-ruta" style="max-width:60rem">
  <?= kc_csrf_falt() ?>
  <input type="hidden" name="atgard" value="satt_alla">
  <label for="alla_antal" style="display:inline">Sätt antal givare för hela urvalet (<?= kc_h($totalt) ?> objekt)</label>
  <select id="alla_antal" name="alla_antal"><?php for ($i = 0; $i <= 9; $i++): ?><option value="<?= $i ?>" <?= $i === 1 ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?></select>
  <button type="submit">Sätt</button>
  <div class="kc-hjalp">T.ex. filtrera på objekttypen Lokal och sätt 0.</div>
</form>
<?php endif; ?>

<?php if ($rader === []): ?>
  <p><em class="kc-dampad">Inga objekt. Lägg till fastigheter, byggnader, objekt eller objektgrupper i projektets omfattning.</em></p>
<?php else: ?>
<form method="post">
  <?= kc_csrf_falt() ?>
  <input type="hidden" name="atgard" value="spara">
  <div class="kc-tabell-wrap"><table class="kc-tabell">
    <thead><tr><th>Objekt</th><th>Typ</th><th>Byggnad</th><th>Adress</th><th>Plan</th><th>Antal</th><th>Mätpunkter</th></tr></thead>
    <tbody>
    <?php foreach ($rader as $r): ?>
      <tr>
        <td><?= kc_h($r['objektnummer']) ?><?= $r['objekt_finns'] === null ? ' <strong style="color:var(--kc-fel)">saknas</strong>' : '' ?></td>
        <td><?= kc_h($r['typ']) ?></td>
        <td><?= kc_h($r['byggnad']) ?></td>
        <td><?= kc_h($r['adress']) ?></td>
        <td><?= kc_h($r['vaningsplan'] ?? '') ?></td>
        <td>
          <?php if ((int)$r['utanfor'] === 1): ?>
            <span class="kc-dampad">utanför omfattningen</span>
          <?php elseif ($kan_andra): ?>
            <select name="antal[<?= kc_h($r['uuid']) ?>]" aria-label="Antal för <?= kc_h($r['objektnummer']) ?>">
              <?php for ($i = 0; $i <= 9; $i++): ?><option value="<?= $i ?>" <?= $i === (int)$r['antal'] ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?>
            </select>
          <?php else: ?>
            <?= kc_h($r['antal']) ?>
          <?php endif; ?>
          <?= (int)$r['lasta'] > 0 ? '<span class="kc-dampad" title="låsta namn">(' . kc_h($r['lasta']) . ' låsta)</span>' : '' ?>
        </td>
        <td><?= kc_h($r['matpunkter'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($kan_andra): ?>
    <p style="margin-top:.75rem"><button type="submit" class="kc-knapp-primar">Spara antal</button>
      <span class="kc-hjalp">Mätpunktslistan genereras om. Låsta namn ändras aldrig.</span></p>
  <?php endif; ?>
</form>
<?php if ($sidor > 1): ?>
  <p>Sida:
    <?php for ($i = 1; $i <= $sidor; $i++): ?>
      <?= $i === $sida ? '<strong>' . $i . '</strong>' : '<a href="' . kc_h(kc_url($sidlank(['sida' => (string)$i]))) . '">' . $i . '</a>' ?>
    <?php endfor; ?>
    <span class="kc-hjalp">Spara innan du byter sida.</span></p>
<?php endif; ?>
<?php endif; ?>
<?php kc_sidfot();
