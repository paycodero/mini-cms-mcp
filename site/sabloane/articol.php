<?php if (!defined('MINICMS')) { http_response_code(403); exit; } ?>
<article class="text">
  <p class="data"><?= esc(data_ro($e['publicat_la'] ?? null)) ?><?php if (($e['autor'] ?? '') !== ''): ?> · <?= esc($e['autor']) ?><?php endif; ?></p>
  <h1><?= esc($e['titlu']) ?></h1>
<?php if (($e['descriere'] ?? '') !== ''): ?>
  <p class="introducere"><?= esc($e['descriere']) ?></p>
<?php endif; ?>
<?php if ($e['etichete'] ?? []): ?>
  <p class="etichete">
<?php foreach ($e['etichete'] as $t): ?>
    <a class="eticheta" href="/eticheta/<?= esc(slug_din_text((string) $t)) ?>"><?= esc($t) ?></a>
<?php endforeach; ?>
  </p>
<?php endif; ?>
<?php if (($e['imagine'] ?? '') !== ''): ?>
  <figure class="coperta"><img src="<?= esc($e['imagine']) ?>" alt="<?= esc($e['imagine_alt'] ?? '') ?>" fetchpriority="high"></figure>
<?php endif; ?>
  <div class="continut"><?= $html ?></div>
  <p class="inapoi"><a href="/articole">← Toate articolele</a></p>
</article>
