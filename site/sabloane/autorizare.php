<?php if (!defined('MINICMS')) { http_response_code(403); exit; } ?>
<section class="autorizare">
  <p class="data">Conectare</p>
  <h1>Conectezi <?= esc($client['nume']) ?> la <?= esc(config('site.nume')) ?>?</h1>
  <div class="aplicatia">
    <p><b><?= esc($client['nume']) ?></b> cere acces la acest site. După aprobare te întoarce la <b><?= esc($gazda_intoarcere) ?></b>.</p>
    <ul>
      <li>Cu <b>cheia de scriere</b> poate citi, crea, modifica și publica pagini și articole, urca imagini și schimba numele site-ului. Nu poate schimba codul, configurarea sau jurnalul.</li>
      <li>Cu <b>cheia de citire</b> doar citește conținutul și jurnalul.</li>
    </ul>
    <p class="mic">Continuă doar dacă tocmai ai pornit tu conectarea, din Claude. Accesul se poate retrage oricând, iar schimbarea cheilor îl anulează. Totul se scrie în jurnal.</p>
  </div>
<?php if ($mesaj !== ''): ?>
  <p class="alerta"><?= esc($mesaj) ?></p>
<?php endif; ?>
  <form method="post" action="/oauth/autorizare" class="formular">
<?php foreach ($cerere as $k => $val): ?>
    <input type="hidden" name="<?= esc($k) ?>" value="<?= esc($val) ?>">
<?php endforeach; ?>
    <label for="cheie">Cheia site-ului</label>
    <input id="cheie" name="cheie" type="password" autocomplete="current-password" required minlength="20">
    <div class="butoane">
      <button class="buton" type="submit" name="decizie" value="permite">Permite</button>
      <button class="buton buton-gol" type="submit" name="decizie" value="refuza" formnovalidate>Refuză</button>
    </div>
  </form>
</section>
