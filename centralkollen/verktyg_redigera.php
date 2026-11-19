<?php
/**
 * CentralKollen – verktyg_redigera.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Bas-URL, aktivering och API-nycklar för ett verktyg. Endast administratörer.
 *
 * Nycklar:
 *  - NYCKEL_TILL_CEN: verktyget använder den mot CentralKollen.
 *    Lagras som SHA-256-hash och visas EN gång när den genereras.
 *  - NYCKEL_FRAN_CEN: CentralKollen använder den mot verktyget.
 *    Lagras läsbar och kan visas när som helst.
 *
 * Verktygets API (fr.o.m. kärna 1.4.0): "Testa verktygets API" anropar ping, och
 * "Hämta tabeller från verktyget" anropar tabeller och erbjuder de tabeller som inte
 * är registrerade än. Tabellnamn kan fortfarande registreras för hand.
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/cen.php';

cen_krav_admin();
$tv = kc_tabell('md_verktyg');

$uuid = kc_get('uuid');
$rad  = kc_ar_uuid($uuid)
    ? kc_hamta_en("SELECT * FROM `$tv` WHERE uuid = ? AND is_deleted = 0 LIMIT 1", [$uuid])
    : null;
if ($rad === null) {
    throw new KcFel('Verktyget finns inte.', 404);
}
$ar_cen   = $rad['prefix'] === 'cen';
$har_sida = 'verktyg_redigera.php?uuid=' . rawurlencode($uuid);
$varden   = $rad;
$fel      = [];

/* ---------- Spara uppgifter ---------- */

if (kc_ar_post() && kc_post('atgard') === 'spara') {
    if ($ar_cen) {
        throw new KcFel('CentralKollen redigeras via config.php.', 400);
    }
    $varden['namn']      = kc_post('namn');
    $varden['bas_url']   = kc_post('bas_url');
    $varden['is_active'] = isset($_POST['is_active']) ? 1 : 0;

    if ($varden['namn'] === '' || mb_strlen($varden['namn']) > 100) {
        $fel[] = 'Namnet måste vara 1–100 tecken.';
    }
    if ($varden['bas_url'] !== '') {
        $delar = parse_url($varden['bas_url']);
        if (mb_strlen($varden['bas_url']) > 255
            || filter_var($varden['bas_url'], FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string)($delar['scheme'] ?? '')), ['http', 'https'], true)
            || isset($delar['query']) || isset($delar['fragment'])
            || !str_ends_with($varden['bas_url'], '/')) {
            $fel[] = 'Bas-URL måste vara en fullständig adress som börjar med https:// och slutar med /.';
        }
    }
    if ($varden['is_active'] === 1 && $varden['bas_url'] === '') {
        $fel[] = 'Ange bas-URL innan verktyget aktiveras.';
    }

    if ($fel === []) {
        $andrat = $varden['namn'] !== $rad['namn']
               || $varden['bas_url'] !== $rad['bas_url']
               || $varden['is_active'] !== (int)$rad['is_active'];
        if ($andrat) {
            kc_kor(
                "UPDATE `$tv` SET namn = ?, bas_url = ?, is_active = ?, last_changed = ?, source_tool = 'cen'
                  WHERE uuid = ? AND is_deleted = 0",
                [$varden['namn'], $varden['bas_url'], $varden['is_active'], kc_nu(), $uuid]
            );
        }
        kc_flash('ok', $andrat ? 'Ändringarna är sparade.' : 'Inget var ändrat.');
        kc_omdirigera($har_sida);
    }
}

/* ---------- Generera nycklar ---------- */

