<?php
/**
 * CentralKollen – demo_data.php
 * Variant: DEMO och GITHUB (nekas i LABB)
 * Förbereder ett exempelekosystem:
 *  - demokontot "demo" (lösenord Demo2026!) med inloggningsbehörighet till FastighetsKollen,
 *    MätarKollen och ProjektKollen (inte till CentralKollen, alltså ingen administratör);
 *  - bas-URL för de tre verktygen, om den saknas, gissad utifrån CentralKollens egen adress
 *    (<samma mapp>/fastighetskollen/ osv.). Nycklar och aktivering görs som vanligt på verktygets sida.
 * Öppen bara när config.php har KOLL_INSTALL = true. Kan köras flera gånger (lägger bara till det som saknas).
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/cen.php';

$oppen = defined('KOLL_INSTALL') && KOLL_INSTALL === true && KOLL_VARIANT !== 'labb';

const CEN_DEMO_KONTO = ['anvandarnamn' => 'demo', 'losenord' => 'Demo2026!', 'fornamn' => 'Demo', 'efternamn' => 'Användare',
                        'epost' => 'demo@example.com', 'telefon' => ''];
const CEN_DEMO_VERKTYG = ['fas' => 'fastighetskollen', 'mat' => 'matarkollen', 'prj' => 'projektkollen'];

function cen_demo_kor(): array
{
    $logg = [];
    $ta = kc_tabell('md_anvandare');
    $k = CEN_DEMO_KONTO;
    $anv = cen_anvandare_via_namn($k['anvandarnamn']);
    if ($anv === null) {
        $u = kc_uuid();
        kc_kor("INSERT INTO `$ta` (uuid, last_changed, is_deleted, source_tool, anvandarnamn, fornamn, efternamn, epost, telefon, losenord_hash, is_active)
                VALUES (?, ?, 0, 'cen', ?, ?, ?, ?, ?, ?, 1)",
            [$u, kc_nu(), $k['anvandarnamn'], $k['fornamn'], $k['efternamn'], $k['epost'], $k['telefon'], kc_losenord_hash($k['losenord'])]);
        $logg[] = "Skapade demokontot {$k['anvandarnamn']} (lösenord {$k['losenord']}).";
    } else {
        $u = $anv['uuid'];
        $logg[] = "Demokontot {$k['anvandarnamn']} finns redan.";
    }
    $bas = (string)preg_replace('#[^/]+/$#', '', KOLL_URL);
    $tv = kc_tabell('md_verktyg');
    foreach (CEN_DEMO_VERKTYG as $prefix => $mapp) {
        $vu = cen_verktyg_uuid($prefix);
        if ($vu === null) {
            continue;
        }
        if (cen_behorighet_satt($u, $vu, true)) {
            $logg[] = "Behörighet till $prefix för demokontot.";
        }
        $n = kc_kor("UPDATE `$tv` SET bas_url = ?, last_changed = ?, source_tool = 'cen' WHERE uuid = ? AND bas_url = ''",
            [$bas . $mapp . '/', kc_nu(), $vu]);
        if ($n > 0) {
            $logg[] = "Bas-URL för $prefix: {$bas}{$mapp}/";
        }
    }
    return $logg;
}

if (kc_ar_post() && kc_post('atgard') === 'fyll') {
    if (!$oppen) {
        throw new KcFel('Exempeldata kan bara läggas in i DEMO/GITHUB när KOLL_INSTALL = true.', 403);
    }
    $_SESSION['cen_demo_logg'] = kc_transaktion(fn() => cen_demo_kor());
    kc_flash('ok', 'Klart.');
    kc_omdirigera('demo_data.php');
}

$logg = $_SESSION['cen_demo_logg'] ?? [];
unset($_SESSION['cen_demo_logg']);
kc_sidhuvud('Exempeldata');
?>
<div class="kc-ruta" style="max-width:52rem">
  <h2>Exempelekosystem i CentralKollen</h2>
  <p>Skapar demokontot <code>demo</code> (lösenord <code>Demo2026!</code>) med behörighet till FastighetsKollen, MätarKollen och
     ProjektKollen, och fyller i verktygens bas-URL om den saknas. Nycklar, aktivering, tabeller och prenumerationer görs sedan
     på respektive verktygs sida under Verktyg.</p>
  <?php if ($logg !== []): ?><ul><?php foreach ($logg as $r): ?><li><?= kc_h($r) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if (!$oppen): ?>
    <p><em class="kc-dampad">Stängd. Kräver KOLL_INSTALL = true i config.php och variant DEMO eller GITHUB (nu: <?= kc_h(KOLL_VARIANT) ?>).</em></p>
  <?php else: ?>
    <form method="post"><?= kc_csrf_falt() ?><input type="hidden" name="atgard" value="fyll">
      <button type="submit" class="kc-knapp-primar">Skapa demokonto</button></form>
  <?php endif; ?>
</div>
<?php kc_sidfot();
