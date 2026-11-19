<?php
/**
 * CentralKollen – anvandare_redigera.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Skapa, redigera, spärra och radera användare samt sätta behörigheter.
 * Endast administratörer.
 *
 * Skydd: en administratör kan inte spärra eller radera sig själv,
 * och kan inte ta bort sin egen behörighet till CentralKollen.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/cen.php';

$admin = cen_krav_admin();
$ta    = kc_tabell('md_anvandare');
$tb    = kc_tabell('md_behorighet');

$uuid = kc_get('uuid');
$ny   = $uuid === '';
$rad  = null;
if (!$ny) {
    $rad = kc_ar_uuid($uuid) ? cen_anvandare_hamta($uuid) : null;
    if ($rad === null) {
        throw new KcFel('Användaren finns inte eller har raderats.', 404);
    }
}
$ar_jag       = !$ny && $rad['uuid'] === $admin['uuid'];
$verktyg      = cen_verktyg_alla();
$verktyg_uuid = array_column($verktyg, 'uuid');
$cen_uuid     = cen_verktyg_uuid('cen');

$varden = $ny
    ? ['anvandarnamn' => '', 'fornamn' => '', 'efternamn' => '', 'epost' => '', 'telefon' => '', 'is_active' => 1]
    : $rad;
$valda = $ny ? [] : cen_behorigheter($uuid);
$fel   = [];

/* ---------- Radera ---------- */

if (kc_ar_post() && kc_post('atgard') === 'radera') {
    if ($ny) {
        throw new KcFel('Det finns ingen användare att radera.', 400);
    }
    if ($ar_jag) {
        kc_flash('fel', 'Du kan inte radera ditt eget konto.');
        kc_omdirigera('anvandare_redigera.php?uuid=' . rawurlencode($uuid));
    }
    kc_transaktion(function () use ($ta, $tb, $uuid): void {
        $nu = kc_nu();
        kc_kor(
            "UPDATE `$ta` SET is_deleted = 1, last_changed = ?, source_tool = 'cen' WHERE uuid = ? AND is_deleted = 0",
            [$nu, $uuid]
        );
        // Kaskad: behörigheterna raderas också, så att verktygen tar bort användaren vid nästa synk
        kc_kor(
            "UPDATE `$tb` SET is_deleted = 1, last_changed = ?, source_tool = 'cen' WHERE anvandare_uuid = ? AND is_deleted = 0",
            [$nu, $uuid]
        );
    });
    kc_flash('ok', 'Användaren ' . $rad['anvandarnamn'] . ' är raderad.');
    kc_omdirigera('anvandare.php');
}

/* ---------- Spara ---------- */