if (kc_ar_post() && in_array(kc_post('atgard'), ['nyckel_till', 'nyckel_fran'], true)) {
    if ($ar_cen) {
        throw new KcFel('CentralKollen behöver inga nycklar mot sig själv.', 400);
    }
    $nyckel = kc_nyckel_generera();
    if (kc_post('atgard') === 'nyckel_till') {
        kc_kor(
            "UPDATE `$tv` SET nyckel_till_cen_hash = ?, last_changed = ?, source_tool = 'cen' WHERE uuid = ? AND is_deleted = 0",
            [hash('sha256', $nyckel), kc_nu(), $uuid]
        );
        // Visas en enda gång, på nästa sidvisning
        $_SESSION['cen_ny_nyckel'] = ['uuid' => $uuid, 'nyckel' => $nyckel];
        kc_flash('ok', 'Ny nyckel till CentralKollen är genererad. Kopiera den nu – den visas bara en gång.');
    } else {
        kc_kor(
            "UPDATE `$tv` SET nyckel_fran_cen = ?, last_changed = ?, source_tool = 'cen' WHERE uuid = ? AND is_deleted = 0",
            [$nyckel, kc_nu(), $uuid]
        );
        kc_flash('ok', 'Ny nyckel från CentralKollen är genererad.');
    }
    kc_omdirigera($har_sida);
}

/* ---------- Testa verktygets API ---------- */

if (kc_ar_post() && kc_post('atgard') === 'api_ping') {
    if ($ar_cen) {
        throw new KcFel('CentralKollen anropar inte sig själv.', 400);
    }
    try {
        $d = cen_verktyg_anrop($rad, 'ping', [], 10);
        $fel_prefix = ($d['verktyg'] ?? '') !== $rad['prefix'];
        kc_flash(
            $fel_prefix ? 'fel' : 'ok',
            $fel_prefix
                ? 'Verktyget svarar, men uppger prefix ' . (string)($d['verktyg'] ?? '?') . ' i stället för ' . $rad['prefix'] . '. Kontrollera bas-URL.'
                : $rad['namn'] . ' svarar. Kärna ' . (string)($d['karna'] ?? '?') . ', variant ' . (string)($d['variant'] ?? '?')
                  . ', ' . (int)($d['antal_tabeller'] ?? 0) . ' delade tabeller.'
        );
    } catch (KcFel $e) {
        kc_flash('fel', $e->getMessage());
    }
    kc_omdirigera($har_sida);
}

/* ---------- Masterdata: ny tabell ---------- */

$tt = kc_tabell('md_tabell');
$tp = kc_tabell('md_prenumeration');

/**
 * Registrerar en tabell för verktyget. En tidigare borttagen tabell återaktiveras med samma uuid.
 * Returnerar 'ny', 'ateraktiverad' eller 'fanns'.
 */
$tabell_registrera = static function (string $tabellnamn, string $beskrivning) use ($tt, $uuid): string {
    $befintliga = kc_hamta_alla(
        "SELECT uuid, is_deleted FROM `$tt` WHERE verktyg_uuid = ? AND tabellnamn = ? ORDER BY is_deleted ASC, id ASC",
        [$uuid, $tabellnamn]
    );
    if ($befintliga !== [] && (int)$befintliga[0]['is_deleted'] === 0) {
        return 'fanns';
    }
    if ($befintliga !== []) {
        kc_kor(
            "UPDATE `$tt` SET is_deleted = 0, is_active = 1, beskrivning = ?, last_changed = ?, source_tool = 'cen' WHERE uuid = ?",
            [$beskrivning, kc_nu(), $befintliga[0]['uuid']]
        );
        return 'ateraktiverad';
    }
    kc_kor(
        "INSERT INTO `$tt` (uuid, last_changed, is_deleted, source_tool, verktyg_uuid, tabellnamn, beskrivning, is_active)
         VALUES (?, ?, 0, 'cen', ?, ?, ?, 1)",
        [kc_uuid(), kc_nu(), $uuid, $tabellnamn, $beskrivning]
    );
    return 'ny';
};

/** Giltigt registrerat tabellnamn? */
$tabellnamn_ok = static fn(string $n): bool =>
    preg_match('/^[a-z][a-z0-9_]{0,39}$/', $n) === 1 && preg_match('/^(md|ld|sys|ck)_/', $n) !== 1;

/** Verktygets delade tabeller via API:et: [tabellnamn => beskrivning]. Kastar KcFel. */
$api_tabeller = static function () use ($rad, $tabellnamn_ok): array {
    $d = cen_verktyg_anrop($rad, 'tabeller', [], 10);
    $lista = [];
    foreach (is_array($d['tabeller'] ?? null) ? $d['tabeller'] : [] as $t) {
        $namn = is_string($t['tabell'] ?? null) ? $t['tabell'] : '';
        if ($tabellnamn_ok($namn)) {
            $lista[$namn] = mb_substr(is_string($t['beskrivning'] ?? null) ? $t['beskrivning'] : '', 0, 255);
        }
    }
    return $lista;
};

