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
    if ($cale === '/cauta') { pagina_cautare(); return; }
    if (preg_match('#^/([a-f0-9]{32})\.txt$#', $cale, $m)) { fisier_indexnow($m[1]); return; }   // dovada pentru IndexNow (Bing)
    if ($cale === '/favicon.ico' || $cale === '/apple-touch-icon.png' || $cale === '/apple-touch-icon-precomposed.png') { fisier_favicon(); return; }
    if (preg_match('#^/fisiere/([a-z0-9]+(?:-[a-z0-9]+)*\.pdf)$#', $cale, $m)) { serveste_fisier($m[1]); return; }   // documentele urcate (fisiere.php)
    if (preg_match('#^/previzualizare/([a-z0-9]+(?:-[a-z0-9]+)*)$#', $cale, $m)) { pagina_previzualizare($m[1]); return; }
    if (preg_match('#^/eticheta/([a-z0-9-]{1,60})$#', $cale, $m)) { pagina_lista($m[1]); return; }
    if (preg_match('#^/([a-z0-9]+(?:-[a-z0-9]+)*)$#', $cale, $m)) { pagina_element($m[1]); return; }
    if (preg_match('#^/articole/([a-z0-9]+(?:-[a-z0-9]+)*)$#', $cale, $m)) { adresa_de_blog($m[1]); return; }
    pagina_eroare(404);
}

// Un site mutat de pe un CMS cu blog (Grav, WordPress) avea articolele la /articole/<slug>; aici stau la /<slug>.
// /articole e rezervat, deci redirecționarea nu se poate scrie de mână: o face site-ul, doar pentru ce se vede.
function adresa_de_blog(string $slug): void
{
    foreach (['articol', 'pagina'] as $tip) {
        $e = citeste_element($tip, $slug);
        if (!$e || !e_vizibil($e)) continue;
        header('Location: ' . url_element($e), true, 301);
        header('Cache-Control: public, max-age=86400');
        return;
    }
    pagina_eroare(404);
}

// --- randare -----------------------------------------------------------------------------------

// $csp: surse în plus pentru această pagină (ex. pagina de aprobare OAuth trimite formularul spre aplicație).
function randeaza(string $sablon, array $v, int $cod = 200, array $csp = []): void
{
    $nonce = base64_encode(random_bytes(16));
    http_response_code($cod);
    header('Content-Type: text/html; charset=utf-8');
    if (!preg_grep('/^Cache-Control:/i', headers_list())) header('Cache-Control: no-cache');
    antete_securitate(csp_pagina($nonce, $csp));
    $v += ['titlu_pagina' => (string) config('site.nume'), 'descriere' => (string) config('site.descriere'),
           'canonic' => null, 'imagine_og' => '', 'tip_og' => 'website', 'jsonld' => null, 'jsonld_extra' => [],
           'noindex' => false, 'previzualizare' => null, 'cautare' => '', 'e' => null];
    if ($v['imagine_og'] === '' && (string) config('site.logo') !== '') $v['imagine_og'] = url_absolut((string) config('site.logo'));
    $v['nonce'] = $nonce;
    extract($v, EXTR_SKIP);
    ob_start();
    require dirname(__DIR__) . '/sabloane/' . $sablon . '.php';
    $corp_pagina = (string) ob_get_clean();
    require dirname(__DIR__) . '/sabloane/baza.php';
}

// Două niveluri: paginile cu „parinte" intră în submeniul acelei pagini, dacă ea e în meniu; altfel nu apar în meniu
// (se ajunge la ele din lista subpaginilor, de pe pagina-părinte).
function meniu(): array
{
    $pagini = listeaza_elemente('pagina', 'vizibil');
    $m = [];
    foreach ($pagini as $p) {
        if (($p['meniu'] ?? null) === null || ($p['parinte'] ?? null) !== null) continue;
        $copii = [];
        foreach ($pagini as $c) {
            if (($c['parinte'] ?? null) === $p['slug'] && ($c['meniu'] ?? null) !== null) $copii[] = ['titlu' => $c['titlu'], 'url' => url_element($c)];
        }
        $m[] = ['titlu' => $p['titlu'], 'url' => url_element($p), 'copii' => $copii];
    }
    if (listeaza_elemente('articol', 'vizibil')) $m[] = ['titlu' => nume_articole(false, true), 'url' => '/articole'];
    return $m;
}

