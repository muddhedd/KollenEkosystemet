<?php
/**
 * MätarKollen – fastighetsdata.php
 * Variant: LABB (identisk i DEMO och GITHUB)
 * Sök i den lokala kopian av FastighetsKollens data (fastigheter, byggnader, objekt, objektgrupper).
 * Läsning öppen för alla. Datan ändras i FastighetsKollen och hämtas under Synk.
 * Sökningen är grunden för placeringsvalet för mätpunkter (etapp 2).
 */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/mat.php';

$nivaer = ['' => 'Alla nivåer', 'fastighet' => 'Fastighet', 'byggnad' => 'Byggnad', 'objekt' => 'Objekt', 'objektgrupp' => 'Objektgrupp'];
$q = mb_substr(kc_get('q'), 0, 100);
$niva = array_key_exists(kc_get('niva'), $nivaer) ? kc_get('niva') : '';
$traffar = $q !== '' ? mat_sok_placering($q, $niva !== '' ? $niva : null) : [];

kc_sidhuvud('Fastighetsdata');
?>
<p class="kc-dampad">Kopia av FastighetsKollens data. Ändringar görs i FastighetsKollen och hämtas hit under
   <a href="<?= kc_h(kc_url('synk.php')) ?>">Synk</a>.</p>

<form method="get" class="kc-ruta" style="max-width:48rem">
  <div class="kc-falt">
    <label for="q">Sök på nummer, namn eller gatuadress</label>
    <input type="text" id="q" name="q" maxlength="100" style="width:100%" value="<?= kc_h($q) ?>" autofocus>
  </div>
  <div class="kc-falt">
    <label for="niva">Nivå</label>
    <select id="niva" name="niva">
      <?php foreach ($nivaer as $k => $e): ?>
        <option value="<?= kc_h($k) ?>" <?= $k === $niva ? 'selected' : '' ?>><?= kc_h($e) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="kc-knapp-primar">Sök</button>
  </div>
</form>

<?php if ($q !== ''): ?>
<div class="kc-ruta">
  <h2>Träffar (<?= kc_h(count($traffar)) ?>)</h2>
  <?php if ($traffar === []): ?>
    <p><em class="kc-dampad">Inga träffar. Har fastighetsdatan hämtats?</em></p>
  <?php else: ?>
    <div class="kc-tabell-wrap"><table class="kc-tabell">
      <thead><tr><th>Nivå</th><th>Nummer</th><th>Namn / adress</th><th>Typ</th><th>Ligger i</th><th>Huvudadress</th></tr></thead>
      <tbody>
      <?php foreach ($traffar as $t): ?>
        <tr>
          <td><?= kc_h($nivaer[$t['niva']]) ?></td>
          <td><?= kc_h($t['nummer']) ?></td>
          <td><?= kc_h($t['namn']) ?></td>
          <td><?= kc_h($t['typ']) ?></td>
          <td><?= kc_h($t['plats']) ?></td>
          <td><?= in_array($t['niva'], ['fastighet', 'byggnad'], true) ? kc_h(mat_adress_text($t['niva'], $t['uuid'])) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="kc-hjalp">Högst 25 träffar per nivå visas. Förfina sökningen vid behov.</p>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php kc_sidfot();