if (kc_ar_post() && kc_post('atgard') === 'tabell_fran_api') {
    if ($ar_cen) {
        throw new KcFel('CentralKollens egna tabeller registreras inte här.', 400);
    }
    $valda = $_POST['tabeller'] ?? [];
    $valda = is_array($valda) ? array_values(array_filter($valda, 'is_string')) : [];
    if ($valda === []) {
        kc_flash('info', 'Inga tabeller valda.');
        kc_omdirigera($har_sida . '&api_tabeller=1');
    }
    try {
        $erbjudna = $api_tabeller();   // hämtas igen – lita inte på formulärets beskrivningar
    } catch (KcFel $e) {
        kc_flash('fel', $e->getMessage());
        kc_omdirigera($har_sida);
    }
    $res = ['ny' => 0, 'ateraktiverad' => 0, 'fanns' => 0];
    $okanda = [];
    kc_transaktion(function () use ($valda, $erbjudna, $tabell_registrera, &$res, &$okanda): void {
        foreach (array_unique($valda) as $namn) {
            if (!array_key_exists($namn, $erbjudna)) {
                $okanda[] = $namn;
                continue;
            }
            $res[$tabell_registrera($namn, $erbjudna[$namn])]++;
        }
    });
    $antal = $res['ny'] + $res['ateraktiverad'];
    kc_flash($antal > 0 ? 'ok' : 'info', "Registrerade $antal tabeller"
        . ($res['fanns'] > 0 ? ', ' . $res['fanns'] . ' fanns redan' : '')
        . ($okanda !== [] ? '. Verktyget erbjuder inte längre: ' . implode(', ', $okanda) : '') . '.');
    kc_omdirigera($har_sida);
}

if (kc_ar_post() && kc_post('atgard') === 'tabell_ny') {
    if ($ar_cen) {
        throw new KcFel('CentralKollens egna tabeller registreras inte här.', 400);
    }
    $tabellnamn  = strtolower(kc_post('tabellnamn'));
    $beskrivning = kc_post('beskrivning');

    if (!$tabellnamn_ok($tabellnamn)) {
        kc_flash('fel', 'Tabellnamnet ska vara 1–40 tecken a–z, 0–9 och _, börja med en bokstav och utan md_/ld_/sys_/ck_ (de läggs till automatiskt).');
        kc_omdirigera($har_sida);
    }
    if (mb_strlen($beskrivning) > 255) {
        kc_flash('fel', 'Beskrivningen är för lång (max 255 tecken).');
        kc_omdirigera($har_sida);
    }
    if ($tabell_registrera($tabellnamn, $beskrivning) === 'fanns') {
        kc_flash('fel', "Tabellen $tabellnamn finns redan för " . $rad['namn'] . '.');
    } else {
        kc_flash('ok', "Tabellen $tabellnamn är registrerad.");
    }
    kc_omdirigera($har_sida);
}

/* ---------- Prenumerationer ---------- */

