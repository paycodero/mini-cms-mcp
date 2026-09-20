<?php
// Testele: pornesc o copie a site-ului într-un dosar temporar, cu chei de unică folosință, pe serverul
// PHP încorporat, și verific pe rând funcțiile și atacurile găsite la mcp-cms. Repo-ul nu e atins.
//
//   php teste/ruleaza.php                    rezumat pe ecran; cod de ieșire 0 = tot a trecut
//   php teste/ruleaza.php --json rez.json    scrie și rezultatele, pentru pagina de raport
//   php teste/ruleaza.php --pastreaza        nu șterge dosarul temporar (pentru investigații)
declare(strict_types=1);

$radacina = dirname(__DIR__);
$optiuni = getopt('', ['json:', 'pastreaza']);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'minicms-test-' . bin2hex(random_bytes(4));
$rezultate = [];

function copiaza_dosar(string $sursa, string $dest, array $exclus, string $rel = ''): void
{
    @mkdir($dest, 0755, true);
    foreach (scandir($sursa) as $f) {
        if ($f === '.' || $f === '..' || in_array(ltrim("$rel/$f", '/'), $exclus, true)) continue;
        if (is_dir("$sursa/$f")) copiaza_dosar("$sursa/$f", "$dest/$f", $exclus, "$rel/$f");
        else copy("$sursa/$f", "$dest/$f");
    }
}

function sterge_dosar(string $d): void
{
    if (!is_dir($d)) return;
    foreach (scandir($d) as $f) {
        if ($f === '.' || $f === '..') continue;
        is_dir("$d/$f") ? sterge_dosar("$d/$f") : @unlink("$d/$f");
    }
    @rmdir($d);
}

function fisiere(string $d, string $model): array
{
    $rez = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if (preg_match($model, $f->getFilename())) $rez[] = $f->getPathname();
    sort($rez);
    return $rez;
}

function verifica(string $grup, string $nume, bool $ok, string $detaliu = ''): void
{
    global $rezultate;
    $rezultate[] = ['grup' => $grup, 'nume' => $nume, 'ok' => $ok, 'detaliu' => $ok ? '' : $detaliu];
    echo ($ok ? '  OK    ' : '  ESEC  ') . "[$grup] $nume" . (!$ok && $detaliu !== '' ? "  →  $detaliu" : '') . "\n";
}

function cerere(string $metoda, string $cale, ?string $corp = null, array $antete = []): array
{
    global $url;
    $h = '';
    if ($corp !== null && !isset($antete['Content-Type'])) $antete['Content-Type'] = 'application/json';
    foreach ($antete as $k => $v) $h .= "$k: $v\r\n";
    $ctx = stream_context_create(['http' => ['method' => $metoda, 'header' => $h, 'content' => $corp ?? '',
        'ignore_errors' => true, 'timeout' => 30, 'follow_location' => 0]]);
    $raspuns = @file_get_contents($url . $cale, false, $ctx);
    $brute = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    $cod = 0;
    $ant = [];
    foreach ($brute as $linie) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linie, $m)) { $cod = (int) $m[1]; continue; }
        $p = strpos($linie, ':');
        if ($p) $ant[strtolower(substr($linie, 0, $p))] = trim(substr($linie, $p + 1));
    }
    return ['cod' => $cod, 'antete' => $ant, 'corp' => $raspuns === false ? '' : $raspuns];
}

function mcp(?string $cheie, string $metoda, array $params = [], $id = 1, array $antete = []): array
{
    if ($cheie !== null) $antete['Authorization'] = 'Bearer ' . $cheie;
    $antete['Accept'] = 'application/json, text/event-stream';
    $corp = ['jsonrpc' => '2.0', 'method' => $metoda];
    if ($id !== null) $corp['id'] = $id;
    if ($params) $corp['params'] = $params;
    $r = cerere('POST', '/mcp', json_encode($corp), $antete);
    $r['json'] = json_decode($r['corp'], true);
    return $r;
}

function unealta(string $cheie, string $nume, array $args = []): array
{
    $r = mcp($cheie, 'tools/call', ['name' => $nume, 'arguments' => (object) $args]);
    $rez = $r['json']['result'] ?? null;
    return ['eroare' => (bool) ($rez['isError'] ?? true), 'text' => (string) ($rez['content'][0]['text'] ?? ($r['json']['error']['message'] ?? $r['corp'])),
            'date' => $rez['structuredContent'] ?? null, 'http' => $r['cod']];
}

// --- pregătire ---------------------------------------------------------------------------------

copiaza_dosar("$radacina/site", "$tmp/site", ['app/config.php', 'date', 'media']);
$kc = 'mcms_c_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');   // cheile există doar cât rulează testele
$ks = 'mcms_s_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$port = 0;
for ($i = 0; $i < 20 && !$port; $i++) {
    $p = random_int(18100, 18999);
    $s = @fsockopen('127.0.0.1', $p, $e1, $e2, 0.2);
    if ($s) fclose($s); else $port = $p;
}
$url = "http://127.0.0.1:$port";
$config = ['site' => ['nume' => 'Site de test', 'descriere' => 'Site pentru teste automate.', 'url' => $url, 'limba' => 'ro',
                      'autor' => 'Autor Test', 'culoare' => '#6d2be8'],
           'chei' => ['citire' => hash('sha256', $kc), 'scriere' => hash('sha256', $ks)]];
file_put_contents("$tmp/site/app/config.php", "<?php\nif (!defined('MINICMS')) { http_response_code(403); exit; }\nreturn "
    . var_export($config, true) . ";\n");

$server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'post_max_size=20M', '-S', "127.0.0.1:$port", '-t', "$tmp/site",
    "$radacina/unelte/router-local.php"], [0 => ['pipe', 'r'], 1 => ['file', "$tmp/server.log", 'a'], 2 => ['file', "$tmp/server.log", 'a']],
    $pipes, "$tmp/site");
register_shutdown_function(function () use ($server) {   // serverul se oprește și dacă testele se opresc brusc
    $stare = @proc_get_status($server);
    if ($stare && $stare['running']) { proc_terminate($server); }
});
for ($i = 0; $i < 60; $i++) {
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
    if ($s) { fclose($s); break; }
    usleep(100000);
}
$php_la_inceput = fisiere($tmp, '/\.(php[0-9]?|phtml|phar)$/i');
echo "mini-cms-mcp — teste pe $url (PHP " . PHP_VERSION . ")\n\n";

// --- protocol și chei --------------------------------------------------------------------------

$r = cerere('GET', '/mcp');
verifica('Protocol', 'GET pe /mcp e refuzat (405)', $r['cod'] === 405, "cod {$r['cod']}");
$init = ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'teste', 'version' => '1']];
$r = mcp(null, 'initialize', $init);
verifica('Chei', 'fără cheie → 401, cu adresa de unde se face conectarea OAuth', $r['cod'] === 401
    && strpos($r['antete']['www-authenticate'] ?? '', 'resource_metadata="' . $url . '/.well-known/oauth-protected-resource"') !== false, "cod {$r['cod']}");
$r = mcp('cheie-gresita-dar-destul-de-lunga-0000', 'initialize', $init);
verifica('Chei', 'cheie greșită → 401', $r['cod'] === 401, "cod {$r['cod']}");
$r = mcp($kc, 'initialize', $init);
verifica('Protocol', 'initialize: versiunea cerută (2025-06-18) și numele serverului',
    $r['cod'] === 200 && ($r['json']['result']['protocolVersion'] ?? '') === '2025-06-18' && ($r['json']['result']['serverInfo']['name'] ?? '') === 'mini-cms-mcp', $r['corp']);
