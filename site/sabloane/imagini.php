<?php if (!defined('MINICMS')) { http_response_code(403); exit; } ?>
<section class="jurnal urcare-imagini">
  <h1><?= $link ? 'Urcă poza' : 'Urcă imagini' ?></h1>
<?php if ($mesaj !== ''): ?><p class="alerta"><?= esc($mesaj) ?></p><?php endif; ?>
<?php if ($rezultate): ?>
  <ol class="imagini-urcate">
<?php foreach ($rezultate as $r): ?>
<?php if ($r['ok']): ?>
    <li class="imagine-ok">
      <img src="<?= esc($r['url']) ?>" alt="" loading="lazy">
      <div>
        <code><?= esc($r['url']) ?></code>
        <button class="buton buton-gol copiaza-adresa" type="button" data-adresa="<?= esc($r['url']) ?>">Copiază adresa</button>
        <p class="mic"><?= esc($r['nume']) ?> · <?= (int) $r['latime'] ?>×<?= (int) $r['inaltime'] ?> px · <?= esc((string) round($r['octeti'] / 1024)) ?> KB<?= $r['operatie'] !== 'urcată' ? ' · era deja pe site' : '' ?></p>
      </div>
    </li>
<?php else: ?>
    <li class="imagine-gresita"><strong><?= esc($r['nume']) ?></strong>: <?= esc($r['eroare']) ?></li>
<?php endif; ?>
<?php endforeach; ?>
  </ol>
<?php if ($link): ?>
  <p class="gata-link"><strong>Gata.</strong> Întoarce-te la Claude și spune-i „am urcat-o”: el o găsește și o pune unde trebuie.</p>
  <h2>Mai ai una?</h2>
<?php else: ?>
  <p>Spune-i lui Claude adresa, de exemplu: „pune <code>/media/…</code> ca copertă la articolul X”.</p>
  <h2>Mai urci?</h2>
<?php endif; ?>
<?php elseif ($link): ?>
  <p>Alege poza de pe telefon sau de pe calculator și apasă „Urcă”. Atât: Claude o găsește pe site după aceea.
     Linkul merge până la ora <?= esc($link['pana_la']) ?>.</p>
<?php else: ?>
  <p>Imaginile ajung în <code>/media/</code>, iar adresa lor o dai apoi lui Claude, pentru o pagină sau un articol.
     Cheia de scriere nu se păstrează nicăieri: o trimiți o dată, cu pozele. Mai simplu: cere-i lui Claude un link de urcare.</p>
<?php endif; ?>
  <form method="post" action="/imagini.php" enctype="multipart/form-data" class="formular" id="formular-imagini">
<?php if ($link): ?>
    <input type="hidden" name="e" value="<?= (int) $link['e'] ?>">
    <input type="hidden" name="s" value="<?= esc($link['s']) ?>">
<?php else: ?>
    <label for="cheie">Cheia de scriere</label>
    <input id="cheie" name="cheie" type="password" autocomplete="current-password" required minlength="20">
<?php endif; ?>
    <label for="poze"><?= $link ? 'Poza' : 'Imaginile' ?> (JPEG, PNG, GIF sau WebP)</label>
    <input id="poze" name="poze[]" type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple required>
    <label class="bifa"><input id="micsoreaza" type="checkbox" checked> micșorează pozele mari la 1600 px înainte de trimitere
      (pleacă mai repede de pe telefon și nu mai poartă locația GPS)</label>
    <button class="buton" type="submit">Urcă</button>
    <p class="mic">Cel mult 5 MB pe imagine. Găzduirea primește <?= esc($limite['fisier']) ?> pe fișier și <?= esc($limite['total']) ?> deodată<?= $limite['numar'] > 0 ? ', cel mult ' . (int) $limite['numar'] . ' fișiere' : '' ?>.</p>
  </form>
</section>
<script nonce="<?= esc($nonce) ?>">
(function () {
  var f = document.getElementById('formular-imagini'), intrare = document.getElementById('poze'), bifa = document.getElementById('micsoreaza');
  var gata = false;
  // Poza se redesenează pe o pânză de cel mult 1600 px și pleacă JPEG (PNG rămâne PNG, pentru transparență; GIF rămâne
  // cum e, poate fi animat). Redesenarea lasă în urmă datele EXIF, deci și locația. Dacă browserul nu poate, pleacă originalul.
  function micsoreaza(fisier) {
    if (!/^image\/(jpeg|png|webp)$/.test(fisier.type) || !window.createImageBitmap) return Promise.resolve(fisier);
    return createImageBitmap(fisier).then(function (bmp) {
      var s = Math.min(1, 1600 / Math.max(bmp.width, bmp.height));
      if (s === 1 && fisier.type === 'image/png') return fisier;
      var c = document.createElement('canvas');
      c.width = Math.round(bmp.width * s); c.height = Math.round(bmp.height * s);
      c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
      var tip = fisier.type === 'image/png' ? 'image/png' : 'image/jpeg';
      return new Promise(function (gataPoza) {
        c.toBlob(function (b) {
          gataPoza(b ? new File([b], fisier.name.replace(/\.[a-z0-9]+$/i, '') + (tip === 'image/png' ? '.png' : '.jpg'), {type: tip}) : fisier);
        }, tip, 0.85);
      });
    }).catch(function () { return fisier; });
  }
  if (f && intrare && bifa) f.addEventListener('submit', function (ev) {
    if (gata || !bifa.checked || !window.DataTransfer || !intrare.files.length) return;
    ev.preventDefault();
    var buton = f.querySelector('button[type=submit]');
    buton.disabled = true; buton.textContent = 'Pregătesc pozele…';
    Promise.all(Array.prototype.map.call(intrare.files, micsoreaza)).then(function (lista) {
      var dt = new DataTransfer();
      lista.forEach(function (x) { dt.items.add(x); });
      intrare.files = dt.files;
    }).catch(function () {}).then(function () { gata = true; buton.textContent = 'Urc…'; f.submit(); });
  });
  document.querySelectorAll('.copiaza-adresa').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = b.getAttribute('data-adresa');
      var ok = function () { b.textContent = 'Copiat ✓'; setTimeout(function () { b.textContent = 'Copiază adresa'; }, 1800); };
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(t).then(ok, function () {});
    });
  });
})();
</script>
