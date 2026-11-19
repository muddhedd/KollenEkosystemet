<?php
/**
 * ProjektKollen – projekt_redigera.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Skapa och ändra ett projekt. Kräver inloggning.
 * - Projektnumret (P00001 …) sätts av systemet och kan inte ändras.
 * - Projekttypen kan bara bytas så länge projektet har status Planering.
 * - Radering: bara felregistrerade projekt med status Planering och utan låsta mätpunktsnamn,
 *   efter att projektnumret skrivits in och en kryssruta bockats i. Omfattning, objektlista och
 *   mätpunkter raderas med projektet.
 * - Status Avslutat sätts bara via Avsluta projekt (avslut.php), som rensar nycklarna; ett avslutat
 *   projekt återöppnas också där. I formuläret kan status bara vara Planering eller Pågående.
 * Parameter: uuid (befintligt), annars nytt.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/prj.php';

prj_krav_inloggning();
$tp = kc_tabell('md_projekt');

$uuid = kc_get('uuid');
$ny   = $uuid === '';
$rad  = null;
if (!$ny) {
    $rad = prj_projekt_hamta($uuid);
    if ($rad === null) {
        throw new KcFel('Projektet finns inte.', 404);
    }
}
$falt = ['namn', 'projekttyp_uuid', 'status', 'projektledare', 'startdatum', 'slutdatum', 'beskrivning'];
$varden = $ny
    ? ['namn' => '', 'projekttyp_uuid' => (string)(array_key_first(prj_typer_aktiva('projekttyp')) ?? ''), 'status' => 'planering',
       'projektledare' => '', 'startdatum' => '', 'slutdatum' => '', 'beskrivning' => '']
    : array_map(static fn($v) => (string)($v ?? ''), array_intersect_key($rad, array_flip($falt)));
$typ_last = !$ny && $rad['status'] !== 'planering';
$status_last = !$ny && $rad['status'] === 'avslutat';
$status_val = array_diff_key(PRJ_STATUS, ['avslutat' => true]);
$fel = [];
$radera_fel = '';

/* ---------- Radera (felregistrering) ---------- */

if (kc_ar_post() && kc_post('atgard') === 'radera' && !$ny) {
    $prj_lasta = prj_projekt_sammanfattning($uuid)['lasta'];
    if ($rad['status'] !== 'planering') {
        $radera_fel = 'Bara projekt med status Planering kan raderas.';
    } elseif ($prj_lasta > 0) {
        $radera_fel = "Projektet har $prj_lasta mätpunkter med låst namn (etiketter utskrivna eller exporterade) och kan inte raderas.";
    } elseif (kc_post('bekrafta_nummer') !== $rad['projektnummer'] || kc_post('bekrafta') !== '1') {
        $radera_fel = 'Skriv in projektnumret ' . $rad['projektnummer'] . ' och bocka i rutan för att radera.';
    } else {
        kc_transaktion(function () use ($tp, $uuid): void {
            $nu = kc_nu();
            foreach (['md_omfattning', 'md_projektobjekt', 'md_matpunkt'] as $md) {
                kc_kor('UPDATE `' . kc_tabell($md) . "` SET is_deleted = 1, last_changed = ?, source_tool = 'prj' WHERE projekt_uuid = ? AND is_deleted = 0", [$nu, $uuid]);
            }
            kc_kor("UPDATE `$tp` SET is_deleted = 1, last_changed = ?, source_tool = 'prj' WHERE uuid = ? AND is_deleted = 0", [$nu, $uuid]);
        });
        kc_flash('ok', 'Projektet ' . $rad['projektnummer'] . ' är raderat.');
        kc_omdirigera('projektlista.php');
    }
}

/* ---------- Spara ---------- */

