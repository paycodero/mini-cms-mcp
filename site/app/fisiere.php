<?php
// Documentele PDF: al doilea fel de fișier pe care AI-ul îl poate pune pe site, după imagini.
// Spre deosebire de imagini, NU stau în dosarul public: se păstrează în date/fisiere/ (lângă conținut, protejat)
// și le servește PHP la /fisiere/<nume>.pdf, cu antetele puse din cod. Așa:
// - un fișier urcat nu poate fi niciodată rulat de server, oricum s-ar numi (nu e în rădăcina web);
// - antetele de siguranță merg și pe găzduirile fără mod_headers;
// - actualizarea codului nu le atinge (date/ e al tău), iar copia de siguranță le ia odată cu conținutul.
// Tipul se află din conținut („%PDF-" la început, „%%EOF" la sfârșit), nu din numele trimis; numele îl curățăm noi.
// Adresa rămâne aceeași la înlocuire (linkurile din pagini nu se strică), iar versiunea veche se păstrează.
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

const FISIER_MAX_OCTETI = 25 * 1024 * 1024;
const FISIER_URL_SECUNDE = 60;
const FISIER_NUME = '/^[a-z0-9]+(-[a-z0-9]+)*\.pdf$/';

function dir_fisiere(): string
{
    return dir_date('fisiere');
}

function nume_fisier(string $nume): string
{
    $baza = rtrim(substr(slug_din_text((string) pathinfo($nume, PATHINFO_FILENAME)), 0, 80), '-');
    if ($baza === '') throw new EroareCms('dă-i documentului un nume, ex. "raport-anual-2025.pdf"');
    return $baza . '.pdf';
}

// Un PDF adevărat: începe cu „%PDF-" (standardul îngăduie câteva octeți înainte) și se încheie cu „%%EOF".
function verifica_pdf(string $date): void
{
    if ($date === '') throw new EroareCms('fișierul e gol');
    if (strlen($date) > FISIER_MAX_OCTETI) throw new EroareCms('documentul depășește ' . intdiv(FISIER_MAX_OCTETI, 1024 * 1024) . ' MB');
    $cap = strpos(substr($date, 0, 1024), '%PDF-');
    if ($cap === false) throw new EroareCms('nu e un document PDF (primesc doar PDF-uri: conținutul trebuie să înceapă cu %PDF-)');
    if (strpos(substr($date, -2048), '%%EOF') === false) throw new EroareCms('PDF-ul pare tăiat (lipsește sfârșitul, %%EOF): urcă-l din nou');
}

function urca_fisier(string $nume, string $base64, bool $inlocuieste): array
{
    $base64 = (string) preg_replace('/^data:[^,]*,/', '', trim($base64));
    $date = base64_decode($base64, true);
    if ($date === false || $date === '') throw new EroareCms('conținutul nu e base64 valid');
    return urca_fisier_date($nume, $date, $inlocuieste);
}

function urca_fisier_din_url(string $url, string $nume, bool $inlocuieste): array
{
    $inceput = $url;
    [$corp, $url] = descarca_dupa_url($url, FISIER_MAX_OCTETI, 'application/pdf', 'documentul', FISIER_URL_SECUNDE);
    if ($nume === '') $nume = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH))) ?: 'document';
    return urca_fisier_date($nume, $corp, $inlocuieste) + ['sursa' => $inceput] + ($url !== $inceput ? ['sursa_finala' => $url] : []);
}

function urca_fisier_date(string $nume, string $date, bool $inlocuieste): array
{
    verifica_pdf($date);
    $fisier = nume_fisier($nume);
    // pe un site mutat de pe alt CMS pot exista PDF-uri vechi chiar în dosarul public /fisiere/: acelea au întâietate
    // la servire (sunt fișiere reale), deci un document cu același nume n-ar apărea niciodată
    if (is_file(dirname(__DIR__) . '/fisiere/' . $fisier)) {
        throw new EroareCms("pe site există deja un fișier static /fisiere/$fisier (pus pe server de mână): alege alt nume");
    }
    $amprenta = hash('sha256', $date);
    $tinta = dir_fisiere() . '/' . $fisier;
    return cu_blocare(function () use ($tinta, $date, $fisier, $amprenta, $inlocuieste) {
        $versiune = null;
        if (is_file($tinta)) {
            if (hash_file('sha256', $tinta) === $amprenta) {
                return ['operatie' => 'exista deja (același conținut)'] + fisier_rezultat($fisier, $date, $amprenta);
            }
            if (!$inlocuieste) {
                throw new EroareCms("există deja documentul /fisiere/$fisier, cu alt conținut. Ca să-l înlocuiești (adresa rămâne, "
                    . 'versiunea veche se păstrează), trimite inlocuieste=true; altfel alege alt nume');
            }
            $versiune = date('Ymd-His');
            $dest = dir_date('versiuni/fisiere') . '/' . $fisier . '.' . $versiune;
            if (!@copy($tinta, $dest)) throw new EroareCms('nu pot păstra versiunea veche: documentul nu a fost înlocuit');
        }
        if (!scrie_atomic($tinta, $date)) throw new EroareCms('scrierea documentului a eșuat');
        return ['operatie' => $versiune ? 'înlocuit (versiunea veche păstrată)' : 'urcat'] + fisier_rezultat($fisier, $date, $amprenta)
            + ($versiune ? ['versiune_veche' => $versiune] : []);
    });
}

