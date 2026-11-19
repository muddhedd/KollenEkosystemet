<?php
/**
 * CentralKollen – login.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Inloggning med spärr mot upprepade felförsök.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/cen.php';

$till = kc_saker_retur(kc_get('till', kc_post('till')));
if ($till === 'login.php' || str_starts_with($till, 'login.php?')) {
    $till = 'index.php';
}
$tillbaka = 'login.php?till=' . rawurlencode($till);

if (cen_inloggad() !== null) {
    kc_omdirigera($till);
}

if (kc_ar_post()) {
    $anvandarnamn = kc_post('anvandarnamn');
    $losenord     = (string)($_POST['losenord'] ?? '');   // trimmas aldrig

    if ($anvandarnamn === '' || $losenord === '') {
        kc_flash('fel', 'Fyll i användarnamn och lösenord.');
        kc_omdirigera($tillbaka);
    }

    if (kc_inloggning_sparrad($anvandarnamn)) {
        kc_flash('fel', 'För många misslyckade försök. Vänta 15 minuter och försök igen.');
        kc_omdirigera($tillbaka);
    }

    $anvandare = cen_anvandare_via_namn($anvandarnamn);
    // Verifiera alltid mot en hash så att svarstiden inte avslöjar om namnet finns
    $hash_ok = password_verify($losenord, $anvandare['losenord_hash'] ?? CEN_DUMMY_HASH);

    if ($anvandare === null || !$hash_ok) {
        kc_inloggning_registrera($anvandarnamn, false);
        kc_flash('fel', 'Fel användarnamn eller lösenord.');
        kc_omdirigera($tillbaka);
    }

    if ((int)$anvandare['is_active'] !== 1) {
        kc_inloggning_registrera($anvandarnamn, false);
        kc_flash('fel', 'Kontot är spärrat. Kontakta en administratör.');
        kc_omdirigera($tillbaka);
    }

    // Uppgradera hashen om PHP:s standard har skärpts
    if (password_needs_rehash($anvandare['losenord_hash'], PASSWORD_BCRYPT)) {
        $t = kc_tabell('md_anvandare');
        kc_kor(
            "UPDATE `$t` SET losenord_hash = ?, last_changed = ?, source_tool = 'cen' WHERE uuid = ? AND is_deleted = 0",
            [kc_losenord_hash($losenord), kc_nu(), $anvandare['uuid']]
        );
    }

    kc_inloggning_registrera($anvandarnamn, true);
    kc_logga_in($anvandare);
    $namn = trim($anvandare['fornamn'] . ' ' . $anvandare['efternamn']);
    kc_flash('ok', 'Välkommen, ' . ($namn !== '' ? $namn : $anvandare['anvandarnamn']) . '!');
    kc_omdirigera($till);
}

kc_sidhuvud('Logga in');
?>
<div class="kc-ruta" style="max-width:28rem">
  <form method="post" action="<?= kc_h(kc_url('login.php')) ?>">
    <?= kc_csrf_falt() ?>
    <input type="hidden" name="till" value="<?= kc_h($till) ?>">
    <div class="kc-falt">
      <label for="anvandarnamn">Användarnamn</label>
      <input type="text" id="anvandarnamn" name="anvandarnamn" maxlength="100"
             autocomplete="username" autofocus required>
    </div>
    <div class="kc-falt">
      <label for="losenord">Lösenord</label>
      <input type="password" id="losenord" name="losenord" maxlength="200"
             autocomplete="current-password" required>
    </div>
    <button type="submit" class="kc-knapp-primar">Logga in</button>
  </form>
</div>
<?php kc_sidfot();
