<?php
// Anunțarea motoarelor de căutare când conținutul se schimbă (IndexNow: Bing, Yandex, Seznam, Naver).
// Google nu are un asemenea punct — acolo rămâne sitemap-ul, citit singur.
//
// Cum merge: site-ul are o cheie de 32 de caractere, păstrată în date/securitate/, servită la
// https://site/<cheie>.txt (dinamic, nu ca fișier pe disc — AI-ul nu scrie niciodată fișiere în rădăcină).
// La publicare, modificarea unui element publicat sau retragere, adresa lui pleacă spre api.indexnow.org.
// Nimic nu se oprește dacă anunțul eșuează: se scrie în jurnal și viața merge mai departe.
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

const INDEXNOW_GAZDA = 'https://api.indexnow.org/IndexNow';
const INDEXNOW_SECUNDE = 4;

function indexnow_pornit(): bool
{
    $c = config('indexnow');
    if ($c === false || $c === 'nu') return false;
    $p = parse_url((string) config('site.url'));
    $gazda = strtolower((string) ($p['host'] ?? ''));
    return ($p['scheme'] ?? '') === 'https' && $gazda !== 'localhost' && $gazda !== '127.0.0.1' && strpos($gazda, '.') !== false;
}

function indexnow_cheie(bool $creeaza = true): string
{
    $f = config('date') . '/securitate/indexnow.cheie';
    $cheie = is_file($f) ? trim((string) file_get_contents($f)) : '';
    if (!preg_match('/^[a-f0-9]{32}$/', $cheie)) {
        if (!$creeaza) return '';
        $cheie = bin2hex(random_bytes(16));
        scrie_atomic(dir_date('securitate') . '/indexnow.cheie', $cheie);
    }
    return $cheie;
}

// Adresele schimbate, trimise o singură dată, fără să blocheze răspunsul mai mult de câteva secunde.
function indexnow_anunta(array $adrese): void
{
    $adrese = array_values(array_unique(array_filter($adrese)));
    if (!$adrese || !indexnow_pornit()) return;
    $cheie = indexnow_cheie();
    $gazda = (string) parse_url((string) config('site.url'), PHP_URL_HOST);
    $corp = json_text(['host' => $gazda, 'key' => $cheie, 'keyLocation' => url_absolut("/$cheie.txt"), 'urlList' => array_slice($adrese, 0, 100)]);
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json; charset=utf-8\r\n",
        'content' => $corp, 'ignore_errors' => true, 'timeout' => INDEXNOW_SECUNDE, 'follow_location' => 0]]);
    $raspuns = @file_get_contents(INDEXNOW_GAZDA, false, $ctx);
    $cod = 0;
    foreach (($http_response_header ?? []) as $linie) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linie, $m)) $cod = (int) $m[1];
    jurnal_scrie(['punct' => 'seo', 'cerere' => 'indexnow', 'tinta' => implode(' ', array_slice($adrese, 0, 3)),
                  'rezultat' => in_array($cod, [200, 202], true) ? 'ok' : 'eroare',
                  'detalii' => ['cod' => $cod, 'adrese' => count($adrese)] + ($raspuns === false ? ['motiv' => 'fără răspuns'] : [])]);
}

// Adresele de anunțat când se schimbă un element: pagina lui, prima pagină și listele care îl conțin.
// Se cheamă și la retragere: adresa a devenit 404, iar asta e tot o schimbare de anunțat.
function indexnow_pentru(array $e): void
{
    $adrese = [url_absolut(url_element($e)), url_absolut('/')];
    if (($e['tip'] ?? '') === 'articol') {
        $adrese[] = url_absolut('/articole');
        foreach ($e['etichete'] ?? [] as $t) $adrese[] = url_absolut('/eticheta/' . slug_din_text((string) $t));
    }
    indexnow_anunta($adrese);
}
