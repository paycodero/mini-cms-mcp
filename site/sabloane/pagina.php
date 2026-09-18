<?php if (!defined('MINICMS')) { http_response_code(403); exit; } ?>
<article class="text">
  <h1><?= esc($e['titlu']) ?></h1>
<?php if (($e['descriere'] ?? '') !== ''): ?>
  <p class="introducere"><?= esc($e['descriere']) ?></p>
<?php endif; ?>
  <div class="continut"><?= $html ?></div>
</article>
