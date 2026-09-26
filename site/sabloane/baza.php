<?php if (!defined('MINICMS')) { http_response_code(403); exit; }
$lang_pagina = $e ? limba_element($e) : limba_curenta();   // limba paginii curente (nu doar limba implicită a site-ului)
$alternative = alternate_limbi($e ?? null);                // adresele în celelalte limbi, pentru comutator și hreflang
?>
<!DOCTYPE html>
<html lang="<?= esc($lang_pagina) ?>">
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
<?php // hreflang: spune motoarelor că paginile sunt aceeași, în limbi diferite. x-default = limba implicită.
if (!$noindex && count($alternative) > 1): foreach ($alternative as $cod_alt => $url_alt): ?>
<link rel="alternate" hreflang="<?= esc($cod_alt) ?>" href="<?= esc($url_alt) ?>">
<?php endforeach; if (isset($alternative[limba_implicita()])): ?>
<link rel="alternate" hreflang="x-default" href="<?= esc($alternative[limba_implicita()]) ?>">
<?php endif; endif; ?>
<meta property="og:title" content="<?= esc($titlu_pagina) ?>">
<meta property="og:site_name" content="<?= esc(text_site('nume')) ?>">
<meta property="og:type" content="<?= esc($tip_og) ?>">
<?php if ($descriere !== ''): ?><meta property="og:description" content="<?= esc($descriere) ?>">
<?php endif; ?>
<meta property="og:locale" content="<?= esc(str_replace('-', '_', $lang_pagina)) ?>">
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
<meta name="theme-color" content="<?= esc(culoare_accent()) ?>">
<?php // Măsurarea: doar pe paginile publice. Jurnalul, actualizarea, previzualizările și aprobările nu se numără.
if ((string) config('site.ga4') !== '' && !$noindex): $ga4 = (string) config('site.ga4'); ?>
<script async nonce="<?= esc($nonce) ?>" src="https://www.googletagmanager.com/gtag/js?id=<?= rawurlencode($ga4) ?>"></script>
<script nonce="<?= esc($nonce) ?>">window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?= esc($ga4) ?>');</script>
<?php endif; ?>
<?php if ((string) config('site.favicon') !== ''): ?><link rel="icon" href="<?= esc(config('site.favicon')) ?>">
<link rel="apple-touch-icon" href="<?= esc(config('site.favicon')) ?>">
<?php endif; ?>
<link rel="stylesheet" href="/assets/stil.css?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/stil.css') ?>">
<?php if (($tema = tema_activa()) !== ''): ?><link rel="stylesheet" href="/assets/teme/<?= esc($tema) ?>.css?v=<?= (int) @filemtime(dirname(__DIR__) . "/assets/teme/$tema.css") ?>">
<?php endif; ?>
<link rel="alternate" type="application/rss+xml" title="<?= esc(text_site('nume')) ?>" href="<?= esc(prefix_limba() . '/feed.xml') ?>">
<style nonce="<?= esc($nonce) ?>">:root{--accent:<?= culoare_accent() ?>}</style>
<?php foreach (array_merge($jsonld ? [$jsonld] : [], $jsonld_extra) as $bloc_ld): ?><script type="application/ld+json" nonce="<?= esc($nonce) ?>"><?= json_encode($bloc_ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endforeach; ?>
</head>
<body class="pagina-<?= esc($sablon) ?>">
<?php if ($previzualizare): ?>
<div class="bara-previzualizare">Previzualizare · <?= esc($previzualizare['stare']) ?> · linkul expiră la <?= esc($previzualizare['expira']) ?></div>
<?php endif; ?>
<header class="antet">
  <div class="lat antet-rand">
    <a class="sigla" href="<?= esc(prefix_limba() ?: '/') ?>"><?php if ((string) config('site.logo') !== ''): ?><img src="<?= esc(config('site.logo')) ?>" alt="" height="40"><?php endif; ?><span><?= esc(text_site('nume')) ?></span></a>
    <nav class="meniu" aria-label="<?= esc(ui('Meniu')) ?>">
<?php foreach (meniu() as $m): ?>
<?php if (!empty($m['copii'])): // submeniul se deschide la trecerea mouse-ului și din tastatură (:focus-within), fără JavaScript ?>
      <div class="meniu-grup">
        <a href="<?= esc($m['url']) ?>" class="are-submeniu"><?= esc($m['titlu']) ?></a>
        <div class="submeniu">
<?php foreach ($m['copii'] as $c): ?>
          <a href="<?= esc($c['url']) ?>"><?= esc($c['titlu']) ?></a>
<?php endforeach; ?>
        </div>
      </div>
<?php else: ?>
      <a href="<?= esc($m['url']) ?>"><?= esc($m['titlu']) ?></a>
<?php endif; ?>
<?php endforeach; ?>
    </nav>
    <form class="cauta-antet" action="<?= esc(prefix_limba() . '/cauta') ?>" method="get" role="search">
      <input type="search" name="q" value="<?= esc($cautare) ?>" placeholder="<?= esc(ui('Caută pe site')) ?>" aria-label="<?= esc(ui('Caută pe site')) ?>" maxlength="100">
    </form>
<?php if (e_multilingv()): ?>
    <nav class="limbi" aria-label="Limbă">
<?php foreach (limbi() as $cod_l): $url_l = $alternative[$cod_l] ?? url_absolut($cod_l === limba_implicita() ? '/' : '/' . $cod_l); ?>
<?php if ($cod_l === $lang_pagina): ?>      <span class="limba-activa" aria-current="true"><?= esc(strtoupper($cod_l)) ?></span>
<?php else: ?>      <a href="<?= esc($url_l) ?>" hreflang="<?= esc($cod_l) ?>" lang="<?= esc($cod_l) ?>"><?= esc(strtoupper($cod_l)) ?></a>
<?php endif; endforeach; ?>
    </nav>
<?php endif; ?>
  </div>
</header>
<main class="lat">
<?= $corp_pagina ?>
</main>
<footer class="subsol">
<?php $legaturi = (array) config('site.legaturi'); if ($legaturi): ?>
  <div class="lat retea">
    <nav aria-label="Celelalte site-uri și conturi">
<?php foreach ($legaturi as $l): ?>
      <a href="<?= esc($l['url']) ?>" rel="noopener me" target="_blank"><?= esc($l['titlu']) ?></a>
<?php endforeach; ?>
    </nav>
  </div>
<?php endif; ?>
  <div class="lat">
    <span>© <?= date('Y') ?> <?= esc(text_site('nume')) ?></span>
<?php $realizare = (string) config('site.realizare'); $realizare_url = (string) config('site.realizare_url'); if ($realizare !== ''): ?>
<?php if ($realizare_url !== ''): ?>
    <a class="realizare" href="<?= esc($realizare_url) ?>" rel="noopener" target="_blank"><?= esc($realizare) ?></a>
<?php else: ?>
    <span class="realizare"><?= esc($realizare) ?></span>
<?php endif; endif; ?>
    <a href="<?= esc(prefix_limba() . '/feed.xml') ?>">RSS</a>
  </div>
<?php if ((string) text_site('subsol') !== ''): ?>
  <div class="lat nota-subsol"><p><?= esc(text_site('subsol')) ?></p></div>
<?php endif; ?>
</footer>
<?php // Butonul „Copiază" pe blocurile <pre> (prompturi, comenzi). Scriptul e al șablonului, cu nonce: conținutul nu poate aduce cod.
if (strpos($corp_pagina, '<pre') !== false): ?>
<script nonce="<?= esc($nonce) ?>">
document.querySelectorAll('main pre').forEach(function (pre) {
  var cutie = document.createElement('div'), b = document.createElement('button');
  cutie.className = 'cod-copiabil'; b.type = 'button'; b.className = 'copiaza'; b.textContent = 'Copiază';
  b.addEventListener('click', function () {
    var gata = function () { b.textContent = 'Copiat ✓'; setTimeout(function () { b.textContent = 'Copiază'; }, 1800); };
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(pre.innerText).then(gata, function () {}); return; }
    var r = document.createRange(), s = window.getSelection();
    r.selectNodeContents(pre); s.removeAllRanges(); s.addRange(r);
    try { if (document.execCommand('copy')) gata(); } catch (e) {}
  });
  pre.parentNode.insertBefore(cutie, pre); cutie.appendChild(b); cutie.appendChild(pre);
});
</script>
<?php endif; ?>
</body>
</html>