$r = mcp($kc, 'initialize', ['protocolVersion' => '1999-01-01'] + $init);
verifica('Protocol', 'initialize cu o versiune necunoscută primește cea mai nouă versiune suportată', ($r['json']['result']['protocolVersion'] ?? '') === '2025-11-25');
$r = mcp($kc, 'notifications/initialized', [], null);
verifica('Protocol', 'o notificare primește 202, fără corp', $r['cod'] === 202 && $r['corp'] === '', "cod {$r['cod']}");
$r = mcp($kc, 'tools/list');
$unelte_c = array_column($r['json']['result']['tools'] ?? [], 'name');
verifica('Chei', 'cheia de citire vede doar cele 11 comenzi de citire', count($unelte_c) === 11 && !in_array('salveaza', $unelte_c, true), implode(', ', $unelte_c));
$r = mcp($ks, 'tools/list');
$lista_s = $r['json']['result']['tools'] ?? [];
verifica('Protocol', 'cheia de scriere vede toate cele 21 de comenzi', count($lista_s) === 21, (string) count($lista_s));
$bune = array_filter($lista_s, fn($t) => ($t['inputSchema']['type'] ?? '') === 'object' && isset($t['annotations']['readOnlyHint']));
verifica('Protocol', 'fiecare comandă are schemă de tip obiect și adnotări', count($bune) === count($lista_s) && $lista_s);
$r = mcp($kc, 'ping');
verifica('Protocol', 'ping → {}', $r['cod'] === 200 && strpos($r['corp'], '"result":{}') !== false, $r['corp']);
$r = cerere('POST', '/mcp', '{nu e json', ['Authorization' => "Bearer $kc"]);
verifica('Protocol', 'JSON invalid → eroarea -32700', (json_decode($r['corp'], true)['error']['code'] ?? 0) === -32700, $r['corp']);
$r = cerere('POST', '/mcp', json_encode([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']]), ['Authorization' => "Bearer $kc"]);
verifica('Protocol', 'lot de cereri → eroarea -32600', (json_decode($r['corp'], true)['error']['code'] ?? 0) === -32600, $r['corp']);
$r = mcp($kc, 'metoda/inexistenta');
verifica('Protocol', 'metodă necunoscută → eroarea -32601', ($r['json']['error']['code'] ?? 0) === -32601, $r['corp']);
$r = mcp($kc, 'tools/call', ['name' => 'executa_cod', 'arguments' => new stdClass()]);
verifica('Protocol', 'comandă inexistentă → eroarea -32602', ($r['json']['error']['code'] ?? 0) === -32602, $r['corp']);
$r = mcp($kc, 'initialize', $init, 1, ['Origin' => 'https://site-strain.example']);
verifica('Securitate', 'cerere dintr-un browser de pe alt site (Origin străin) → 403', $r['cod'] === 403, "cod {$r['cod']}");
$r = mcp($kc, 'tools/list', [], 1, ['Origin' => 'https://claude.ai']);
verifica('Protocol', 'cererile cu Origin de la claude.ai sunt primite (conectorul), tot cu cheie', $r['cod'] === 200, "cod {$r['cod']}");
$r = cerere('POST', '/mcp', '{"jsonrpc":"2.0","id":1,"method":"ping","params":{"x":"' . str_repeat('a', 9 * 1024 * 1024) . '"}}', ['Authorization' => "Bearer $kc"]);
verifica('Securitate', 'cerere de 9 MB → 413, necitită', $r['cod'] === 413, "cod {$r['cod']}");

// --- conținut ----------------------------------------------------------------------------------

$u = unealta($kc, 'salveaza', ['tip' => 'articol', 'slug' => 'test', 'titlu' => 'x', 'continut_html' => '<p>x</p>']);
verifica('Chei', 'cheia de citire nu poate scrie: refuz și nimic creat', $u['eroare'] && strpos($u['text'], 'Refuzat') !== false && !glob("$tmp/site/date/articole/*.json"), $u['text']);
$u = unealta($kc, 'despre_site');
verifica('Conținut', 'despre_site întoarce regulile și rolul cheii', !$u['eroare'] && ($u['date']['cheia_ta'] ?? '') === 'citire' && in_array('p', $u['date']['html_permis'] ?? [], true), $u['text']);

// --- identitatea site-ului: conținut, schimbat de AI prin seteaza_site ----------------------------

verifica('Site', 'numele din config.php e punctul de plecare', ($u['date']['site']['nume'] ?? '') === 'Site de test', $u['text']);
$u = unealta($kc, 'seteaza_site', ['nume' => 'Alt nume']);
verifica('Chei', 'cheia de citire nu poate schimba numele site-ului', $u['eroare'] && !is_file("$tmp/site/date/site.json"), $u['text']);
$u = unealta($ks, 'seteaza_site', ['nume' => 'Atelierul <b>Test</b>', 'descriere' => 'Descriere nouă, cu diacritice: ăîșț.', 'culoare' => '#0050E6']);
verifica('Site', 'seteaza_site schimbă numele, descrierea și culoarea; răspunsul arată valorile de dinainte',
    !$u['eroare'] && ($u['date']['site']['nume'] ?? '') === 'Atelierul Test' && ($u['date']['inainte']['nume'] ?? '') === 'Site de test', $u['text']);
$r = cerere('GET', '/');
verifica('Site', 'noul nume și noua culoare apar imediat pe site', strpos($r['corp'], '<span>Atelierul Test</span></a>') !== false && strpos($r['corp'], '--accent:#0050e6') !== false);
$r = cerere('GET', '/llms.txt');
verifica('Site', 'și în llms.txt, cu descrierea nouă', strpos($r['corp'], '# Atelierul Test') !== false && strpos($r['corp'], 'ăîșț') !== false, $r['corp']);
$u = unealta($ks, 'seteaza_site', ['culoare' => 'red;background:url(//site-rau.example/x)']);
verifica('Securitate', 'o „culoare" care nu e cod hex e refuzată (nu ajunge în CSS)', $u['eroare'], $u['text']);
$u = unealta($ks, 'seteaza_site', ['nume' => '   ']);
verifica('Site', 'numele gol e refuzat', $u['eroare'], $u['text']);
$u = unealta($ks, 'seteaza_site', ['autor' => 'Autor Nou']);
verifica('Site', 'a doua schimbare păstrează versiunea anterioară', !$u['eroare'] && !empty($u['date']['versiune_anterioara'])
    && count(glob("$tmp/site/date/versiuni/site/*.json") ?: []) === 1 && ($u['date']['site']['nume'] ?? '') === 'Atelierul Test', $u['text']);
$u = unealta($ks, 'seteaza_site', ['autor' => 'Autor Nou']);
verifica('Site', 'aceleași valori de două ori → "neschimbat"', ($u['date']['operatie'] ?? '') === 'neschimbat', $u['text']);

// --- teme: foi de stil puse de om, alese de AI --------------------------------------------------
$u = unealta($kc, 'despre_site');
verifica('Teme', 'despre_site arată temele de pe server și că niciuna nu e aleasă', in_array('simpluspv', $u['date']['teme']['disponibile'] ?? [], true)
    && ($u['date']['teme']['activa'] ?? null) === '', $u['text']);
$link_tema = '<link rel="stylesheet" href="/assets/teme/simpluspv.css?v=';
verifica('Teme', 'fără temă, pagina încarcă doar foaia implicită', strpos(cerere('GET', '/')['corp'], '/assets/teme/') === false);
$u = unealta($ks, 'seteaza_site', ['tema' => 'simpluspv']);
$r = cerere('GET', '/');
verifica('Teme', 'seteaza_site alege tema; pagina o încarcă după foaia implicită', !$u['eroare'] && ($u['date']['site']['tema'] ?? '') === 'simpluspv'
    && strpos($r['corp'], $link_tema) > strpos($r['corp'], '/assets/stil.css'), $u['text']);
$u = unealta($ks, 'seteaza_site', ['tema' => '../stil']);
$u2 = unealta($ks, 'seteaza_site', ['tema' => 'nu-exista']);
verifica('Securitate', 'tema poate fi doar una dintre foile de pe server (nici cale, nici nume inventat)', $u['eroare'] && $u2['eroare']
    && strpos($u2['text'], '"simpluspv"') !== false && strpos(cerere('GET', '/')['corp'], $link_tema) !== false, $u['text'] . ' / ' . $u2['text']);
rename("$tmp/site/assets/teme/simpluspv.css", "$tmp/site/assets/teme/simpluspv.scos");
$r = cerere('GET', '/');
rename("$tmp/site/assets/teme/simpluspv.scos", "$tmp/site/assets/teme/simpluspv.css");
verifica('Teme', 'o temă aleasă dar scoasă de pe server nu strică pagina: revine aspectul implicit', $r['cod'] === 200 && strpos($r['corp'], '/assets/teme/') === false);
$u = unealta($ks, 'seteaza_site', ['tema' => '']);
$u2 = unealta($ks, 'seteaza_site', ['tema' => 'simpluspv']);
verifica('Teme', 'tema se scoate cu "" și se pune la loc', !$u['eroare'] && ($u['date']['site']['tema'] ?? null) === '' && !$u2['eroare'], $u['text']);
$r = cerere('GET', '/assets/teme/simpluspv/hanken-grotesk-latin.woff2');
verifica('Teme', 'fonturile temei se servesc de pe site (CSP: font-src \'self\')', $r['cod'] === 200 && substr($r['corp'], 0, 4) === 'wOF2', "cod {$r['cod']}");

$u = unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'primul-articol', 'titlu' => 'Primul articol', 'descriere' => 'Descriere scurtă.',
    'continut_html' => '<h2>Început</h2><p>Text cu diacritice: ă î â ș ț Ă Î Â Ș Ț.</p>', 'etichete' => ['Decizii', 'Timp']]);
verifica('Conținut', 'articol nou → creat ca ciornă', !$u['eroare'] && ($u['date']['element']['stare'] ?? '') === 'ciorna', $u['text']);
$r = cerere('GET', '/primul-articol');
verifica('Conținut', 'ciorna nu are adresă publică (404)', $r['cod'] === 404, "cod {$r['cod']}");
unealta($ks, 'publica', ['tip' => 'articol', 'slug' => 'primul-articol']);
$r = cerere('GET', '/primul-articol');
verifica('Conținut', 'după "publica", articolul apare pe site', $r['cod'] === 200 && strpos($r['corp'], '<h1>Primul articol</h1>') !== false, "cod {$r['cod']}");
verifica('Conținut', 'diacriticele rămân intacte (UTF-8, nu entități)', strpos($r['corp'], 'ă î â ș ț Ă Î Â Ș Ț') !== false);
$r = cerere('GET', '/eticheta/decizii');
verifica('Conținut', 'pagina etichetei listează articolul', $r['cod'] === 200 && strpos($r['corp'], 'Primul articol') !== false, "cod {$r['cod']}");
$r = cerere('GET', '/articole');
verifica('Conținut', 'lista de articole', $r['cod'] === 200 && strpos($r['corp'], 'Primul articol') !== false);
foreach (['/sitemap.xml' => 'primul-articol', '/feed.xml' => 'primul-articol', '/llms.txt' => 'primul-articol', '/robots.txt' => 'Sitemap:'] as $c => $caut) {
    $r = cerere('GET', $c);
    verifica('Conținut', "$c e generat", $r['cod'] === 200 && strpos($r['corp'], $caut) !== false, "cod {$r['cod']}");
}
foreach ([['acasa', 'Bine ai venit', '<p>Prima pagină a site-ului.</p>', null], ['despre', 'Despre', '<p>Despre noi.</p>', 1]] as [$s, $t, $h, $meniu]) {
    unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => $s, 'titlu' => $t, 'continut_html' => $h, 'meniu' => $meniu]);
    unealta($ks, 'publica', ['tip' => 'pagina', 'slug' => $s]);
}
$r = cerere('GET', '/');
verifica('Conținut', 'prima pagină arată pagina "acasa" și ultimele articole', strpos($r['corp'], 'Bine ai venit') !== false && strpos($r['corp'], 'Primul articol') !== false);
verifica('Conținut', 'pagina cu poziție în meniu apare în meniu', strpos($r['corp'], 'href="/despre"') !== false);
$r = cerere('GET', '/acasa');
verifica('Conținut', '/acasa trimite spre / (301)', $r['cod'] === 301, "cod {$r['cod']}");
$r = cerere('GET', '/nu-exista');
verifica('Conținut', 'adresă inexistentă → 404', $r['cod'] === 404);
$u = unealta($ks, 'cauta', ['text' => 'diacritice']);
verifica('Conținut', 'căutarea găsește textul, cu fragment', count($u['date']['rezultate'] ?? []) === 1, $u['text']);
$u = unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => '../../app/config', 'titlu' => 'x', 'continut_html' => 'x']);
verifica('Securitate', 'slug cu ../ refuzat', $u['eroare'], $u['text']);
$u = unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => 'mcp', 'titlu' => 'x', 'continut_html' => 'x']);
verifica('Conținut', 'slug rezervat (mcp) refuzat', $u['eroare'], $u['text']);
$u = unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => 'primul-articol', 'titlu' => 'x', 'continut_html' => 'x']);
verifica('Conținut', 'același slug la pagină și la articol refuzat', $u['eroare'], $u['text']);

