<?php if (!defined('MINICMS')) { http_response_code(403); exit; } ?>
<div class="carduri">
<?php foreach ($articole as $a): ?>
  <article class="card">
<?php if (($a['imagine'] ?? '') !== ''): ?>
    <a class="card-imagine" href="<?= esc(url_element($a)) ?>" tabindex="-1" aria-hidden="true"><img src="<?= esc($a['imagine']) ?>" alt="" loading="lazy"></a>
<?php endif; ?>
    <div class="card-text">
      <p class="data"><?= esc(data_ro($a['publicat_la'] ?? null)) ?></p>
      <h3><a href="<?= esc(url_element($a)) ?>"><?= esc($a['titlu']) ?></a></h3>
<?php if (($a['descriere'] ?? '') !== ''): ?>
      <p><?= esc($a['descriere']) ?></p>
<?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>
</div>
