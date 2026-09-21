<?php if (!defined('MINICMS')) { http_response_code(403); exit; } ?>
<section class="actualizare">
  <h1>Actualizarea codului</h1>
  <p>Site-ul își ia versiunea nouă din depozitul lui, dar numai când i-o ceri tu, de aici sau din comanda
     <code>php unelte/actualizeaza.php</code>. Conținutul, imaginile, jurnalul și <code>app/config.php</code> nu se ating.</p>
<?php if ($mesaj !== ''): ?>
  <p class="alerta"><?= esc($mesaj) ?></p>
<?php endif; ?>
<?php if ($stare): ?>
  <div class="aplicatia">
    <p>Pe server: <b><?= esc($stare['versiune_instalata']) ?></b> · în depozit: <b><?= esc($stare['versiune_in_pachet']) ?></b></p>
<?php if (!empty($stare['teme_proprii_ocolite'])): ?>
    <p>Depozitul are o temă cu același nume ca tema proprie a site-ului (<?= esc(implode(', ', $stare['teme_proprii_ocolite'])) ?>): tema proprie rămâne neatinsă.</p>
<?php endif; ?>
<?php if (!$stare['de_schimbat'] && !$stare['noi']): ?>
    <p>Nimic de schimbat: codul de pe server e deja cel din depozit.</p>
<?php else: ?>
    <p><?= count($stare['de_schimbat']) ?> fișiere s-ar schimba, <?= count($stare['noi']) ?> ar fi noi:</p>
    <ul>
<?php foreach (array_slice(array_merge($stare['de_schimbat'], $stare['noi']), 0, 40) as $f): ?>
      <li><code><?= esc($f) ?></code></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
  </div>
<?php endif; ?>
<?php if ($rezultat): ?>
  <div class="aplicatia">
    <p><b><?= esc($rezultat['operatie'] ?? '') ?></b><?php if (isset($rezultat['versiune_in_pachet'])): ?> · versiunea <?= esc($rezultat['versiune_in_pachet']) ?><?php endif; ?></p>
<?php if (isset($rezultat['scrise'])): ?>
    <p><?= (int) $rezultat['scrise'] ?> fișiere scrise · copia de siguranță: <code><?= esc($rezultat['copie'] ?? '') ?></code>
       · autocontrol: <?= esc($rezultat['control']['detaliu'] ?? '') ?></p>
<?php endif; ?>
<?php if (isset($rezultat['atentie'])): ?>
    <p class="alerta"><?= esc($rezultat['atentie']) ?></p>
<?php endif; ?>
  </div>
<?php endif; ?>
  <form method="post" action="/actualizare.php" class="formular">
    <label for="cheie">Cheia de cod</label>
    <input id="cheie" name="cheie" type="password" autocomplete="current-password" required minlength="20">
    <div class="butoane">
      <button class="buton buton-gol" type="submit" name="actiune" value="stare">Vezi ce s-ar schimba</button>
      <button class="buton" type="submit" name="actiune" value="sincronizeaza">Adu versiunea din depozit</button>
    </div>
  </form>
<?php if (!empty($teme['proprii'])): ?>
  <p>Teme proprii pe acest site, puse cu <code>php unelte/tema.php</code>:
     <?= esc(implode(', ', array_column($teme['proprii'], 'nume'))) ?>. Sincronizarea cu depozitul nu le atinge.</p>
<?php endif; ?>
<?php if ($copii): ?>
  <h2>Copii de siguranță</h2>
  <p>Fiecare actualizare salvează întâi fișierele pe care le înlocuiește. De aici se pun la loc.</p>
<?php foreach (array_slice($copii, 0, 10) as $c): ?>
  <form method="post" action="/actualizare.php" class="formular formular-rand">
    <input type="hidden" name="actiune" value="restaureaza">
    <input type="hidden" name="copie" value="<?= esc($c['copie']) ?>">
    <span><code><?= esc($c['copie']) ?></code> · versiunea <?= esc($c['versiune']) ?></span>
    <input name="cheie" type="password" placeholder="cheia de cod" required minlength="20">
    <button class="buton buton-gol" type="submit">Pune înapoi</button>
  </form>
<?php endforeach; ?>
<?php endif; ?>
</section>
