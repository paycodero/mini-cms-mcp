<?php if (!defined('MINICMS')) { http_response_code(403); exit; } ?>
<section class="autorizare">
  <p class="data">Conectare</p>
  <h1>Conectezi <?= esc($client['nume']) ?> la <?= esc(config('site.nume')) ?>?</h1>
  <div class="aplicatia">
    <p><b><?= esc($client['nume']) ?></b> cere acces la acest site. Numele de mai sus l-a ales aplicația însăși, deci nu dovedește nimic — uită-te la rândurile de dedesubt.</p>
    <dl class="detalii-client">
      <dt>Se întoarce la</dt><dd><code><?= esc($cerere['redirect_uri']) ?></code></dd>
      <dt>Înregistrată</dt><dd><?= $varsta < 120 ? 'acum ' . (int) $varsta . ' secunde' : 'acum ' . (int) round($varsta / 60) . ' de minute' ?><?= $varsta > 300 ? ' — <b>mai demult decât ar trebui pentru o conectare pornită acum</b>' : '' ?></dd>
      <dt>Identificator</dt><dd><code><?= esc($cerere['client_id']) ?></code></dd>
    </dl>
    <ul>
      <li>Cu <b>cheia de scriere</b> poate citi, crea, modifica și publica pagini și articole, urca imagini și schimba numele site-ului. Nu poate schimba codul, configurarea sau jurnalul.</li>
      <li>Cu <b>cheia de citire</b> doar citește conținutul și jurnalul.</li>
    </ul>
<?php if ($cere_cod): ?>
    <p class="mic"><b>Ai nevoie de codul de conectare</b> — cele 6 cifre afișate în terminal când ai deschis conectarea de pe calculatorul tău. Dacă n-ai deschis-o tu chiar acum, înseamnă că altcineva a pornit această cerere: închide pagina.</p>
<?php else: ?>
    <p class="mic">Continuă doar dacă tocmai ai pornit tu conectarea, din Claude. Accesul se poate retrage oricând, iar schimbarea cheilor îl anulează. Totul se scrie în jurnal.</p>
<?php endif; ?>
  </div>
<?php if ($mesaj !== ''): ?>
  <p class="alerta"><?= esc($mesaj) ?></p>
<?php endif; ?>
  <form method="post" action="/oauth/autorizare" class="formular">
<?php foreach ($cerere as $k => $val): ?>
    <input type="hidden" name="<?= esc($k) ?>" value="<?= esc($val) ?>">
<?php endforeach; ?>
<?php if ($cere_cod): ?>
    <label for="cod_conectare">Codul de conectare (din terminal)</label>
    <input id="cod_conectare" name="cod_conectare" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off" required>
<?php endif; ?>
    <label for="cheie">Cheia site-ului</label>
    <input id="cheie" name="cheie" type="password" autocomplete="current-password" required minlength="20">
    <div class="butoane">
      <button class="buton" type="submit" name="decizie" value="permite">Permite</button>
      <button class="buton buton-gol" type="submit" name="decizie" value="refuza" formnovalidate>Refuză</button>
    </div>
  </form>
</section>
