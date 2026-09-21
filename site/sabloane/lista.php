<?php if (!defined('MINICMS')) { http_response_code(403); exit; } ?>
<section>
<?php if (!empty($eticheta)): ?>
  <p class="eticheta-sus">Eticheta</p>
<?php endif; ?>
  <h1><?= esc($titlu) ?></h1>
<?php require __DIR__ . '/_carduri.php'; ?>
<?php if ($total_pagini > 1): ?>
  <nav class="paginare" aria-label="Pagini">
<?php if ($nr > 1): ?><a class="buton" href="<?= esc($baza . ($nr > 2 ? '?pagina=' . ($nr - 1) : '')) ?>">← Mai noi</a><?php endif; ?>
    <span>Pagina <?= $nr ?> din <?= $total_pagini ?></span>
<?php if ($nr < $total_pagini): ?><a class="buton" href="<?= esc($baza . '?pagina=' . ($nr + 1)) ?>">Mai vechi →</a><?php endif; ?>
  </nav>
<?php endif; ?>
</section>