if (kc_ar_post() && kc_post('atgard') === 'pren_ny') {
    if ($ar_cen) {
        throw new KcFel('CentralKollen prenumererar inte på tabeller.', 400);
    }
    $tabell_uuid = kc_post('tabell_uuid');
    $tabell = kc_ar_uuid($tabell_uuid)
        ? kc_hamta_en(
            "SELECT t.uuid, t.tabellnamn, t.verktyg_uuid FROM `$tt` t
               JOIN `$tv` v ON v.uuid = t.verktyg_uuid AND v.is_deleted = 0
              WHERE t.uuid = ? AND t.is_deleted = 0 LIMIT 1",
            [$tabell_uuid])
        : null;
    if ($tabell === null) {
        kc_flash('fel', 'Välj en tabell i listan.');
        kc_omdirigera($har_sida);
    }
    if ($tabell['verktyg_uuid'] === $uuid) {
        kc_flash('fel', 'Ett verktyg kan inte prenumerera på sin egen masterdata.');
        kc_omdirigera($har_sida);
    }
    $befintliga = kc_hamta_alla(
        "SELECT uuid, is_deleted FROM `$tp` WHERE verktyg_uuid = ? AND tabell_uuid = ? ORDER BY is_deleted ASC, id ASC",
        [$uuid, $tabell_uuid]
    );
    if ($befintliga !== [] && (int)$befintliga[0]['is_deleted'] === 0) {
        kc_flash('info', 'Prenumerationen finns redan.');
        kc_omdirigera($har_sida);
    }
    if ($befintliga !== []) {
        kc_kor(
            "UPDATE `$tp` SET is_deleted = 0, last_changed = ?, source_tool = 'cen' WHERE uuid = ?",
            [kc_nu(), $befintliga[0]['uuid']]
        );
    } else {
        kc_kor(
            "INSERT INTO `$tp` (uuid, last_changed, is_deleted, source_tool, verktyg_uuid, tabell_uuid)
             VALUES (?, ?, 0, 'cen', ?, ?)",
            [kc_uuid(), kc_nu(), $uuid, $tabell_uuid]
        );
    }
    kc_flash('ok', 'Prenumerationen på ' . $tabell['tabellnamn'] . ' är tillagd.');
    kc_omdirigera($har_sida);
}

if (kc_ar_post() && kc_post('atgard') === 'pren_bort') {
    $n = kc_kor(
        "UPDATE `$tp` SET is_deleted = 1, last_changed = ?, source_tool = 'cen'
          WHERE uuid = ? AND verktyg_uuid = ? AND is_deleted = 0",
        [kc_nu(), kc_post('pren_uuid'), $uuid]
    );
    kc_flash($n > 0 ? 'ok' : 'info', $n > 0 ? 'Prenumerationen är borttagen.' : 'Prenumerationen fanns inte.');
    kc_omdirigera($har_sida);
}

/* ---------- Visning ---------- */

$egna_tabeller = $ar_cen ? [] : kc_hamta_alla(
    "SELECT t.uuid, t.tabellnamn, t.beskrivning, t.is_active,
            (SELECT COUNT(*) FROM `$tp` p WHERE p.tabell_uuid = t.uuid AND p.is_deleted = 0) AS antal_pren
       FROM `$tt` t
      WHERE t.verktyg_uuid = ? AND t.is_deleted = 0
      ORDER BY t.tabellnamn",
    [$uuid]
);
$prenumerationer = $ar_cen ? [] : kc_hamta_alla(
    "SELECT p.uuid AS pren_uuid, t.uuid AS tabell_uuid, t.tabellnamn, t.is_active, v.prefix AS master, v.namn AS master_namn
       FROM `$tp` p
       JOIN `$tt` t ON t.uuid = p.tabell_uuid AND t.is_deleted = 0
       JOIN `$tv` v ON v.uuid = t.verktyg_uuid AND v.is_deleted = 0
      WHERE p.verktyg_uuid = ? AND p.is_deleted = 0
      ORDER BY v.namn, t.tabellnamn",
    [$uuid]
);
$valbara = $ar_cen ? [] : kc_hamta_alla(
    "SELECT t.uuid, t.tabellnamn, v.prefix AS master, v.namn AS master_namn
       FROM `$tt` t
       JOIN `$tv` v ON v.uuid = t.verktyg_uuid AND v.is_deleted = 0
      WHERE t.is_deleted = 0 AND t.verktyg_uuid <> ?
        AND NOT EXISTS (SELECT 1 FROM `$tp` p WHERE p.verktyg_uuid = ? AND p.tabell_uuid = t.uuid AND p.is_deleted = 0)
      ORDER BY v.namn, t.tabellnamn",
    [$uuid, $uuid]
);
$demo = KOLL_VARIANT === 'demo' ? 'demo_' : '';

// Tabellista från verktygets API, bara när den efterfrågas (?api_tabeller=1)
$api_lista = null;
$api_fel = null;
if (!$ar_cen && kc_get('api_tabeller') === '1') {
    try {
        $api_lista = $api_tabeller();
    } catch (KcFel $e) {
        $api_fel = $e->getMessage();
    }
}
$registrerade = array_column($egna_tabeller, 'tabellnamn');
$kan_anropa = !$ar_cen && $rad['bas_url'] !== '' && $rad['nyckel_fran_cen'] !== '';

