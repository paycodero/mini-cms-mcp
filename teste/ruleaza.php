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
$kc = 'test_c_' . bin2hex(random_bytes(16));   // cheile există doar cât rulează testele
$ks = 'test_s_' . bin2hex(random_bytes(16));
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
verifica('Chei', 'fără cheie → 401', $r['cod'] === 401 && isset($r['antete']['www-authenticate']), "cod {$r['cod']}");
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
verifica('Chei', 'cheia de citire vede doar cele 7 comenzi de citire', count($unelte_c) === 7 && !in_array('salveaza', $unelte_c, true), implode(', ', $unelte_c));
$r = mcp($ks, 'tools/list');
$lista_s = $r['json']['result']['tools'] ?? [];
verifica('Protocol', 'cheia de scriere vede toate cele 14 comenzi', count($lista_s) === 14, (string) count($lista_s));
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
$r = cerere('POST', '/mcp', '{"jsonrpc":"2.0","id":1,"method":"ping","params":{"x":"' . str_repeat('a', 9 * 1024 * 1024) . '"}}', ['Authorization' => "Bearer $kc"]);
verifica('Securitate', 'cerere de 9 MB → 413, necitită', $r['cod'] === 413, "cod {$r['cod']}");

// --- conținut ----------------------------------------------------------------------------------

$u = unealta($kc, 'salveaza', ['tip' => 'articol', 'slug' => 'test', 'titlu' => 'x', 'continut_html' => '<p>x</p>']);
verifica('Chei', 'cheia de citire nu poate scrie: refuz și nimic creat', $u['eroare'] && strpos($u['text'], 'Refuzat') !== false && !glob("$tmp/site/date/articole/*.json"), $u['text']);
$u = unealta($kc, 'despre_site');
verifica('Conținut', 'despre_site întoarce regulile și rolul cheii', !$u['eroare'] && ($u['date']['cheia_ta'] ?? '') === 'citire' && in_array('p', $u['date']['html_permis'] ?? [], true), $u['text']);

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
    . '<a href="https://exemplu.ro" target="_blank">extern</a>';
$u = unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => 'atac', 'titlu' => 'Atac', 'continut_html' => $rau]);
unealta($ks, 'publica', ['tip' => 'pagina', 'slug' => 'atac']);
$salvat = (string) (json_decode((string) @file_get_contents("$tmp/site/date/pagini/atac.json"), true)['continut_html'] ?? '');
$r = cerere('GET', '/atac');
$pagina = $r['corp'];
verifica('Securitate', 'codul PHP trimis ca conținut nu e nici salvat, nici rulat',
    $salvat !== '' && stripos($salvat, '<?php') === false && strpos($pagina, 'RCE-EXECUTAT') === false && !fisiere($tmp, '/^pwn\.txt$/'));
$interzise = ['<script', 'onerror', 'onclick', 'onload', 'javascript:', '<svg', 'style=', '<form', '<input', 'site-rau.example'];
$gasite = array_values(array_filter($interzise, fn($x) => stripos($salvat, $x) !== false || stripos($pagina, $x) !== false));
verifica('Securitate', 'scoase: <script>, on*, javascript:, //alt-site, SVG, style, formulare, iframe străin', !$gasite, implode(', ', $gasite));
verifica('Securitate', 'păstrate: textul, imaginea, videoclipul YouTube', strpos($salvat, 'Text bun') !== false && strpos($salvat, '<img src="x.png">') !== false
    && strpos($salvat, 'youtube-nocookie.com/embed/dQw4w9WgXcQ') !== false, $salvat);
verifica('Securitate', 'link cu target=_blank primește rel="noopener noreferrer"', strpos($salvat, 'rel="noopener noreferrer"') !== false);
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

// --- acces direct din web la fișierele interne --------------------------------------------------

$interne = ['/app/config.php', '/app/nucleu.php', '/sabloane/baza.php', '/date/pagini/atac.json',
            '/date/jurnal/' . date('Y-m') . '.ndjson', '/date/securitate/incercari.json', '/.htaccess', '/media/x.php'];
foreach ($interne as $c) {
    $r = cerere('GET', $c);
    verifica('Securitate', "$c nu se poate citi din web (403)", $r['cod'] === 403, "cod {$r['cod']}");
}
$r = cerere('GET', '/');
$csp = $r['antete']['content-security-policy'] ?? '';
verifica('Securitate', 'paginile publice au CSP cu nonce, fără unsafe-inline', strpos($csp, "'nonce-") !== false && strpos($csp, 'unsafe-inline') === false, $csp);
verifica('Securitate', 'antete nosniff și X-Frame-Options DENY', ($r['antete']['x-content-type-options'] ?? '') === 'nosniff' && ($r['antete']['x-frame-options'] ?? '') === 'DENY');

// --- jurnal ------------------------------------------------------------------------------------

$u = unealta($kc, 'citeste_jurnal', ['ultimele' => 500]);
$intrari = $u['date']['intrari'] ?? [];
$pe_rezultat = array_count_values(array_map(fn($i) => (string) ($i['rezultat'] ?? ''), $intrari));
verifica('Jurnal', 'încercările cu cheie lipsă sau greșită sunt în jurnal', ($pe_rezultat['auth_esuat'] ?? 0) >= 2, json_encode($pe_rezultat));
verifica('Jurnal', 'refuzul cheii de citire e în jurnal', ($pe_rezultat['refuzat'] ?? 0) >= 1);
verifica('Jurnal', 'și citirile sunt în jurnal', (bool) array_filter($intrari, fn($i) => ($i['unealta'] ?? '') === 'listeaza_versiuni' && ($i['cheie'] ?? '') === 'citire'));
verifica('Jurnal', 'scrierile au amprenta conținutului scris', (bool) array_filter($intrari, fn($i) => ($i['unealta'] ?? '') === 'salveaza' && preg_match('/^[a-f0-9]{64}$/', (string) ($i['amprenta'] ?? ''))));
verifica('Jurnal', 'lanțul de amprente e intact', ($u['date']['lant']['intact'] ?? false) === true, json_encode($u['date']['lant'] ?? null));
verifica('Jurnal', 'nicio comandă MCP nu scrie în jurnal', !array_filter($lista_s, fn($t) => strpos($t['name'], 'jurnal') !== false && $t['name'] !== 'citeste_jurnal'));
$r = cerere('POST', '/jurnal.php', http_build_query(['cheie' => $kc]), ['Content-Type' => 'application/x-www-form-urlencoded']);
verifica('Jurnal', 'pagina jurnalului se deschide cu cheia de citire', $r['cod'] === 200 && strpos($r['corp'], 'Lanțul e intact') !== false, "cod {$r['cod']}");
$r = cerere('GET', '/jurnal.php?cheie=' . $kc);
verifica('Jurnal', 'cheia pusă în adresă (GET) nu deschide jurnalul', $r['cod'] === 200 && strpos($r['corp'], 'Lanțul') === false);

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

$php_la_final = fisiere($tmp, '/\.(php[0-9]?|phtml|phar)$/i');
verifica('Securitate', 'după toate testele, niciun fișier executabil nou pe server', $php_la_final === $php_la_inceput,
    implode(', ', array_diff($php_la_final, $php_la_inceput)));

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
