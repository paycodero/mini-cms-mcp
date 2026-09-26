<?php if (!defined('MINICMS')) { http_response_code(403); exit; } ?>
<article class="text">
<?php if (($e['autor'] ?? '') !== '' || data_vizibila($e) !== ''): ?>
  <p class="semnatura"><?= esc($e['autor'] ?? '') ?><?php if (data_vizibila($e) !== ''): ?><span class="data-publicarii"><?= esc(data_vizibila($e)) ?></span><?php endif; ?></p>
<?php endif; ?>
  <h1><?= esc($e['titlu']) ?></h1>
<?php if (($e['descriere'] ?? '') !== ''): ?>
  <p class="introducere"><?= esc($e['descriere']) ?></p>
<?php endif; ?>
<?php if ($e['etichete'] ?? []): ?>
  <p class="etichete">
<?php foreach ($e['etichete'] as $t): $s_t = slug_din_text((string) $t); ?>
    <a class="eticheta eticheta-<?= esc($s_t) ?>" href="<?= esc(prefix_limba(limba_element($e))) ?>/eticheta/<?= esc($s_t) ?>"><?= esc($t) ?></a>
<?php endforeach; ?>
  </p>
<?php endif; ?>
<?php // coperta nu se repetă sus dacă aceeași imagine e deja în text (de ex. într-o comparație)
if (($e['imagine'] ?? '') !== '' && strpos($html, (string) $e['imagine']) === false): ?>
  <?php $m_cop = imagine_masuri((string) $e['imagine']); ?>
  <figure class="coperta<?= e_portret((string) $e['imagine']) ? ' coperta-portret' : '' ?>"><img src="<?= esc($e['imagine']) ?>" alt="<?= esc($e['imagine_alt'] ?? '') ?>" fetchpriority="high"<?= $m_cop ? ' width="' . (int) $m_cop['latime'] . '" height="' . (int) $m_cop['inaltime'] . '"' : '' ?>></figure>
<?php endif; ?>
  <div class="continut"><?= $html ?></div>
<?php if ($legate ?? []): ?>
  <aside class="legate">
    <h2><?= esc(ui('Citește mai departe')) ?></h2>
    <div class="legate-lista">
<?php foreach ($legate as $x): ?>
      <a class="legat" href="<?= esc(url_element($x)) ?>">
<?php if (($x['imagine'] ?? '') !== ''): ?>
        <img src="<?= esc($x['imagine']) ?>"<?= e_portret((string) $x['imagine']) ? ' class="portret"' : '' ?> alt="" width="88" height="66" loading="lazy" decoding="async">
<?php endif; ?>
        <span class="legat-text">
<?php if ($x['etichete'] ?? []): ?>
          <span class="legat-eticheta eticheta-<?= esc(slug_din_text((string) $x['etichete'][0])) ?>"><?= esc($x['etichete'][0]) ?></span>
<?php endif; ?>
          <span class="legat-titlu"><?= esc($x['titlu']) ?></span>
        </span>
      </a>
<?php endforeach; ?>
    </div>
  </aside>
<?php endif; ?>
  <p class="inapoi"><a href="<?= esc(prefix_limba()) ?>/articole">← <?= esc(ui('Toate')) ?> <?= esc(nume_articole(true)) ?></a></p>
</article>
