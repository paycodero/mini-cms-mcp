<?php
// Citirile AI: câte pagini deschid ChatGPT, Claude, Perplexity & co. și câți oameni vin din asistenți.
// GA4 nu le poate vedea: boții nu execută JavaScript, deci momentul în care ChatGPT îți citește pagina ca să
// răspundă cuiva rămâne invizibil acolo. Aici se numără pe server, la cererea paginii.
//
// Ce se păstrează: pe zi, pe asistent, pe adresă — un număr. Fără IP, fără user-agent întreg, fără nimic despre om.
// Un fișier pe lună, date/vizite-ai/AAAA-LL.json. Nu e jurnalul cu lanț: e o statistică, nu o dovadă.
// Cererile obișnuite (browsere, alți boți) nu costă nimic în plus: doar două căutări de text în antete.
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

const VIZITE_AI_MAX_OCTETI = 2 * 1024 * 1024;   // peste atât, luna curentă nu mai crește (un val de cereri false nu umple discul)

// Boții AI, după rostul lor. Ordinea contează doar ca text: numele nu se includ unul pe altul.
//   om        = asistentul deschide pagina chiar acum, la cererea unui om (ChatGPT-User: „cineva a întrebat și ai fost sursa”)
//   cautare   = indexul din care asistenții își aleg sursele (fără el nu există citare; Bing alimentează și ChatGPT, și Copilot)
//   antrenare = colectare pentru antrenarea modelelor; nu aduce citări
const BOTI_AI = [
    'ChatGPT-User' => 'om', 'Claude-User' => 'om', 'Perplexity-User' => 'om', 'MistralAI-User' => 'om',
    'DuckAssistBot' => 'om', 'Gemini-Deep-Research' => 'om',
    'OAI-SearchBot' => 'cautare', 'Claude-SearchBot' => 'cautare', 'PerplexityBot' => 'cautare', 'bingbot' => 'cautare',
    'GPTBot' => 'antrenare', 'ClaudeBot' => 'antrenare', 'anthropic-ai' => 'antrenare', 'CCBot' => 'antrenare',
    'meta-externalagent' => 'antrenare', 'Amazonbot' => 'antrenare', 'Bytespider' => 'antrenare',
];

// Oamenii care dau click pe o sursă în asistent: după adresa de unde vin (Referer) sau după utm_source.
const ASISTENTI_AI = [
    'chatgpt.com' => 'ChatGPT', 'chat.openai.com' => 'ChatGPT', 'perplexity.ai' => 'Perplexity',
    'copilot.microsoft.com' => 'Copilot', 'copilot.cloud.microsoft' => 'Copilot', 'gemini.google.com' => 'Gemini',
    'claude.ai' => 'Claude', 'chat.mistral.ai' => 'Mistral', 'chat.deepseek.com' => 'DeepSeek',
];

const FELURI_VIZITE_AI = [
    'om' => 'asistentul a deschis pagina la cererea unui om, ca să-i răspundă (pagina ta a fost sursă)',
    'cautare' => 'robotul de căutare din care asistentul își alege sursele; fără trecerea lui nu există citare',
    'antrenare' => 'colectare pentru antrenarea modelelor; nu aduce citări',
    'vizitator' => 'un om a dat click pe pagina ta dintr-un asistent (ChatGPT, Perplexity, Copilot…)',
];

function vizite_ai_pornit(): bool
{
    $c = config('vizite_ai');
    return $c !== false && $c !== 'nu';
}

// Cine e cererea, dacă e una de numărat: ['cine' => 'ChatGPT-User', 'fel' => 'om'] sau null.
function vizita_ai_din(string $ua, string $referer, string $interogare): ?array
{
    if ($ua !== '') foreach (BOTI_AI as $bot => $fel) if (stripos($ua, $bot) !== false) return ['cine' => $bot, 'fel' => $fel];
    $gazda = strtolower((string) parse_url($referer, PHP_URL_HOST));
    $gazda = (string) preg_replace('/^www\./', '', $gazda);
    if (isset(ASISTENTI_AI[$gazda])) return ['cine' => ASISTENTI_AI[$gazda], 'fel' => 'vizitator'];
    parse_str($interogare, $q);
    $sursa = strtolower(trim((string) (is_string($q['utm_source'] ?? null) ? $q['utm_source'] : '')));
    if ($sursa !== '') foreach (ASISTENTI_AI as $domeniu => $nume) {
        if ($sursa === $domeniu || $sursa === strtolower($nume)) return ['cine' => $nume, 'fel' => 'vizitator'];
    }
    return null;
}

