<?php
/**
 * ProjektKollen – omfattning.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Tar emot ändringar av ett projekts omfattning (POST) och skickar tillbaka till projektets sida.
 * Efter varje ändring uppdateras objektlistan och mätpunktslistan. Kräver inloggning.
 * Parametrar: projekt (uuid); POST atgard = lagg_till (niva, nummer – ett per rad) | ta_bort (omfattning).
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/prj.php';

prj_krav_inloggning();
$p = prj_projekt_hamta(kc_get('projekt'));
if ($p === null) {
    throw new KcFel('Projektet finns inte.', 404);
}
$tillbaka = 'projekt.php?uuid=' . rawurlencode($p['uuid']) . '#omfattning';
if (!kc_ar_post()) {
    kc_omdirigera($tillbaka);
}
if (prj_projekt_last($p)) {
    kc_flash('fel', 'Projektet är avslutat och kan inte ändras.');
    kc_omdirigera($tillbaka);
}
if (prj_projekttyp_funktion($p['projekttyp_uuid']) === null) {
    kc_flash('fel', 'Projekttypen saknar funktion – ange den under Typer.');
    kc_omdirigera($tillbaka);
}

$atgard = kc_post('atgard');
if ($atgard === 'lagg_till') {
    $niva = kc_post('niva');
    if (!array_key_exists($niva, PRJ_NIVAER)) {
        kc_flash('fel', 'Välj nivå.');
        kc_omdirigera($tillbaka);
    }
    $nummer = array_slice(preg_split('/[\r\n,;]+/', (string)($_POST['nummer'] ?? '')) ?: [], 0, 500);
    $res = null;
    $sum = null;
    kc_transaktion(function () use ($p, $niva, $nummer, &$res, &$sum): void {
        $res = prj_omfattning_lagg_till($p['uuid'], $niva, $nummer);
        $sum = prj_objektlista_uppdatera($p);
    });
    kc_flash($res['nya'] > 0 ? 'ok' : 'info',
        "Lade till {$res['nya']}" . ($res['fanns'] > 0 ? " ({$res['fanns']} fanns redan)" : '') . '. '
        . "Objektlistan: {$sum['obj_nya']} nya objekt, {$sum['obj_borttagna']} borttagna. Mätpunkter: {$sum['skapade']} nya, {$sum['andrade']} ändrade, {$sum['borttagna']} borttagna.");
    if ($res['okanda'] !== []) {
        kc_flash('fel', 'Hittades inte i fastighetsdatan: ' . implode(', ', array_slice($res['okanda'], 0, 30)) . (count($res['okanda']) > 30 ? ' …' : '') . '.');
    }
    kc_omdirigera($tillbaka);
}

if ($atgard === 'ta_bort') {
    $t = kc_tabell('md_omfattning');
    $sum = null;
    kc_transaktion(function () use ($p, $t, &$sum): void {
        kc_kor("UPDATE `$t` SET is_deleted = 1, last_changed = ?, source_tool = 'prj' WHERE uuid = ? AND projekt_uuid = ? AND is_deleted = 0",
               [kc_nu(), kc_post('omfattning'), $p['uuid']]);
        $sum = prj_objektlista_uppdatera($p);
    });
    kc_flash('ok', "Borttagen ur omfattningen. Objektlistan: {$sum['obj_borttagna']} objekt borttagna"
        . ($sum['utanfor'] > 0 ? ", {$sum['utanfor']} ligger kvar eftersom de har låsta mätpunkter" : '') . '. '
        . "Mätpunkter: {$sum['borttagna']} borttagna.");
    kc_omdirigera($tillbaka);
}

kc_omdirigera($tillbaka);
