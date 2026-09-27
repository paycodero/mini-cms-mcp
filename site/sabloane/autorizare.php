<?php if (!defined('MINICMS')) { http_response_code(403); exit; } ?>
<section class="autorizare">
  <p class="data"><?= esc(ui('Conectare')) ?></p>
  <h1><?= esc(sprintf(ui('Conectezi %s la %s?'), $client['nume'], (string) text_site('nume'))) ?></h1>
  <div class="aplicatia">
    <p><b><?= esc($client['nume']) ?></b> <?= esc(ui('cere acces la acest site. Numele de mai sus l-a ales aplicația însăși, deci nu dovedește nimic — uită-te la rândurile de dedesubt.')) ?></p>
    <dl class="detalii-client">
      <dt><?= esc(ui('Se întoarce la')) ?></dt><dd><code><?= esc($cerere['redirect_uri']) ?></code></dd>
      <dt><?= esc(ui('Înregistrată')) ?></dt><dd><?= esc($varsta < 120 ? sprintf(ui('acum %d secunde'), (int) $varsta) : sprintf(ui('acum %d de minute'), (int) round($varsta / 60))) ?><?= $varsta > 300 ? ' — <b>' . esc(ui('mai demult decât ar trebui pentru o conectare pornită acum')) . '</b>' : '' ?></dd>
      <dt><?= esc(ui('Identificator')) ?></dt><dd><code><?= esc($cerere['client_id']) ?></code></dd>
    </dl>
    <ul>
      <li><?= esc(ui('Cu cheia de scriere poate citi, crea, modifica și publica pagini și articole, urca imagini și schimba numele site-ului. Nu poate schimba codul, configurarea sau jurnalul.')) ?></li>
      <li><?= esc(ui('Cu o cheie de editor, la fel, afară de numele, aspectul și conexiunile site-ului; tot ce face apare în jurnal pe numele editorului.')) ?></li>
      <li><?= esc(ui('Cu cheia de citire doar citește conținutul și jurnalul.')) ?></li>
    </ul>
<?php if ($cere_cod): ?>
    <p class="mic"><?= esc(ui('Ai nevoie de codul de conectare: cele 6 cifre primite când ai deschis conectarea, pe pagina de conectare a site-ului (sau în terminal). Dacă n-ai deschis-o tu chiar acum, înseamnă că altcineva a pornit această cerere: închide pagina.')) ?> <a href="/oauth/conectare" target="_blank" rel="noopener"><?= esc(ui('Pagina de conectare')) ?></a></p>
<?php else: ?>
    <p class="mic"><?= esc(ui('Continuă doar dacă tocmai ai pornit tu conectarea, din Claude. Accesul se poate retrage oricând, iar schimbarea cheilor îl anulează. Totul se scrie în jurnal.')) ?></p>
<?php endif; ?>
  </div>
<?php if ($mesaj !== ''): ?>
  <p class="alerta"><?= esc(ui($mesaj)) ?></p>
<?php endif; ?>
  <form method="post" action="/oauth/autorizare" class="formular">
<?php foreach ($cerere as $k => $val): ?>
    <input type="hidden" name="<?= esc($k) ?>" value="<?= esc($val) ?>">
<?php endforeach; ?>
<?php if ($cere_cod): ?>
    <label for="cod_conectare"><?= esc(ui('Codul de conectare (6 cifre)')) ?></label>
    <input id="cod_conectare" name="cod_conectare" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off" required>
<?php endif; ?>
    <label for="cheie"><?= esc(ui('Cheia site-ului')) ?></label>
    <input id="cheie" name="cheie" type="password" autocomplete="current-password" required minlength="20">
    <div class="butoane">
      <button class="buton" type="submit" name="decizie" value="permite"><?= esc(ui('Permite')) ?></button>
      <button class="buton buton-gol" type="submit" name="decizie" value="refuza" formnovalidate><?= esc(ui('Refuză')) ?></button>
    </div>
  </form>
</section>
