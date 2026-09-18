<?php
// Partea publică: rutele, paginile HTML și fișierele generate (sitemap, feed, robots, llms.txt).
// Arată doar ce e publicat; ciornele nu au adresă publică.
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

function ruleaza_site(): void
{
    $metoda = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($metoda !== 'GET' && $metoda !== 'HEAD') {
        header('Allow: GET, HEAD');
        pagina_eroare(405);
        return;
    }
    $cale = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));
    $cale = rtrim($cale, '/');
    if ($cale === '' || $cale === '/index.php') $cale = '/';

    if ($cale === '/') { pagina_acasa(); return; }
    if ($cale === '/articole') { pagina_lista(null); return; }
    if ($cale === '/sitemap.xml') { fisier_sitemap(); return; }
    if ($cale === '/feed.xml') { fisier_feed(); return; }
    if ($cale === '/robots.txt') { fisier_robots(); return; }
    if ($cale === '/llms.txt') { fisier_llms(); return; }
    if (preg_match('#^/eticheta/([a-z0-9-]{1,60})$#', $cale, $m)) { pagina_lista($m[1]); return; }
    if (preg_match('#^/([a-z0-9]+(?:-[a-z0-9]+)*)$#', $cale, $m)) { pagina_element($m[1]); return; }
    pagina_eroare(404);
}

// --- randare -----------------------------------------------------------------------------------

function randeaza(string $sablon, array $v, int $cod = 200): void
{
    $nonce = base64_encode(random_bytes(16));
    http_response_code($cod);
    header('Content-Type: text/html; charset=utf-8');
    if (!preg_grep('/^Cache-Control:/i', headers_list())) header('Cache-Control: no-cache');
    antete_securitate(csp_pagina($nonce));
    $v += ['titlu_pagina' => (string) config('site.nume'), 'descriere' => (string) config('site.descriere'),
           'canonic' => null, 'imagine_og' => '', 'tip_og' => 'website', 'jsonld' => null, 'noindex' => false];
    $v['nonce'] = $nonce;
    extract($v, EXTR_SKIP);
    ob_start();
    require dirname(__DIR__) . '/sabloane/' . $sablon . '.php';
    $corp_pagina = (string) ob_get_clean();
    require dirname(__DIR__) . '/sabloane/baza.php';
}

function meniu(): array
{
    $m = [];
    foreach (listeaza_elemente('pagina', 'publicat') as $p) {
        if (($p['meniu'] ?? null) !== null) $m[] = ['titlu' => $p['titlu'], 'url' => url_element($p)];
    }
    if (listeaza_elemente('articol', 'publicat')) $m[] = ['titlu' => 'Articole', 'url' => '/articole'];
    return $m;
}

function culoare_accent(): string
{
    $c = (string) config('site.culoare');
    return preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) ? $c : '#6d2be8';
}

function data_ro(?string $iso): string
{
    if (!$iso || !($t = strtotime($iso))) return '';
    $luni = ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];
    return date('j', $t) . ' ' . $luni[(int) date('n', $t) - 1] . ' ' . date('Y', $t);
}

function imagine_absoluta(string $img): string
{
    return $img !== '' && $img[0] === '/' ? url_absolut($img) : $img;
}

// --- pagini ------------------------------------------------------------------------------------

function pagina_acasa(): void
{
    $acasa = citeste_element('pagina', 'acasa');
    if ($acasa && ($acasa['stare'] ?? '') !== 'publicat') $acasa = null;
    randeaza('acasa', [
        'acasa' => $acasa,
        'html' => $acasa ? curata_html((string) $acasa['continut_html']) : '',
        'articole' => array_slice(listeaza_elemente('articol', 'publicat'), 0, 6),
        'titlu_pagina' => $acasa ? $acasa['titlu'] . ' — ' . config('site.nume') : (string) config('site.nume'),
        'descriere' => ($acasa['descriere'] ?? '') ?: (string) config('site.descriere'),
        'canonic' => url_absolut('/'),
        'jsonld' => ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => config('site.nume'), 'url' => url_absolut('/')],
    ]);
}

function pagina_element(string $slug): void
{
    if ($slug === 'acasa') {
        header('Location: /', true, 301);
        return;
    }
    foreach (['pagina', 'articol'] as $tip) {
        $e = citeste_element($tip, $slug);
        if (!$e || ($e['stare'] ?? '') !== 'publicat') continue;
        $v = ['e' => $e, 'html' => curata_html((string) ($e['continut_html'] ?? '')),
              'titlu_pagina' => $e['titlu'] . ' — ' . config('site.nume'),
              'descriere' => ($e['descriere'] ?? '') ?: (string) config('site.descriere'),
              'canonic' => url_absolut(url_element($e))];
        if ($tip === 'articol') {
            $v['tip_og'] = 'article';
            $v['imagine_og'] = imagine_absoluta((string) ($e['imagine'] ?? ''));
            $v['jsonld'] = array_filter(['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $e['titlu'],
                'description' => $e['descriere'] ?? '', 'datePublished' => $e['publicat_la'] ?? null, 'dateModified' => $e['actualizat'] ?? null,
                'image' => $v['imagine_og'] ?: null, 'url' => $v['canonic'],
                'author' => ($e['autor'] ?? '') !== '' ? ['@type' => 'Person', 'name' => $e['autor']] : null]);
        }
        randeaza($tip, $v);
        return;
    }
    pagina_eroare(404);
}

