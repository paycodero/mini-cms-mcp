<?php
// verifica_boti: site-ul se cere singur, cu numele boților AI și de căutare.
// robots.txt poate spune „Allow”, iar firewallul găzduirii sau Cloudflare („Block AI bots”) îi poate opri totuși,
// fără niciun semn vizibil pentru om. Aici site-ul își cere prima pagină și cel mai nou articol pe drumul public
// (prin Cloudflare, dacă e pornit), o dată ca un browser obișnuit și o dată pentru fiecare bot, și compară.
// Cererile de probă poartă o cheie de unică folosință, ca să nu intre în numărătoarea din vizite_ai.
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

const BOTI_DE_VERIFICAT = [
    'OAI-SearchBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.3; +https://openai.com/searchbot',
    'ChatGPT-User' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot',
    'GPTBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot',
    'Claude-User' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-User/1.0; +Claude-User@anthropic.com)',
    'ClaudeBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
    'PerplexityBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)',
    'bingbot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm) Chrome/116.0.1938.76 Safari/537.36',
    'Googlebot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Googlebot/2.1; +http://www.google.com/bot.html) Chrome/130.0.0.0 Safari/537.36',
];
const UA_BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
const PROBA_BOTI_SECUNDE = 6;
const PROBA_BOTI_PAUZA = 60;   // cel mult o verificare pe minut: fiecare face ~20 de cereri spre site

// Cererea vine chiar de la verifica_boti? Antetul poartă cheia de unică folosință, valabilă cât rulează verificarea.
function e_proba_boti(): bool
{
    $primit = (string) ($_SERVER['HTTP_X_MINICMS_PROBA'] ?? '');
    if ($primit === '') return false;
    $f = config('date') . '/securitate/proba-boti.cheie';
    if (!is_file($f) || time() - (int) filemtime($f) > 600) return false;
    return hash_equals(trim((string) file_get_contents($f)), $primit);
}

function cerere_proba(string $url, string $ua, string $cheie): array
{
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => PROBA_BOTI_SECUNDE, 'ignore_errors' => true, 'follow_location' => 0,
        'header' => "User-Agent: $ua\r\nAccept: text/html,text/plain,*/*\r\nX-MiniCMS-Proba: $cheie\r\n"],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $corp = @file_get_contents($url, false, $ctx, 0, 300000);
    $cod = 0;
    $ant = [];
    foreach (($http_response_header ?? []) as $linie) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linie, $m)) { $cod = (int) $m[1]; $ant = []; continue; }
        $p = strpos($linie, ':');
        if ($p) $ant[strtolower(trim(substr($linie, 0, $p)))] = trim(substr($linie, $p + 1));
    }
    return ['cod' => $cod, 'antete' => $ant, 'corp' => $corp === false ? '' : $corp];
}

function titlu_din(string $html): string
{
    return preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
}

// Ce a primit botul, față de ce a primit browserul.
function clasifica_proba(array $r, string $titlu_asteptat): array
{
    // Printr-un site cu proxy Cloudflare, orice răspuns poartă „Server: cloudflare”, și cele venite de la găzduire.
    // Blocajul e al Cloudflare doar când pagina e a lui (error code 1xxx, Ray ID); altfel doar a trecut prin el.
    $prin_cf = stripos((string) ($r['antete']['server'] ?? ''), 'cloudflare') !== false || isset($r['antete']['cf-ray']);
    $cf = $prin_cf && preg_match('/error code: 1\d{3}|Cloudflare Ray ID|cf-error-details|cdn-cgi\/styles\/cf/i', substr($r['corp'], 0, 20000)) === 1;
    if ($r['cod'] === 0) return ['rezultat' => 'fara_raspuns', 'explicatie' => 'nu a venit niciun răspuns în ' . PROBA_BOTI_SECUNDE . ' secunde'];
    if (($r['antete']['cf-mitigated'] ?? '') !== '' || preg_match('/Just a moment|challenge-platform|cf-chl-|Attention Required/i', substr($r['corp'], 0, 20000)))
        return ['rezultat' => 'provocare_cloudflare', 'explicatie' => 'Cloudflare i-a cerut o verificare de om, pe care un bot n-o poate trece: pagina nu ajunge la el'];
    if (in_array($r['cod'], [401, 403, 406, 429, 503], true))
        return ['rezultat' => $cf ? 'blocat_cloudflare' : 'blocat_gazduire',
                'explicatie' => $cf ? "Cloudflare a răspuns {$r['cod']}: o regulă de acolo oprește botul"
                                    : "serverul găzduirii a răspuns {$r['cod']}" . ($prin_cf ? ' (trecut prin Cloudflare, dar nu de la el)' : '')
                                      . ': firewallul găzduirii (LiteSpeed, ModSecurity, Imunify360…) oprește botul'];
    if ($r['cod'] >= 300 && $r['cod'] < 400) return ['rezultat' => 'redirectionat', 'explicatie' => "{$r['cod']} spre " . ($r['antete']['location'] ?? '?')];
    if ($r['cod'] !== 200) return ['rezultat' => 'eroare', 'explicatie' => "răspuns {$r['cod']}"];
    $titlu = titlu_din($r['corp']);
    if ($titlu_asteptat !== '' && $titlu !== $titlu_asteptat)
        return ['rezultat' => 'alta_pagina', 'explicatie' => 'a primit 200, dar altă pagină decât browserul („' . substr($titlu, 0, 80) . '”)'];
    return ['rezultat' => 'ok', 'explicatie' => 'primește aceeași pagină ca un browser'];
}

