<?php if (!defined('MINICMS')) { http_response_code(403); exit; } ?>
<div class="carduri">
<?php foreach ($articole as $a): ?>
  <article class="card">
<?php if (($a['imagine'] ?? '') !== ''): $m_card = imagine_masuri((string) $a['imagine']); ?>
    <a class="card-imagine" href="<?= esc(url_element($a)) ?>" tabindex="-1" aria-hidden="true"><img src="<?= esc($a['imagine']) ?>" alt="" loading="lazy" decoding="async"<?= $m_card ? ' width="' . (int) $m_card['latime'] . '" height="' . (int) $m_card['inaltime'] . '"' : '' ?>></a>
<?php endif; ?>
    <div class="card-text">
<?php if ($a['etichete'] ?? []): $s_t = slug_din_text((string) $a['etichete'][0]); ?>
      <a class="card-eticheta eticheta-<?= esc($s_t) ?>" href="/eticheta/<?= esc($s_t) ?>"><?= esc($a['etichete'][0]) ?></a>
<?php endif; ?>
<?php if (data_vizibila($a) !== ''): ?>
      <p class="data"><?= esc(data_vizibila($a)) ?></p>
<?php endif; ?>
      <h3><a href="<?= esc(url_element($a)) ?>"><?= esc($a['titlu']) ?></a></h3>
<?php if (($a['descriere'] ?? '') !== ''): ?>
      <p><?= esc($a['descriere']) ?></p>
<?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>
</div>
