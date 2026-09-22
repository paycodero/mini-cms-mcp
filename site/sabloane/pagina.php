<?php if (!defined('MINICMS')) { http_response_code(403); exit; } ?>
<article class="text">
  <h1><?= esc($e['titlu']) ?></h1>
<?php if (($e['descriere'] ?? '') !== ''): ?>
  <p class="introducere"><?= esc($e['descriere']) ?></p>
<?php endif; ?>
  <div class="continut"><?= $html ?></div>
<?php if ($subpagini ?? []): ?>
  <nav class="subpagini" aria-label="Paginile secțiunii <?= esc($e['titlu']) ?>">
    <h2>În această secțiune</h2>
    <ul>
<?php foreach ($subpagini as $sp): ?>
      <li><a href="<?= esc(url_element($sp)) ?>"><?= esc($sp['titlu']) ?></a><?php if (($sp['descriere'] ?? '') !== ''): ?><span><?= esc($sp['descriere']) ?></span><?php endif; ?></li>
<?php endforeach; ?>
    </ul>
  </nav>
<?php endif; ?>
<?php if ($sectiune ?? null): ?>
  <p class="inapoi-sectiune"><a href="<?= esc(url_element($sectiune)) ?>">← <?= esc($sectiune['titlu']) ?></a></p>
<?php endif; ?>
</article>