// Cum se numesc articolele pe site („ghiduri", „rețete"; implicit „articole"). $articulat adaugă „le" („ghidurile"),
// $majuscula pune prima literă mare („Ghiduri"). Adresa rămâne /articole oricum.
function nume_articole(bool $articulat = false, bool $majuscula = false): string
{
    $n = (string) config('site.nume_articole');
    if ($n === '') $n = 'articole';
    if ($articulat) $n .= 'le';
    if ($majuscula && preg_match('/^(.)(.*)$/us', $n, $m)) {
        $n = strtr($m[1], ['ă' => 'Ă', 'â' => 'Â', 'î' => 'Î', 'ș' => 'Ș', 'ş' => 'Ş', 'ț' => 'Ț', 'ţ' => 'Ţ']);
        $n = strtoupper($n) . $m[2];
    }
    return $n;
}

// Data publicării pe pagini: doar dacă site-ul a cerut-o (arata_data = "da"). Implicit nu apare: pe un site de
// documentație, o dată lângă titlu doar face conținutul să pară vechi.
function data_vizibila(array $e): string
{
    return (string) config('site.arata_data') === 'da' ? data_ro($e['publicat_la'] ?? null) : '';
}

// Ziua evenimentului, pentru card: „Marți, 27 octombrie 2026, ora 19:00”; la un eveniment amânat sau anulat, și starea.
// Apare chiar dacă data publicării e ascunsă: la un anunț, ziua evenimentului e informația.
function data_eveniment(array $e): string
{
    $ev = $e['eveniment'] ?? null;
    if (!$ev || !($t = strtotime((string) $ev['inceput']))) return '';
    $zile = ['Luni', 'Marți', 'Miercuri', 'Joi', 'Vineri', 'Sâmbătă', 'Duminică'];
    $text = $zile[(int) date('N', $t) - 1] . ', ' . data_ro((string) $ev['inceput']) . (date('H:i', $t) !== '00:00' ? ', ora ' . date('H:i', $t) : '');
    $stari = ['amanat' => 'amânat', 'reprogramat' => 'reprogramat', 'anulat' => 'anulat'];
    return $text . (isset($stari[$ev['stare'] ?? '']) ? ' — ' . $stari[$ev['stare']] : '');
}

// Un eveniment „urmează” până la sfârșitul zilei în care se termină.
function eveniment_urmeaza(array $e): bool
{
    $ev = $e['eveniment'] ?? null;
    if (!$ev) return false;
    $t = strtotime((string) ($ev['sfarsit'] ?? $ev['inceput']));
    return $t !== false && strtotime(date('Y-m-d 23:59:59', $t)) >= time();
}

// Prima pagină: întâi evenimentele care urmează, cel mai apropiat primul; apoi restul, cele mai noi întâi.
function articole_acasa(int $cate = 6): array
{
    $toate = listeaza_elemente('articol', 'vizibil');
    $urmeaza = array_values(array_filter($toate, 'eveniment_urmeaza'));
    usort($urmeaza, fn($a, $b) => strtotime((string) $a['eveniment']['inceput']) <=> strtotime((string) $b['eveniment']['inceput']));
    $restul = array_filter($toate, fn($a) => !eveniment_urmeaza($a));
    return array_slice(array_merge($urmeaza, array_values($restul)), 0, $cate);
}