if (kc_ar_post() && kc_post('atgard') === 'spara') {
    $varden = [
        'anvandarnamn' => kc_post('anvandarnamn'),
        'fornamn'      => kc_post('fornamn'),
        'efternamn'    => kc_post('efternamn'),
        'epost'        => mb_strtolower(kc_post('epost')),
        'telefon'      => kc_post('telefon'),
        'is_active'    => isset($_POST['is_active']) ? 1 : 0,
    ];
    $posted = $_POST['behorighet'] ?? [];
    $valda  = array_values(array_intersect(is_array($posted) ? array_filter($posted, 'is_string') : [], $verktyg_uuid));

    if ($ar_jag) {
        $varden['is_active'] = 1;                         // kan inte spärra sig själv
        if ($cen_uuid !== null && !in_array($cen_uuid, $valda, true)) {
            $valda[] = $cen_uuid;                         // kan inte ta bort sin egen admin
        }
    }

    $fel = cen_anvandare_validera($varden, $ny ? null : $uuid);

    $nytt    = (string)($_POST['nytt'] ?? '');
    $upprepa = (string)($_POST['upprepa'] ?? '');
    $byt_losenord = $ny || $nytt !== '' || $upprepa !== '';
    if ($byt_losenord) {
        if ($nytt !== $upprepa) {
            $fel[] = 'De två lösenorden är inte likadana.';
        } elseif (($losenord_fel = kc_losenord_fel($nytt)) !== null) {
            $fel[] = $losenord_fel;
        }
    }

    if ($fel === []) {
        $mal_uuid = $ny ? kc_uuid() : $uuid;

        $andrat = kc_transaktion(function () use ($ny, $rad, $varden, $valda, $verktyg_uuid,
                                                 $byt_losenord, $nytt, $mal_uuid, $ta): bool {
            $andrat = false;

            if ($ny) {
                kc_kor(
                    "INSERT INTO `$ta` (uuid, last_changed, is_deleted, source_tool,
                                        anvandarnamn, fornamn, efternamn, epost, telefon, losenord_hash, is_active)
                     VALUES (?, ?, 0, 'cen', ?, ?, ?, ?, ?, ?, ?)",
                    [$mal_uuid, kc_nu(), $varden['anvandarnamn'], $varden['fornamn'], $varden['efternamn'],
                     $varden['epost'], $varden['telefon'], kc_losenord_hash($nytt), $varden['is_active']]
                );
                $andrat = true;
            } else {
                $skillnad = false;
                foreach ($varden as $falt => $varde) {
                    if ((string)$rad[$falt] !== (string)$varde) {
                        $skillnad = true;
                        break;
                    }
                }
                if ($skillnad || $byt_losenord) {
                    kc_kor(
                        "UPDATE `$ta` SET anvandarnamn = ?, fornamn = ?, efternamn = ?, epost = ?, telefon = ?,
                                          is_active = ?, losenord_hash = ?, last_changed = ?, source_tool = 'cen'
                          WHERE uuid = ? AND is_deleted = 0",
                        [$varden['anvandarnamn'], $varden['fornamn'], $varden['efternamn'], $varden['epost'],
                         $varden['telefon'], $varden['is_active'],
                         $byt_losenord ? kc_losenord_hash($nytt) : $rad['losenord_hash'],
                         kc_nu(), $mal_uuid]
                    );
                    $andrat = true;
                }
            }

            foreach ($verktyg_uuid as $v_uuid) {
                if (cen_behorighet_satt($mal_uuid, $v_uuid, in_array($v_uuid, $valda, true))) {
                    $andrat = true;
                }
            }
            return $andrat;
        });

        if ($ar_jag && $andrat) {
            kc_logga_in(cen_anvandare_hamta($mal_uuid));   // uppdaterar namnet i sidhuvudet
        }
        kc_flash('ok', match (true) {
            $ny     => 'Användaren ' . $varden['anvandarnamn'] . ' är skapad.',
            $andrat => 'Ändringarna är sparade.',
            default => 'Inget var ändrat.',
        });
        kc_omdirigera('anvandare_redigera.php?uuid=' . rawurlencode($mal_uuid));
    }
}

/* ---------- Visning ---------- */

kc_sidhuvud($ny ? 'Ny användare' : 'Användare: ' . $rad['anvandarnamn']);
?>
<p><a href="<?= kc_h(kc_url('anvandare.php')) ?>">« Tillbaka till listan</a></p>

<?php if ($fel !== []): ?>
<div class="kc-flash kc-flash-fel">
  <strong>Kunde inte spara:</strong>
  <ul style="margin:.25rem 0 0">
    <?php foreach ($fel as $f): ?><li><?= kc_h($f) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if (!$ny && $rad['epost'] === '' && $fel === []): ?>
<div class="kc-flash kc-flash-info">Användaren saknar e-postadress. Fyll i en och spara.</div>
<?php endif; ?>