// --- atacul de la mcp-cms: cod trimis ca „conținut" ---------------------------------------------

$rau = '<?php file_put_contents("pwn.txt", "RCE"); echo "RCE-EXECUTAT"; ?>'
    . '<p>Text bun</p><script>alert(1)</script><img src="x.png" onerror="alert(2)">'
    . '<a href="javascript:alert(3)">link</a><a href="java&#x09;script:alert(4)">link2</a><a href="//site-rau.example/">link3</a>'
    . '<iframe src="https://site-rau.example/"></iframe><svg onload="alert(5)"><circle/></svg>'
    . '<p style="background:url(javascript:alert(6))" onclick="alert(7)">stil</p><form action="https://x.example"><input name="p"></form>'
    . '<h1>Titlu în conținut</h1><iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"></iframe>'
    . '<iframe src="https://www.youtube.com/embed/abcdefghijk" allow="camera; microphone; fullscreen"></iframe>'
    . '<img src="y.png" alt="Poză de probă" loading="lazy">'
    . '<a href="https://exemplu.ro" target="_blank">extern</a>';
$u = unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => 'atac', 'titlu' => 'Atac', 'continut_html' => $rau]);
unealta($ks, 'publica', ['tip' => 'pagina', 'slug' => 'atac']);
$salvat = (string) (json_decode((string) @file_get_contents("$tmp/site/date/pagini/atac.json"), true)['continut_html'] ?? '');
$r = cerere('GET', '/atac');
$pagina = preg_match('#<div class="continut">(.*)</div>\s*</article>#s', $r['corp'], $m) ? $m[1] : $r['corp'];   // doar ce a scris AI-ul, fără antet
verifica('Securitate', 'codul PHP trimis ca conținut nu e nici salvat, nici rulat',
    $salvat !== '' && stripos($salvat, '<?php') === false && strpos($pagina, 'RCE-EXECUTAT') === false && !fisiere($tmp, '/^pwn\.txt$/'));
$interzise = ['<script', 'onerror', 'onclick', 'onload', 'javascript:', '<svg', 'style=', '<form', '<input', 'site-rau.example'];
$gasite = array_values(array_filter($interzise, fn($x) => stripos($salvat, $x) !== false || stripos($pagina, $x) !== false));
verifica('Securitate', 'scoase: <script>, on*, javascript:, //alt-site, SVG, style, formulare, iframe străin', !$gasite, implode(', ', $gasite));
verifica('Securitate', 'păstrate: textul, imaginea, videoclipul YouTube', strpos($salvat, 'Text bun') !== false && strpos($salvat, '<img src="x.png">') !== false
    && strpos($salvat, 'youtube-nocookie.com/embed/dQw4w9WgXcQ') !== false, $salvat);
verifica('Securitate', 'link cu target=_blank primește rel="noopener noreferrer"', strpos($salvat, 'rel="noopener noreferrer"') !== false);
verifica('Securitate', 'iframe-ul nu poate cere camera sau microfonul (atributul allow e curățat)',
    stripos($salvat, 'camera') === false && stripos($salvat, 'microphone') === false && strpos($salvat, 'youtube.com/embed/abcdefghijk') !== false, $salvat);
verifica('Conținut', 'textul alternativ al imaginii (alt) se păstrează', strpos($salvat, 'alt="Poză de probă"') !== false, $salvat);
$u_cop = unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'primul-articol', 'imagine' => 'https://alt-domeniu.example/urmarire.png']);
verifica('Securitate', 'coperta de pe alt domeniu e refuzată (ar trimite IP-ul vizitatorilor acolo)', $u_cop['eroare'], $u_cop['text']);
verifica('Conținut', '<h1> din conținut devine <h2> (h1 e titlul)', strpos($salvat, '<h2>Titlu în conținut</h2>') !== false);
verifica('Securitate', 'răspunsul spune AI-ului ce s-a scos ("curatari")', count($u['date']['curatari'] ?? []) >= 8, $u['text']);

// --- versiuni ----------------------------------------------------------------------------------

$u = unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'primul-articol', 'titlu' => 'Primul articol, revizuit']);
verifica('Versiuni', 'modificarea salvează automat versiunea anterioară', !$u['eroare'] && !empty($u['date']['versiune_anterioara']), $u['text']);
verifica('Versiuni', 'modificarea unui element publicat e semnalată AI-ului', isset($u['date']['atentie']));
$u = unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'primul-articol', 'titlu' => 'Primul articol, revizuit']);
verifica('Versiuni', 'aceeași modificare de două ori → "neschimbat", fără versiune nouă', ($u['date']['operatie'] ?? '') === 'neschimbat', $u['text']);
$v = unealta($kc, 'listeaza_versiuni', ['tip' => 'articol', 'slug' => 'primul-articol']);
$versiuni = $v['date']['versiuni'] ?? [];
verifica('Versiuni', 'versiunile se văd și cu cheia de citire', count($versiuni) >= 2, $v['text']);
$cea_mai_veche = $versiuni ? $versiuni[count($versiuni) - 1]['versiune'] : '';
$u = unealta($ks, 'restaureaza', ['tip' => 'articol', 'slug' => 'primul-articol', 'versiune' => $cea_mai_veche]);
verifica('Versiuni', 'restaurarea aduce titlul vechi, ca ciornă', ($u['date']['element']['titlu'] ?? '') === 'Primul articol' && ($u['date']['element']['stare'] ?? '') === 'ciorna', $u['text']);
$u = unealta($ks, 'restaureaza', ['tip' => 'articol', 'slug' => 'primul-articol', 'versiune' => '../../jurnal/2026-09']);
verifica('Securitate', 'identificator de versiune cu ../ refuzat', $u['eroare'], $u['text']);
unealta($ks, 'publica', ['tip' => 'articol', 'slug' => 'primul-articol']);
$u = unealta($ks, 'sterge', ['tip' => 'articol', 'slug' => 'primul-articol']);
$r = cerere('GET', '/primul-articol');
verifica('Versiuni', '"sterge" scoate articolul de pe site', !$u['eroare'] && $r['cod'] === 404, $u['text']);
$u2 = unealta($ks, 'restaureaza', ['tip' => 'articol', 'slug' => 'primul-articol', 'versiune' => (string) ($u['date']['versiune'] ?? '')]);
verifica('Versiuni', 'articolul șters se poate restaura', !$u2['eroare'] && ($u2['date']['element']['stare'] ?? '') === 'ciorna', $u2['text']);

// --- imagini -----------------------------------------------------------------------------------

$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
$u = unealta($ks, 'urca_imagine', ['nume' => 'Copertă test.png', 'continut_base64' => $png]);
$url_img = (string) ($u['date']['url'] ?? '');
verifica('Imagini', 'PNG urcat, cu nume curat și amprentă', !$u['eroare'] && preg_match('#^/media/coperta-test-[a-f0-9]{8}\.png$#', $url_img) === 1, $u['text']);
$r = cerere('GET', $url_img);
verifica('Imagini', 'imaginea se servește identic', $r['cod'] === 200 && $r['corp'] === base64_decode($png), "cod {$r['cod']}");
$u = unealta($ks, 'urca_imagine', ['nume' => 'shell.php', 'continut_base64' => $png]);
verifica('Securitate', 'o imagine numită „shell.php" se salvează .png (extensia vine din conținut)', !$u['eroare'] && substr((string) ($u['date']['url'] ?? ''), -4) === '.png', $u['text']);
$u = unealta($ks, 'urca_imagine', ['nume' => 'poliglot.png', 'continut_base64' => base64_encode(base64_decode($png) . '<?php system($_GET["c"]); ?>')]);
verifica('Securitate', 'imagine cu cod PHP ascuns înăuntru refuzată', $u['eroare'], $u['text']);
$u = unealta($ks, 'urca_imagine', ['nume' => 'desen.svg', 'continut_base64' => base64_encode('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>')]);
verifica('Securitate', 'SVG refuzat', $u['eroare'], $u['text']);
$u = unealta($ks, 'urca_imagine', ['nume' => 'x.png', 'continut_base64' => base64_encode('<?php echo 1; ?>')]);
verifica('Securitate', 'fișier PHP deghizat în imagine refuzat', $u['eroare'], $u['text']);
unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'cu-coperta', 'titlu' => 'Cu copertă', 'continut_html' => '<p>x</p>', 'imagine' => $url_img]);
$u = unealta($ks, 'sterge_imagine', ['nume' => basename($url_img)]);
verifica('Imagini', 'o imagine folosită nu se șterge fără forteaza=true', $u['eroare'] && strpos($u['text'], 'articol/cu-coperta') !== false, $u['text']);

// --- SEO și citit de agenți (0.7) ----------------------------------------------------------------

unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'cu-coperta', 'imagine_alt' => 'O copertă de probă']);
unealta($ks, 'publica', ['tip' => 'articol', 'slug' => 'cu-coperta']);
$faq = '<p>Răspunsul scurt stă în prima frază.</p><h2>Întrebări frecvente</h2>'
    . '<h3>Cât durează instalarea?</h3><p>Vreo zece minute, dacă ai deja PHP pe calculator.</p>'
    . '<h3>Merge pe orice găzduire?</h3><p>Pe orice găzduire cu PHP 8 și Apache.</p>'
    . '<h3>Ce fac dacă pierd cheia?</h3><p>Faci altele, cu --chei-noi.</p>';
unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'cu-intrebari', 'titlu' => 'Ghid cu întrebări',
    'descriere' => 'Un ghid scurt, cu întrebări frecvente la final.', 'continut_html' => $faq, 'etichete' => ['Ghid', 'Instalare']]);
unealta($ks, 'publica', ['tip' => 'articol', 'slug' => 'cu-intrebari']);

function jsonld_din(string $html): array
{
    preg_match_all('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $m);
    return array_map(fn($t) => json_decode($t, true) ?? [], $m[1]);
}
function ld_de_tip(array $blocuri, string $tip): array
{
    foreach ($blocuri as $b) if (($b['@type'] ?? '') === $tip) return $b;
    return [];
}

$r = cerere('GET', '/cu-intrebari');
$ld = jsonld_din($r['corp']);
$art = ld_de_tip($ld, 'Article');
verifica('SEO', 'Article cu editor, limbă, etichete și pagina-părinte',
    ($art['publisher']['@type'] ?? '') === 'Organization' && ($art['inLanguage'] ?? '') === 'ro'
    && strpos((string) ($art['keywords'] ?? ''), 'Ghid') !== false && isset($art['mainEntityOfPage']['@id']), json_encode($art));
verifica('SEO', 'firimituri (BreadcrumbList): Acasă → Articole → articolul',
    count(ld_de_tip($ld, 'BreadcrumbList')['itemListElement'] ?? []) === 3, json_encode(ld_de_tip($ld, 'BreadcrumbList')));
$faq_ld = ld_de_tip($ld, 'FAQPage');
verifica('SEO', 'secțiunea „Întrebări frecvente" devine FAQPage, cu întrebările și răspunsurile din text',
    count($faq_ld['mainEntity'] ?? []) === 3
    && ($faq_ld['mainEntity'][0]['name'] ?? '') === 'Cât durează instalarea?'
    && strpos((string) ($faq_ld['mainEntity'][0]['acceptedAnswer']['text'] ?? ''), 'zece minute') !== false, json_encode($faq_ld));
verifica('SEO', 'articolul are og:locale, datele de publicare și etichetele ca meta',
    strpos($r['corp'], 'property="og:locale"') !== false && strpos($r['corp'], 'article:published_time') !== false
    && strpos($r['corp'], 'article:modified_time') !== false && strpos($r['corp'], 'article:tag') !== false);

$r = cerere('GET', '/cu-coperta');
verifica('SEO', 'coperta are măsurile ei, în pagină și în cardul social (fără sărituri la încărcare)',
    strpos($r['corp'], 'property="og:image:width"') !== false && strpos($r['corp'], 'property="og:image:alt"') !== false
    && preg_match('#<figure class="coperta"><img[^>]*width="1" height="1"#', $r['corp']) === 1);

unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'chiar-atelierul-test', 'titlu' => 'Cum lucrează Atelierul Test',
    'descriere' => 'Titlu care conține deja numele site-ului.', 'continut_html' => '<p>Text.</p>']);
unealta($ks, 'publica', ['tip' => 'articol', 'slug' => 'chiar-atelierul-test']);
$r = cerere('GET', '/chiar-atelierul-test');
preg_match('#<title>(.*?)</title>#s', $r['corp'], $m_titlu);
verifica('SEO', 'numele site-ului nu se repetă în titlu când e deja în el',
    substr_count((string) ($m_titlu[1] ?? ''), 'Atelierul Test') === 1, (string) ($m_titlu[1] ?? ''));

$roboti = cerere('GET', '/robots.txt')['corp'];
verifica('SEO', 'robots.txt numește boții de căutare și pe cei AI, și arată llms.txt',
    strpos($roboti, "User-agent: Googlebot\n") !== false && strpos($roboti, "User-agent: Bingbot\n") !== false
    && strpos($roboti, "User-agent: GPTBot\n") !== false && strpos($roboti, "User-agent: ClaudeBot\n") !== false
    && strpos($roboti, '/llms.txt') !== false && substr_count($roboti, 'Disallow: /jurnal.php') > 1, substr($roboti, 0, 120));
$harta = cerere('GET', '/sitemap.xml')['corp'];
verifica('SEO', 'sitemap: lastmod pe prima pagină, coperta ca imagine și paginile de etichetă',
    strpos($harta, '<lastmod>') !== false && strpos($harta, '<image:loc>') !== false
    && strpos($harta, '/eticheta/') !== false, substr($harta, 0, 200));
$feed_xml = cerere('GET', '/feed.xml')['corp'];
verifica('SEO', 'feed: legătură spre el însuși, lastBuildDate, autor și etichete',
    strpos($feed_xml, 'rel="self"') !== false && strpos($feed_xml, '<lastBuildDate>') !== false
    && strpos($feed_xml, '<dc:creator>') !== false && strpos($feed_xml, '<category>') !== false, substr($feed_xml, 0, 200));
$llms = cerere('GET', '/llms.txt')['corp'];
verifica('SEO', 'llms.txt spune adresa, limba, autorul și unde sunt feed-ul și harta site-ului',
    strpos($llms, 'Adresa site-ului:') !== false && strpos($llms, '/feed.xml') !== false
    && strpos($llms, '/sitemap.xml') !== false && strpos($llms, '[Ghid, Instalare]') !== false, substr($llms, 0, 200));

@mkdir("$tmp/site/date/securitate", 0755, true);
$cheie_in = bin2hex(random_bytes(16));
file_put_contents("$tmp/site/date/securitate/indexnow.cheie", $cheie_in);
$r = cerere('GET', "/$cheie_in.txt");
verifica('SEO', 'cheia IndexNow se servește la adresa ei (fără fișier pus în rădăcină)',
    $r['cod'] === 200 && trim($r['corp']) === $cheie_in, "cod {$r['cod']}");
$r = cerere('GET', '/' . bin2hex(random_bytes(16)) . '.txt');
verifica('SEO', 'o cheie IndexNow greșită dă 404', $r['cod'] === 404, "cod {$r['cod']}");
$j_seo = unealta($kc, 'citeste_jurnal', ['ultimele' => 300]);
verifica('SEO', 'pe un site local nu se anunță nimic în afară (IndexNow tace)',
    !array_filter($j_seo['date']['intrari'] ?? [], fn($i) => ($i['punct'] ?? '') === 'seo'));

// --- 0.3: previzualizare, publicare programată, căutare, redirecționări, logo, export -------------

function cale_din(string $url_absolut): string { return (string) preg_replace('#^https?://[^/]+#', '', $url_absolut); }

unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => 'ciorna-noua', 'titlu' => 'Ciornă secretă zmeurie', 'continut_html' => '<p>Text zmeuriu.</p>']);
$u = unealta($kc, 'previzualizeaza', ['tip' => 'pagina', 'slug' => 'ciorna-noua']);
$prev = cale_din((string) ($u['date']['url'] ?? ''));
$r = cerere('GET', $prev);
verifica('Previzualizare', 'linkul (cerut și cu cheia de citire) arată ciorna așezată în șablon, cu banda de previzualizare',
    !$u['eroare'] && $r['cod'] === 200 && strpos($r['corp'], 'Ciornă secretă zmeurie') !== false && strpos($r['corp'], 'bara-previzualizare') !== false, $u['text']);
verifica('Previzualizare', 'pagina de previzualizare nu se indexează și nu se păstrează în cache',
    strpos($r['antete']['x-robots-tag'] ?? '', 'noindex') !== false && ($r['antete']['cache-control'] ?? '') === 'no-store' && strpos($r['corp'], 'name="robots" content="noindex') !== false);
$r = cerere('GET', (string) preg_replace('/s=[a-f0-9]{8}/', 's=00000000', $prev));
$r2 = cerere('GET', '/previzualizare/ciorna-noua');
verifica('Previzualizare', 'semnătură schimbată → 403; fără semnătură → 404', $r['cod'] === 403 && $r2['cod'] === 404, "cod {$r['cod']} / {$r2['cod']}");
$cheie_prev = trim((string) @file_get_contents("$tmp/site/date/securitate/previzualizare.cheie"));
$expirat = time() - 60;
$r = cerere('GET', "/previzualizare/ciorna-noua?e=$expirat&s=" . hash_hmac('sha256', "ciorna-noua|$expirat", $cheie_prev));
verifica('Previzualizare', 'un link expirat, chiar cu semnătură bună, → 403', $cheie_prev !== '' && $r['cod'] === 403, "cod {$r['cod']}");
verifica('Previzualizare', 'ciorna rămâne fără adresă publică', cerere('GET', '/ciorna-noua')['cod'] === 404);

unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'programat', 'titlu' => 'Articol programat', 'continut_html' => '<p>Mai târziu.</p>']);
$u = unealta($ks, 'publica', ['tip' => 'articol', 'slug' => 'programat', 'la' => date('Y-m-d H:i', time() + 2 * 86400)]);
$r = cerere('GET', '/programat');
$feed = cerere('GET', '/feed.xml')['corp'] . cerere('GET', '/sitemap.xml')['corp'] . cerere('GET', '/llms.txt')['corp'] . cerere('GET', '/articole')['corp'];
verifica('Programare', 'publicat cu "la" în viitor: programat, invizibil pe site, în feed, sitemap, llms.txt și listă',
    ($u['date']['operatie'] ?? '') === 'programat' && $r['cod'] === 404 && strpos($feed, '/programat') === false, $u['text']);
$l = unealta($kc, 'listeaza', ['tip' => 'articol']);
$programat = array_values(array_filter($l['date']['articole'] ?? [], fn($a) => $a['slug'] === 'programat'))[0] ?? [];
verifica('Programare', 'listeaza arată data la care apare', !empty($programat['programat_pentru']), $l['text']);
$r = cerere('GET', cale_din((string) (unealta($kc, 'previzualizeaza', ['tip' => 'articol', 'slug' => 'programat'])['date']['url'] ?? '')));
verifica('Programare', 'previzualizarea unui articol programat spune când apare', $r['cod'] === 200 && strpos($r['corp'], 'programat pentru') !== false);
unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'articol-vechi', 'titlu' => 'Articol mutat de pe site-ul vechi', 'continut_html' => '<p>Vechi.</p>', 'autor' => 'Ion Popescu']);
$u = unealta($ks, 'publica', ['tip' => 'articol', 'slug' => 'articol-vechi', 'la' => '2020-05-01 10:00']);
$r = cerere('GET', '/articol-vechi');
verifica('Programare', 'publicat cu "la" în trecut: apare imediat și își păstrează data și autorul',
    $r['cod'] === 200 && strpos((string) ($u['date']['element']['publicat_la'] ?? ''), '2020-05-01') === 0 && strpos($r['corp'], 'Ion Popescu') !== false, $u['text']);