// Liniile „Disallow: /” care se aplică unui bot în robots.txt servit (grupul lui și grupul *).
function robots_opriri(string $robots, string $bot): array
{
    $opriri = [];
    $grup = [];
    $in_reguli = false;
    foreach (preg_split('/\r?\n/', $robots) ?: [] as $linie) {
        $linie = trim((string) preg_replace('/#.*$/', '', $linie));
        if ($linie === '' || !preg_match('/^([A-Za-z-]+)\s*:\s*(.*)$/', $linie, $m)) continue;
        $camp = strtolower($m[1]);
        if ($camp === 'user-agent') {
            if ($in_reguli) { $grup = []; $in_reguli = false; }
            $grup[] = strtolower(trim($m[2]));
            continue;
        }
        $in_reguli = true;
        if ($camp === 'disallow' && trim($m[2]) === '/' && (in_array(strtolower($bot), $grup, true) || in_array('*', $grup, true)))
            $opriri[] = 'User-agent: ' . implode(', ', $grup) . ' → Disallow: /';
    }
    return array_values(array_unique($opriri));
}

function verifica_boti(): array
{
    $f = dir_date('securitate') . '/proba-boti.cheie';
    $ultima = $f . '.ultima';
    if (is_file($ultima) && time() - (int) filemtime($ultima) < PROBA_BOTI_PAUZA)
        throw new EroareCms('o verificare a rulat acum mai puțin de un minut; încearcă din nou puțin mai târziu');
    @touch($ultima);
    $cheie = bin2hex(random_bytes(16));
    scrie_atomic($f, $cheie);

    $baza = rtrim((string) config('verifica_boti_url'), '/') ?: url_site();
    $adrese = ['/'];
    $articole = listeaza_elemente('articol', 'vizibil');
    if ($articole) $adrese[] = url_element($articole[0]);

    try {
        $asteptat = [];
        foreach ($adrese as $a) {
            $r = cerere_proba($baza . $a, UA_BROWSER, $cheie);
            $asteptat[$a] = ['cod' => $r['cod'], 'titlu' => $r['cod'] === 200 ? titlu_din($r['corp']) : ''];
        }
        if (!array_filter($asteptat, fn($x) => $x['cod'] === 200)) {
            return ['stare' => 'necunoscut', 'adresa' => $baza,
                'rezumat' => 'Site-ul nu se poate cere singur: nici ca browser obișnuit nu primește 200 ('
                    . implode(', ', array_map(fn($a, $x) => "$a → {$x['cod']}", array_keys($asteptat), $asteptat))
                    . '). Unele găzduiri nu permit asta; verificarea trebuie făcută din afară.'];
        }

        $robots = cerere_proba($baza . '/robots.txt', UA_BROWSER, $cheie);
        require_once __DIR__ . '/site.php';   // robots.txt așa cum îl scrie site-ul (partea publică nu e încărcată în MCP)
        $robots_propriu = text_robots();
        $robots_modificat = $robots['cod'] === 200 && trim(str_replace("\r", '', $robots['corp'])) !== trim($robots_propriu);

        $boti = [];
        $probleme = [];
        foreach (BOTI_DE_VERIFICAT as $bot => $ua) {
            $pagini = [];
            foreach ($adrese as $a) {
                if ($asteptat[$a]['cod'] !== 200) continue;
                $pagini[] = ['adresa' => $a] + clasifica_proba(cerere_proba($baza . $a, $ua, $cheie), $asteptat[$a]['titlu']);
            }
            $rele = array_filter($pagini, fn($p) => $p['rezultat'] !== 'ok');
            $opriri = $robots['cod'] === 200 ? robots_opriri($robots['corp'], $bot) : [];
            $boti[$bot] = ['fel' => BOTI_AI[$bot] ?? 'cautare', 'poate_citi' => !$rele && !$opriri, 'pagini' => $pagini]
                + ($opriri ? ['robots_txt_il_opreste' => $opriri] : []);
            if ($rele) $probleme[] = "$bot: " . implode('; ', array_unique(array_column($rele, 'explicatie')));
            if ($opriri) $probleme[] = "$bot: robots.txt servit îl oprește (" . implode(' | ', $opriri) . ')';
        }
        if ($robots_modificat) $probleme[] = 'robots.txt ajunge la boți altfel decât îl scrie site-ul: pe drum îl schimbă ceva (de ex. „Managed robots.txt” din Cloudflare)';
    } finally {
        @unlink($f);
    }

    return [
        'stare' => $probleme ? 'probleme' : 'ok',
        'rezumat' => $probleme ? 'Unii boți nu pot citi site-ul: ' . implode(' · ', $probleme)
                               : 'Toți boții verificați primesc aceleași pagini ca un browser, iar robots.txt nu-i oprește.',
        'adresa' => $baza, 'pagini_verificate' => $adrese,
        'boti' => $boti,
        'robots_txt' => ['cod' => $robots['cod'], 'identic_cu_cel_scris_de_site' => !$robots_modificat],
        'limite' => 'Cererile pleacă de pe serverul site-ului, cu numele boților, dar nu de la adresele lor IP. Cloudflare poate trata '
            . 'altfel un bot adevărat (îl recunoaște după IP) decât proba: un „ok” aici e un semn bun, nu o garanție, iar un blocaj '
            . 'poate veni și din regula care oprește boții falși. Dovada din partea boților reali: vizite_ai, în zilele următoare.',
    ];
}