$ny_till = null;
if (($_SESSION['cen_ny_nyckel']['uuid'] ?? null) === $uuid) {
    $ny_till = (string)$_SESSION['cen_ny_nyckel']['nyckel'];
}
unset($_SESSION['cen_ny_nyckel']);

$har_till = $rad['nyckel_till_cen_hash'] !== '';
$har_fran = $rad['nyckel_fran_cen'] !== '';

/** Värde i enkla citattecken för PHP-kod. */
$php_str = static fn(string $v): string => "'" . addcslashes($v, "\\'") . "'";

/** En define-rad, med ev. kommentar EFTER semikolonet så att raden alltid är giltig PHP. */
$define = static fn(string $namn, string $varde, string $kommentar = ''): string =>
    str_pad("define('$namn',", 25) . ' ' . $varde . ');' . ($kommentar !== '' ? "  // $kommentar" : '') . "\n";

$config_rader = "// Koppling till CentralKollen – från CentralKollens verktygsregister\n"
    . $define('KOLL_PREFIX', $php_str($rad['prefix']))
    . $define('KOLL_NAMN', $php_str($varden['namn']))
    . ($rad['bas_url'] !== ''
        ? $define('KOLL_URL', $php_str($rad['bas_url']))
        : $define('KOLL_URL', "''", 'ange bas-URL ovan först'))
    . $define('CEN_URL', $php_str(KOLL_URL))
    . match (true) {
        $ny_till !== null => $define('NYCKEL_TILL_CEN', $php_str($ny_till)),
        $har_till         => $define('NYCKEL_TILL_CEN', "'…'", 'behåll värdet du redan har, eller generera en ny'),
        default           => $define('NYCKEL_TILL_CEN', "''", 'generera nyckeln ovan'),
    }
    . ($har_fran
        ? $define('NYCKEL_FRAN_CEN', $php_str($rad['nyckel_fran_cen']))
        : $define('NYCKEL_FRAN_CEN', "''", 'generera nyckeln ovan'));

kc_sidhuvud('Verktyg: ' . $rad['namn']);
?>
<p><a href="<?= kc_h(kc_url('verktyg.php')) ?>">« Tillbaka till listan</a></p>

<?php if ($ar_cen): ?>
<div class="kc-ruta" style="max-width:48rem">
  <h2>CentralKollen själv</h2>
  <p>Prefix <code>cen</code> · alltid aktiv.</p>
  <p>Bas-URL hämtas från <code>config.php</code> (<code>KOLL_URL</code>):<br>
     <code><?= kc_h(KOLL_URL) ?></code></p>
  <p class="kc-dampad">CentralKollen behöver inga nycklar mot sig själv. Behörighet till
     CentralKollen betyder administratör.</p>
</div>
<?php else: ?>

<?php if ($fel !== []): ?>
<div class="kc-flash kc-flash-fel">
  <strong>Kunde inte spara:</strong>
  <ul style="margin:.25rem 0 0">
    <?php foreach ($fel as $f): ?><li><?= kc_h($f) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if ($ny_till !== null): ?>
<div class="kc-ruta" style="max-width:48rem;border-color:var(--kc-lank)">
  <h2>Ny NYCKEL_TILL_CEN – visas bara nu</h2>
  <p>Kopiera nyckeln och klistra in den i <strong><?= kc_h($rad['namn']) ?></strong>s
     <code>config.php</code> som <code>NYCKEL_TILL_CEN</code>. När du lämnar sidan går den inte att visa igen.</p>
  <p><code class="kc-nyckel" id="ny-nyckel"><?= kc_h($ny_till) ?></code></p>
  <button type="button" data-kopiera="ny-nyckel">Kopiera nyckeln</button>
</div>
<?php endif; ?>

