<?php if (!defined('MINICMS')) { http_response_code(403); exit; } ?>
<section class="eroare">
  <p class="cod"><?= (int) $cod ?></p>
  <h1><?= esc($mesaj) ?></h1>
  <p><a class="buton" href="<?= esc(prefix_limba() ?: '/') ?>"><?= esc(ui('Prima pagină')) ?></a></p>
<?php if ($articole): ?>
  <h2><?= esc(ui('Poate căutai')) ?></h2>
<?php require __DIR__ . '/_carduri.php'; ?>
<?php endif; ?>
</section>
