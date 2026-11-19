<?php
/**
 * FastighetsKollen – objektgrupp.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * En objektgrupp: uppgifter, egenskaper, kopplingar och alla objekt som omfattas.
 * Läsning öppen för alla. Att lägga till och ta bort kopplingar kräver inloggning.
 * Kopplingar läggs till med nummer (fastighets-, byggnads- eller objektnummer),
 * ett eller flera åt gången, och tas bort genom att markera dem ("Ta bort markerade").
 * En borttagen koppling återaktiveras med samma uuid. Import av kopplingar: import.php?tabell=koppling.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/fas.php';

$inloggad = fas_inloggad() !== null;
$uuid = kc_get('uuid');
$g = fas_objektgrupp_hamta($uuid);
if ($g === null) {
    throw new KcFel('Objektgruppen finns inte eller har raderats.', 404);
}
$tk = kc_tabell('md_objektgrupp_koppling');
$sida = 'objektgrupp.php?uuid=' . rawurlencode($uuid);

$ny = ['kopplingstyp' => 'anvandare', 'maltyp' => 'byggnad', 'nummer' => ''];
$fel = [];

/* ---------- Ta bort markerade kopplingar ---------- */

if (kc_ar_post() && kc_post('atgard') === 'ta_bort_markerade') {
    fas_krav_inloggning();
    $valda = $_POST['kopplingar'] ?? [];
    $valda = is_array($valda) ? array_values(array_unique(array_filter($valda, static fn($v) => is_string($v) && kc_ar_uuid($v)))) : [];
    if ($valda === []) {
        kc_flash('info', 'Inga kopplingar var markerade.');
        kc_omdirigera($sida);
    }
    $n = 0;
    kc_transaktion(function () use ($tk, $uuid, $valda, &$n): void {
        $nu = kc_nu();
        foreach ($valda as $k) {
            $n += kc_kor(
                "UPDATE `$tk` SET is_deleted = 1, last_changed = ?, source_tool = 'fas'
                  WHERE uuid = ? AND objektgrupp_uuid = ? AND is_deleted = 0",
                [$nu, $k, $uuid]
            );
        }
    });
    kc_flash($n > 0 ? 'ok' : 'info', $n > 0 ? "Tog bort $n kopplingar." : 'Kopplingarna fanns inte längre.');
    kc_omdirigera($sida);
}

/* ---------- Lägg till kopplingar ---------- */

if (kc_ar_post() && kc_post('atgard') === 'lagg_till') {
    fas_krav_inloggning();
    foreach (array_keys($ny) as $k) {
        $ny[$k] = kc_post($k);
    }
    if (!array_key_exists($ny['kopplingstyp'], FAS_KOPPLINGSTYPER)) {
        $fel[] = 'Välj om målet är resurs eller användare.';
    }
    if (!array_key_exists($ny['maltyp'], FAS_MALTYPER)) {
        $fel[] = 'Välj fastighet, byggnad eller objekt.';
    }
    $nummer = array_values(array_unique(array_filter(
        array_map('trim', preg_split('/[,;\r\n]+/', $ny['nummer']) ?: []),
        static fn($s) => $s !== ''
    )));
    if ($nummer === []) {
        $fel[] = 'Ange minst ett nummer.';
    } elseif (count($nummer) > 500) {
        $fel[] = 'Högst 500 nummer åt gången.';
    }

    $mal = [];
    if ($fel === []) {
        $saknas = [];
        foreach ($nummer as $nr) {
            $m = fas_mal_hitta($ny['maltyp'], $nr);
            if ($m === null) {
                $saknas[] = $nr;
            } else {
                $mal[$m] = $nr;
            }
        }
        if ($saknas !== []) {
            $fel[] = 'Hittar ingen ' . mb_strtolower(FAS_MALTYPER[$ny['maltyp']]['etikett']) . ' med nummer: '
                   . implode(', ', $saknas) . '. Inget sparades.';
        }
    }

    if ($fel === []) {
        $res = ['nya' => 0, 'ateraktiverade' => 0, 'fanns' => 0];
        kc_transaktion(function () use ($tk, $uuid, $ny, $mal, &$res): void {
            $nu = kc_nu();
            foreach (array_keys($mal) as $mal_uuid) {
                $befintlig = kc_hamta_en(
                    "SELECT uuid, is_deleted FROM `$tk`
                      WHERE objektgrupp_uuid = ? AND maltyp = ? AND mal_uuid = ? AND kopplingstyp = ?
                      ORDER BY is_deleted ASC, id ASC LIMIT 1",
                    [$uuid, $ny['maltyp'], $mal_uuid, $ny['kopplingstyp']]
                );
                if ($befintlig === null) {
                    kc_kor(
                        "INSERT INTO `$tk` (uuid, last_changed, is_deleted, source_tool, objektgrupp_uuid, maltyp, mal_uuid, kopplingstyp)
                         VALUES (?, ?, 0, 'fas', ?, ?, ?, ?)",
                        [kc_uuid(), $nu, $uuid, $ny['maltyp'], $mal_uuid, $ny['kopplingstyp']]
                    );
                    $res['nya']++;
                } elseif ((int)$befintlig['is_deleted'] === 1) {
                    kc_kor("UPDATE `$tk` SET is_deleted = 0, last_changed = ?, source_tool = 'fas' WHERE uuid = ?", [$nu, $befintlig['uuid']]);
                    $res['ateraktiverade']++;
                } else {
                    $res['fanns']++;
                }
            }
        });
        $delar = [];
        if ($res['nya'] + $res['ateraktiverade'] > 0) {
            $delar[] = 'Lade till ' . ($res['nya'] + $res['ateraktiverade']) . ' kopplingar';
        }
        if ($res['fanns'] > 0) {
            $delar[] = $res['fanns'] . ' fanns redan';
        }
        kc_flash('ok', implode(', ', $delar) . '.');
        kc_omdirigera($sida);
    }
}