<form method="post">
  <?= kc_csrf_falt() ?>
  <input type="hidden" name="atgard" value="spara">
  <div class="kc-ruta" style="max-width:48rem">
    <h2>Uppgifter</h2>
    <p>Prefix: <code><?= kc_h($rad['prefix']) ?></code> <span class="kc-dampad">(rollen i ekosystemet, kan inte ändras)</span></p>
    <div class="kc-falt">
      <label for="namn">Namn</label>
      <input type="text" id="namn" name="namn" maxlength="100" required value="<?= kc_h($varden['namn']) ?>">
      <div class="kc-hjalp">Kan ändras om rollen fylls av ett annat system.</div>
    </div>
    <div class="kc-falt">
      <label for="bas_url">Bas-URL</label>
      <input type="url" id="bas_url" name="bas_url" maxlength="255" style="width:100%"
             value="<?= kc_h($varden['bas_url']) ?>"
             placeholder="<?= kc_h((string)preg_replace('#[^/]+/$#', '', KOLL_URL)) ?>&lt;mapp&gt;/">
      <div class="kc-hjalp">Adressen till verktygets mapp, slutar med /. Samma värde som verktygets <code>KOLL_URL</code>.</div>
    </div>
    <div class="kc-falt">
      <label>
        <input type="checkbox" name="is_active" value="1" <?= (int)$varden['is_active'] === 1 ? 'checked' : '' ?>>
        Aktivt i ekosystemet
      </label>
      <div class="kc-hjalp">Inaktiva verktyg kommer att nekas vid synk.</div>
    </div>
    <button type="submit" class="kc-knapp-primar">Spara</button>
  </div>
</form>

<div class="kc-ruta" style="max-width:48rem">
  <h2>API-nycklar</h2>

  <h3 style="margin-bottom:.25rem">NYCKEL_TILL_CEN</h3>
  <p class="kc-hjalp">Verktyget använder den när det anropar CentralKollen. CentralKollen sparar bara en hash,
     så nyckeln visas bara när den genereras.</p>
  <p>Status: <?= $har_till ? '<strong>Finns</strong>' : '<em class="kc-dampad">saknas</em>' ?></p>
  <form method="post" class="kc-inline"
        <?= $har_till ? 'onsubmit="return confirm(\'Den gamla nyckeln slutar fungera direkt. Fortsätt?\');"' : '' ?>>
    <?= kc_csrf_falt() ?>
    <input type="hidden" name="atgard" value="nyckel_till">
    <button type="submit"><?= $har_till ? 'Generera ny (ersätter den gamla)' : 'Generera nyckel' ?></button>
  </form>

  <h3 style="margin:1.25rem 0 .25rem">NYCKEL_FRAN_CEN</h3>
  <p class="kc-hjalp">CentralKollen använder den när den anropar verktyget (t.ex. "hämta all data").
     Sparas läsbar.</p>
  <?php if ($har_fran): ?>
    <details><summary>Visa nyckeln</summary>
      <p><code class="kc-nyckel" id="fran-nyckel"><?= kc_h($rad['nyckel_fran_cen']) ?></code></p>
      <button type="button" data-kopiera="fran-nyckel">Kopiera nyckeln</button>
    </details>
  <?php else: ?>
    <p>Status: <em class="kc-dampad">saknas</em></p>
  <?php endif; ?>
  <form method="post" class="kc-inline"
        <?= $har_fran ? 'onsubmit="return confirm(\'Den gamla nyckeln slutar fungera när verktyget fått den nya. Fortsätt?\');"' : '' ?>>
    <?= kc_csrf_falt() ?>
    <input type="hidden" name="atgard" value="nyckel_fran">
    <button type="submit" style="margin-top:.5rem"><?= $har_fran ? 'Generera ny (ersätter den gamla)' : 'Generera nyckel' ?></button>
  </form>
</div>

<div class="kc-ruta" style="max-width:48rem">
  <h2>Verktygets API</h2>
  <p class="kc-hjalp">CentralKollen anropar <code><?= kc_h($rad['bas_url'] !== '' ? $rad['bas_url'] . 'api/' : '<bas-URL>api/') ?></code>
     med NYCKEL_FRAN_CEN. Verktyget måste ha nyckeln i sin <code>config.php</code> och kärna 1.4.0 eller senare.</p>
  <?php if (!$kan_anropa): ?>
    <p><em class="kc-dampad">Ange bas-URL och generera NYCKEL_FRAN_CEN först.</em></p>
  <?php else: ?>
    <form method="post" class="kc-inline">
      <?= kc_csrf_falt() ?>
      <input type="hidden" name="atgard" value="api_ping">
      <button type="submit">Testa verktygets API</button>
    </form>
  <?php endif; ?>
