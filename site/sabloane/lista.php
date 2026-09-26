<?php if (!defined('MINICMS')) { http_response_code(403); exit; } ?>
<section>
<?php if (!empty($eticheta)): ?>
  <p class="eticheta-sus"><?= esc(ui('Eticheta')) ?></p>
<?php endif; ?>
  <h1><?= esc($titlu) ?></h1>
<?php require __DIR__ . '/_carduri.php'; ?>
<?php if ($total_pagini > 1): ?>
  <nav class="paginare" aria-label="<?= esc(ui('Pagini')) ?>">
<?php if ($nr > 1): ?><a class="buton" href="<?= esc($baza . ($nr > 2 ? '?pagina=' . ($nr - 1) : '')) ?>"><?= esc(ui('← Mai noi')) ?></a><?php endif; ?>
    <span><?= esc(sprintf(ui('Pagina %d din %d'), $nr, $total_pagini)) ?></span>
<?php if ($nr < $total_pagini): ?><a class="buton" href="<?= esc($baza . '?pagina=' . ($nr + 1)) ?>"><?= esc(ui('Mai vechi →')) ?></a><?php endif; ?>
  </nav>
<?php endif; ?>
</section>