// Datele structurate Event, compuse din câmpul „eveniment” (conținutul nu poate avea <script>).
function eveniment_jsonld(array $e, string $canonic, string $imagine): ?array
{
    $ev = $e['eveniment'] ?? null;
    if (!$ev) return null;
    $adresa = array_filter(['@type' => 'PostalAddress', 'streetAddress' => $ev['adresa'] ?? null,
        'addressLocality' => $ev['oras'] ?? null, 'addressCountry' => $ev['tara'] ?? 'RO']);
    return array_filter(['@context' => 'https://schema.org', '@type' => $ev['tip'] ?? 'Event', 'name' => $e['titlu'] ?? '',
        'description' => ($e['descriere'] ?? '') ?: null, 'startDate' => $ev['inceput'], 'endDate' => $ev['sfarsit'] ?? null,
        'eventStatus' => 'https://schema.org/' . (EVENIMENT_STARI[$ev['stare'] ?? 'programat'] ?? 'EventScheduled'),
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        'location' => ['@type' => 'Place', 'name' => $ev['loc'], 'address' => $adresa],
        'image' => $imagine !== '' ? [$imagine] : null, 'url' => $canonic,
        'performer' => array_map(fn($a) => ['@type' => $a['grup'] ? 'PerformingGroup' : 'Person', 'name' => $a['nume']], $ev['artisti'] ?? []) ?: null,
        'organizer' => ['@type' => 'Organization', 'name' => ($ev['organizator'] ?? '') ?: (string) config('site.nume'), 'url' => url_absolut('/')],
        'offers' => ($ev['bilete'] ?? '') !== '' ? ['@type' => 'Offer', 'url' => $ev['bilete']] : null]);
}

// Două articole de citit mai departe: întâi cele cu prima etichetă a articolului, apoi cele mai noi.
function articole_legate(array $e, int $cate = 2): array
{
    $alte = array_values(array_filter(listeaza_elemente('articol', 'vizibil'), fn($x) => $x['slug'] !== $e['slug']));
    $prima = slug_din_text((string) (($e['etichete'] ?? [])[0] ?? ''));
    $aceeasi = $prima === '' ? [] : array_filter($alte, fn($x) => in_array($prima, array_map(fn($t) => slug_din_text((string) $t), $x['etichete'] ?? []), true));
    $rez = [];
    foreach (array_merge($aceeasi, $alte) as $x) {
        $rez[$x['slug']] = $x;
        if (count($rez) >= $cate) break;
    }
    return array_values($rez);
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

// Lățimea și înălțimea unei imagini din /media/, citite din fișier: fără ele, pagina sare la încărcare
// (CLS), iar cardul social nu știe ce format are coperta.
function imagine_masuri(string $img): array
{
    static $stiute = [];
    if ($img === '' || strncmp($img, '/media/', 7) !== 0) return [];
    if (isset($stiute[$img])) return $stiute[$img];
    $cale = dir_media() . '/' . basename($img);
    $i = is_file($cale) ? @getimagesize($cale) : false;
    return $stiute[$img] = ($i ? ['latime' => (int) $i[0], 'inaltime' => (int) $i[1]] : []);
}

// O imagine mai înaltă decât lată (captura unui telefon, o poză pe verticală) nu se întinde pe toată lățimea coloanei:
// pe calculator ar ocupa două ecrane înainte de primul rând de text (cms.paycode.ro, 21 sept 2026). Se recunoaște din fișier.
function e_portret(string $img): bool
{
    $m = imagine_masuri($img);
    return $m && $m['inaltime'] > $m['latime'] * 1.1;
}

// În conținut, imaginile de pe site care sunt pe verticală primesc clasa "portret" (la afișare; ce e salvat nu se schimbă).
function marcheaza_portret(string $html): string
{
    return (string) preg_replace_callback('#<img\b[^>]*\bsrc="(/media/[a-z0-9-]+\.(?:jpg|png|gif|webp))"[^>]*>#', function ($m) {
        if (!e_portret($m[1])) return $m[0];
        if (!preg_match('/\bclass="([^"]*)"/', $m[0], $c)) return (string) preg_replace('/^<img\b/', '<img class="portret"', $m[0], 1);
        if (preg_match('/\b(portret|ingust)\b/', $c[1])) return $m[0];
        return str_replace($c[0], 'class="' . trim($c[1] . ' portret') . '"', $m[0]);
    }, $html);
}

// --- ce citesc motoarele de căutare și agenții --------------------------------------------------

function editor_jsonld(): array
{
    $ed = ['@type' => 'Organization', '@id' => url_absolut('/#editor'), 'name' => (string) config('site.nume'), 'url' => url_absolut('/')];
    if ((string) config('site.logo') !== '') $ed['logo'] = url_absolut((string) config('site.logo'));
    // sameAs: celelalte site-uri și conturi. De aici află motoarele că profilurile sunt ale aceleiași entități.
    $sameas = array_values(array_filter(array_column((array) config('site.legaturi'), 'url')));
    if ($sameas) $ed['sameAs'] = $sameas;
    return $ed;
}

// Pagina-părinte a unei subpagini, dacă se vede pe site.
function sectiune_pagina(array $e): ?array
{
    if (($e['tip'] ?? '') !== 'pagina' || empty($e['parinte'])) return null;
    $p = citeste_element('pagina', (string) $e['parinte']);
    return $p && e_vizibil($p) ? $p : null;
}

function firimituri_jsonld(array $e): array
{
    $cale = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Acasă', 'item' => url_absolut('/')]];
    if (($e['tip'] ?? '') === 'articol') $cale[] = ['@type' => 'ListItem', 'position' => 2, 'name' => nume_articole(false, true), 'item' => url_absolut('/articole')];
    if ($s = sectiune_pagina($e)) $cale[] = ['@type' => 'ListItem', 'position' => 2, 'name' => $s['titlu'], 'item' => url_absolut(url_element($s))];
    $cale[] = ['@type' => 'ListItem', 'position' => count($cale) + 1, 'name' => $e['titlu'] ?? '', 'item' => url_absolut(url_element($e))];
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $cale];
}