/* ---------- Visning ---------- */

$kopplingar = fas_objektgrupp_kopplingar($uuid);
$omfattar   = fas_objektgrupp_omfattar($uuid);
$antal = ['resurs' => 0, 'anvandare' => 0];
foreach ($omfattar as $o) {
    $antal[$o['kopplingstyp']] = ($antal[$o['kopplingstyp']] ?? 0) + 1;
}
$visa_max = 200;

kc_sidhuvud('Objektgrupp: ' . trim($g['objektgruppsnummer'] . ' ' . $g['namn']));
?>
<p><a href="<?= kc_h(kc_url('objektgrupper.php')) ?>">« Alla objektgrupper</a></p>

<div class="kc-ruta" style="max-width:48rem">
  <h2>Uppgifter</h2>
  <div class="kc-tabell-wrap"><table class="kc-tabell"><tbody>
    <tr><th>Objektgruppsnummer</th><td><?= kc_h($g['objektgruppsnummer']) ?></td></tr>
    <tr><th>Namn</th><td><?= kc_h($g['namn']) ?></td></tr>
    <tr><th>Typ</th><td><?= kc_h(fas_typ_namn('objektgruppstyp', $g['objektgruppstyp_uuid'])) ?></td></tr>
    <tr><th>Beskrivning</th><td><?= kc_h($g['beskrivning']) ?></td></tr>
    <tr><th>Senast ändrad</th><td><?= kc_h(kc_tid($g['last_changed'])) ?></td></tr>
  </tbody></table></div>
  <?php if ($inloggad): ?>
    <p style="margin-top:.75rem"><a class="kc-knapp" href="<?= kc_h(kc_url('objektgrupp_redigera.php?uuid=' . rawurlencode($uuid))) ?>">Ändra / radera</a></p>
  <?php endif; ?>
</div>

<?= fas_egenskaper_ruta('objektgrupp', $uuid, $inloggad) ?>

