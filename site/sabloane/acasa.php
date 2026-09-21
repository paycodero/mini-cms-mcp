<?php if (!defined('MINICMS')) { http_response_code(403); exit; } ?>
<section class="erou">
  <h1><?= esc($acasa ? $acasa['titlu'] : config('site.nume')) ?></h1>
<?php if ($acasa && ($acasa['descriere'] ?? '') !== ''): ?>
  <p class="introducere"><?= esc($acasa['descriere']) ?></p>
<?php elseif (!$acasa && config('site.descriere')): ?>
  <p class="introducere"><?= esc(config('site.descriere')) ?></p>
<?php endif; ?>
</section>
<?php if ($html !== ''): ?>
<div class="continut"><?= $html ?></div>
<?php endif; ?>
<?php if ($articole): ?>
<section class="ultimele">
  <h2>Ultimele <?= esc(nume_articole()) ?></h2>
<?php require __DIR__ . '/_carduri.php'; ?>
  <p class="toate"><a class="buton" href="/articole">Toate <?= esc(nume_articole(true)) ?></a></p>
</section>
<?php endif; ?>
