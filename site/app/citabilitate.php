<?php
// Sfaturi de citabilitate: ce se poate verifica mecanic, la previzualizare și la publicare, ca un text să fie ușor
// de citat de un asistent AI (și de arătat de Google). Sunt sfaturi, nu reguli: nimic nu se blochează.
// Temeiul e practica recomandată (răspunsul direct în text vizibil, structura pe întrebări), netestată controlat
// pe ChatGPT — de aceea rămân avertismente pe care AI-ul i le spune omului, nu condiții de publicare.
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

const PRIMUL_PARAGRAF_MAX_CUVINTE = 60;
const TEXT_LUNG_CUVINTE = 500;

function numar_cuvinte(string $html): int
{
    $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);
}

function sfaturi_ai(array $e): array
{
    if (($e['slug'] ?? '') === 'acasa') return [];
    $html = (string) ($e['continut_html'] ?? '');
    $sfaturi = [];

    if (trim((string) ($e['descriere'] ?? '')) === '')
        $sfaturi[] = 'Lipsește descrierea: e rezumatul pe care îl arată Google și îl citesc asistenții (și llms.txt). '
            . 'O frază care răspunde direct la întrebarea paginii.';

    if (preg_match('#<p\b[^>]*>(.*?)</p>#is', $html, $m) && ($n = numar_cuvinte($m[1])) > PRIMUL_PARAGRAF_MAX_CUVINTE)
        $sfaturi[] = "Primul paragraf are $n de cuvinte: un asistent citează mai ușor un răspuns scurt, pus la început. "
            . 'Răspunsul în prima frază, primul paragraf sub ' . PRIMUL_PARAGRAF_MAX_CUVINTE . ' de cuvinte, detaliile după.';

    if (preg_match('/<h2[^>]*>\s*(întrebări frecvente|intrebari frecvente|frequently asked questions|faq)/iu', $html)) {
        require_once __DIR__ . '/site.php';   // faq_jsonld() stă în partea publică, care nu e încărcată în MCP
        if (faq_jsonld($html) === null)
            $sfaturi[] = 'Secțiunea „Întrebări frecvente” nu devine FAQ pentru Google și asistenți: fiecare întrebare trebuie să fie '
                . 'un <h3>, urmat de răspuns în <p>, și să fie cel puțin două.';
    }

    if (($e['tip'] ?? '') === 'articol' && ($n = numar_cuvinte($html)) > TEXT_LUNG_CUVINTE && !preg_match('/<h2\b/i', $html))
        $sfaturi[] = "Articolul are $n de cuvinte și niciun subtitlu <h2>: împărțit pe întrebările cititorului, e mai ușor de găsit "
            . 'și de citat bucată cu bucată.';

    return $sfaturi;
}

// Adaugă sfaturile la răspunsul unei comenzi, doar când există.
function cu_sfaturi_ai(array $raspuns, string $tip, string $slug): array
{
    $e = citeste_element($tip, $slug);
    $s = $e ? sfaturi_ai($e) : [];
    return $s ? $raspuns + ['sfaturi_ai' => $s, 'despre_sfaturi' => 'Sfaturi de citabilitate, nu reguli: spune-i omului și '
        . 'întreabă-l dacă vrea să le aplici. Nimic nu e blocat.'] : $raspuns;
}
