<?php
/**
 * ProjektKollen – login.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Inloggning mot användare från CentralKollen (lokal kopia, uppdateras vid varje försök).
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/prj.php';

$till = kc_saker_retur(kc_get('till', kc_post('till')));
if ($till === 'login.php' || str_starts_with($till, 'login.php?')) {
    $till = 'index.php';
}
$tillbaka = 'login.php?till=' . rawurlencode($till);

if (prj_inloggad() !== null) {
    kc_omdirigera($till);
}

if (kc_ar_post()) {
    $anvandarnamn = kc_post('anvandarnamn');
    $losenord     = (string)($_POST['losenord'] ?? '');   // trimmas aldrig

    if ($anvandarnamn === '' || $losenord === '') {
        kc_flash('fel', 'Fyll i användarnamn och lösenord.');
        kc_omdirigera($tillbaka);
    }

    $res = kc_verktyg_logga_in($anvandarnamn, $losenord);
    if (!$res['ok']) {
        kc_flash('fel', (string)$res['fel']);
        kc_omdirigera($tillbaka);
    }
    if ($res['kalla'] === 'lokal') {
        kc_flash('info', 'CentralKollen svarade inte – du loggades in mot den lokala kopian av användarna.');
    }
    $a = $res['anvandare'];
    $namn = trim($a['fornamn'] . ' ' . $a['efternamn']);
    kc_flash('ok', 'Välkommen, ' . ($namn !== '' ? $namn : $a['anvandarnamn']) . '!');
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
  <p class="kc-hjalp" style="margin-top:.75rem">
    Användare och lösenord administreras i CentralKollen.
    <?php if (defined('CEN_URL') && CEN_URL !== ''): ?>
      <a href="<?= kc_h(CEN_URL . 'losenord.php') ?>">Byt lösenord där</a>.
    <?php endif; ?>
  </p>
</div>
<?php kc_sidfot();
