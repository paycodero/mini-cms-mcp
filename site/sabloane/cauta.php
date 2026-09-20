<?php if (!defined('MINICMS')) { http_response_code(403); exit; } ?>
<section class="cautare">
  <h1>Caută pe site</h1>
  <form class="cauta-mare" action="/cauta" method="get" role="search">
    <input type="search" name="q" value="<?= esc($q) ?>" placeholder="Ce cauți?" aria-label="Ce cauți" maxlength="100">
    <button class="buton" type="submit">Caută</button>
  </form>
<?php if ($q !== ''): ?>
  <p class="numar-rezultate"><?= esc(numar_rezultate(count($rezultate))) ?> pentru „<?= esc($q) ?>”</p>
<?php if ($rezultate): ?>
  <ol class="rezultate">
<?php foreach ($rezultate as $r): $e = $r['e']; ?>
    <li>
      <p class="data"><?= $e['tip'] === 'articol' ? 'Articol' : 'Pagină' ?></p>
      <h3><a href="<?= esc(url_element($e)) ?>"><?= evidentiaza((string) $e['titlu'], $modele) ?></a></h3>
<?php if ($r['fragment'] !== ''): ?>
      <p><?= evidentiaza($r['fragment'], $modele) ?></p>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ol>
<?php else: ?>
  <p>Încearcă alte cuvinte, mai puține sau mai scurte.</p>
<?php endif; ?>
<?php endif; ?>
</section>
