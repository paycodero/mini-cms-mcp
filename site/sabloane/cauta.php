<?php if (!defined('MINICMS')) { http_response_code(403); exit; } ?>
<section class="cautare">
  <h1><?= esc(ui('Caută pe site')) ?></h1>
  <form class="cauta-mare" action="<?= esc(prefix_limba()) ?>/cauta" method="get" role="search">
    <input type="search" name="q" value="<?= esc($q) ?>" placeholder="<?= esc(ui('Ce cauți?')) ?>" aria-label="<?= esc(ui('Ce cauți')) ?>" maxlength="100">
    <button class="buton" type="submit"><?= esc(ui('Caută')) ?></button>
  </form>
<?php if ($q !== ''): ?>
  <p class="numar-rezultate"><?= esc(numar_rezultate(count($rezultate))) ?> <?= esc(ui('pentru')) ?> „<?= esc($q) ?>”</p>
<?php if ($rezultate): ?>
  <ol class="rezultate">
<?php foreach ($rezultate as $r): $e = $r['e']; ?>
    <li>
      <p class="data"><?= esc($e['tip'] === 'articol' ? ui('Articol') : ui('Pagină')) ?></p>
      <h3><a href="<?= esc(url_element($e)) ?>"><?= evidentiaza((string) $e['titlu'], $modele) ?></a></h3>
<?php if ($r['fragment'] !== ''): ?>
      <p><?= evidentiaza($r['fragment'], $modele) ?></p>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ol>
<?php else: ?>
  <p><?= esc(ui('Încearcă alte cuvinte, mai puține sau mai scurte.')) ?></p>
<?php endif; ?>
<?php endif; ?>
</section>