$u = unealta($ks, 'publica', ['tip' => 'articol', 'slug' => 'articol-vechi', 'la' => 'mâine dimineață']);
verifica('Programare', 'o dată care nu se înțelege e refuzată', $u['eroare'], $u['text']);

unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'sedinta-de-luni', 'titlu' => 'Ședința de luni', 'continut_html' => '<p>La ședința de luni discutăm bugetul pe trimestrul patru.</p>']);
unealta($ks, 'publica', ['tip' => 'articol', 'slug' => 'sedinta-de-luni']);
$r = cerere('GET', '/cauta?q=' . rawurlencode('sedinta bugetul'));
verifica('Căutare', 'fără diacritice găsește și textul cu diacritice, cu potrivirile marcate',
    $r['cod'] === 200 && strpos($r['corp'], 'href="/sedinta-de-luni"') !== false && strpos($r['corp'], '<mark>Ședința</mark>') !== false, "cod {$r['cod']}");
verifica('Căutare', 'pagina de rezultate nu se indexează', strpos($r['corp'], 'name="robots" content="noindex') !== false);
$r = cerere('GET', '/cauta?q=zmeurie');
$r2 = cerere('GET', '/cauta?q=' . rawurlencode('Mai târziu'));
verifica('Căutare', 'nu găsește ciorne și nici articole programate', strpos($r['corp'], 'ciorna-noua') === false && strpos($r2['corp'], '/programat"') === false
    && strpos($r['corp'], '0 de rezultate') !== false);
$r = cerere('GET', '/cauta?q=' . rawurlencode('<script>alert(1)</script>'));
verifica('Securitate', 'textul căutat nu ajunge în pagină ca HTML', $r['cod'] === 200 && stripos($r['corp'], '<script>alert') === false);
verifica('Căutare', 'caseta de căutare apare în antetul site-ului', strpos(cerere('GET', '/')['corp'], 'action="/cauta"') !== false);

$u = unealta($ks, 'redirectioneaza', ['de' => '/despre-noi.html', 'la' => '/despre']);
$r = cerere('GET', '/despre-noi.html');
verifica('Redirecționări', 'adresa veche trimite cu 301 spre cea nouă', !$u['eroare'] && $r['cod'] === 301 && ($r['antete']['location'] ?? '') === '/despre', "cod {$r['cod']} " . $u['text']);
unealta($ks, 'redirectioneaza', ['de' => 'https://site-vechi.ro/pagina.php?id=5', 'la' => '/despre']);
$r = cerere('GET', '/pagina.php?id=5');
$r2 = cerere('GET', '/pagina.php?id=6');
verifica('Redirecționări', 'adresa completă a site-ului vechi, cu parametri, se potrivește exact', $r['cod'] === 301 && $r2['cod'] === 404, "cod {$r['cod']} / {$r2['cod']}");
unealta($ks, 'redirectioneaza', ['de' => '/a', 'la' => '/b']);
$u = unealta($ks, 'redirectioneaza', ['de' => '/b', 'la' => '/a']);
verifica('Redirecționări', 'o buclă e refuzată', $u['eroare'] && strpos($u['text'], 'buclă') !== false, $u['text']);
$u = unealta($ks, 'redirectioneaza', ['de' => '/mcp', 'la' => '/despre']);
$u2 = unealta($ks, 'redirectioneaza', ['de' => '/x', 'la' => 'https://site-rau.example/']);
verifica('Securitate', 'nu se pot redirecționa adresele site-ului și nici trimite vizitatorii pe alt domeniu', $u['eroare'] && $u2['eroare'], $u['text'] . ' / ' . $u2['text']);
$u = unealta($kc, 'redirectioneaza', ['de' => '/y', 'la' => '/despre']);
verifica('Chei', 'cheia de citire nu poate face redirecționări', $u['eroare'] && strpos($u['text'], 'Refuzat') !== false, $u['text']);
$u = unealta($ks, 'redirectioneaza', ['de' => '/despre', 'la' => '/']);
$r = cerere('GET', '/despre');
verifica('Redirecționări', 'o pagină existentă are întâietate; răspunsul avertizează', isset($u['date']['atentie']) && $r['cod'] === 200, $u['text']);
$u = unealta($ks, 'redirectioneaza', ['de' => '/despre', 'la' => '']);
$l = unealta($kc, 'listeaza_redirectionari');
verifica('Redirecționări', 'se scot cu "la" gol și se listează cu cheia de citire', ($u['date']['operatie'] ?? '') === 'scoasă'
    && isset($l['date']['redirectionari']['/despre-noi.html']) && !isset($l['date']['redirectionari']['/despre']), $l['text']);

$u = unealta($ks, 'seteaza_site', ['logo' => $url_img, 'favicon' => $url_img]);
$r = cerere('GET', '/');
verifica('Site', 'logo în antet, favicon în tab, logo ca imagine de distribuire pe prima pagină', !$u['eroare']
    && strpos($r['corp'], 'class="sigla" href="/"><img src="' . $url_img . '"') !== false && strpos($r['corp'], '<link rel="icon" href="' . $url_img . '"') !== false
    && strpos($r['corp'], 'og:image" content="' . $url . $url_img . '"') !== false, $u['text']);
verifica('SEO', 'iconița merge și pe telefon (apple-touch-icon) și dă culoarea barei de browser',
    strpos($r['corp'], '<link rel="apple-touch-icon" href="' . $url_img . '"') !== false
    && strpos($r['corp'], '<meta name="theme-color"') !== false);
foreach (['/favicon.ico', '/apple-touch-icon.png'] as $c_icon) {
    $r = cerere('GET', $c_icon);
    verifica('SEO', "$c_icon trimite spre iconița site-ului (boții o cer fără să citească pagina)",
        $r['cod'] === 301 && ($r['antete']['location'] ?? '') === $url_img, "cod {$r['cod']} " . ($r['antete']['location'] ?? ''));
}
$u = unealta($ks, 'seteaza_site', ['logo' => '/media/nu-exista-12345678.png']);
$u2 = unealta($ks, 'seteaza_site', ['logo' => 'https://site-rau.example/x.png']);
verifica('Site', 'logo-ul trebuie să fie o imagine urcată pe site', $u['eroare'] && $u2['eroare'], $u['text'] . ' / ' . $u2['text']);
$u = unealta($ks, 'sterge_imagine', ['nume' => basename($url_img)]);
verifica('Imagini', 'imaginea folosită ca logo nu se șterge fără forteaza=true', $u['eroare'] && strpos($u['text'], 'site/logo') !== false, $u['text']);

$u = unealta($kc, 'exporta');
$exp = $u['date'] ?? [];
$slugs = array_column(array_merge($exp['pagini'] ?? [], $exp['articole'] ?? []), 'slug');
verifica('Export', 'exportul (cu cheia de citire) are tot: ciorne, programate, identitate, redirecționări, imagini cu amprentă',
    !$u['eroare'] && in_array('ciorna-noua', $slugs, true) && in_array('programat', $slugs, true) && ($exp['site']['logo'] ?? '') === $url_img
    && isset($exp['redirectionari']['/despre-noi.html']) && preg_match('/^[a-f0-9]{64}$/', (string) ($exp['imagini'][0]['amprenta'] ?? '')), substr($u['text'], 0, 300));

$copii = "$tmp/copii";
mkdir($copii);
file_put_contents("$copii/chei-127-0-0-1.json", json_encode(['citire' => ['cheie' => $kc, 'amprenta' => hash('sha256', $kc)],
                                                             'scriere' => ['cheie' => $ks, 'amprenta' => hash('sha256', $ks)]]));
$rc = unealta_locala('copie.php', [$url, '--local', "--dosar=$copii"]);
$dosar_copie = (glob("$copii/_copii/127-0-0-1/*") ?: [''])[0];
$exp_copie = json_decode((string) @file_get_contents("$dosar_copie/export.json"), true) ?? [];
$imagini_ok = $exp_copie && count(glob("$dosar_copie/media/*") ?: []) === count($exp_copie['imagini'] ?? [-1])
    && @file_get_contents("$dosar_copie/media/" . basename($url_img)) === @file_get_contents("$tmp/site/media/" . basename($url_img));
verifica('Export', 'copie.php salvează copia pe calculator: export.json și imaginile, identice cu cele de pe site',
    $rc['cod'] === 0 && $imagini_ok && strpos($rc['iesire'], $kc) === false, $rc['iesire']);

// --- OAuth: conectorul din claude.ai --------------------------------------------------------------

