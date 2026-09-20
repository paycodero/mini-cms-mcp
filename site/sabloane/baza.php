<?php if (!defined('MINICMS')) { http_response_code(403); exit; } ?>
<!DOCTYPE html>
<html lang="<?= esc(config('site.limba')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($titlu_pagina) ?></title>
<?php if ($descriere !== ''): ?><meta name="description" content="<?= esc($descriere) ?>">
<?php endif; ?>
<?php foreach ((array) config('verificari') as $nume_meta => $continut_meta): if ((string) $continut_meta === '') continue; ?>
<meta name="<?= esc($nume_meta) ?>" content="<?= esc($continut_meta) ?>">
<?php endforeach; ?>
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
<meta property="og:locale" content="<?= esc(str_replace('-', '_', (string) config('site.limba'))) ?>">
<?php if ($imagine_og !== ''): ?><meta property="og:image" content="<?= esc($imagine_og) ?>">
<?php $masuri_og = imagine_masuri((string) ($e['imagine'] ?? '')); if ($masuri_og): ?><meta property="og:image:width" content="<?= (int) $masuri_og['latime'] ?>">
<meta property="og:image:height" content="<?= (int) $masuri_og['inaltime'] ?>">
<?php endif; ?><?php if (($e['imagine_alt'] ?? '') !== ''): ?><meta property="og:image:alt" content="<?= esc($e['imagine_alt']) ?>">
<?php endif; ?><meta name="twitter:card" content="summary_large_image">
<?php endif; ?>
<?php if ($tip_og === 'article' && $e): ?><meta property="article:published_time" content="<?= esc($e['publicat_la'] ?? '') ?>">
<meta property="article:modified_time" content="<?= esc($e['actualizat'] ?? '') ?>">
<?php if (($e['autor'] ?? '') !== ''): ?><meta name="author" content="<?= esc($e['autor']) ?>">
<?php endif; ?><?php foreach ($e['etichete'] ?? [] as $t_og): ?><meta property="article:tag" content="<?= esc($t_og) ?>">
<?php endforeach; ?><?php endif; ?>
<?php if ((string) config('site.favicon') !== ''): ?><link rel="icon" href="<?= esc(config('site.favicon')) ?>">
<?php endif; ?>
<link rel="stylesheet" href="/assets/stil.css?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/stil.css') ?>">
<?php if (($tema = tema_activa()) !== ''): ?><link rel="stylesheet" href="/assets/teme/<?= esc($tema) ?>.css?v=<?= (int) @filemtime(dirname(__DIR__) . "/assets/teme/$tema.css") ?>">
<?php endif; ?>
<link rel="alternate" type="application/rss+xml" title="<?= esc(config('site.nume')) ?>" href="/feed.xml">
<style nonce="<?= esc($nonce) ?>">:root{--accent:<?= culoare_accent() ?>}</style>
<?php foreach (array_merge($jsonld ? [$jsonld] : [], $jsonld_extra) as $bloc_ld): ?><script type="application/ld+json" nonce="<?= esc($nonce) ?>"><?= json_encode($bloc_ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endforeach; ?>
</head>
<body>
<?php if ($previzualizare): ?>
<div class="bara-previzualizare">Previzualizare · <?= esc($previzualizare['stare']) ?> · linkul expiră la <?= esc($previzualizare['expira']) ?></div>
<?php endif; ?>
<header class="antet">
  <div class="lat antet-rand">
    <a class="sigla" href="/"><?php if ((string) config('site.logo') !== ''): ?><img src="<?= esc(config('site.logo')) ?>" alt="" height="40"><?php endif; ?><span><?= esc(config('site.nume')) ?></span></a>
    <nav class="meniu" aria-label="Meniu">
<?php foreach (meniu() as $m): ?>
      <a href="<?= esc($m['url']) ?>"><?= esc($m['titlu']) ?></a>
<?php endforeach; ?>
    </nav>
    <form class="cauta-antet" action="/cauta" method="get" role="search">
      <input type="search" name="q" value="<?= esc($cautare) ?>" placeholder="Caută pe site" aria-label="Caută pe site" maxlength="100">
    </form>
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