// Întrebările frecvente scrise în articol (un <h2> „Întrebări frecvente" urmat de <h3> întrebare + răspuns)
// devin FAQPage: așa ajung în rezultatele Google și sunt ușor de citat de un asistent AI.
function faq_jsonld(string $html): ?array
{
    if ($html === '' || !class_exists('DOMDocument') || !preg_match('/<h2[^>]*>\s*(întrebări frecvente|intrebari frecvente|faq)/iu', $html)) return null;
    $doc = new DOMDocument('1.0', 'UTF-8');
    $anterior = libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>'
        . $html . '</body></html>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($anterior);
    $corp = $doc->getElementsByTagName('body')->item(0);
    if (!$corp) return null;
    $in_sectiune = false;
    $intrebari = [];
    $curenta = null;
    foreach (iterator_to_array($corp->childNodes) as $nod) {
        if (!($nod instanceof DOMElement)) continue;
        $nume = strtolower($nod->nodeName);
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $nod->textContent));
        if ($nume === 'h2') {
            if ($curenta) { $intrebari[] = $curenta; $curenta = null; }
            $in_sectiune = preg_match('/^(întrebări frecvente|intrebari frecvente|faq)/iu', $text) === 1;
            continue;
        }
        // un bloc separat (ex. chemarea de la finalul articolului) încheie secțiunea, nu se lipește de ultimul răspuns
        if ($nume === 'aside' || $nume === 'section') {
            if ($curenta) { $intrebari[] = $curenta; $curenta = null; }
            $in_sectiune = false;
            continue;
        }
        if (!$in_sectiune) continue;
        if ($nume === 'h3') {
            if ($curenta) $intrebari[] = $curenta;
            $curenta = $text !== '' ? ['intrebare' => $text, 'raspuns' => ''] : null;
        } elseif ($curenta && $text !== '') {
            $curenta['raspuns'] = trim($curenta['raspuns'] . ' ' . $text);
        }
    }
    if ($curenta) $intrebari[] = $curenta;
    $intrebari = array_values(array_filter($intrebari, fn($i) => $i['raspuns'] !== ''));
    if (count($intrebari) < 2) return null;
    return ['@context' => 'https://schema.org', '@type' => 'FAQPage',
            'mainEntity' => array_map(fn($i) => ['@type' => 'Question', 'name' => $i['intrebare'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $i['raspuns']]], array_slice($intrebari, 0, 20))];
}

// --- pagini ------------------------------------------------------------------------------------

function pagina_acasa(): void
{
    $acasa = citeste_element('pagina', 'acasa');
    if ($acasa && !e_vizibil($acasa)) $acasa = null;
    randeaza('acasa', variabile_acasa($acasa));
}