<div class="kc-ruta">
  <h2>Kopplingar</h2>
  <?php if ($kopplingar === []): ?>
    <p><em class="kc-dampad">Inga kopplingar än.</em></p>
  <?php else: ?>
    <?php if ($inloggad): ?>
    <form method="post" id="fas_kopplingar"
          onsubmit="var n=this.querySelectorAll('input[name=&quot;kopplingar[]&quot;]:checked').length;
                    if(n===0){alert('Markera de kopplingar som ska tas bort.');return false;}
                    return confirm('Ta bort '+n+' markerade kopplingar?');">
      <?= kc_csrf_falt() ?>
      <input type="hidden" name="atgard" value="ta_bort_markerade">
    <?php endif; ?>
    <div class="kc-tabell-wrap"><table class="kc-tabell">
      <thead><tr>
        <?php if ($inloggad): ?>
          <th><input type="checkbox" aria-label="Markera alla" title="Markera alla"
                     onclick="for (const c of document.querySelectorAll('#fas_kopplingar input[name=&quot;kopplingar[]&quot;]')) c.checked=this.checked;"></th>
        <?php endif; ?>
        <th>Som</th><th>Nivå</th><th>Nummer</th><th>Namn</th><th>Ligger i</th></tr></thead>
      <tbody>
      <?php foreach ($kopplingar as $k):
          $def = FAS_MALTYPER[$k['maltyp']]; ?>
        <tr>
          <?php if ($inloggad): ?>
            <td><input type="checkbox" name="kopplingar[]" value="<?= kc_h($k['uuid']) ?>" aria-label="Markera <?= kc_h($k['nummer']) ?>"></td>
          <?php endif; ?>
          <td><?= kc_h(FAS_KOPPLINGSTYPER[$k['kopplingstyp']] ?? $k['kopplingstyp']) ?></td>
          <td><?= kc_h($def['etikett']) ?></td>
          <td><a href="<?= kc_h(kc_url($def['sida'] . '?uuid=' . rawurlencode($k['mal_uuid']))) ?>"><?= kc_h($k['nummer']) ?></a></td>
          <td><?= kc_h($k['namn']) ?></td>
          <td><?= kc_h($k['plats']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($inloggad): ?>
      <p style="margin-top:.6rem"><button type="submit" class="kc-knapp-fara">Ta bort markerade</button></p>
    </form>
    <?php endif; ?>
  <?php endif; ?>
  <p class="kc-hjalp">Koppling till en byggnad eller fastighet omfattar alla objekt där, även objekt som läggs till senare.
     Många kopplingar på en gång läggs enklast in med <a href="<?= kc_h(kc_url('import.php?tabell=koppling')) ?>">import av kopplingar</a>.</p>
</div>

<?php if ($inloggad): ?>
<?php if ($fel !== []): ?>
<div class="kc-flash kc-flash-fel">
  <strong>Kunde inte lägga till:</strong>
  <ul style="margin:.25rem 0 0"><?php foreach ($fel as $f): ?><li><?= kc_h($f) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>
<form method="post">
  <?= kc_csrf_falt() ?>
  <input type="hidden" name="atgard" value="lagg_till">
  <div class="kc-ruta" style="max-width:48rem">
    <h2>Lägg till kopplingar</h2>
    <div class="kc-falt">
      <label for="kopplingstyp">Som</label>
      <select id="kopplingstyp" name="kopplingstyp">
        <?php foreach (FAS_KOPPLINGSTYPER as $k => $etikett): ?>
          <option value="<?= kc_h($k) ?>" <?= $k === $ny['kopplingstyp'] ? 'selected' : '' ?>><?= kc_h($etikett) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="maltyp" style="display:inline;margin-left:1rem">Nivå</label>
      <select id="maltyp" name="maltyp">
        <?php foreach (FAS_MALTYPER as $k => $def): ?>
          <option value="<?= kc_h($k) ?>" <?= $k === $ny['maltyp'] ? 'selected' : '' ?>><?= kc_h($def['etikett']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="kc-hjalp">Resurs = själva saken (t.ex. objektet som är soprummet). Användare = de som använder den.</div>
    </div>
    <div class="kc-falt">
      <label for="nummer">Nummer</label>
      <textarea id="nummer" name="nummer" rows="4" style="width:100%" placeholder="0101&#10;0102"><?= kc_h($ny['nummer']) ?></textarea>
      <div class="kc-hjalp">Fastighets-, byggnads- eller objektnummer enligt vald nivå. Ett per rad, eller åtskilda med komma.
        Okända nummer stoppar hela inläggningen; nummer som redan är kopplade hoppas över.</div>
    </div>
    <button type="submit" class="kc-knapp-primar">Lägg till</button>
  </div>
</form>
<?php endif; ?>

<div class="kc-ruta">
  <h2>Objekt som omfattas</h2>
  <p><?= kc_h($antal['resurs']) ?> som resurs · <?= kc_h($antal['anvandare']) ?> som användare</p>
  <?php if ($omfattar !== []): ?>
    <div class="kc-tabell-wrap"><table class="kc-tabell">
      <thead><tr><th>Som</th><th>Objektnr</th><th>Typ</th><th>Byggnad</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($omfattar, 0, $visa_max) as $o): ?>
        <tr>
          <td><?= kc_h(FAS_KOPPLINGSTYPER[$o['kopplingstyp']] ?? $o['kopplingstyp']) ?></td>
          <td><a href="<?= kc_h(kc_url('objekt.php?uuid=' . rawurlencode($o['uuid']))) ?>"><?= kc_h($o['objektnummer']) ?></a></td>
          <td><?= kc_h($o['typ']) ?></td>
          <td><?= kc_h($o['byggnadsnummer']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if (count($omfattar) > $visa_max): ?>
      <p class="kc-hjalp">Visar <?= $visa_max ?> av <?= count($omfattar) ?>.</p>
    <?php endif; ?>
  <?php endif; ?>
  <p class="kc-hjalp">Räknas ut när sidan visas: direkt kopplade objekt plus alla objekt i kopplade byggnader och fastigheter.</p>
</div>
<?php kc_sidfot();