// Testele fac zeci de cereri OAuth la rând, mult peste ce face un client real; contorul de cereri
// fără cheie se eliberează între blocuri, ca să nu se blocheze singure. Plafonul are testul lui, mai jos.
function elibereaza(): void { @unlink($GLOBALS['tmp'] . '/site/date/securitate/incercari.json'); }
function b64url(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function formular(array $d): array { return [http_build_query($d), ['Content-Type' => 'application/x-www-form-urlencoded']]; }
function autorizare(string $client, string $intoarcere, string $provocare, array $in_plus = []): array
{
    return $in_plus + ['response_type' => 'code', 'client_id' => $client, 'redirect_uri' => $intoarcere, 'state' => 'st4te',
                       'code_challenge' => $provocare, 'code_challenge_method' => 'S256', 'resource' => $GLOBALS['url'] . '/mcp'];
}
function aproba(string $client, string $intoarcere, string $provocare, string $cheie, ?string $cod_conectare = null): array
{
    $cod_conectare = $cod_conectare ?? (string) ($GLOBALS['COD'] ?? '');
    [$corp, $ant] = formular(autorizare($client, $intoarcere, $provocare) + ['cheie' => $cheie, 'decizie' => 'permite', 'cod_conectare' => $cod_conectare]);
    $r = cerere('POST', '/oauth/autorizare', $corp, $ant);
    parse_str((string) parse_url($r['antete']['location'] ?? '', PHP_URL_QUERY), $q);
    return ['r' => $r, 'q' => $q, 'cod' => (string) ($q['code'] ?? '')];
}
function token(array $d): array
{
    [$corp, $ant] = formular($d);
    $r = cerere('POST', '/oauth/token', $corp, $ant);
    $r['json'] = json_decode($r['corp'], true) ?? [];
    return $r;
}

elibereaza();
$prm = json_decode(cerere('GET', '/.well-known/oauth-protected-resource')['corp'], true) ?? [];
$asm = json_decode(cerere('GET', '/.well-known/oauth-authorization-server')['corp'], true) ?? [];
verifica('OAuth', 'descoperirea: resursa /mcp, serverul de autorizare, înregistrare, PKCE S256', ($prm['resource'] ?? '') === "$url/mcp"
    && ($prm['authorization_servers'][0] ?? '') === $url && ($asm['token_endpoint'] ?? '') === "$url/oauth/token"
    && ($asm['registration_endpoint'] ?? '') === "$url/oauth/inregistrare" && ($asm['code_challenge_methods_supported'] ?? []) === ['S256'], json_encode([$prm, $asm]));

$claude = 'https://claude.ai/api/mcp/auth_callback';

// 0.6: fereastra de conectare. Fără ea, un străin care citește codul sursă nu poate nici să se înregistreze,
// nici să deschidă pagina care cere cheia — adică nu-ți poate trimite un link de aprobare care arată legitim.
function fereastra_test(string $cheie, int $minute = 15): array
{
    $r = cerere('POST', '/oauth/deschide', json_encode(['minute' => $minute]), ['Authorization' => 'Bearer ' . $cheie]);
    $r['json'] = json_decode($r['corp'], true) ?? [];
    return $r;
}
$r = cerere('POST', '/oauth/inregistrare', json_encode(['client_name' => 'Claude', 'redirect_uris' => [$claude]]));
verifica('Securitate', 'OAuth: fără fereastră deschisă de om, nimeni nu se poate înregistra', $r['cod'] === 403
    && strpos($r['corp'], 'access_denied') !== false, $r['corp']);
$r = cerere('GET', '/oauth/autorizare?' . http_build_query(['response_type' => 'code', 'client_id' => 'mcms_k_oricare']));
verifica('Securitate', 'OAuth: fără fereastră, nici pagina care cere cheia nu se deschide', $r['cod'] === 400
    && strpos($r['corp'], 'nu e deschisă acum') !== false, "cod {$r['cod']}");
$r = fereastra_test($kc);
verifica('Securitate', 'OAuth: fereastra nu se deschide cu cheia de citire', $r['cod'] === 403, "cod {$r['cod']} {$r['corp']}");
$r = cerere('POST', '/oauth/deschide', json_encode(['minute' => 15]));
verifica('Securitate', 'OAuth: fereastra nu se deschide fără nicio cheie', $r['cod'] === 401, "cod {$r['cod']}");
$r = fereastra_test($ks);
$COD = (string) ($r['json']['cod'] ?? '');
verifica('OAuth', 'omul deschide fereastra cu cheia de scriere și primește un cod de 6 cifre', $r['cod'] === 200
    && preg_match('/^[0-9]{6}$/', $COD) === 1, $r['corp']);
$pe_disc = (string) @file_get_contents("$tmp/site/date/oauth/fereastra.json");
verifica('Securitate', 'OAuth: pe server stă doar amprenta codului de conectare, nu codul', $COD !== '' && strpos($pe_disc, $COD) === false, $pe_disc);

$r = cerere('POST', '/oauth/inregistrare', json_encode(['client_name' => 'Rău', 'redirect_uris' => ['https://site-rau.example/cb']]));
verifica('OAuth', 'înregistrarea cu o adresă de întoarcere străină e refuzată', $r['cod'] === 400 && strpos($r['corp'], 'invalid_redirect_uri') !== false, $r['corp']);
$r = cerere('POST', '/oauth/inregistrare', json_encode(['client_name' => 'Claude', 'redirect_uris' => [$claude], 'token_endpoint_auth_method' => 'none',
    'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']]));
$client = (string) (json_decode($r['corp'], true)['client_id'] ?? '');
verifica('OAuth', 'Claude se înregistrează singur (RFC 7591)', $r['cod'] === 201 && strpos($client, 'mcms_k_') === 0, $r['corp']);

$ver = b64url(random_bytes(32));
$prov = b64url(hash('sha256', $ver, true));
$r = cerere('GET', '/oauth/autorizare?' . http_build_query(autorizare($client, $claude, $prov)));
verifica('OAuth', 'pagina de aprobare cere cheia și spune unde se întoarce; CSP permite trimiterea spre Claude', $r['cod'] === 200
    && strpos($r['corp'], 'name="cheie"') !== false && strpos($r['corp'], 'claude.ai') !== false
    && strpos($r['antete']['content-security-policy'] ?? '', "form-action 'self' https://claude.ai") !== false, "cod {$r['cod']}");
$r = cerere('GET', '/oauth/autorizare?' . http_build_query(autorizare($client, 'https://claude.ai/alt-callback', $prov)));
verifica('Securitate', 'OAuth: o adresă de întoarcere neînregistrată → pagină de eroare, fără redirecționare', $r['cod'] === 400 && !isset($r['antete']['location']), "cod {$r['cod']}");
$r = cerere('GET', '/oauth/autorizare?' . http_build_query(autorizare($client, $claude, $prov, ['code_challenge_method' => 'plain'])));
verifica('Securitate', 'OAuth: fără PKCE S256 nu se aprobă nimic', $r['cod'] === 400, "cod {$r['cod']}");
$a = aproba($client, $claude, $prov, 'cheie-gresita-dar-destul-de-lunga-1111');
verifica('Securitate', 'OAuth: cu o cheie greșită nu se aprobă (și se numără la blocare)', $a['r']['cod'] === 401 && $a['cod'] === '', "cod {$a['r']['cod']}");
$a = aproba($client, $claude, $prov, $ks, '000000' === $COD ? '111111' : '000000');
verifica('Securitate', 'OAuth: cheia bună dar codul de conectare greșit → refuzat (atacatorul are linkul, nu are codul)',
    $a['r']['cod'] === 401 && $a['cod'] === '' && strpos($a['r']['corp'], 'Codul de conectare') !== false, "cod {$a['r']['cod']}");
[$corp, $ant] = formular(autorizare($client, $claude, $prov) + ['decizie' => 'refuza']);
$r = cerere('POST', '/oauth/autorizare', $corp, $ant);
verifica('OAuth', '„Refuză” → înapoi la Claude cu access_denied', $r['cod'] === 302 && strpos($r['antete']['location'] ?? '', 'error=access_denied') !== false);

$a = aproba($client, $claude, $prov, $ks);
verifica('OAuth', 'cu cheia de scriere: înapoi la Claude cu cod, state și emitent', $a['r']['cod'] === 302
    && strpos($a['r']['antete']['location'] ?? '', $claude . '?') === 0 && ($a['q']['state'] ?? '') === 'st4te' && ($a['q']['iss'] ?? '') === $url && strlen($a['cod']) > 40,
    $a['r']['antete']['location'] ?? '');
$r = token(['grant_type' => 'authorization_code', 'code' => $a['cod'], 'redirect_uri' => $claude, 'client_id' => $client, 'code_verifier' => b64url(random_bytes(32))]);
verifica('Securitate', 'OAuth: un code_verifier greșit (PKCE) e refuzat', $r['cod'] === 400 && ($r['json']['error'] ?? '') === 'invalid_grant', $r['corp']);
$r = token(['grant_type' => 'authorization_code', 'code' => $a['cod'], 'redirect_uri' => $claude, 'client_id' => $client, 'code_verifier' => $ver]);
verifica('Securitate', 'OAuth: un cod se folosește o singură dată, chiar dacă prima încercare a fost greșită', $r['cod'] === 400, $r['corp']);

// O fereastră = o conectare: după o aprobare reușită se închide singură, deci un al doilea link nu mai merge.
$r = cerere('POST', '/oauth/inregistrare', json_encode(['client_name' => 'Al doilea', 'redirect_uris' => [$claude]]));
verifica('Securitate', 'OAuth: după o aprobare reușită fereastra se închide singură', $r['cod'] === 403, "cod {$r['cod']}");
$COD = (string) (fereastra_test($ks)['json']['cod'] ?? '');

elibereaza();
$ver = b64url(random_bytes(32));
$a = aproba($client, $claude, b64url(hash('sha256', $ver, true)), $ks);
$r = token(['grant_type' => 'authorization_code', 'code' => $a['cod'], 'redirect_uri' => $claude, 'client_id' => $client, 'code_verifier' => $ver]);
$acces = (string) ($r['json']['access_token'] ?? '');
$reinnoire = (string) ($r['json']['refresh_token'] ?? '');
verifica('OAuth', 'cod bun + verificator bun → token de acces și de reînnoire, cu drept de scriere', $r['cod'] === 200 && strpos($acces, 'mcms_t_') === 0
    && strpos($reinnoire, 'mcms_r_') === 0 && ($r['json']['scope'] ?? '') === 'scriere' && ($r['json']['token_type'] ?? '') === 'Bearer', $r['corp']);
$pe_server = implode('', array_map('file_get_contents', glob("$tmp/site/date/oauth/*.json") ?: []));
verifica('Securitate', 'OAuth: pe server nu stă niciun token sau cod în clar, doar amprente', $acces !== '' && strpos($pe_server, $acces) === false
    && strpos($pe_server, $reinnoire) === false && strpos($pe_server, $a['cod']) === false && strpos($pe_server, hash('sha256', $acces)) !== false);
