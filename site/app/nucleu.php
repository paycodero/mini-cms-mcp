<?php
// mini-cms-mcp — nucleul, încărcat de fiecare punct de intrare (index.php, mcp.php, jurnal.php).
// Fără biblioteci externe, fără bază de date, fără fișiere de pe alte servere.
// Compatibil PHP 8.0 (versiunea de pe găzduirea paycode.ro) — fără enum, readonly, never, array_is_list.
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

const MINICMS_VERSIUNE = '0.4.0';

ini_set('display_errors', '0');   // un avertisment afișat ar strica JSON-ul MCP și ar scurge căi de pe server
error_reporting(E_ALL);

class EroareCms extends RuntimeException {}   // eroare de validare, cu mesaj bun de arătat AI-ului

// --- configurarea ------------------------------------------------------------------------------

// Identitatea site-ului e conținut: AI-ul o schimbă cu seteaza_site, iar valorile stau în date/site.json.
// Ce scrie în config.php e doar punctul de plecare. Adresa (url) și cheile rămân numai în config.php.
const CAMPURI_IDENTITATE = ['nume', 'descriere', 'limba', 'autor', 'culoare', 'logo', 'favicon'];

function config(string $cale = '')
{
    static $c = null;
    if ($c === null) {
        $fisier = __DIR__ . '/config.php';
        if (!is_file($fisier)) oprire(503, 'Lipsește app/config.php (pornește de la app/config.exemplu.php).');
        $dat = require $fisier;
        if (!is_array($dat)) oprire(503, 'app/config.php trebuie să întoarcă un array.');
        $implicit = [
            'site' => ['nume' => '', 'descriere' => '', 'url' => '', 'limba' => 'ro', 'autor' => '', 'culoare' => '#6d2be8',
                       'logo' => '', 'favicon' => ''],
            'chei' => ['citire' => '', 'scriere' => ''],
            'date' => dirname(__DIR__) . '/date',
            'media' => dirname(__DIR__) . '/media',
            'fus_orar' => 'Europe/Bucharest',
            'cloudflare' => 'auto',   // IP-ul real din CF-Connecting-IP, doar când cererea vine din rețeaua Cloudflare
            'hsts' => 'auto',         // antetul HSTS pe orice răspuns servit prin https
            'csp_extra' => [],
            'oauth_gazde' => [],      // gazde https în plus la care OAuth poate trimite codul (implicit: claude.ai, claude.com, localhost)
            'articole_pe_pagina' => 12,
        ];
        foreach (['site', 'chei'] as $k) $dat[$k] = (array) ($dat[$k] ?? []) + $implicit[$k];
        $c = $dat + $implicit;
        $c['site']['url'] = rtrim((string) $c['site']['url'], '/');
        $identitate = json_citeste($c['date'] . '/site.json') ?? [];
        foreach (CAMPURI_IDENTITATE as $k) {
            if (isset($identitate[$k]) && is_string($identitate[$k])) $c['site'][$k] = $identitate[$k];
        }
        if ((string) $c['site']['nume'] === '') $c['site']['nume'] = (string) (parse_url($c['site']['url'], PHP_URL_HOST) ?: 'Site nou');
    }
    if ($cale === '') return $c;
    $v = $c;
    foreach (explode('.', $cale) as $p) {
        if (!is_array($v) || !array_key_exists($p, $v)) return null;
        $v = $v[$p];
    }
    return $v;
}

function oprire(int $cod, string $mesaj): void
{
    if (!headers_sent()) {
        http_response_code($cod);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo $mesaj, "\n";
    exit;
}

// --- fișiere -----------------------------------------------------------------------------------

// Orice dosar de date se creează cu .htaccess de blocare din prima clipă (lecția de la cinesunt.info:
// dosarele create fără el au stat citibile public).
function dir_protejat(string $dir): string
{
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('nu pot crea dosarul de date ' . basename($dir));
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    return $dir;
}

function dir_date(string $sub = ''): string
{
    $rad = dir_protejat((string) config('date'));
    return $sub === '' ? $rad : dir_protejat($rad . '/' . $sub);
}

// Scriere atomică: fișier temporar în același dosar, apoi rename. Dacă sistemul refuză rename peste un
// fișier existent (Windows), copiem și verificăm amprenta — lecția din deploy.php de la Gabriel.
function scrie_atomic(string $tinta, string $continut): bool
{
    $tmp = $tinta . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $continut) === false) return false;
    $ok = @rename($tmp, $tinta)
        || (@copy($tmp, $tinta) && hash_file('sha256', $tinta) === hash('sha256', $continut));
    if (is_file($tmp)) @unlink($tmp);
    return $ok;
}

