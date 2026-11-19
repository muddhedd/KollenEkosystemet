<?php
/**
 * MätarKollen – synk.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Kopplingen till CentralKollen: testa anslutning, hämta användare, hämta fastighetsdata
 * (prenumerationer), skicka ändringar (från etapp 2), synkstatus.
 * Hämtning (pull) får göras av alla, även icke inloggade.
 * Skicka (push) och användarlistan kräver inloggning.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/mat.php';

$inloggad = mat_inloggad() !== null;

if (kc_ar_post()) {
    $atgard = kc_post('atgard');
    try {
        if ($atgard === 'ping') {
            $d = kc_cen_anrop('ping', [], 10);
            kc_flash(
                ($d['aktiv'] ?? false) ? 'ok' : 'info',
                'CentralKollen svarar. Verktyget är ' . (($d['aktiv'] ?? false) ? 'aktivt' : 'INAKTIVT – aktivera det i CentralKollen') . '.'
            );
        } elseif ($atgard === 'push') {
            mat_krav_inloggning();
            $nya_nummer = mat_placering_nummer_uppdatera();   // placeringsnummer som ändrats i FastighetsKollen
            $res = kc_push_alla(mat_delade_tabeller());
            $skickade = $sparade = $ignorerade = 0;
            $fel = [];
            $mer = false;
            foreach ($res as $namn => $r) {
                if (isset($r['fel'])) {
                    $fel[] = "$namn: " . $r['fel'];
                    continue;
                }
                $skickade += $r['skickade'];
                $sparade += $r['sparade'];
                $ignorerade += $r['ignorerade'];
                $mer = $mer || $r['mer'];
            }
            $ok_text = $skickade === 0
                ? 'Inget att skicka – CentralKollen är aktuell.'
                : "Skickade $skickade rader: $sparade nya eller ändrade i CentralKollen, $ignorerade redan aktuella."
                  . ($mer ? ' Det finns fler – tryck igen.' : '')
                  . ($nya_nummer > 0 ? " ($nya_nummer mätpunkter fick uppdaterat placeringsnummer från FastighetsKollen.)" : '');
            if ($fel !== []) {
                kc_flash('fel', count($fel) . ' tabeller kunde inte skickas: ' . implode(' | ', $fel));
                if ($skickade > 0) {
                    kc_flash('info', $ok_text);
                }
            } else {
                kc_flash('ok', $ok_text);
            }
        } elseif ($atgard === 'pull') {
            if (kc_synk_for_tat('fas_fastighet', 'pull')) {
                kc_flash('info', 'Fastighetsdatan hämtades nyss. Vänta några sekunder och försök igen.');
            } else {
                $res = kc_pull_alla(mat_prenumerationer());
                $antal = 0;
                $fel = [];
                foreach ($res as $n => $r) {
                    if (is_array($r)) {
                        $fel[] = "$n: " . $r['fel'];
                    } else {
                        $antal += $r;
                    }
                }
                if ($fel !== []) {
                    kc_flash('fel', count($fel) . ' tabeller kunde inte hämtas: ' . implode(' | ', $fel));
                }
                if ($fel === [] || $antal > 0) {
                    kc_flash($fel === [] ? 'ok' : 'info', $antal === 0 ? 'Fastighetsdatan är redan aktuell.' : "Hämtade $antal ändrade rader från CentralKollen.");
                }
            }
        } elseif ($atgard === 'anvandare') {
            if (kc_synk_for_tat('cen_anvandare', 'pull')) {
                kc_flash('info', 'Användarna hämtades nyss. Vänta några sekunder och försök igen.');
            } else {
                $n = kc_anvandare_synka();
                kc_flash('ok', $n === 0 ? 'Användarna är redan aktuella.' : "Hämtade $n ändrade användare från CentralKollen.");
            }
        }
    } catch (KcFel $e) {
        kc_flash('fel', $e->getMessage());
    }
    kc_omdirigera('synk.php');
}

$ts = kc_tabell('sys_synkstatus');
$status = kc_hamta_alla("SELECT * FROM `$ts` WHERE riktning = 'pull' AND tabell = 'cen_anvandare'");
$delade = mat_delade_tabeller();
$push = [];
$push_totalt = 0;
foreach ($delade as $namn => $def) {
    $n = kc_push_osynkade($namn, $def['tabell']);
    $push_totalt += $n;
    $push[$namn] = ['osynkade' => $n, 'status' => kc_synk_status($namn, 'push')];
}
$pren = mat_prenumerationer();
$antal_anv = (int)kc_hamta_varde('SELECT COUNT(*) FROM `' . kc_tabell('ld_cen_anvandare') . '` WHERE is_deleted = 0');

kc_sidhuvud('Synk');
?>
<div class="kc-ruta">
  <h2>Koppling till CentralKollen</h2>
  <?php if (!kc_cen_konfigurerad()): ?>
    <div class="kc-flash kc-flash-fel">
      Kopplingen är inte konfigurerad. Fyll i <code>CEN_URL</code> och <code>NYCKEL_TILL_CEN</code> i
      <code>config.php</code> – raderna finns på MätarKollens sida i CentralKollen.
    </div>
  <?php else: ?>
    <p>CentralKollen: <code><?= kc_h(CEN_URL) ?></code></p>
  <?php endif; ?>
  <form method="post" class="kc-inline"><?= kc_csrf_falt() ?><input type="hidden" name="atgard" value="ping">
    <button type="submit">Testa anslutningen</button></form>
  <form method="post" class="kc-inline"><?= kc_csrf_falt() ?><input type="hidden" name="atgard" value="anvandare">
    <button type="submit" class="kc-knapp-primar">Hämta användare</button></form>
</div>

<div class="kc-ruta">
  <h2>Hämta fastighetsdata (pull)</h2>
  <p>MätarKollen prenumererar på <?= kc_h(count($pren)) ?> tabeller från FastighetsKollen. Hämtningen får göras av alla.</p>
  <form method="post" class="kc-inline"><?= kc_csrf_falt() ?><input type="hidden" name="atgard" value="pull">
    <button type="submit" class="kc-knapp-primar">Hämta från CentralKollen</button></form>
  <div class="kc-tabell-wrap" style="margin-top:.75rem"><table class="kc-tabell">
    <thead><tr><th>Tabell</th><th>Rader lokalt</th><th>Status</th><th>Senast OK</th><th>Seq</th><th>Meddelande</th></tr></thead>
    <tbody>
    <?php foreach ($pren as $n => $p):
        $s = kc_synk_status($n, 'pull'); ?>
      <tr>
        <td><?= kc_h($p['rubrik']) ?> <code class="kc-dampad"><?= kc_h($n) ?></code></td>
        <td><?= kc_h(mat_ld_antal($p['ld'])) ?></td>
        <td><?php if ($s === null): ?><em class="kc-dampad">aldrig</em>
            <?php elseif ((int)$s['status'] === 200): ?><strong style="color:var(--kc-ok)">OK</strong>
            <?php else: ?><strong style="color:var(--kc-fel)">Fel <?= kc_h($s['status']) ?></strong><?php endif; ?></td>
        <td><?= $s !== null && $s['senaste_ok_tid'] ? kc_h(kc_tid($s['senaste_ok_tid'], 'Y-m-d H:i:s')) : '' ?></td>
        <td><?= $s !== null ? kc_h($s['senaste_seq']) : '' ?></td>
        <td><?= $s === null || $s['meddelande'] === 'ok' ? '' : kc_h($s['meddelande']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="kc-hjalp">Tabellerna måste vara valda som prenumerationer för MätarKollen i CentralKollen,
     och FastighetsKollen måste ha skickat dem (push).</p>
</div>

<?php if ($delade !== []): ?>
<div class="kc-ruta">
  <h2>Skicka till CentralKollen (push)</h2>
  <p>MätarKollen delar <?= kc_h(count($delade)) ?> tabeller.
     <?= $push_totalt === 0 ? 'Alla ändringar är skickade.' : '<strong>' . kc_h($push_totalt) . ' ändrade rader</strong> är inte skickade än.' ?></p>
  <?php if ($inloggad): ?>
    <form method="post" class="kc-inline"><?= kc_csrf_falt() ?><input type="hidden" name="atgard" value="push">
      <button type="submit" class="kc-knapp-primar">Skicka ändringar</button></form>
  <?php else: ?>
    <p class="kc-dampad">Logga in för att skicka ändringar.</p>
  <?php endif; ?>
  <div class="kc-tabell-wrap" style="margin-top:.75rem"><table class="kc-tabell">
    <thead><tr><th>Tabell</th><th>Ej skickade</th><th>Status</th><th>Senast OK</th><th>Senaste skickade ändring</th><th>Meddelande</th></tr></thead>
    <tbody>
    <?php foreach ($push as $namn => $p):
        $s = $p['status']; ?>
      <tr>
        <td><code><?= kc_h($namn) ?></code></td>
        <td><?= $p['osynkade'] > 0 ? '<strong>' . kc_h($p['osynkade']) . '</strong>' : '0' ?></td>
        <td><?php if ($s === null): ?><em class="kc-dampad">aldrig</em>
            <?php elseif ((int)$s['status'] === 200): ?><strong style="color:var(--kc-ok)">OK</strong>
            <?php else: ?><strong style="color:var(--kc-fel)">Fel <?= kc_h($s['status']) ?></strong><?php endif; ?></td>
        <td><?= $s !== null && $s['senaste_ok_tid'] ? kc_h(kc_tid($s['senaste_ok_tid'], 'Y-m-d H:i:s')) : '' ?></td>
        <td><?= $s !== null && !empty($s['senaste_lc']) && !str_starts_with((string)$s['senaste_lc'], '1970')
                ? kc_h(kc_tid($s['senaste_lc'], 'Y-m-d H:i:s')) : '' ?></td>
        <td><?= $s === null || $s['meddelande'] === 'ok' ? '' : kc_h($s['meddelande']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="kc-hjalp">Tabellerna måste vara registrerade och aktiva för MätarKollen i CentralKollen.
     Raderade rader skickas också, så att raderingen når alla som prenumererar.</p>
</div>
<?php endif; ?>

<div class="kc-ruta">
  <h2>Hämtning av användare</h2>
  <?php if ($status === []): ?>
    <p><em class="kc-dampad">Användarna har inte hämtats ännu.</em></p>
  <?php else: ?>
    <div class="kc-tabell-wrap"><table class="kc-tabell">
      <thead><tr><th>Data</th><th>Riktning</th><th>Status</th><th>Senaste försök</th><th>Senast OK</th><th>Rader</th><th>Seq</th><th>Meddelande</th></tr></thead>
      <tbody>
      <?php foreach ($status as $s): ?>
        <tr>
          <td><code><?= kc_h($s['tabell']) ?></code></td>
          <td><?= $s['riktning'] === 'push' ? 'push ↑' : 'pull ↓' ?></td>
          <td><?= (int)$s['status'] === 200
                ? '<strong style="color:var(--kc-ok)">OK</strong>'
                : '<strong style="color:var(--kc-fel)">Fel ' . kc_h($s['status']) . '</strong>' ?></td>
          <td><?= kc_h(kc_tid($s['senaste_tid'], 'Y-m-d H:i:s')) ?></td>
          <td><?= $s['senaste_ok_tid'] ? kc_h(kc_tid($s['senaste_ok_tid'], 'Y-m-d H:i:s')) : '<em class="kc-dampad">aldrig</em>' ?></td>
          <td><?= kc_h($s['antal']) ?></td>
          <td><?= kc_h($s['senaste_seq']) ?></td>
          <td><?= $s['meddelande'] === 'ok' ? '' : kc_h($s['meddelande']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>

<h2>Användare med behörighet till MätarKollen (<?= kc_h($antal_anv) ?>)</h2>
<?php if ($inloggad): ?>
  <?= kc_lista([
      'tabell'            => kc_tabell('ld_cen_anvandare'),
      'kolumner'          => ['anvandarnamn' => 'Användarnamn', 'fornamn' => 'Förnamn', 'efternamn' => 'Efternamn', 'is_active' => 'Status'],
      'villkor'           => 'is_deleted = 0',
      'standardsortering' => 'anvandarnamn',
      'format'            => ['is_active' => fn($v) => (int)$v === 1 ? 'Aktiv' : '<strong>Spärrad</strong>'],
      'export'            => false,
      'tom_text'          => 'Inga användare hämtade ännu.',
  ]) ?>
  <p class="kc-hjalp">Användare och behörigheter administreras i CentralKollen. Den här listan är en kopia.</p>
<?php else: ?>
  <p class="kc-dampad">Listan visas för inloggade användare.</p>
<?php endif; ?>
<?php kc_sidfot();