$r = mcp($acces, 'tools/list');
verifica('OAuth', 'Claude folosește tokenul pe /mcp și vede toate comenzile', $r['cod'] === 200 && count($r['json']['result']['tools'] ?? []) === 21, $r['corp']);
$r = token(['grant_type' => 'refresh_token', 'refresh_token' => $reinnoire, 'client_id' => $client]);
$acces2 = (string) ($r['json']['access_token'] ?? '');
$r2 = token(['grant_type' => 'refresh_token', 'refresh_token' => $reinnoire, 'client_id' => $client]);
verifica('OAuth', 'reînnoirea dă token-uri noi; tokenul de reînnoire vechi nu mai merge', $r['cod'] === 200 && $acces2 !== ''
    && ($r['json']['refresh_token'] ?? $reinnoire) !== $reinnoire && $r2['cod'] === 400, $r['corp'] . ' / ' . $r2['corp']);
$r = token(['grant_type' => 'refresh_token', 'refresh_token' => (string) ($r['json']['refresh_token'] ?? ''), 'client_id' => 'mcms_k_necunoscut']);
verifica('Securitate', 'OAuth: un client necunoscut nu primește nimic', $r['cod'] === 401, $r['corp']);

elibereaza();
$ver = b64url(random_bytes(32));
$COD = (string) (fereastra_test($ks)['json']['cod'] ?? '');
$a = aproba($client, $claude, b64url(hash('sha256', $ver, true)), $kc);
$r = token(['grant_type' => 'authorization_code', 'code' => $a['cod'], 'redirect_uri' => $claude, 'client_id' => $client, 'code_verifier' => $ver]);
$r2 = mcp((string) ($r['json']['access_token'] ?? ''), 'tools/list');
verifica('OAuth', 'aprobat cu cheia de citire, tokenul are doar drept de citire', ($r['json']['scope'] ?? '') === 'citire'
    && count($r2['json']['result']['tools'] ?? []) === 11, $r['corp']);
$l = unealta($kc, 'listeaza_conexiuni');
verifica('OAuth', 'listeaza_conexiuni arată conexiunea Claude, aprobată, cu drepturile ei', ($l['date']['conexiuni'][0]['nume'] ?? '') === 'Claude'
    && ($l['date']['conexiuni'][0]['aprobat'] ?? false) === true && in_array('scriere', $l['date']['conexiuni'][0]['drepturi'] ?? [], true), $l['text']);
$j = unealta($kc, 'citeste_jurnal', ['ultimele' => 60]);
verifica('OAuth', 'jurnalul arată apelurile prin conexiune și pașii OAuth, fără token-uri sau coduri',
    array_filter($j['date']['intrari'] ?? [], fn($i) => ($i['conexiune'] ?? '') === 'Claude')
    && array_filter($j['date']['intrari'] ?? [], fn($i) => ($i['punct'] ?? '') === 'oauth' && ($i['cerere'] ?? '') === 'token')
    && strpos($j['text'], $acces) === false && strpos($j['text'], $a['cod']) === false);

$cfg_original = (string) file_get_contents("$tmp/site/app/config.php");
file_put_contents("$tmp/site/app/config.php", str_replace(hash('sha256', $ks), hash('sha256', $ks . 'alta'), $cfg_original));
$r = mcp($acces2, 'tools/list');
file_put_contents("$tmp/site/app/config.php", $cfg_original);
$r2 = mcp($acces2, 'tools/list');
verifica('Securitate', 'OAuth: când cheia de scriere se schimbă, tokenurile aprobate cu ea nu mai merg', $r['cod'] === 401
    && strpos($r['antete']['www-authenticate'] ?? '', 'invalid_token') !== false && $r2['cod'] === 200, "cod {$r['cod']} / {$r2['cod']}");
$u = unealta($ks, 'retrage_conexiune', ['client_id' => $client]);
$r = mcp($acces2, 'tools/list');
verifica('OAuth', 'retrage_conexiune anulează imediat accesul', !$u['eroare'] && $r['cod'] === 401, $u['text'] . " / cod {$r['cod']}");

// --- acces direct din web la fișierele interne --------------------------------------------------

$interne = ['/app/config.php', '/app/nucleu.php', '/sabloane/baza.php', '/date/pagini/atac.json',
            '/date/jurnal/' . date('Y-m') . '.ndjson', '/date/securitate/incercari.json', '/.htaccess', '/media/x.php',
            '/minicms-0.2.0-site.zip', '/copie.sql', '/site.tar.gz'];
foreach ($interne as $c) {
    $r = cerere('GET', $c);
    verifica('Securitate', "$c nu se poate citi din web (403)", $r['cod'] === 403, "cod {$r['cod']}");
}
$r = cerere('GET', '/');
$csp = $r['antete']['content-security-policy'] ?? '';
verifica('Securitate', 'paginile publice au CSP cu nonce, fără unsafe-inline', strpos($csp, "'nonce-") !== false && strpos($csp, 'unsafe-inline') === false, $csp);
verifica('Securitate', 'antete nosniff și X-Frame-Options DENY', ($r['antete']['x-content-type-options'] ?? '') === 'nosniff' && ($r['antete']['x-frame-options'] ?? '') === 'DENY');
verifica('Securitate', 'fără HSTS pe http (se trimite singur doar pe https)', !isset($r['antete']['strict-transport-security']));
$r = mcp('cheie-gresita-cu-ip-falsificat-000', 'ping', [], 1, ['CF-Connecting-IP' => '203.0.113.9', 'X-Forwarded-Proto' => 'https']);
$falsificat = array_filter(unealta($kc, 'citeste_jurnal', ['ultimele' => 20])['date']['intrari'] ?? [], fn($i) => ($i['ip'] ?? '') === '203.0.113.9');
verifica('Securitate', 'antetul CF-Connecting-IP trimis din afara Cloudflare e ignorat (jurnalul păstrează IP-ul real)', $r['cod'] === 401 && !$falsificat);

// --- jurnal ------------------------------------------------------------------------------------

$u = unealta($kc, 'citeste_jurnal', ['ultimele' => 500]);
$intrari = $u['date']['intrari'] ?? [];
$pe_rezultat = array_count_values(array_map(fn($i) => (string) ($i['rezultat'] ?? ''), $intrari));
verifica('Jurnal', 'încercările cu cheie lipsă sau greșită sunt în jurnal', ($pe_rezultat['auth_esuat'] ?? 0) >= 2, json_encode($pe_rezultat));
verifica('Jurnal', 'refuzul cheii de citire e în jurnal', ($pe_rezultat['refuzat'] ?? 0) >= 1);
verifica('Jurnal', 'și citirile sunt în jurnal', (bool) array_filter($intrari, fn($i) => ($i['unealta'] ?? '') === 'listeaza_versiuni' && ($i['cheie'] ?? '') === 'citire'));
verifica('Jurnal', 'scrierile au amprenta conținutului scris', (bool) array_filter($intrari, fn($i) => ($i['unealta'] ?? '') === 'salveaza' && preg_match('/^[a-f0-9]{64}$/', (string) ($i['amprenta'] ?? ''))));
verifica('Jurnal', 'lanțul de amprente e intact', ($u['date']['lant']['intact'] ?? false) === true, json_encode($u['date']['lant'] ?? null));
verifica('Jurnal', 'schimbarea identității apare în jurnal, cu ținta "site" și amprenta', (bool) array_filter($intrari, fn($i) => ($i['unealta'] ?? '') === 'seteaza_site'
    && ($i['tinta'] ?? '') === 'site' && preg_match('/^[a-f0-9]{64}$/', (string) ($i['amprenta'] ?? ''))));
verifica('Jurnal', 'vizualizările previzualizărilor sunt în jurnal, inclusiv cele refuzate', count(array_filter($intrari, fn($i) => ($i['punct'] ?? '') === 'previzualizare' && ($i['rezultat'] ?? '') === 'ok')) >= 2
    && count(array_filter($intrari, fn($i) => ($i['punct'] ?? '') === 'previzualizare' && ($i['rezultat'] ?? '') === 'respins')) >= 2);
verifica('Jurnal', 'la previzualizări (pagini publice) se scrie adresa trunchiată, nu IP-ul întreg',
    (bool) array_filter($intrari, fn($i) => ($i['punct'] ?? '') === 'previzualizare' && ($i['ip'] ?? '') === '127.0.0.0')
    && !array_filter($intrari, fn($i) => ($i['punct'] ?? '') === 'previzualizare' && ($i['ip'] ?? '') === '127.0.0.1'));
verifica('Jurnal', 'nicio comandă MCP nu scrie în jurnal', !array_filter($lista_s, fn($t) => strpos($t['name'], 'jurnal') !== false && $t['name'] !== 'citeste_jurnal'));
$r = cerere('POST', '/jurnal.php', http_build_query(['cheie' => $kc]), ['Content-Type' => 'application/x-www-form-urlencoded']);
verifica('Jurnal', 'pagina jurnalului se deschide cu cheia de citire', $r['cod'] === 200 && strpos($r['corp'], 'Lanțul e intact') !== false, "cod {$r['cod']}");
$r = cerere('GET', '/jurnal.php?cheie=' . $kc);
verifica('Jurnal', 'cheia pusă în adresă (GET) nu deschide jurnalul', $r['cod'] === 200 && strpos($r['corp'], 'Lanțul') === false);

// --- plafon pe adresele care răspund fără cheie (OAuth) ------------------------------------------

@unlink("$tmp/site/date/securitate/incercari.json");
$coduri = [];
for ($i = 0; $i < 70 && !in_array(429, $coduri, true); $i++) $coduri[] = cerere('GET', '/.well-known/oauth-authorization-server')['cod'];
verifica('Securitate', 'adresele OAuth (care răspund fără cheie) au plafon pe numărul de cereri, nu doar pe eșecuri',
    ($coduri[0] ?? 0) === 200 && in_array(429, $coduri, true), implode(',', $coduri));
@unlink("$tmp/site/date/securitate/incercari.json");   // eliberăm adresa testelor pentru ce urmează

// --- blocarea după încercări eșuate (la final: blochează IP-ul testelor) ------------------------