function fisier_rezultat(string $fisier, string $date, string $amprenta): array
{
    return ['url' => '/fisiere/' . $fisier, 'url_absolut' => url_absolut('/fisiere/' . $fisier),
            'octeti' => strlen($date), 'amprenta' => $amprenta,
            'folosire' => 'în conținut: <a href="/fisiere/' . $fisier . '">Descarcă (PDF)</a>'];
}

function listeaza_fisiere(): array
{
    $rez = [];
    foreach (scandir(dir_fisiere()) ?: [] as $f) {
        if (!preg_match(FISIER_NUME, $f)) continue;
        $cale = dir_fisiere() . '/' . $f;
        $rez[] = ['nume' => $f, 'url' => '/fisiere/' . $f, 'octeti' => filesize($cale), 'urcat_la' => date('c', (int) filemtime($cale))];
    }
    usort($rez, fn($a, $b) => strcmp($b['urcat_la'], $a['urcat_la']));
    return $rez;
}

function fisier_folosit_in(string $fisier): array
{
    $unde = [];
    foreach (array_keys(TIPURI) as $tip) {
        foreach (listeaza_elemente($tip) as $e) {
            if (strpos((string) ($e['continut_html'] ?? ''), '/fisiere/' . $fisier) !== false) $unde[] = $tip . '/' . $e['slug'];
        }
    }
    return $unde;
}

function sterge_fisier(string $fisier, bool $forteaza): array
{
    if (!preg_match(FISIER_NUME, $fisier)) throw new EroareCms('nume de document invalid (ex. "raport-anual-2025.pdf", din listeaza_fisiere)');
    return cu_blocare(function () use ($fisier, $forteaza) {
        $sursa = dir_fisiere() . '/' . $fisier;
        if (!is_file($sursa)) throw new EroareCms("documentul $fisier nu există");
        $unde = fisier_folosit_in($fisier);
        if ($unde && !$forteaza) {
            throw new EroareCms('documentul e legat din: ' . implode(', ', $unde) . ' — scoate linkul de acolo sau cere ștergerea cu forteaza=true');
        }
        $dest = dir_date('versiuni/fisiere') . '/' . $fisier . '.' . date('Ymd-His');
        if (!@rename($sursa, $dest) && !(@copy($sursa, $dest) && @unlink($sursa))) throw new EroareCms('mutarea documentului a eșuat');
        return ['operatie' => 'șters (mutat între versiuni)', 'nume' => $fisier, 'era_legat_din' => $unde];
    });
}

// Servirea publică: /fisiere/<nume>.pdf. Numele e verificat înainte de orice acces la disc (fără „..", fără alt dosar).
function serveste_fisier(string $fisier): void
{
    $cale = preg_match(FISIER_NUME, $fisier) ? dir_fisiere() . '/' . $fisier : '';
    if ($cale === '' || !is_file($cale)) { pagina_eroare(404); return; }
    $etag = '"' . dechex((int) filemtime($cale)) . '-' . dechex((int) filesize($cale)) . '"';   // ca Apache: fără să citim 25 MB la fiecare cerere
    // Protecția e tipul fix application/pdf + nosniff: browserul nu-l poate trata niciodată drept pagină a site-ului
    // (HTML), orice ar conține, iar un script din PDF rulează în vizualizator, izolat de site. Fără CSP aici, anume:
    // „sandbox" interzice pluginurile, iar object-src 'none' (din default-src 'none') blochează <embed>-ul — și unul, și
    // celălalt fac vizualizatorul PDF din Chrome să arate „blocat" în loc de document.
    antete_securitate();
    header('X-Frame-Options: SAMEORIGIN');   // vizualizatorul PDF al browserului încarcă documentul într-un cadru propriu
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fisier . '"');
    header('Cache-Control: public, max-age=3600');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) filemtime($cale)) . ' GMT');
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); return; }
    header('Content-Length: ' . filesize($cale));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') return;
    readfile($cale);
}