// Toate scrierile de conținut trec pe rând, printr-o singură blocare — un site mic nu are nevoie de mai mult.
function cu_blocare(callable $f)
{
    $fp = fopen(dir_date() . '/.blocare', 'c');
    if (!$fp) throw new RuntimeException('nu pot obține blocarea de scriere');
    flock($fp, LOCK_EX);
    try {
        return $f();
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function json_citeste(string $f): ?array
{
    if (!is_file($f)) return null;
    $d = json_decode((string) file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

function json_text($v, bool $frumos = false): string
{
    $f = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | ($frumos ? JSON_PRETTY_PRINT : 0);
    return (string) json_encode($v, $f);
}

// --- text --------------------------------------------------------------------------------------

function esc($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

// Text de o linie (titlu, descriere, etichetă): fără etichete HTML, fără caractere de control, tăiat la $max caractere.
function text_simplu($v, int $max): string
{
    $t = strip_tags((string) $v);
    $t = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $t);
    $t = trim((string) preg_replace('/\s+/u', ' ', $t));
    if (preg_match('/^.{0,' . min($max, 60000) . '}/us', $t, $m)) $t = $m[0];
    return trim($t);
}

function slug_din_text(string $t): string
{
    $t = strtr($t, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
                    'Ă' => 'a', 'Â' => 'a', 'Î' => 'i', 'Ș' => 's', 'Ş' => 's', 'Ț' => 't', 'Ţ' => 't']);
    $t = strtolower($t);
    $t = (string) preg_replace('/[^a-z0-9]+/', '-', $t);
    return trim($t, '-');
}

// --- cererea HTTP ------------------------------------------------------------------------------

// Rețelele Cloudflare: CF-Connecting-IP e crezut DOAR dacă cererea vine chiar din Cloudflare.
const CLOUDFLARE = ['173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
    '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
    '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
    '2a06:98c0::/29', '2c0f:f248::/32'];

function ip_in_retea(string $ip, string $cidr): bool
{
    [$retea, $bits] = explode('/', $cidr);
    $a = @inet_pton($ip);
    $b = @inet_pton($retea);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
    $bits = (int) $bits;
    $octeti = intdiv($bits, 8);
    if (substr($a, 0, $octeti) !== substr($b, 0, $octeti)) return false;
    $rest = $bits % 8;
    if ($rest === 0) return true;
    $masca = chr((0xFF << (8 - $rest)) & 0xFF);
    return (($a[$octeti] & $masca) === ($b[$octeti] & $masca));
}

// Antetele puse de Cloudflare (CF-Connecting-IP, X-Forwarded-Proto) se cred doar dacă cererea vine chiar din
// rețeaua Cloudflare: acolo Cloudflare le suprascrie, clientul nu le poate impune. Oricine altcineva le trimite,
// sunt ignorate. Așa setarea se potrivește singură, fără să știi dinainte dacă domeniul trece prin Cloudflare.
// Cu 'cloudflare' => false în config.php, antetele sunt ignorate mereu.
function din_cloudflare(): bool
{
    static $rez = null;
    if ($rez === null) {
        $rez = false;
        if (config('cloudflare') !== false) {
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            foreach (CLOUDFLARE as $retea) {
                if (ip_in_retea($ip, $retea)) { $rez = true; break; }
            }
        }
    }
    return $rez;
}

function ip_client(): string
{
    $cf = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
    if (din_cloudflare() && filter_var($cf, FILTER_VALIDATE_IP)) return $cf;
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'necunoscut');
}

function este_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    return din_cloudflare() && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function url_site(): string
{
    $u = (string) config('site.url');
    if ($u !== '') return $u;
    $gazda = (string) preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return (este_https() ? 'https' : 'http') . '://' . $gazda;
}

function url_absolut(string $cale): string
{
    return url_site() . $cale;
}

// --- antete de securitate, puse din PHP: merg și unde .htaccess nu are mod_headers ------------

function antete_securitate(?string $csp = null): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    if (config('hsts') !== false && este_https()) header('Strict-Transport-Security: max-age=31536000');
    if ($csp !== null) header('Content-Security-Policy: ' . $csp);
}

function csp_pagina(string $nonce, array $extra = []): string
{
    $surse = [
        'default-src' => ["'self'"],
        'script-src' => ["'self'", "'nonce-$nonce'"],
        'style-src' => ["'self'", "'nonce-$nonce'"],
        'img-src' => ["'self'", 'https:', 'data:'],
        'font-src' => ["'self'"],
        'connect-src' => ["'self'"],
        'frame-src' => ['https://www.youtube-nocookie.com', 'https://www.youtube.com', 'https://player.vimeo.com'],
        'object-src' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'none'"],
    ];
    foreach (array_merge_recursive((array) config('csp_extra'), $extra) as $directiva => $lista) {
        if (isset($surse[$directiva])) $surse[$directiva] = array_merge($surse[$directiva], (array) $lista);
    }
    $parti = [];
    foreach ($surse as $directiva => $lista) $parti[] = $directiva . ' ' . implode(' ', $lista);
    return implode('; ', $parti);
}

date_default_timezone_set((string) (config('fus_orar') ?: 'Europe/Bucharest'));

require __DIR__ . '/jurnalizare.php';
require __DIR__ . '/securitate.php';
require __DIR__ . '/curatare.php';
require __DIR__ . '/continut.php';
require __DIR__ . '/imagini.php';
require __DIR__ . '/oauth.php';