$cod = 0;
for ($i = 0; $i < 12 && $cod !== 429; $i++) $cod = mcp('cheie-gresita-' . str_repeat('x', 20) . $i, 'ping')['cod'];
$r = mcp($ks, 'ping');
verifica('Securitate', 'după 8 încercări greșite IP-ul e blocat 15 minute, chiar și cu cheia bună (429)', $r['cod'] === 429, "cod {$r['cod']}");
$r = cerere('POST', '/jurnal.php', http_build_query(['cheie' => $kc]), ['Content-Type' => 'application/x-www-form-urlencoded']);
verifica('Securitate', 'blocarea se aplică și paginii de jurnal', $r['cod'] === 429, "cod {$r['cod']}");

// --- lanțul jurnalului, verificat direct pe fișiere ----------------------------------------------

define('MINICMS', true);
require "$tmp/site/app/nucleu.php";
$fisier = "$tmp/site/date/jurnal/" . date('Y-m') . '.ndjson';
$original = (string) file_get_contents($fisier);
verifica('Jurnal', 'verificarea directă a lanțului: intact', jurnal_verifica()['intact']);
file_put_contents($fisier, preg_replace('/"rezultat":"auth_esuat"/', '"rezultat":"ok"', $original, 1));
$v = jurnal_verifica();
verifica('Jurnal', 'un rând modificat de mână rupe lanțul, cu locul exact', !$v['intact'] && isset($v['unde']), json_encode($v));
$linii = explode("\n", rtrim($original, "\n"));
array_pop($linii);
file_put_contents($fisier, implode("\n", $linii) . "\n");
verifica('Jurnal', 'un rând scos de la final e detectat', !jurnal_verifica()['intact']);
file_put_contents($fisier, $original);

$php_la_final = fisiere($tmp, '/\.(php[0-9]?|phtml|phar)$/i');   // înainte de instalarea de mai jos, care aduce fișierele ei
verifica('Securitate', 'după toate testele, niciun fișier executabil nou pe server', $php_la_final === $php_la_inceput,
    implode(', ', array_diff($php_la_final, $php_la_inceput)));

// --- instalarea în doi pași, cap-coadă: pachetul făcut de instaleaza.php, despachetat ca pe server, verificat ---

function instaleaza(array $argumente): array
{
    return unealta_locala('instaleaza.php', $argumente);
}

function unealta_locala(string $script, array $argumente): array   // stdin e un pipe, deci unealta rulează neinteractiv (fără pauze)
{
    global $radacina;
    $proc = proc_open(array_merge([PHP_BINARY, "$radacina/unelte/$script"], $argumente),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $iesire = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['cod' => proc_close($proc), 'iesire' => (string) $iesire];
}

$inst = "$tmp/instalare";
mkdir($inst);
$port2 = 0;
for ($i = 0; $i < 20 && !$port2; $i++) {
    $p = random_int(19000, 19899);
    $s = @fsockopen('127.0.0.1', $p, $e1, $e2, 0.2);
    if ($s) fclose($s); else $port2 = $p;
}
$url2 = "http://127.0.0.1:$port2";
$nume2 = '127-0-0-1';
$pas1 = [$url2, '--local', "--dosar=$inst", '--fara-teste', '--fara-claude'];
$r1 = instaleaza($pas1);
$chei2 = json_decode((string) @file_get_contents("$inst/chei-$nume2.json"), true);
$zip2 = "$inst/_livrare/$nume2/minicms-" . MINICMS_VERSIUNE . "-$nume2.zip";
verifica('Instalare', 'pasul 1 face cheile și pachetul, fără să afișeze vreo cheie', $r1['cod'] === 0 && is_file($zip2) && isset($chei2['scriere']['cheie'])
    && strpos($r1['iesire'], $chei2['scriere']['cheie']) === false && strpos($r1['iesire'], $chei2['citire']['cheie']) === false, $r1['iesire']);
$r3 = instaleaza($pas1);
$chei3 = json_decode((string) @file_get_contents("$inst/chei-$nume2.json"), true);
verifica('Instalare', 'a doua rulare refolosește cheile și păstrează pachetul vechi, cu data în nume', $r3['cod'] === 0 && $chei3 === $chei2
    && count(glob("$inst/_livrare/$nume2/minicms-*.zip") ?: []) === 2, $r3['iesire']);

$pachet = new PharData($zip2);
$in_pachet = [];
foreach (new RecursiveIteratorIterator($pachet) as $f) {
    $c = str_replace('\\', '/', $f->getPathname());
    $in_pachet[] = substr($c, (int) strpos($c, '.zip/') + 5);
}
$cfg2 = $pachet['app/config.php']->getContent();
verifica('Instalare', 'pachetul are cele trei .htaccess și config.php, dar nu date/ sau media/',
    !array_diff(['.htaccess', 'app/.htaccess', 'sabloane/.htaccess', 'app/config.php', 'mcp.php'], $in_pachet)
    && !preg_grep('#^(date|media)/#', $in_pachet), implode(', ', $in_pachet));
verifica('Instalare', 'config.php din pachet are adresa și amprentele, nu și cheile', strpos($cfg2, $chei2['scriere']['amprenta']) !== false
    && strpos($cfg2, $chei2['citire']['amprenta']) !== false && strpos($cfg2, $url2) !== false && strpos($cfg2, 'mcms_') === false, $cfg2);
$pachet->extractTo("$inst/server");
unset($pachet);

$server2 = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', "127.0.0.1:$port2", '-t', "$inst/server", "$radacina/unelte/router-local.php"],
    [0 => ['pipe', 'r'], 1 => ['file', "$tmp/server2.log", 'a'], 2 => ['file', "$tmp/server2.log", 'a']], $pipes2, "$inst/server");
for ($i = 0; $i < 60; $i++) {
    $s = @fsockopen('127.0.0.1', $port2, $e1, $e2, 0.2);
    if ($s) { fclose($s); break; }
    usleep(100000);
}
$r2 = instaleaza([$url2, '--verifica', '--local', "--dosar=$inst", '--fara-claude']);
verifica('Instalare', 'pasul 2 trece pe pachetul despachetat: dosare închise, /mcp, ambele chei', $r2['cod'] === 0 && strpos($r2['iesire'], 'Gata') !== false, $r2['iesire']);
$url_principal = $url;
$url = $url2;
$u = unealta($chei2['citire']['cheie'], 'despre_site');
$url = $url_principal;
verifica('Instalare', 'fără nume în config.php, site-ul poartă numele domeniului până îl setează AI-ul', ($u['date']['site']['nume'] ?? '') === '127.0.0.1', $u['text']);
$rp = unealta_locala('copie.php', [$url2, '--local', "--dosar=$inst", "--pune=$dosar_copie"]);
$url = $url2;
$vechi = cerere('GET', '/articol-vechi');
$acasa2 = cerere('GET', '/');
$img2 = cerere('GET', $url_img);
$refacut = $rp['cod'] === 0 && cerere('GET', '/sedinta-de-luni')['cod'] === 200 && $vechi['cod'] === 200
    && strpos($vechi['corp'], 'Ion Popescu') !== false && strpos($vechi['corp'], '2020') !== false
    && cerere('GET', '/programat')['cod'] === 404 && cerere('GET', '/ciorna-noua')['cod'] === 404
    && cerere('GET', '/despre-noi.html')['cod'] === 301 && strpos($acasa2['corp'], 'Atelierul Test') !== false
    && strpos($acasa2['corp'], 'src="' . $url_img . '"') !== false && $img2['corp'] === @file_get_contents("$tmp/site/media/" . basename($url_img));
$url = $url_principal;
verifica('Export', 'copia pusă pe un site gol îl reface: elemente, stări, date, autori, identitate, imagini cu aceleași adrese, redirecționări',
    $refacut, $rp['iesire']);
verifica('Export', 'copia păstrează și tema aleasă', strpos($acasa2['corp'], '/assets/teme/simpluspv.css') !== false);
$rp2 = unealta_locala('copie.php', [$url2, '--local', "--dosar=$inst", "--pune=$dosar_copie"]);
verifica('Export', 'peste un site cu conținut, copia nu se pune fără --peste', $rp2['cod'] === 1 && strpos($rp2['iesire'], '--peste') !== false, $rp2['iesire']);
rename("$inst/server/app/config.php", "$inst/server/app/config.scos");
$r4 = instaleaza([$url2, '--verifica', '--local', "--dosar=$inst", '--fara-claude']);
verifica('Instalare', 'verificarea se oprește și spune cauza când serverul nu e în regulă (config.php lipsă)',
    $r4['cod'] === 1 && strpos($r4['iesire'], 'Lipsește app/config.php') !== false, $r4['iesire']);
proc_terminate($server2);
$r5 = instaleaza(array_merge($pas1, ['--chei-noi']));
$chei5 = json_decode((string) @file_get_contents("$inst/chei-$nume2.json"), true);
$cfg5 = (string) @file_get_contents("$inst/_livrare/$nume2/config.php");
verifica('Instalare', '--chei-noi: chei noi în config, cele vechi păstrate cu data în nume', $r5['cod'] === 0
    && ($chei5['scriere']['cheie'] ?? '') !== $chei2['scriere']['cheie'] && strpos($cfg5, $chei5['scriere']['amprenta']) !== false
    && strpos($cfg5, $chei2['scriere']['amprenta']) === false && count(glob("$inst/chei-$nume2.*.json") ?: []) === 1, $r5['iesire']);

// --- încheiere ---------------------------------------------------------------------------------

proc_terminate($server);
$trecute = count(array_filter($rezultate, fn($r) => $r['ok']));
echo "\n$trecute din " . count($rezultate) . " teste trecute.\n";
if (!empty($optiuni['json'])) {
    file_put_contents($optiuni['json'], json_encode(['data' => date('c'), 'php' => PHP_VERSION, 'trecute' => $trecute,
        'total' => count($rezultate), 'teste' => $rezultate], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
if (isset($optiuni['pastreaza'])) echo "Dosarul de test: $tmp\n"; else sterge_dosar($tmp);
exit($trecute === count($rezultate) ? 0 : 1);