</div>

<div class="kc-ruta" style="max-width:48rem">
  <h2>Masterdata – tabeller som <?= kc_h($varden['namn']) ?> äger</h2>
  <?php if ($egna_tabeller === []): ?>
    <p><em class="kc-dampad">Inga tabeller registrerade.</em></p>
  <?php else: ?>
    <div class="kc-tabell-wrap"><table class="kc-tabell">
      <thead><tr><th>Tabell</th><th>Central kopia</th><th>Prenumeranter</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($egna_tabeller as $t): ?>
        <tr>
          <td><a href="<?= kc_h(kc_url('tabell_redigera.php?uuid=' . rawurlencode($t['uuid']))) ?>"><?= kc_h($t['tabellnamn']) ?></a>
              <?php if ($t['beskrivning'] !== ''): ?><br><span class="kc-hjalp"><?= kc_h($t['beskrivning']) ?></span><?php endif; ?></td>
          <td><code><?= kc_h(kc_ck_tabell($rad['prefix'], $t['tabellnamn'])) ?></code></td>
          <td><?= kc_h($t['antal_pren']) ?></td>
          <td><?= (int)$t['is_active'] === 1 ? 'Aktiv' : '<span class="kc-dampad">Inaktiv</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>

  <h3 style="margin:1.25rem 0 .5rem">Registrera tabeller från verktyget</h3>
  <?php if (!$kan_anropa): ?>
    <p><em class="kc-dampad">Kräver bas-URL och NYCKEL_FRAN_CEN (se Verktygets API).</em></p>
  <?php elseif ($api_lista === null && $api_fel === null): ?>
    <p><a class="kc-knapp" href="<?= kc_h(kc_url($har_sida . '&api_tabeller=1')) ?>">Hämta tabeller från verktyget</a></p>
  <?php elseif ($api_fel !== null): ?>
    <div class="kc-flash kc-flash-fel"><?= kc_h($api_fel) ?></div>
    <p><a href="<?= kc_h(kc_url($har_sida . '&api_tabeller=1')) ?>">Försök igen</a></p>
  <?php else:
      $nya = array_diff_key($api_lista, array_flip($registrerade)); ?>
    <?php if ($nya === []): ?>
      <p>Alla <?= kc_h(count($api_lista)) ?> tabeller som verktyget delar är redan registrerade.</p>
    <?php else: ?>
      <form method="post">
        <?= kc_csrf_falt() ?>
        <input type="hidden" name="atgard" value="tabell_fran_api">
        <div class="kc-tabell-wrap"><table class="kc-tabell">
          <thead><tr><th></th><th>Tabell</th><th>Beskrivning</th></tr></thead>
          <tbody>
          <?php foreach ($nya as $namn => $beskr): ?>
            <tr>
              <td><input type="checkbox" name="tabeller[]" value="<?= kc_h($namn) ?>" checked aria-label="<?= kc_h($namn) ?>"></td>
              <td><code><?= kc_h($namn) ?></code></td>
              <td><?= kc_h($beskr) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <p class="kc-hjalp"><?= kc_h(count($nya)) ?> av <?= kc_h(count($api_lista)) ?> delade tabeller är inte registrerade.
           Namnen kan inte ändras i efterhand.</p>
        <button type="submit" class="kc-knapp-primar">Registrera valda</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>

  <details style="margin-top:1rem">
  <summary>Registrera tabell för hand</summary>
  <form method="post" style="margin-top:.5rem">
    <?= kc_csrf_falt() ?>
    <input type="hidden" name="atgard" value="tabell_ny">
    <div class="kc-falt">
      <label for="tabellnamn">Tabellnamn</label>
      <input type="text" id="tabellnamn" name="tabellnamn" maxlength="40" required pattern="[a-z][a-z0-9_]{0,39}"
             placeholder="t.ex. nycklar">
      <div class="kc-hjalp">Bara själva namnet: <code>nycklar</code> blir <code><?= kc_h($demo . $rad['prefix']) ?>_md_nycklar</code>
         hos verktyget och <code><?= kc_h(kc_ck_tabell($rad['prefix'], 'nycklar')) ?></code> centralt.
         Namnet kan inte ändras i efterhand.</div>
    </div>
    <div class="kc-falt">
      <label for="beskrivning">Beskrivning</label>
      <input type="text" id="beskrivning" name="beskrivning" maxlength="255" style="width:100%">
    </div>
    <button type="submit">Registrera tabell</button>
  </form>
  </details>