if (kc_ar_post() && kc_post('atgard') === 'spara') {
    foreach ($falt as $k) {
        $varden[$k] = $k === 'beskrivning' ? trim((string)($_POST['beskrivning'] ?? '')) : kc_post($k);
    }
    if ($typ_last) {
        $varden['projekttyp_uuid'] = $rad['projekttyp_uuid'];
    }
    if ($status_last) {
        $varden['status'] = 'avslutat';
    } elseif ($varden['status'] === 'avslutat') {
        $fel[] = 'Ett projekt avslutas med knappen Avsluta projekt på projektsidan (då rensas nycklarna).';
    }
    if ($varden['namn'] === '' || mb_strlen($varden['namn']) > 150) {
        $fel[] = 'Namn måste fyllas i (max 150 tecken).';
    }
    $typ = prj_typ_hamta('projekttyp', $varden['projekttyp_uuid']);
    if ($typ === null || ((int)$typ['is_active'] !== 1 && ($ny || $varden['projekttyp_uuid'] !== $rad['projekttyp_uuid']))) {
        $fel[] = 'Välj projekttyp.';
    }
    if (!array_key_exists($varden['status'], PRJ_STATUS)) {
        $fel[] = 'Välj status.';
    }
    if (mb_strlen($varden['projektledare']) > 150) {
        $fel[] = 'Projektledare får vara max 150 tecken.';
    }
    foreach (['startdatum' => 'Startdatum', 'slutdatum' => 'Slutdatum'] as $k => $etikett) {
        if ($varden[$k] !== '' && !prj_datum_ok($varden[$k])) {
            $fel[] = "$etikett ska vara ÅÅÅÅ-MM-DD.";
        }
    }
    if ($varden['startdatum'] !== '' && $varden['slutdatum'] !== '' && $varden['slutdatum'] < $varden['startdatum']) {
        $fel[] = 'Slutdatum kan inte vara före startdatum.';
    }
    if (mb_strlen($varden['beskrivning']) > 5000) {
        $fel[] = 'Beskrivningen är för lång (max 5 000 tecken).';
    }

    if ($fel === []) {
        $spara = $varden;
        foreach (['startdatum', 'slutdatum'] as $k) {
            $spara[$k] = $spara[$k] === '' ? null : $spara[$k];
        }
        $nummer = $ny ? '' : $rad['projektnummer'];
        $andrat = false;
        kc_transaktion(function () use ($ny, &$uuid, &$nummer, $rad, $spara, $tp, &$andrat): void {
            $nu = kc_nu();
            if ($ny) {
                $uuid = kc_uuid();
                $nummer = prj_nasta_projektnummer();
                kc_kor(
                    "INSERT INTO `$tp` (uuid, last_changed, is_deleted, source_tool, projektnummer, `" . implode('`, `', array_keys($spara)) . "`)
                     VALUES (?, ?, 0, 'prj', ?" . str_repeat(', ?', count($spara)) . ')',
                    array_merge([$uuid, $nu, $nummer], array_values($spara))
                );
                $andrat = true;
                return;
            }
            foreach ($spara as $k => $v) {
                $andrat = $andrat || (string)($rad[$k] ?? '') !== (string)($v ?? '');
            }
            if ($andrat) {
                $set = implode(', ', array_map(static fn($k) => "`$k` = ?", array_keys($spara)));
                kc_kor("UPDATE `$tp` SET $set, last_changed = ?, source_tool = 'prj' WHERE uuid = ? AND is_deleted = 0",
                       array_merge(array_values($spara), [$nu, $uuid]));
            }
        });
        kc_flash('ok', $ny ? "Projektet $nummer är skapat." : ($andrat ? 'Ändringarna är sparade.' : 'Inget var ändrat.'));
        kc_omdirigera('projekt.php?uuid=' . rawurlencode($uuid));
    }
}

/* ---------- Visning ---------- */

$typval = array_map(static fn($r) => $r['namn'], prj_typer_aktiva('projekttyp'));
if ($varden['projekttyp_uuid'] !== '' && !isset($typval[$varden['projekttyp_uuid']])
    && ($t = prj_typ_hamta('projekttyp', $varden['projekttyp_uuid'])) !== null) {
    $typval[$varden['projekttyp_uuid']] = $t['namn'] . ' (inaktiv)';
}
$tillbaka = $ny ? 'projektlista.php' : 'projekt.php?uuid=' . rawurlencode($uuid);

kc_sidhuvud($ny ? 'Nytt projekt' : 'Ändra projekt ' . $rad['projektnummer']);
?>
<p><a href="<?= kc_h(kc_url($tillbaka)) ?>">« Tillbaka</a></p>

<?php if ($fel !== []): ?>
<div class="kc-flash kc-flash-fel">
  <strong>Kunde inte spara:</strong>
  <ul style="margin:.25rem 0 0"><?php foreach ($fel as $f): ?><li><?= kc_h($f) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="post">
  <?= kc_csrf_falt() ?>
  <input type="hidden" name="atgard" value="spara">
  <div class="kc-ruta" style="max-width:44rem">
    <p>Projektnummer: <strong><?= $ny ? '<span class="kc-dampad">sätts automatiskt när projektet skapas</span>' : kc_h($rad['projektnummer']) ?></strong></p>
    <div class="kc-falt">
      <label for="namn">Namn *</label>
      <input type="text" id="namn" name="namn" maxlength="150" required style="width:100%" value="<?= kc_h($varden['namn']) ?>"
             placeholder="t.ex. Temperaturgivare Hjorten 2027">
    </div>
    <div class="kc-falt">
      <label for="projekttyp_uuid">Projekttyp *</label>
      <select id="projekttyp_uuid" name="projekttyp_uuid" <?= $typ_last ? 'disabled' : '' ?>><?= prj_alternativ($typval, $varden['projekttyp_uuid']) ?></select>
      <?php if ($typ_last): ?><div class="kc-hjalp">Projekttypen kan bara bytas under Planering.</div><?php endif; ?>
    </div>
    <div class="kc-falt">
      <label for="status">Status</label>
      <?php if ($status_last): ?>
        <select id="status" name="status" disabled><?= prj_alternativ(PRJ_STATUS, 'avslutat') ?></select>
        <div class="kc-hjalp">Avslutat projekt – återöppnas under <a href="<?= kc_h(kc_url('avslut.php?projekt=' . rawurlencode($uuid))) ?>">Avsluta/återöppna</a>.</div>
      <?php else: ?>
        <select id="status" name="status"><?= prj_alternativ($status_val, $varden['status']) ?></select>
        <div class="kc-hjalp">Avslutat sätts med knappen Avsluta projekt på projektsidan.</div>
      <?php endif; ?>
    </div>
    <div class="kc-falt">
      <label for="projektledare">Projektledare</label>
      <input type="text" id="projektledare" name="projektledare" maxlength="150" style="width:20rem" value="<?= kc_h($varden['projektledare']) ?>">
      <div class="kc-hjalp">Text tills vidare – väljs ur PersonalKollen när den finns.</div>
    </div>
    <div class="kc-falt">
      <label for="startdatum">Start</label>
      <input type="date" id="startdatum" name="startdatum" value="<?= kc_h($varden['startdatum']) ?>">
      <label for="slutdatum" style="display:inline;margin-left:1rem">Slut</label>
      <input type="date" id="slutdatum" name="slutdatum" value="<?= kc_h($varden['slutdatum']) ?>">
    </div>
    <div class="kc-falt">
      <label for="beskrivning">Beskrivning</label>
      <textarea id="beskrivning" name="beskrivning" rows="5" maxlength="5000" style="width:100%"><?= kc_h($varden['beskrivning']) ?></textarea>
    </div>
    <button type="submit" class="kc-knapp-primar"><?= $ny ? 'Skapa projekt' : 'Spara' ?></button>
  </div>
</form>

<?php if (!$ny): ?>
<div class="kc-ruta" style="max-width:44rem">
  <h2>Radera felregistrerat projekt</h2>
  <?php if ($radera_fel !== ''): ?><div class="kc-flash kc-flash-fel"><?= kc_h($radera_fel) ?></div><?php endif; ?>
  <?php if ($rad['status'] !== 'planering'): ?>
    <p>Bara projekt med status Planering kan raderas. Ett projekt som har startat avslutas i stället.</p>
  <?php else: ?>
    <form method="post">
      <?= kc_csrf_falt() ?>
      <input type="hidden" name="atgard" value="radera">
      <div class="kc-falt">
        <label for="bekrafta_nummer">Skriv projektnumret <code><?= kc_h($rad['projektnummer']) ?></code> för att bekräfta</label>
        <input type="text" id="bekrafta_nummer" name="bekrafta_nummer" maxlength="20" style="width:10rem" autocomplete="off">
      </div>
      <div class="kc-falt">
        <label><input type="checkbox" name="bekrafta" value="1"> Jag förstår att projektet raderas</label>
      </div>
      <button type="submit" class="kc-knapp-fara">Radera projektet</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php kc_sidfot();
