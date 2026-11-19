<?php
/**
 * CentralKollen – index.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Icke inloggade: informationssida. Inloggade: översikt.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/cen.php';

$anvandare = cen_inloggad();

if ($anvandare === null) {
    kc_sidhuvud('Välkommen till CentralKollen');
    ?>
    <div class="kc-ruta">
      <h2>Vad är CentralKollen?</h2>
      <p>CentralKollen är navet i ekosystemet Kollen – en samling fristående verktyg
         som tillsammans håller ordning på nycklar, avtal, dokument, fastigheter och mycket mer.</p>
      <p>Här administreras sådant som gäller hela ekosystemet:</p>
      <ul>
        <li>användare och vilka verktyg de får logga in i</li>
        <li>registret över verktyg, deras adresser och API-nycklar</li>
        <li>vilken data varje verktyg äger och vilken data det hämtar från andra</li>
        <li>en central kopia av all data, som verktygen synkar mot</li>
      </ul>
      <p>Varje verktyg ansvarar för sin egen data. All data administreras på ett ställe
         men kan användas på många.</p>
    </div>
    <div class="kc-ruta">
      <p>Innehållet i CentralKollen visas bara för inloggade användare.</p>
      <a class="kc-knapp kc-knapp-primar" href="<?= kc_h(kc_url('login.php')) ?>">Logga in</a>
    </div>
    <?php
    kc_sidfot();
    exit;
}

$ta = kc_tabell('md_anvandare');
$tv = kc_tabell('md_verktyg');

kc_sidhuvud('Översikt');

if (cen_har_standardlosenord($anvandare)): ?>
<div class="kc-flash kc-flash-fel">
  <strong>Du använder fortfarande standardlösenordet.</strong>
  CentralKollen nås från internet – <a href="<?= kc_h(kc_url('losenord.php')) ?>">byt lösenord nu</a>.
</div>
<?php endif;

if ($anvandare['epost'] === ''): ?>
<div class="kc-flash kc-flash-info">
  <strong>Ditt konto saknar e-postadress.</strong>
  <?php if ($anvandare['ar_admin']): ?>
    <a href="<?= kc_h(kc_url('anvandare_redigera.php?uuid=' . rawurlencode($anvandare['uuid']))) ?>">Fyll i den här</a>.
  <?php else: ?>
    Be en administratör att lägga till den.
  <?php endif; ?>
</div>
<?php endif;

if ($anvandare['ar_admin']):
    $anv_aktiva   = (int)kc_hamta_varde("SELECT COUNT(*) FROM `$ta` WHERE is_deleted = 0 AND is_active = 1");
    $anv_sparrade = (int)kc_hamta_varde("SELECT COUNT(*) FROM `$ta` WHERE is_deleted = 0 AND is_active = 0");
    $vkt_aktiva   = (int)kc_hamta_varde("SELECT COUNT(*) FROM `$tv` WHERE is_deleted = 0 AND is_active = 1");
    $vkt_totalt   = (int)kc_hamta_varde("SELECT COUNT(*) FROM `$tv` WHERE is_deleted = 0");
    ?>
    <div class="kc-ruta">
      <h2>Läget just nu</h2>
      <p>Användare: <strong><?= kc_h($anv_aktiva) ?></strong> aktiva,
         <strong><?= kc_h($anv_sparrade) ?></strong> spärrade</p>
      <p>Verktyg: <strong><?= kc_h($vkt_aktiva) ?></strong> aktiverade av
         <strong><?= kc_h($vkt_totalt) ?></strong> i registret</p>
      <p><a href="<?= kc_h(kc_url('anvandare.php')) ?>">Hantera användare »</a><br>
         <a href="<?= kc_h(kc_url('verktyg.php')) ?>">Hantera verktyg »</a></p>
    </div>
<?php else: ?>
    <div class="kc-ruta">
      <h2>Du är inloggad</h2>
      <p>Som användare utan administratörsbehörighet kan du byta ditt lösenord här.
         Det nya lösenordet gäller i alla verktyg du har behörighet till efter nästa synk.</p>
      <a class="kc-knapp" href="<?= kc_h(kc_url('losenord.php')) ?>">Byt lösenord</a>
    </div>
<?php endif;

kc_sidfot();