</div>

<div class="kc-ruta" style="max-width:48rem">
  <h2>Prenumerationer – data som <?= kc_h($varden['namn']) ?> hämtar</h2>
  <p class="kc-hjalp">Användare synkas automatiskt till alla verktyg (bara de med behörighet) och listas inte här.</p>
  <?php if ($prenumerationer === []): ?>
    <p><em class="kc-dampad">Inga prenumerationer.</em></p>
  <?php else: ?>
    <div class="kc-tabell-wrap"><table class="kc-tabell">
      <thead><tr><th>Från</th><th>Tabell</th><th>Lokal kopia</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($prenumerationer as $p): ?>
        <tr>
          <td><code><?= kc_h($p['master']) ?></code> <?= kc_h($p['master_namn']) ?></td>
          <td><a href="<?= kc_h(kc_url('tabell_redigera.php?uuid=' . rawurlencode($p['tabell_uuid']))) ?>"><?= kc_h($p['tabellnamn']) ?></a>
              <?php if ((int)$p['is_active'] !== 1): ?><span class="kc-dampad">(inaktiv)</span><?php endif; ?></td>
          <td><code><?= kc_h($demo . $rad['prefix'] . '_ld_' . $p['master'] . '_' . $p['tabellnamn']) ?></code></td>
          <td>
            <form method="post" class="kc-inline">
              <?= kc_csrf_falt() ?>
              <input type="hidden" name="atgard" value="pren_bort">
              <input type="hidden" name="pren_uuid" value="<?= kc_h($p['pren_uuid']) ?>">
              <button type="submit" class="kc-lank-knapp">Ta bort</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>

  <h3 style="margin:1.25rem 0 .5rem">Lägg till prenumeration</h3>
  <?php if ($valbara === []): ?>
    <p><em class="kc-dampad">Det finns inga fler tabeller att prenumerera på.</em></p>
  <?php else: ?>
    <form method="post">
      <?= kc_csrf_falt() ?>
      <input type="hidden" name="atgard" value="pren_ny">
      <select name="tabell_uuid" required>
        <option value="">– välj tabell –</option>
        <?php $grupp = null; foreach ($valbara as $t):
            if ($grupp !== $t['master']):
                if ($grupp !== null): ?></optgroup><?php endif;
                $grupp = $t['master']; ?>
          <optgroup label="<?= kc_h($t['master_namn'] . ' (' . $t['master'] . ')') ?>">
        <?php endif; ?>
          <option value="<?= kc_h($t['uuid']) ?>"><?= kc_h($t['tabellnamn']) ?></option>
        <?php endforeach; ?>
          </optgroup>
      </select>
      <button type="submit">Lägg till</button>
    </form>
  <?php endif; ?>
</div>

<div class="kc-ruta" style="max-width:48rem">
  <h2>Klistra in i <?= kc_h($rad['namn']) ?>s config.php</h2>
  <p class="kc-hjalp">Ersätt motsvarande rader i verktygets <code>config.php</code>. Databasuppgifterna lägger du in själv.</p>
  <pre class="kc-kod" id="config-rader" style="white-space:pre-wrap;word-break:break-all;padding:.75rem"><?= kc_h($config_rader) ?></pre>
  <button type="button" data-kopiera="config-rader">Kopiera raderna</button>
</div>

<script>
document.querySelectorAll('[data-kopiera]').forEach(function (knapp) {
  knapp.addEventListener('click', function () {
    var text = document.getElementById(knapp.dataset.kopiera).textContent;
    navigator.clipboard.writeText(text).then(function () {
      var gammal = knapp.textContent;
      knapp.textContent = 'Kopierat!';
      setTimeout(function () { knapp.textContent = gammal; }, 1500);
    });
  });
});
</script>
<?php endif; ?>
<?php kc_sidfot();