function variabile_acasa(?array $acasa): array
{
    return [
        'acasa' => $acasa,
        'html' => $acasa ? marcheaza_portret(curata_html((string) $acasa['continut_html'])) : '',
        'articole' => articole_acasa(),
        'titlu_pagina' => $acasa ? titlu_pagina((string) $acasa['titlu']) : (string) config('site.nume'),
        'descriere' => ($acasa['descriere'] ?? '') ?: (string) config('site.descriere'),
        'canonic' => url_absolut('/'),
        // WebSite + editorul: așa știu Google, Bing și asistenții AI ce entitate e site-ul, nu doar ce pagini are.
        'jsonld' => ['@context' => 'https://schema.org', '@type' => 'WebSite', '@id' => url_absolut('/#site'),
            'name' => config('site.nume'), 'url' => url_absolut('/'), 'description' => (string) config('site.descriere'),
            'inLanguage' => (string) config('site.limba'), 'publisher' => editor_jsonld(),
            'potentialAction' => ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint',
                'urlTemplate' => url_absolut('/cauta?q={search_term_string}')], 'query-input' => 'required name=search_term_string']],
    ];
}

function pagina_element(string $slug): void
{
    if ($slug === 'acasa') {
        header('Location: /', true, 301);
        return;
    }
    foreach (['pagina', 'articol'] as $tip) {
        $e = citeste_element($tip, $slug);
        if (!$e || !e_vizibil($e)) continue;
        randeaza($tip, variabile_element($tip, $e));
        return;
    }
    pagina_eroare(404);
}

function variabile_element(string $tip, array $e): array
{
    $html = marcheaza_portret(curata_html((string) ($e['continut_html'] ?? '')));
    $v = ['e' => $e, 'html' => $html,
          'titlu_pagina' => titlu_pagina((string) $e['titlu']),
          'descriere' => ($e['descriere'] ?? '') ?: (string) config('site.descriere'),
          'canonic' => url_absolut(url_element($e)),
          'jsonld_extra' => [firimituri_jsonld($e)]];
    $faq = faq_jsonld($html);
    if ($faq) $v['jsonld_extra'][] = $faq;
    if ($tip === 'pagina') {   // meniul pe două niveluri: pagina-părinte își listează subpaginile, subpagina duce înapoi
        $v['sectiune'] = sectiune_pagina($e);
        $v['subpagini'] = ($e['slug'] ?? '') === 'acasa' ? [] : subpagini_pentru((string) $e['slug']);
    }
    if ($tip === 'articol') {
        $v['legate'] = articole_legate($e);
        $v['tip_og'] = 'article';
        $v['imagine_og'] = imagine_absoluta((string) ($e['imagine'] ?? ''));
        $v['jsonld'] = array_filter(['@context' => 'https://schema.org', '@type' => 'Article',
            '@id' => $v['canonic'] . '#articol', 'headline' => $e['titlu'],
            'description' => $e['descriere'] ?? '', 'datePublished' => $e['publicat_la'] ?? null, 'dateModified' => $e['actualizat'] ?? null,
            'image' => $v['imagine_og'] ?: null, 'url' => $v['canonic'],
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $v['canonic']],
            'inLanguage' => (string) config('site.limba'),
            'keywords' => ($e['etichete'] ?? []) ? implode(', ', $e['etichete']) : null,
            'articleSection' => $e['etichete'][0] ?? null,
            'isAccessibleForFree' => true,
            'publisher' => editor_jsonld(),
            'author' => ($e['autor'] ?? '') !== '' ? ['@type' => 'Person', 'name' => $e['autor']] : null]);
        if ($ev = eveniment_jsonld($e, $v['canonic'], $v['imagine_og'])) $v['jsonld_extra'][] = $ev;
    } else {
        $v['jsonld'] = ['@context' => 'https://schema.org', '@type' => 'WebPage', '@id' => $v['canonic'],
                        'name' => $e['titlu'], 'url' => $v['canonic'], 'inLanguage' => (string) config('site.limba'),
                        'isPartOf' => ['@id' => url_absolut('/#site')], 'publisher' => editor_jsonld()];
    }
    return $v;
}