<form method="post">
  <?= kc_csrf_falt() ?>
  <input type="hidden" name="atgard" value="spara">

  <div class="kc-ruta" style="max-width:40rem">
    <h2>Uppgifter</h2>
    <div class="kc-falt">
      <label for="anvandarnamn">Användarnamn *</label>
      <input type="text" id="anvandarnamn" name="anvandarnamn" maxlength="100" required
             value="<?= kc_h($varden['anvandarnamn']) ?>" autocomplete="off">
      <div class="kc-hjalp">3–100 tecken: a–z, siffror, punkt, bindestreck, understreck.</div>
    </div>
    <div class="kc-falt">
      <label for="fornamn">Förnamn *</label>
      <input type="text" id="fornamn" name="fornamn" maxlength="100" required value="<?= kc_h($varden['fornamn']) ?>">
    </div>
    <div class="kc-falt">
      <label for="efternamn">Efternamn</label>
      <input type="text" id="efternamn" name="efternamn" maxlength="100" value="<?= kc_h($varden['efternamn']) ?>">
    </div>
    <div class="kc-falt">
      <label for="epost">E-post *</label>
      <input type="email" id="epost" name="epost" maxlength="255" required value="<?= kc_h($varden['epost']) ?>">
    </div>
    <div class="kc-falt">
      <label for="telefon">Telefon</label>
      <input type="text" id="telefon" name="telefon" maxlength="50" value="<?= kc_h($varden['telefon']) ?>">
    </div>
    <div class="kc-falt">
      <label>
        <input type="checkbox" name="is_active" value="1"
               <?= (int)$varden['is_active'] === 1 ? 'checked' : '' ?> <?= $ar_jag ? 'disabled' : '' ?>>
        Aktiv (kan logga in)
      </label>
      <?php if ($ar_jag): ?><div class="kc-hjalp">Du kan inte spärra ditt eget konto.</div><?php endif; ?>
    </div>
  </div>

  <div class="kc-ruta" style="max-width:40rem">
    <h2><?= $ny ? 'Lösenord *' : 'Nytt lösenord' ?></h2>
    <?php if (!$ny): ?><p class="kc-hjalp">Lämna tomt för att behålla nuvarande lösenord.</p><?php endif; ?>
    <div class="kc-falt">
      <label for="nytt">Lösenord</label>
      <input type="password" id="nytt" name="nytt" maxlength="200" autocomplete="new-password" <?= $ny ? 'required' : '' ?>>
      <div class="kc-hjalp">Minst 8 tecken, minst en versal, en gemen och en siffra.</div>
    </div>
    <div class="kc-falt">
      <label for="upprepa">Upprepa lösenord</label>
      <input type="password" id="upprepa" name="upprepa" maxlength="200" autocomplete="new-password" <?= $ny ? 'required' : '' ?>>
    </div>
  </div>

  <div class="kc-ruta" style="max-width:40rem">
    <h2>Får logga in i</h2>
    <?php foreach ($verktyg as $v):
        $ar_cen  = $v['prefix'] === 'cen';
        $las     = $ar_cen && $ar_jag;
        $markt   = in_array($v['uuid'], $valda, true);
        ?>
      <div>
        <label>
          <input type="checkbox" name="behorighet[]" value="<?= kc_h($v['uuid']) ?>"
                 <?= $markt ? 'checked' : '' ?> <?= $las ? 'disabled' : '' ?>>
          <?= kc_h($v['namn']) ?> <span class="kc-dampad">(<?= kc_h($v['prefix']) ?>)</span>
          <?php if ($ar_cen): ?><strong>– administratör</strong><?php endif; ?>
          <?php if ((int)$v['is_active'] !== 1): ?><span class="kc-dampad">– inaktivt</span><?php endif; ?>
        </label>
      </div>
    <?php endforeach; ?>
    <?php if ($ar_jag): ?><p class="kc-hjalp" style="margin-top:.5rem">Du kan inte ta bort din egen administratörsbehörighet.</p><?php endif; ?>
  </div>

  <p><button type="submit" class="kc-knapp-primar"><?= $ny ? 'Skapa användare' : 'Spara' ?></button></p>
</form>

<?php if (!$ny && !$ar_jag): ?>
<div class="kc-ruta" style="max-width:40rem">
  <h2>Radera</h2>
  <p>Användaren och alla dess behörigheter tas bort. Användarnamn och e-post blir lediga igen.</p>
  <form method="post" onsubmit="return confirm('Radera användaren <?= kc_h(addslashes($rad['anvandarnamn'])) ?>?');">
    <?= kc_csrf_falt() ?>
    <input type="hidden" name="atgard" value="radera">
    <button type="submit" class="kc-knapp-fara">Radera användaren</button>
  </form>
</div>
<?php endif; ?>
<?php kc_sidfot();