// Se cheamă la începutul oricărei cereri publice. Numărarea se face la sfârșit, când se știe codul răspunsului.
function vizite_ai_urmareste(string $cale): void
{
    if (!vizite_ai_pornit() || e_proba_boti()) return;   // cererile de probă ale lui verifica_boti nu sunt citiri reale
    // Un Worker Cloudflare care ocolește blocajul găzduirii (ex. boti-ai, pe paycode.ro) îi schimbă botului numele în unul
    // de browser și păstrează originalul în X-Original-User-Agent. Îl luăm de acolo; un antet fals n-ar păcăli mai mult
    // decât un User-Agent fals, iar aici e doar o statistică.
    $ua = trim((string) ($_SERVER['HTTP_X_ORIGINAL_USER_AGENT'] ?? '')) ?: (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $v = vizita_ai_din($ua, (string) ($_SERVER['HTTP_REFERER'] ?? ''), (string) ($_SERVER['QUERY_STRING'] ?? ''));
    if (!$v) return;
    register_shutdown_function(function () use ($v, $cale) {
        $cod = (int) (http_response_code() ?: 200);
        // un om numărat doar când chiar a văzut o pagină; un bot, și la redirecționări (a urmat adresa veche)
        if ($v['fel'] === 'vizitator' && ($cod !== 200 || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET')) return;
        vizite_ai_numara($v['cine'], $cale, $cod);
    });
}

function vizite_ai_numara(string $cine, string $cale, int $cod, ?string $zi = null): void
{
    $zi = $zi ?? date('Y-m-d');
    try {
        $f = dir_date('vizite-ai') . '/' . substr($zi, 0, 7) . '.json';
    } catch (Throwable $e) {
        return;
    }
    $fp = @fopen($f, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $brut = (string) stream_get_contents($fp);
    if (strlen($brut) <= VIZITE_AI_MAX_OCTETI) {
        $d = json_decode($brut, true);
        if (!is_array($d)) $d = [];
        // erorile se țin pe cod, nu pe adresă: adresele inventate nu umflă fișierul
        if ($cod >= 400) $d['erori'][$zi][$cine][(string) $cod] = (int) ($d['erori'][$zi][$cine][(string) $cod] ?? 0) + 1;
        else {
            $cale = substr($cale === "" ? "/" : $cale, 0, 200);
            $d['zile'][$zi][$cine][$cale] = (int) ($d['zile'][$zi][$cine][$cale] ?? 0) + 1;
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_text($d));
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
}

function fel_vizita_ai(string $cine): string
{
    return BOTI_AI[$cine] ?? 'vizitator';
}

// Raportul pentru ultimele $zile zile (inclusiv azi).
function raport_vizite_ai(int $zile = 30, int $pagini_max = 50): array
{
    $zile = max(1, min(366, $zile));
    $pana = date('Y-m-d');
    $de_la = date('Y-m-d', strtotime("-" . ($zile - 1) . " days"));
    $luni = [];
    for ($t = strtotime(substr($de_la, 0, 7) . '-01'); $t <= strtotime($pana); $t = strtotime('+1 month', $t)) $luni[] = date('Y-m', $t);

    $pe_fel = array_fill_keys(array_keys(FELURI_VIZITE_AI), 0);
    $pe_asistent = [];
    $pagini = [];
    $pe_zile = [];
    $erori = [];
    foreach ($luni as $l) {
        $f = config('date') . "/vizite-ai/$l.json";
        $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        if (!is_array($d)) continue;
        foreach ((array) ($d['zile'] ?? []) as $zi => $cine_lista) {
            if ($zi < $de_la || $zi > $pana) continue;
            foreach ((array) $cine_lista as $cine => $adrese) foreach ((array) $adrese as $cale => $n) {
                $n = (int) $n;
                $pe_fel[fel_vizita_ai((string) $cine)] += $n;
                $pe_asistent[$cine] = ($pe_asistent[$cine] ?? 0) + $n;
                $pe_zile[$zi] = ($pe_zile[$zi] ?? 0) + $n;
                $pagini[$cale]['total'] = ($pagini[$cale]['total'] ?? 0) + $n;
                $pagini[$cale]['pe_asistent'][$cine] = ($pagini[$cale]['pe_asistent'][$cine] ?? 0) + $n;
            }
        }
        foreach ((array) ($d['erori'] ?? []) as $zi => $cine_lista) {
            if ($zi < $de_la || $zi > $pana) continue;
            foreach ((array) $cine_lista as $cine => $coduri) foreach ((array) $coduri as $cod => $n)
                $erori[$cine][$cod] = ($erori[$cine][$cod] ?? 0) + (int) $n;
        }
    }
    arsort($pe_asistent);
    ksort($pe_zile);
    uasort($pagini, fn($a, $b) => $b['total'] <=> $a['total']);
    $lista = [];
    foreach (array_slice($pagini, 0, $pagini_max, true) as $cale => $p) {
        arsort($p['pe_asistent']);
        $lista[] = ['adresa' => (string) $cale, 'total' => $p['total'], 'pe_asistent' => $p['pe_asistent']];
    }
    return [
        'perioada' => ['de_la' => $de_la, 'pana_la' => $pana, 'zile' => $zile],
        'pornit' => vizite_ai_pornit(),
        'pe_fel' => $pe_fel,
        'pe_asistent' => $pe_asistent ?: new stdClass(),
        'pagini' => $lista,
        'pagini_in_total' => count($pagini),
        'pe_zile' => $pe_zile ?: new stdClass(),
        'erori' => $erori ?: new stdClass(),
        'ce_inseamna' => FELURI_VIZITE_AI,
        'limite' => 'Boții se recunosc după numele declarat (user-agent), fără verificarea IP-ului. Vizitatorii din asistenți se văd doar '
            . 'când browserul trimite adresa de unde vin sau utm_source; din aplicațiile de telefon adesea nu o trimite. '
            . 'O pagină servită din cache-ul Cloudflare nu ajunge la server, deci nu se numără. Numărătoarea începe de la instalarea versiunii 0.21.0.',
    ];
}