// Titlul din bara browserului: numele site-ului se adaugă doar dacă nu e deja în titlu (altfel apare de două ori,
// iar Google taie oricum după vreo 60 de caractere).
function titlu_pagina(string $titlu): string
{
    $nume = (string) config('site.nume');
    if ($nume === '' || stripos($titlu, $nume) !== false) return $titlu;
    return $titlu . ' — ' . $nume;
}

// Previzualizarea unei ciorne (sau a unui element programat), printr-un link semnat creat cu previzualizeaza.
function pagina_previzualizare(string $slug): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    $expira = (int) ($_GET['e'] ?? 0);
    $semn = (string) ($_GET['s'] ?? '');
    if ($expira <= 0 || $semn === '') { pagina_eroare(404); return; }
    $jurnal = ['punct' => 'previzualizare', 'cerere' => 'vizualizare'];
    if (!previzualizare_valida($slug, $expira, $semn)) {
        jurnal_scrie($jurnal + ['tinta' => $slug, 'rezultat' => 'respins', 'detalii' => ['motiv' => $expira < time() ? 'link expirat' : 'semnătură greșită']]);
        pagina_eroare(403);
        return;
    }
    foreach (['pagina', 'articol'] as $tip) {
        $e = citeste_element($tip, $slug);
        if (!$e) continue;
        jurnal_scrie($jurnal + ['tinta' => "$tip/$slug", 'rezultat' => 'ok']);
        $acasa = $tip === 'pagina' && $slug === 'acasa';
        $v = $acasa ? variabile_acasa($e) : variabile_element($tip, $e);
        $v['canonic'] = null;
        $v['noindex'] = true;
        $stare = e_vizibil($e) ? 'e deja pe site'
            : ((($e['stare'] ?? '') === 'publicat') ? 'programat pentru ' . data_ro($e['publicat_la'] ?? null) . ', ora ' . date('H:i', (int) strtotime((string) $e['publicat_la']))
            : 'ciornă, nu e pe site');
        $v['previzualizare'] = ['stare' => $stare, 'expira' => date('H:i', $expira)];
        randeaza($acasa ? 'acasa' : $tip, $v);
        return;
    }
    pagina_eroare(404);
}

function pagina_cautare(): void
{
    $q = text_simplu($_GET['q'] ?? '', 100);
    $gasit = $q === '' ? ['modele' => [], 'rezultate' => []] : cauta_public($q);
    randeaza('cauta', ['q' => $q, 'cautare' => $q, 'rezultate' => $gasit['rezultate'], 'modele' => $gasit['modele'], 'noindex' => true,
                       'titlu_pagina' => ($q !== '' ? 'Caută: ' . $q : 'Caută') . ' — ' . config('site.nume')]);
}

function numar_rezultate(int $n): string
{
    if ($n === 1) return '1 rezultat';
    return $n . ($n === 0 || $n % 100 >= 20 ? ' de rezultate' : ' rezultate');
}

function pagina_lista(?string $eticheta): void
{
    $toate = listeaza_elemente('articol', 'vizibil');
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
    $titlu = $eticheta === null ? nume_articole(false, true) : (string) $nume_eticheta;
    randeaza('lista', [
        'titlu' => $titlu, 'eticheta' => $eticheta, 'articole' => array_slice($toate, ($nr - 1) * $pe_pagina, $pe_pagina),
        'nr' => $nr, 'total_pagini' => $total_pagini, 'baza' => $baza,
        'titlu_pagina' => $titlu . ' — ' . config('site.nume'),
        'canonic' => url_absolut($baza . ($nr > 1 ? '?pagina=' . $nr : '')),
    ]);
}