function pagina_lista(?string $eticheta): void
{
    $toate = listeaza_elemente('articol', 'publicat');
    $nume_eticheta = null;
    if ($eticheta !== null) {
        $toate = array_values(array_filter($toate, function ($e) use ($eticheta, &$nume_eticheta) {
            foreach ($e['etichete'] ?? [] as $t) {
                if (slug_din_text((string) $t) === $eticheta) { $nume_eticheta = $nume_eticheta ?? $t; return true; }
            }
            return false;
        }));
        if (!$toate) { pagina_eroare(404); return; }
    }
    $pe_pagina = max(1, (int) config('articole_pe_pagina'));
    $total_pagini = max(1, (int) ceil(count($toate) / $pe_pagina));
    $nr = max(1, min($total_pagini, (int) ($_GET['pagina'] ?? 1)));
    $baza = $eticheta === null ? '/articole' : '/eticheta/' . $eticheta;
    $titlu = $eticheta === null ? 'Articole' : 'Eticheta: ' . $nume_eticheta;
    randeaza('lista', [
        'titlu' => $titlu, 'articole' => array_slice($toate, ($nr - 1) * $pe_pagina, $pe_pagina),
        'nr' => $nr, 'total_pagini' => $total_pagini, 'baza' => $baza,
        'titlu_pagina' => $titlu . ' — ' . config('site.nume'),
        'canonic' => url_absolut($baza . ($nr > 1 ? '?pagina=' . $nr : '')),
    ]);
}

function pagina_eroare(int $cod): void
{
    $mesaje = [404 => 'Pagina nu există.', 405 => 'Metodă nepermisă.'];
    randeaza('eroare', ['cod' => $cod, 'mesaj' => $mesaje[$cod] ?? 'Eroare.', 'noindex' => true,
                        'articole' => array_slice(listeaza_elemente('articol', 'publicat'), 0, 3),
                        'titlu_pagina' => $cod . ' — ' . config('site.nume')], $cod);
}

// --- fișiere generate --------------------------------------------------------------------------

function xml($s): string
{
    return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function antete_text(string $tip): void
{
    header('Content-Type: ' . $tip . '; charset=utf-8');
    header('Cache-Control: no-cache');
    antete_securitate("default-src 'none'");
}

function fisier_sitemap(): void
{
    antete_text('application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
    echo '<url><loc>', xml(url_absolut('/')), '</loc></url>', "\n";
    foreach (['pagina', 'articol'] as $tip) {
        foreach (listeaza_elemente($tip, 'publicat') as $e) {
            if ($tip === 'pagina' && $e['slug'] === 'acasa') continue;
            echo '<url><loc>', xml(url_absolut(url_element($e))), '</loc>';
            if (!empty($e['actualizat'])) echo '<lastmod>', xml(substr((string) $e['actualizat'], 0, 10)), '</lastmod>';
            echo '</url>', "\n";
        }
    }
    if (listeaza_elemente('articol', 'publicat')) echo '<url><loc>', xml(url_absolut('/articole')), '</loc></url>', "\n";
    echo '</urlset>', "\n";
}

function fisier_feed(): void
{
    antete_text('application/rss+xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", '<rss version="2.0"><channel>', "\n";
    echo '<title>', xml(config('site.nume')), '</title><link>', xml(url_absolut('/')), '</link>';
    echo '<description>', xml(config('site.descriere')), '</description><language>', xml(config('site.limba')), '</language>', "\n";
    foreach (array_slice(listeaza_elemente('articol', 'publicat'), 0, 20) as $e) {
        $url = url_absolut(url_element($e));
        echo '<item><title>', xml($e['titlu']), '</title><link>', xml($url), '</link><guid>', xml($url), '</guid>';
        if (!empty($e['publicat_la'])) echo '<pubDate>', xml(date(DATE_RSS, (int) strtotime((string) $e['publicat_la']))), '</pubDate>';
        echo '<description>', xml($e['descriere'] ?? ''), '</description></item>', "\n";
    }
    echo '</channel></rss>', "\n";
}

function fisier_robots(): void
{
    antete_text('text/plain');
    echo "User-agent: *\nAllow: /\nDisallow: /mcp\nDisallow: /mcp.php\nDisallow: /jurnal.php\n\nSitemap: ", url_absolut('/sitemap.xml'), "\n";
}

// Rezumatul site-ului pentru modelele AI care îl citesc (llmstxt.org).
function fisier_llms(): void
{
    antete_text('text/plain');
    echo '# ', config('site.nume'), "\n\n";
    if (config('site.descriere')) echo '> ', config('site.descriere'), "\n\n";
    foreach (['pagina' => 'Pagini', 'articol' => 'Articole'] as $tip => $titlu) {
        $lista = listeaza_elemente($tip, 'publicat');
        if (!$lista) continue;
        echo '## ', $titlu, "\n\n";
        foreach ($lista as $e) {
            echo '- [', $e['titlu'], '](', url_absolut(url_element($e)), ')', ($e['descriere'] ?? '') !== '' ? ': ' . $e['descriere'] : '', "\n";
        }
        echo "\n";
    }
}
