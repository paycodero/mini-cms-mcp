<?php if (!defined('MINICMS')) { http_response_code(403); exit; } ?>
<!DOCTYPE html>
<html lang="<?= esc(config('site.limba')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($titlu_pagina) ?></title>
<?php if ($descriere !== ''): ?><meta name="description" content="<?= esc($descriere) ?>">
<?php endif; ?>
<?php if ($noindex): ?><meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<?php if ($canonic): ?><link rel="canonical" href="<?= esc($canonic) ?>">
<meta property="og:url" content="<?= esc($canonic) ?>">
<?php endif; ?>
<meta property="og:title" content="<?= esc($titlu_pagina) ?>">
<meta property="og:site_name" content="<?= esc(config('site.nume')) ?>">
<meta property="og:type" content="<?= esc($tip_og) ?>">
<?php if ($descriere !== ''): ?><meta property="og:description" content="<?= esc($descriere) ?>">
<?php endif; ?>
<?php if ($imagine_og !== ''): ?><meta property="og:image" content="<?= esc($imagine_og) ?>">
<meta name="twitter:card" content="summary_large_image">
<?php endif; ?>
<link rel="stylesheet" href="/assets/stil.css?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/stil.css') ?>">
<link rel="alternate" type="application/rss+xml" title="<?= esc(config('site.nume')) ?>" href="/feed.xml">
<style nonce="<?= esc($nonce) ?>">:root{--accent:<?= culoare_accent() ?>}</style>
<?php if ($jsonld): ?><script type="application/ld+json" nonce="<?= esc($nonce) ?>"><?= json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
</head>
<body>
<header class="antet">
  <div class="lat antet-rand">
    <a class="sigla" href="/"><?= esc(config('site.nume')) ?></a>
    <nav class="meniu" aria-label="Meniu">
<?php foreach (meniu() as $m): ?>
      <a href="<?= esc($m['url']) ?>"><?= esc($m['titlu']) ?></a>
<?php endforeach; ?>
    </nav>
  </div>
</header>
<main class="lat">
<?= $corp_pagina ?>
</main>
<footer class="subsol">
  <div class="lat">
    <span>© <?= date('Y') ?> <?= esc(config('site.nume')) ?></span>
    <a href="/feed.xml">RSS</a>
  </div>
</footer>
</body>
</html>