function pagina_eroare(int $cod): void
{
    if ($cod === 404) {   // o adresă veche, redirecționată, trimite spre cea nouă în locul paginii 404
        $cale = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));
        $tinta = cauta_redirectionare($cale, (string) ($_SERVER['QUERY_STRING'] ?? ''));
        if ($tinta !== null) {
            [$c, $q] = array_pad(explode('?', $tinta, 2), 2, '');
            header('Location: ' . implode('/', array_map('rawurlencode', explode('/', $c))) . ($q !== '' ? '?' . $q : ''), true, 301);
            header('Cache-Control: public, max-age=86400');
            return;
        }
    }
    $mesaje = [403 => 'Linkul nu mai e valabil.', 404 => 'Pagina nu există.', 405 => 'Metodă nepermisă.'];
    randeaza('eroare', ['cod' => $cod, 'mesaj' => $mesaje[$cod] ?? 'Eroare.', 'noindex' => true,
                        'articole' => array_slice(listeaza_elemente('articol', 'vizibil'), 0, 3),
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
    $articole = listeaza_elemente('articol', 'vizibil');
    $pagini = listeaza_elemente('pagina', 'vizibil');
    $toate = array_merge($pagini, $articole);
    $ultima = '';
    foreach ($toate as $e) $ultima = max($ultima, substr((string) ($e['actualizat'] ?? ''), 0, 10));
    echo '<?xml version="1.0" encoding="UTF-8"?>', "\n",
         '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">', "\n";
    echo '<url><loc>', xml(url_absolut('/')), '</loc>', ($ultima !== '' ? '<lastmod>' . xml($ultima) . '</lastmod>' : ''), '</url>', "\n";
    foreach (['pagina' => $pagini, 'articol' => $articole] as $tip => $lista) {
        foreach ($lista as $e) {
            if ($tip === 'pagina' && $e['slug'] === 'acasa') continue;
            echo '<url><loc>', xml(url_absolut(url_element($e))), '</loc>';
            if (!empty($e['actualizat'])) echo '<lastmod>', xml(substr((string) $e['actualizat'], 0, 10)), '</lastmod>';
            // coperta, ca să ajungă și în căutarea de imagini
            if (($e['imagine'] ?? '') !== '') {
                echo '<image:image><image:loc>', xml(imagine_absoluta((string) $e['imagine'])), '</image:loc>';
                if (($e['imagine_alt'] ?? '') !== '') echo '<image:title>', xml($e['imagine_alt']), '</image:title>';
                echo '</image:image>';
            }
            echo '</url>', "\n";
        }
    }
    if ($articole) {
        echo '<url><loc>', xml(url_absolut('/articole')), '</loc>', ($ultima !== '' ? '<lastmod>' . xml($ultima) . '</lastmod>' : ''), '</url>', "\n";
        $etichete = [];
        foreach ($articole as $e) foreach ($e['etichete'] ?? [] as $t) $etichete[slug_din_text((string) $t)] = true;
        foreach (array_keys($etichete) as $s) if ($s !== '') echo '<url><loc>', xml(url_absolut('/eticheta/' . $s)), '</loc></url>', "\n";
    }
    echo '</urlset>', "\n";
}

function fisier_feed(): void
{
    antete_text('application/rss+xml');
    $articole = array_slice(listeaza_elemente('articol', 'vizibil'), 0, 20);
    echo '<?xml version="1.0" encoding="UTF-8"?>', "\n",
         '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel>', "\n";
    echo '<title>', xml(config('site.nume')), '</title><link>', xml(url_absolut('/')), '</link>';
    echo '<atom:link href="', xml(url_absolut('/feed.xml')), '" rel="self" type="application/rss+xml"/>';
    echo '<description>', xml(config('site.descriere')), '</description><language>', xml(config('site.limba')), '</language>';
    if ($articole && !empty($articole[0]['publicat_la'])) echo '<lastBuildDate>', xml(date(DATE_RSS, (int) strtotime((string) $articole[0]['publicat_la']))), '</lastBuildDate>';
    echo "\n";
    foreach ($articole as $e) {
        $url = url_absolut(url_element($e));
        echo '<item><title>', xml($e['titlu']), '</title><link>', xml($url), '</link><guid isPermaLink="true">', xml($url), '</guid>';
        if (!empty($e['publicat_la'])) echo '<pubDate>', xml(date(DATE_RSS, (int) strtotime((string) $e['publicat_la']))), '</pubDate>';
        if (($e['autor'] ?? '') !== '') echo '<dc:creator>', xml($e['autor']), '</dc:creator>';
        foreach ($e['etichete'] ?? [] as $t) echo '<category>', xml($t), '</category>';
        echo '<description>', xml($e['descriere'] ?? ''), '</description></item>', "\n";
    }
    echo '</channel></rss>', "\n";
}

// Boții care contează, numiți pe rând: „Allow: /" îi acoperă oricum, dar unele sisteme (și Bing, și boții AI)
// caută întâi un bloc pe numele lor. Un site făcut ca să fie citit de asistenți nu-i lasă să ghicească.
const ROBOTI = ['Googlebot', 'Googlebot-Image', 'Google-Extended', 'Bingbot', 'msnbot', 'Slurp', 'DuckDuckBot',
    'Applebot', 'Applebot-Extended', 'GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-User', 'Claude-SearchBot',
    'anthropic-ai', 'PerplexityBot', 'Perplexity-User', 'Gemini-Deep-Research', 'CCBot', 'Amazonbot', 'meta-externalagent', 'YandexBot'];

// Boții și browserele vechi cer /favicon.ico fără să se uite în pagină; iOS cere /apple-touch-icon.png.
// Nu punem fișiere în rădăcină (AI-ul n-are voie să scrie acolo): trimitem spre iconița din /media/.
function fisier_favicon(): void
{
    $f = (string) config('site.favicon');
    if ($f === '') { pagina_eroare(404); return; }
    header('Location: ' . $f, true, 301);
    header('Cache-Control: public, max-age=86400');
}

function fisier_indexnow(string $cerut): void
{
    $cheie = indexnow_cheie(false);
    if ($cheie === '' || !hash_equals($cheie, $cerut)) { pagina_eroare(404); return; }
    antete_text('text/plain');
    echo $cheie, "\n";
}

function fisier_robots(): void
{
    antete_text('text/plain');
    $interzise = "Disallow: /mcp\nDisallow: /mcp.php\nDisallow: /jurnal.php\nDisallow: /actualizare.php\nDisallow: /imagini.php\nDisallow: /cauta\nDisallow: /previzualizare/\nDisallow: /oauth/\n";
    echo "User-agent: *\nAllow: /\n", $interzise;
    foreach (ROBOTI as $bot) echo "\nUser-agent: $bot\nAllow: /\n", $interzise;
    echo "\n# Rezumatul site-ului pentru modele de limbaj: ", url_absolut('/llms.txt'), "\n";
    echo "Sitemap: ", url_absolut('/sitemap.xml'), "\n";
}

// Rezumatul site-ului pentru modelele AI care îl citesc (llmstxt.org).
function fisier_llms(): void
{
    antete_text('text/plain');
    echo '# ', config('site.nume'), "\n\n";
    if (config('site.descriere')) echo '> ', config('site.descriere'), "\n\n";
    if ((string) config('site.subsol') !== '') echo config('site.subsol'), "\n\n";   // ce stă pe fiecare pagină, citesc și modelele
    echo 'Adresa site-ului: ', url_absolut('/'), ' · limba: ', config('site.limba');
    if ((string) config('site.autor') !== '') echo ' · autor: ', config('site.autor');
    echo "\n", 'Conținutul se poate citi și prin ', url_absolut('/feed.xml'), ' (RSS) sau ', url_absolut('/sitemap.xml'), ' (toate adresele).', "\n\n";
    $legaturi = (array) config('site.legaturi');
    if ($legaturi) {
        echo '## Celelalte site-uri și conturi ale aceluiași autor', "\n\n";
        foreach ($legaturi as $l) echo '- [', $l['titlu'], '](', $l['url'], ")\n";
        echo "\n";
    }
    foreach (['pagina' => 'Pagini', 'articol' => nume_articole(false, true)] as $tip => $titlu) {
        $lista = listeaza_elemente($tip, 'vizibil');
        if (!$lista) continue;
        echo '## ', $titlu, "\n\n";
        foreach ($lista as $e) {
            echo '- [', $e['titlu'], '](', url_absolut(url_element($e)), ')';
            if ($tip === 'articol' && !empty($e['publicat_la'])) echo ' — ', substr((string) $e['publicat_la'], 0, 10);
            if (($e['descriere'] ?? '') !== '') echo ': ', $e['descriere'];
            if ($tip === 'articol' && ($e['etichete'] ?? [])) echo ' [' . implode(', ', $e['etichete']) . ']';
            echo "\n";
        }
        echo "\n";
    }
}
