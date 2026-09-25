<?php if (!defined('MINICMS')) { http_response_code(403); exit; } ?>
<section class="autorizare">
  <p class="data">Conectare</p>
<?php if ($fereastra === null): ?>
  <h1>Legi <?= esc(config('site.nume')) ?> de Claude</h1>
  <div class="aplicatia">
    <p>Pagina asta îți dă <b>codul de conectare</b>, de 6 cifre, cerut când legi site-ul în Claude: pe claude.ai, în aplicația Claude pentru calculator sau pe telefon.
       Codul e bun 15 minute, pentru o singură conectare.</p>
    <p class="mic">Îți trebuie cheia de scriere (sau cheia ta de editor). Cheia nu se păstrează nicăieri; deschiderea conectării se scrie în jurnal, pe numele cheii.</p>
  </div>
<?php if ($mesaj !== ''): ?>
  <p class="alerta"><?= esc($mesaj) ?></p>
<?php endif; ?>
  <form method="post" action="/oauth/conectare" class="formular">
    <label for="cheie">Cheia ta</label>
    <input id="cheie" name="cheie" type="password" autocomplete="current-password" required minlength="20">
    <button class="buton" type="submit">Dă-mi codul de conectare</button>
  </form>
<?php else: ?>
  <h1>Codul tău de conectare</h1>
<?php if ($fereastra['cod'] !== ''): ?>
  <p class="cod-conectare" aria-label="Codul de conectare"><?= esc(implode(' ', str_split($fereastra['cod'], 3))) ?></p>
  <p>E bun până la <b><?= esc($fereastra['pana_la']) ?></b>, pentru o singură conectare. Nu-l trimite nimănui.</p>
<?php else: ?>
  <p>Pe acest site conectarea e deschisă permanent: nu ai nevoie de cod.</p>
<?php endif; ?>
  <div class="aplicatia">
    <p><b>Acum, în Claude:</b></p>
    <ol>
      <li>Settings → Connectors → <b>Add custom connector</b>.</li>
      <li>La URL pune <code><?= esc($fereastra['mcp']) ?></code> și apasă <b>Add</b>, apoi <b>Connect</b>.</li>
      <li>Pe pagina care se deschide scrii codul de mai sus și cheia ta, apoi apeși <b>Permite</b>.</li>
    </ol>
  </div>
<?php endif; ?>
</section>
