<?php
/**
 * CentralKollen – losenord.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Byt eget lösenord. Tillgänglig för alla inloggade användare.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/cen.php';

$anvandare = cen_krav_inloggning();

if (kc_ar_post()) {
    $nuvarande = (string)($_POST['nuvarande'] ?? '');
    $nytt      = (string)($_POST['nytt'] ?? '');
    $upprepa   = (string)($_POST['upprepa'] ?? '');
    $fel       = null;

    if (kc_inloggning_sparrad($anvandare['anvandarnamn'])) {
        $fel = 'För många felaktiga försök. Vänta 15 minuter och försök igen.';
    } elseif (!password_verify($nuvarande, $anvandare['losenord_hash'])) {
        kc_inloggning_registrera($anvandare['anvandarnamn'], false);
        $fel = 'Nuvarande lösenord stämmer inte.';
    } elseif ($nytt !== $upprepa) {
        $fel = 'De två nya lösenorden är inte likadana.';
    } elseif ($nytt === $nuvarande) {
        $fel = 'Det nya lösenordet måste skilja sig från det nuvarande.';
    } else {
        $fel = kc_losenord_fel($nytt);
    }

    if ($fel !== null) {
        kc_flash('fel', $fel);
        kc_omdirigera('losenord.php');
    }

    $t = kc_tabell('md_anvandare');
    kc_kor(
        "UPDATE `$t` SET losenord_hash = ?, last_changed = ?, source_tool = 'cen' WHERE uuid = ? AND is_deleted = 0",
        [kc_losenord_hash($nytt), kc_nu(), $anvandare['uuid']]
    );
    session_regenerate_id(true);
    kc_flash('ok', 'Ditt lösenord är bytt.');
    kc_omdirigera('index.php');
}

kc_sidhuvud('Byt lösenord');
?>
<div class="kc-ruta" style="max-width:32rem">
  <form method="post">
    <?= kc_csrf_falt() ?>
    <input type="text" name="anvandarnamn" value="<?= kc_h($anvandare['anvandarnamn']) ?>"
           autocomplete="username" hidden>
    <div class="kc-falt">
      <label for="nuvarande">Nuvarande lösenord</label>
      <input type="password" id="nuvarande" name="nuvarande" maxlength="200"
             autocomplete="current-password" required>
    </div>
    <div class="kc-falt">
      <label for="nytt">Nytt lösenord</label>
      <input type="password" id="nytt" name="nytt" maxlength="200"
             autocomplete="new-password" required>
      <div class="kc-hjalp">Minst 8 tecken, minst en versal, en gemen och en siffra. Specialtecken är tillåtna.</div>
    </div>
    <div class="kc-falt">
      <label for="upprepa">Upprepa nytt lösenord</label>
      <input type="password" id="upprepa" name="upprepa" maxlength="200"
             autocomplete="new-password" required>
    </div>
    <button type="submit" class="kc-knapp-primar">Byt lösenord</button>
  </form>
</div>
<?php kc_sidfot();
