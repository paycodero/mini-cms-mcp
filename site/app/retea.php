<?php
// Rețeaua de site-uri: la finalul unui articol sau al unei pagini, legături spre paginile de pe CELELALTE site-uri
// ale aceluiași autor, pe același subiect. Legăturile nu se aleg aici: site-ul citește un index comun (JSON),
// construit în afara lui, de la adresa din identitatea site-ului („retea”). Gol = funcția e oprită (implicit).
//
// Formatul indexului: {"legaturi": {"<gazda fără www><calea fără / final>": [{"url","titlu","nume_site"}, …]}}.
// Se citește pe server (blocul e în HTML, deci îl văd și motoarele de căutare, și asistenții AI), cu o copie
// în date/ reînnoită la 6 ore. Dacă sursa nu răspunde, rămâne ultima copie bună; fără nicio copie, blocul lipsește.
// Pagina nu așteaptă niciodată mai mult de 4 secunde, o dată la 6 ore.
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

const RETEA_ORE = 6;
const RETEA_MAX_OCTETI = 2 * 1024 * 1024;
const RETEA_MAX_LEGATURI = 6;

function retea_cheie(string $url): string
{
    $p = parse_url(strtolower(trim($url)));
    if (empty($p['host'])) return '';
    return (string) preg_replace('/^www\./', '', $p['host']) . rtrim((string) ($p['path'] ?? ''), '/');
}

function retea_descarca(string $url): ?string
{
    $antete = ['User-Agent: mini-cms-mcp/' . MINICMS_VERSIUNE . ' (retea)', 'Accept: application/json'];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $antete, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        $corp = curl_exec($ch);
        $cod = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($corp !== false && $cod === 200 && strlen((string) $corp) <= RETEA_MAX_OCTETI) ? (string) $corp : null;
    }
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $antete), 'timeout' => 4],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $corp = @file_get_contents($url, false, $ctx, 0, RETEA_MAX_OCTETI + 1);
    return ($corp !== false && strlen($corp) <= RETEA_MAX_OCTETI) ? $corp : null;
}

function retea_index(): array
{
    $sursa = trim((string) config('site.retea'));
    if ($sursa === '' || !preg_match('#^https://#i', $sursa)) return [];
    $fisier = dir_date() . '/retea-cache.json';
    $cache = is_file($fisier) ? json_decode((string) file_get_contents($fisier), true) : null;
    $proaspat = is_array($cache) && ($cache['sursa'] ?? '') === $sursa && time() - (int) ($cache['la'] ?? 0) < RETEA_ORE * 3600;
    if (!$proaspat) {
        $corp = retea_descarca($sursa);
        $nou = $corp !== null ? json_decode((string) preg_replace('/^\xEF\xBB\xBF/', '', $corp), true) : null;
        if (is_array($nou) && is_array($nou['legaturi'] ?? null)) {
            $cache = ['sursa' => $sursa, 'la' => time(), 'legaturi' => $nou['legaturi']];
        } elseif (is_array($cache)) {
            $cache['la'] = time() - (RETEA_ORE - 1) * 3600;   // sursa n-a răspuns: păstrăm copia, reîncercăm peste o oră
        } else {
            $cache = ['sursa' => $sursa, 'la' => time() - (RETEA_ORE - 1) * 3600, 'legaturi' => []];
        }
        scrie_atomic($fisier, (string) json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    return (array) ($cache['legaturi'] ?? []);
}

// Legăturile pentru o adresă canonică: doar https, doar spre alte gazde, titlu și nume de site ca text simplu.
function retea_legaturi(string $canonic): array
{
    $cheie = retea_cheie($canonic);
    if ($cheie === '') return [];
    try {
        $lista = retea_index()[$cheie] ?? [];
    } catch (Throwable $t) {
        return [];
    }
    $gazda = (string) parse_url(strtolower($canonic), PHP_URL_HOST);
    $rez = [];
    foreach ((array) $lista as $x) {
        $url = (string) ($x['url'] ?? '');
        $h = (string) parse_url(strtolower($url), PHP_URL_HOST);
        if (!preg_match('#^https://#i', $url) || $h === '' || $h === $gazda) continue;
        $titlu = trim((string) ($x['titlu'] ?? ''));
        if ($titlu === '') continue;
        $rez[] = ['url' => $url, 'titlu' => mb_substr($titlu, 0, 160), 'site' => mb_substr(trim((string) ($x['nume_site'] ?? $h)), 0, 40)];
        if (count($rez) >= RETEA_MAX_LEGATURI) break;
    }
    return $rez;
}
