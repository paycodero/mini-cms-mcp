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
$kd = 'mcms_d_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');   // cheia de cod (actualizarea)
$port = 0;
for ($i = 0; $i < 20 && !$port; $i++) {
    $p = random_int(18100, 18999);
    $s = @fsockopen('127.0.0.1', $p, $e1, $e2, 0.2);
    if ($s) fclose($s); else $port = $p;
}
$url = "http://127.0.0.1:$port";
$port_depozit = 0;   // al doilea server, care ține pachetul de probă: serverul de test e cu un singur fir
for ($i = 0; $i < 20 && !$port_depozit; $i++) {
    $p = random_int(19100, 19999);
    $s = @fsockopen('127.0.0.1', $p, $e1, $e2, 0.2);
    if ($s) fclose($s); else $port_depozit = $p;
}
@mkdir("$tmp/depozit", 0755, true);
$server_depozit = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port_depozit", '-t', "$tmp/depozit"],
    [0 => ['pipe', 'r'], 1 => ['file', "$tmp/depozit.log", 'a'], 2 => ['file', "$tmp/depozit.log", 'a']], $pd, "$tmp/depozit");
register_shutdown_function(function () use ($server_depozit) {
    $st = @proc_get_status($server_depozit);
    if ($st && $st['running']) proc_terminate($server_depozit);
});
$config = ['site' => ['nume' => 'Site de test', 'descriere' => 'Site pentru teste automate.', 'url' => $url, 'limba' => 'ro',
                      'autor' => 'Autor Test', 'culoare' => '#6d2be8'],
           'chei' => ['citire' => hash('sha256', $kc), 'scriere' => hash('sha256', $ks), 'cod' => hash('sha256', $kd)],
           'depozit_zip' => "http://127.0.0.1:$port_depozit/pachet.bin", 'autocontrol_secunde' => 2,
           'imagini_url_permise' => ["127.0.0.1:$port_depozit"]];   // doar serverul de probă; orice altă adresă locală rămâne refuzată
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
verifica('Chei', 'cheia de citire vede doar cele 13 comenzi de citire', count($unelte_c) === 13 && !in_array('salveaza', $unelte_c, true), implode(', ', $unelte_c));
$r = mcp($ks, 'tools/list');
$lista_s = $r['json']['result']['tools'] ?? [];
verifica('Protocol', 'cheia de scriere vede toate cele 26 de comenzi', count($lista_s) === 26, (string) count($lista_s));
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

// --- 0.16: meniul pe două niveluri -------------------------------------------------------------
unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => 'servicii', 'titlu' => 'Servicii', 'continut_html' => '<p>Ce facem.</p>', 'meniu' => 2]);
unealta($ks, 'publica', ['tip' => 'pagina', 'slug' => 'servicii']);
foreach ([['audit', 'Audit', 2], ['consultanta', 'Consultanță', 1]] as [$s, $t, $poz]) {
    unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => $s, 'titlu' => $t, 'continut_html' => "<p>$t pe larg.</p>", 'descriere' => "Despre $t.",
                              'meniu' => $poz, 'parinte' => 'servicii']);
    unealta($ks, 'publica', ['tip' => 'pagina', 'slug' => $s]);
}
$r = cerere('GET', '/despre');
$sub = preg_match('#<div class="meniu-grup">\s*<a href="/servicii" class="are-submeniu">Servicii</a>\s*<div class="submeniu">\s*'
    . '<a href="/consultanta">Consultanță</a>\s*<a href="/audit">Audit</a>\s*</div>#u', $r['corp']);
verifica('Meniu', 'subpaginile stau în submeniul părintelui, în ordinea din "meniu", nu și în meniul principal',
    $sub === 1 && substr_count($r['corp'], 'href="/audit"') === 1, substr((string) strstr($r['corp'], '<nav class="meniu"'), 0, 600));
$r = cerere('GET', '/servicii');
verifica('Meniu', 'pagina-părinte își listează singură subpaginile, cu descrierea lor',
    strpos($r['corp'], '<nav class="subpagini"') !== false && strpos($r['corp'], 'Despre Audit.') !== false && strpos($r['corp'], 'inapoi-sectiune') === false);
$r = cerere('GET', '/audit');
verifica('Meniu', 'subpagina: adresa rămâne /audit, are link înapoi și firimituri Acasă › Servicii › Audit', $r['cod'] === 200
    && strpos($r['corp'], 'class="inapoi-sectiune"><a href="/servicii">') !== false
    && strpos($r['corp'], '"position":2,"name":"Servicii"') !== false && strpos($r['corp'], '"position":3,"name":"Audit"') !== false);
$refuzuri = [
    unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => 'detalii-audit', 'titlu' => 'x', 'continut_html' => '<p>x</p>', 'parinte' => 'audit']),
    unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => 'servicii', 'parinte' => 'despre']),
    unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => 'audit', 'parinte' => 'audit']),
    unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => 'audit', 'parinte' => 'nu-exista']),
    unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => 'audit', 'parinte' => 'acasa']),
    unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'primul-articol', 'parinte' => 'servicii']),
];
verifica('Meniu', 'refuzate: al treilea nivel, o pagină cu subpagini pusă sub alta, propriul părinte, părinte inexistent, prima pagină, articol cu părinte',
    count(array_filter($refuzuri, fn($x) => $x['eroare'])) === count($refuzuri) && unealta($kc, 'citeste', ['tip' => 'pagina', 'slug' => 'detalii-audit'])['eroare'],
    implode(' / ', array_column($refuzuri, 'text')));
$u = unealta($ks, 'sterge', ['tip' => 'pagina', 'slug' => 'servicii']);
verifica('Meniu', 'o pagină cu subpagini nu se șterge până nu le muți', $u['eroare'] && strpos($u['text'], 'audit') !== false, $u['text']);
$u = unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => 'despre', 'titlu' => 'Despre']);
verifica('Meniu', 'o pagină fără părinte nu primește câmpul degeaba: salvată la fel, rămâne neschimbată', ($u['date']['operatie'] ?? '') === 'neschimbat', $u['text']);

// --- 0.17: evenimentele ------------------------------------------------------------------------
$viitor = date('Y-m-d', strtotime('+40 days')) . ' 19:00';
$aproape = date('Y-m-d', strtotime('+10 days')) . ' 18:00';
$viitor_iso = (new DateTime($viitor, new DateTimeZone('Europe/Bucharest')))->format('c');   // site-ul scrie ora României
$ev_concert = ['tip' => 'MusicEvent', 'inceput' => $viitor, 'loc' => 'Ateneul Român', 'adresa' => 'Str. Benjamin Franklin nr. 1-3',
    'oras' => 'București', 'artisti' => [['nume' => 'Orchestra de probă', 'grup' => true], ['nume' => 'Ion Dirijor']],
    'bilete' => 'https://bilete.exemplu.ro/concert'];
foreach ([['concert-de-proba', 'Concert de probă', $ev_concert, '2020-01-03'], ['festival-de-proba', 'Festival de probă', ['inceput' => $aproape, 'loc' => 'Sala mică'], '2020-01-02'],
          ['concert-trecut', 'Concert trecut', ['inceput' => '2020-05-01 19:00', 'loc' => 'Sala veche'], '2020-01-04']] as [$s, $t, $ev, $publicat]) {
    $u = unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => $s, 'titlu' => $t, 'continut_html' => "<p>$t.</p>", 'descriere' => "Despre $t.", 'eveniment' => $ev]);
    unealta($ks, 'publica', ['tip' => 'articol', 'slug' => $s, 'la' => $publicat]);
}
$c = unealta($kc, 'citeste', ['tip' => 'articol', 'slug' => 'concert-de-proba']);
verifica('Evenimente', 'articolul primește evenimentul, cu ora României păstrată', !$u['eroare']
    && (($c['date']['element']['eveniment']['inceput'] ?? $c['date']['eveniment']['inceput'] ?? '') === $viitor_iso), $c['text']);
$r = cerere('GET', '/concert-de-proba');
verifica('Evenimente', 'pagina are datele Event: tip, început, loc cu adresă, artiști (grup și persoană), organizator, bilete',
    strpos($r['corp'], '"@type":"MusicEvent"') !== false && strpos($r['corp'], '"startDate":"' . $viitor_iso . '"') !== false
    && strpos($r['corp'], '"streetAddress":"Str. Benjamin Franklin nr. 1-3"') !== false && strpos($r['corp'], '{"@type":"PerformingGroup","name":"Orchestra de probă"}') !== false
    && strpos($r['corp'], '{"@type":"Person","name":"Ion Dirijor"}') !== false && strpos($r['corp'], '"eventStatus":"https://schema.org/EventScheduled"') !== false
    && strpos($r['corp'], '"offers":{"@type":"Offer","url":"https://bilete.exemplu.ro/concert"}') !== false, substr((string) strstr($r['corp'], 'MusicEvent'), 0, 500));
$r = cerere('GET', '/');
$p_f = strpos($r['corp'], 'Festival de probă'); $p_c = strpos($r['corp'], 'Concert de probă'); $p_t = strpos($r['corp'], 'Concert trecut');
verifica('Evenimente', 'prima pagină: întâi evenimentele care urmează, cel mai apropiat primul, apoi restul',
    $p_f !== false && $p_c !== false && $p_t !== false && $p_f < $p_c && $p_c < $p_t, "festival $p_f, concert $p_c, trecut $p_t");
verifica('Evenimente', 'cardul arată ziua și ora evenimentului, nu data publicării',
    preg_match('#<article class="card card-eveniment">.*?<p class="data data-eveniment"><time datetime="[^"]+">[^<]+ ' . date('Y', strtotime($aproape)) . ', ora 18:00</time>#su', $r['corp']) === 1);
$u = unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'festival-de-proba', 'eveniment' => ['inceput' => $aproape, 'loc' => 'Sala mică', 'stare' => 'anulat']]);
$r = cerere('GET', '/');
verifica('Evenimente', 'un eveniment anulat: starea apare pe card și în datele structurate',
    strpos($r['corp'], ', ora 18:00 — anulat') !== false && strpos(cerere('GET', '/festival-de-proba')['corp'], 'EventCancelled') !== false, $u['text']);
$refuzuri = [
    unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'concert-de-proba', 'eveniment' => ['loc' => 'Ateneu']]),
    unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'concert-de-proba', 'eveniment' => ['inceput' => 'mâine seară', 'loc' => 'Ateneu']]),
    unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'concert-de-proba', 'eveniment' => ['inceput' => $viitor]]),
    unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'concert-de-proba', 'eveniment' => ['inceput' => $viitor, 'loc' => 'A', 'tip' => 'Script']]),
    unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'concert-de-proba', 'eveniment' => ['inceput' => $viitor, 'loc' => 'A', 'bilete' => 'javascript:alert(1)']]),
    unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'concert-de-proba', 'eveniment' => ['inceput' => $viitor, 'sfarsit' => '2020-01-01', 'loc' => 'A']]),
    unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => 'despre', 'eveniment' => ['inceput' => $viitor, 'loc' => 'A']]),
];
verifica('Evenimente', 'refuzate: fără început, dată de neînțeles, fără loc, tip necunoscut, bilete fără https, sfârșit înainte de început, eveniment pe pagină',
    count(array_filter($refuzuri, fn($x) => $x['eroare'])) === count($refuzuri), implode(' / ', array_column($refuzuri, 'text')));
$u = unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'concert-trecut', 'continut_html' => '<p>x</p><script type="application/ld+json">{"@type":"Event"}</script>']);
verifica('Evenimente', 'un <script> JSON-LD scris de mână în conținut e scos: datele Event vin doar din câmp',
    strpos(cerere('GET', '/concert-trecut')['corp'], '{"@type":"Event"}') === false, $u['text']);
$u = unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'concert-trecut', 'eveniment' => null]);
$c = unealta($kc, 'citeste', ['tip' => 'articol', 'slug' => 'concert-trecut']);
verifica('Evenimente', 'eveniment null îl scoate: articolul rămâne fără câmp și fără date Event', !$u['eroare'] && strpos($c['text'], '"eveniment"') === false
    && strpos(cerere('GET', '/concert-trecut')['corp'], 'Sala veche') === false, $c['text']);
foreach (['concert-de-proba', 'festival-de-proba', 'concert-trecut'] as $s) unealta($ks, 'sterge', ['tip' => 'articol', 'slug' => $s]);

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
    . '<a href="https://exemplu.ro" target="_blank">extern</a>'
    . '<p id="dataLayer">clobber 1</p><p id="google_tag_data">clobber 2</p><p id="cuprins">ancoră bună</p>';
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
verifica('Securitate', 'id-urile care ar ocupa variabilele etichetei GA4 (dataLayer, google_tag_data) se scot; o ancoră obișnuită rămâne',
    stripos($salvat, 'id="datalayer"') === false && stripos($salvat, 'id="google_tag_data"') === false
    && strpos($salvat, '<p id="cuprins">') !== false && strpos($salvat, 'clobber 2') !== false, $salvat);

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
$r = cerere('GET', '/articole/articol-vechi');
$r2 = cerere('GET', '/articole/programat');
$r3 = cerere('GET', '/articole/nu-exista');
verifica('Redirecționări', 'adresa de blog /articole/<slug> a unui site mutat trimite cu 301 spre /<slug>; programatul și inexistentul dau 404',
    $r['cod'] === 301 && ($r['antete']['location'] ?? '') === '/articol-vechi' && $r2['cod'] === 404 && $r3['cod'] === 404,
    "cod {$r['cod']} / {$r2['cod']} / {$r3['cod']}");
$u = unealta($ks, 'redirectioneaza', ['de' => '/articole/formularul-800-notificarea-privind-facturile-netransmise-la-timp-pentru-tranzactiile-cu-plata-pe-loc', 'la' => '/articol-vechi']);
$u2 = unealta($ks, 'redirectioneaza', ['de' => '/articole', 'la' => '/despre']);
$r = cerere('GET', '/articole/formularul-800-notificarea-privind-facturile-netransmise-la-timp-pentru-tranzactiile-cu-plata-pe-loc');
verifica('Redirecționări', 'sub /articole/ se poate scrie o redirecționare de mână (slug vechi prea lung); /articole însuși rămâne al site-ului',
    !$u['eroare'] && $u2['eroare'] && $r['cod'] === 301 && ($r['antete']['location'] ?? '') === '/articol-vechi', $u['text'] . ' / ' . $u2['text']);

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
// --- legăturile din subsol (rețeaua aceluiași autor) ---------------------------------------------

$u = unealta($ks, 'seteaza_site', ['legaturi' => [
    ['titlu' => 'Celălalt site', 'url' => 'https://exemplu.ro/'],
    ['url' => 'https://www.youtube.com/@cineva'],
    ['url' => 'https://exemplu.ro/'],
]]);
$r = cerere('GET', '/');
$subsol = preg_match('#<footer.*?</footer>#s', $r['corp'], $mf) ? $mf[0] : '';
verifica('Site', 'legăturile apar în subsol, o singură dată fiecare, cu titlul luat din adresă când lipsește',
    !$u['eroare'] && strpos($subsol, '>Celălalt site</a>') !== false
    && strpos($subsol, '>youtube.com · cineva</a>') !== false
    && substr_count($subsol, 'https://exemplu.ro/') === 1, $subsol . ' || ' . $u['text']);
$ld_acasa = jsonld_din($r['corp']);
$sameas = ld_de_tip($ld_acasa, 'WebSite')['publisher']['sameAs'] ?? [];
verifica('SEO', 'aceleași adrese ajung în "sameAs", de unde motoarele leagă profilurile de site',
    count($sameas) === 2 && in_array('https://www.youtube.com/@cineva', $sameas, true), json_encode($sameas));
verifica('SEO', 'legăturile apar și în llms.txt, pentru asistenții care citesc site-ul',
    strpos(cerere('GET', '/llms.txt')['corp'], 'Celelalte site-uri și conturi') !== false);
$u = unealta($ks, 'seteaza_site', ['legaturi' => [['url' => 'javascript:alert(1)']]]);
verifica('Securitate', 'o legătură care nu e http(s) e refuzată', $u['eroare'], $u['text']);
$u = unealta($ks, 'seteaza_site', ['legaturi' => []]);
$r = cerere('GET', '/');
verifica('Site', 'lista goală scoate legăturile din subsol', !$u['eroare'] && strpos($r['corp'], 'class="lat retea"') === false, $u['text']);
$u = unealta($ks, 'seteaza_site', ['legaturi' => [['titlu' => 'Celălalt site', 'url' => 'https://exemplu.ro/']]]);

// --- 0.16.1: cine a făcut site-ul, pe rândul cu © ------------------------------------------------

$u = unealta($ks, 'seteaza_site', ['realizare' => 'Website realizat cu AI și <b>miniCMS</b>']);
$r = cerere('GET', '/');
$subsol = preg_match('#<footer.*?</footer>#s', $r['corp'], $mf) ? $mf[0] : '';
verifica('Site', 'mențiunea realizatorului apare în subsol ca text simplu când n-are adresă',
    !$u['eroare'] && strpos($subsol, '<span class="realizare">Website realizat cu AI și miniCMS</span>') !== false, $subsol . ' || ' . $u['text']);
$u = unealta($ks, 'seteaza_site', ['realizare_url' => 'https://realizator.example/']);
$r = cerere('GET', '/');
$subsol = preg_match('#<footer.*?</footer>#s', $r['corp'], $mf) ? $mf[0] : '';
$sameas = ld_de_tip(jsonld_din($r['corp']), 'WebSite')['publisher']['sameAs'] ?? [];
verifica('Site', 'cu adresă, mențiunea devine link, fără rel="me"',
    !$u['eroare'] && strpos($subsol, '<a class="realizare" href="https://realizator.example/" rel="noopener" target="_blank">Website realizat cu AI și miniCMS</a>') !== false,
    $subsol . ' || ' . $u['text']);
verifica('SEO', 'adresa realizatorului nu intră în "sameAs" și nici în llms.txt (nu e un profil al autorului)',
    !in_array('https://realizator.example/', $sameas, true) && substr_count($r['corp'], 'realizator.example') === 1
    && strpos(cerere('GET', '/llms.txt')['corp'], 'realizator.example') === false, json_encode($sameas));
$u = unealta($ks, 'seteaza_site', ['realizare_url' => 'javascript:alert(1)']);
verifica('Securitate', 'adresa realizatorului care nu e http(s) e refuzată', $u['eroare'], $u['text']);

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

// --- 0.18: documente PDF (urca_fisier) ------------------------------------------------------------

$pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\n"
    . "trailer << /Root 1 0 R >>\n%%EOF\n";
$pdf2 = str_replace('/Count 0', '/Count 0 /Versiunea 2', $pdf);
$in_fisiere = fn() => count(glob("$tmp/site/date/fisiere/*.pdf") ?: []);
$u = unealta($ks, 'urca_fisier', ['nume' => 'Raport Anual ĂÎ.pdf', 'continut_base64' => base64_encode($pdf)]);
$r = cerere('GET', '/fisiere/raport-anual-ai.pdf');
verifica('Documente', 'un PDF se urcă prin base64: nume curățat, servit la /fisiere/<nume>.pdf, identic, ca application/pdf cu nosniff',
    !$u['eroare'] && ($u['date']['url'] ?? '') === '/fisiere/raport-anual-ai.pdf' && $r['cod'] === 200 && $r['corp'] === $pdf
    && strpos($r['antete']['content-type'] ?? '', 'application/pdf') === 0 && ($r['antete']['x-content-type-options'] ?? '') === 'nosniff'
    && strpos($r['antete']['content-disposition'] ?? '', 'inline') === 0 && !is_dir("$tmp/site/fisiere"), $u['text'] . " / {$r['cod']}");
$r2 = cerere('GET', '/fisiere/raport-anual-ai.pdf', null, ['If-None-Match' => (string) ($r['antete']['etag'] ?? 'x')]);
verifica('Documente', 'documentul are ETag: a doua cerere, cu If-None-Match, primește 304 fără corp', $r2['cod'] === 304 && $r2['corp'] === '', (string) $r2['cod']);
$inainte = $in_fisiere();
$refuzate = [];
foreach (['o imagine PNG' => base64_decode($png), 'un text redenumit' => "nu sunt PDF\n", 'un PDF tăiat (fără %%EOF)' => substr($pdf, 0, 40),
          'cod PHP cu nume de PDF' => "<?php echo 'x'; ?>\n%%EOF"] as $ce => $date) {
    $u = unealta($ks, 'urca_fisier', ['nume' => 'atac.pdf', 'continut_base64' => base64_encode($date)]);
    if (!$u['eroare']) $refuzate[] = "$ce: TRECUT";
}
verifica('Documente', 'doar PDF-uri adevărate: imagine, text redenumit, PDF tăiat, cod PHP — refuzate, nimic scris',
    !$refuzate && $in_fisiere() === $inainte, implode('; ', $refuzate));
$u = unealta($ks, 'urca_fisier', ['nume' => '../../app/config.php', 'continut_base64' => base64_encode($pdf)]);
verifica('Documente', 'un nume cu cale și altă extensie devine un nume curat .pdf, în dosarul documentelor',
    !$u['eroare'] && ($u['date']['url'] ?? '') === '/fisiere/config.pdf' && is_file("$tmp/site/date/fisiere/config.pdf")
    && strpos((string) @file_get_contents("$tmp/site/app/config.php"), '%PDF') === false, $u['text']);
unealta($ks, 'sterge_fisier', ['nume' => 'config.pdf']);
$u = unealta($ks, 'urca_fisier', ['nume' => 'raport-anual-ai.pdf', 'continut_base64' => base64_encode($pdf2)]);
verifica('Documente', 'același nume cu alt conținut e refuzat fără inlocuieste=true, iar documentul rămâne neatins',
    $u['eroare'] && strpos($u['text'], 'inlocuieste') !== false && cerere('GET', '/fisiere/raport-anual-ai.pdf')['corp'] === $pdf, $u['text']);
$u = unealta($ks, 'urca_fisier', ['nume' => 'raport-anual-ai.pdf', 'continut_base64' => base64_encode($pdf2), 'inlocuieste' => true]);
$vechi = glob("$tmp/site/date/versiuni/fisiere/raport-anual-ai.pdf.*") ?: [];
verifica('Documente', 'cu inlocuieste=true: aceeași adresă, conținut nou, versiunea veche păstrată',
    !$u['eroare'] && cerere('GET', '/fisiere/raport-anual-ai.pdf')['corp'] === $pdf2 && count($vechi) === 1 && file_get_contents($vechi[0]) === $pdf, $u['text']);
$u = unealta($ks, 'urca_fisier', ['nume' => 'raport-anual-ai.pdf', 'continut_base64' => base64_encode($pdf2)]);
verifica('Documente', 'același conținut, urcat din nou, nu scrie nimic și nu face versiune', !$u['eroare']
    && strpos($u['text'], 'exista deja') !== false && count(glob("$tmp/site/date/versiuni/fisiere/raport-anual-ai.pdf.*") ?: []) === 1, $u['text']);
file_put_contents("$tmp/depozit/document-din-url.pdf", $pdf);
$u = unealta($ks, 'urca_fisier', ['url' => "http://127.0.0.1:$port_depozit/document-din-url.pdf"]);
verifica('Documente', 'un PDF se urcă după adresă: serverul îl descarcă singur, numele vine din adresă',
    !$u['eroare'] && ($u['date']['url'] ?? '') === '/fisiere/document-din-url.pdf' && cerere('GET', '/fisiere/document-din-url.pdf')['corp'] === $pdf, $u['text']);
$inainte = $in_fisiere();
$refuzate = [];
foreach (['https://127.0.0.1/x.pdf' => '127.0.0.1', 'https://169.254.169.254/latest/meta-data' => 'metadatele cloud',
          'http://example.com/x.pdf' => 'http', "http://127.0.0.1:$port_depozit/text.txt" => 'un fișier care nu e PDF'] as $adresa => $ce) {
    $u = unealta($ks, 'urca_fisier', ['nume' => 'atac', 'url' => $adresa]);
    if (!$u['eroare']) $refuzate[] = "$ce: TRECUT";
}
verifica('Securitate', 'SSRF la documente: aceleași apărări ca la imagini (adrese interne, http, alt tip) — nimic scris',
    !$refuzate && $in_fisiere() === $inainte, implode('; ', $refuzate));
$lista = unealta($kc, 'listeaza_fisiere');
$scriere_cu_citire = unealta($kc, 'urca_fisier', ['nume' => 'x.pdf', 'continut_base64' => base64_encode($pdf)]);
verifica('Documente', 'cheia de citire vede lista documentelor, dar nu poate urca', !$lista['eroare']
    && count($lista['date']['fisiere'] ?? []) === 2 && $scriere_cu_citire['eroare'], $lista['text'] . ' / ' . $scriere_cu_citire['text']);
$coduri = [];
foreach (['/fisiere/../app/config.php', '/fisiere/nu-exista.pdf', '/fisiere/x.php', '/fisiere/Raport.PDF', '/fisiere', '/date/fisiere/raport-anual-ai.pdf'] as $c) {
    $coduri[$c] = cerere('GET', $c)['cod'];
}
verifica('Securitate', 'la /fisiere/ se servesc doar documentele urcate: cale cu .., alt tip, nume inexistent, dosarul de date — 403/404',
    !array_filter($coduri, fn($c) => !in_array($c, [403, 404], true)), json_encode($coduri));
unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => 'cu-document', 'titlu' => 'Cu document',
    'continut_html' => '<p><a href="/fisiere/document-din-url.pdf">Descarcă (PDF)</a></p>']);
$u = unealta($ks, 'sterge_fisier', ['nume' => 'document-din-url.pdf']);
$u2 = unealta($ks, 'sterge_fisier', ['nume' => 'document-din-url.pdf', 'forteaza' => true]);
verifica('Documente', 'ștergerea refuză un document legat dintr-o pagină; cu forteaza=true îl mută între versiuni și adresa dă 404',
    $u['eroare'] && strpos($u['text'], 'pagina/cu-document') !== false && !$u2['eroare'] && cerere('GET', '/fisiere/document-din-url.pdf')['cod'] === 404
    && glob("$tmp/site/date/versiuni/fisiere/document-din-url.pdf.*"), $u['text'] . ' / ' . $u2['text']);
unealta($ks, 'sterge', ['tip' => 'pagina', 'slug' => 'cu-document']);
$u = unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => 'fisiere', 'titlu' => 'X', 'continut_html' => '<p>x</p>']);
$ds = unealta($kc, 'despre_site');
verifica('Documente', 'slugul "fisiere" e rezervat, iar despre_site descrie documentele (adresa, limita, felul de urcare)',
    $u['eroare'] && isset($ds['date']['fisiere_acceptate'], $ds['date']['adrese']['/fisiere/<nume>.pdf']) && ($ds['date']['fisiere'] ?? -1) === 1, $u['text']);
$exp_doc = unealta($kc, 'exporta');
verifica('Documente', 'exportul are documentele, cu amprentă',
    preg_match('/^[a-f0-9]{64}$/', (string) ($exp_doc['date']['fisiere'][0]['amprenta'] ?? '')) === 1, substr($exp_doc['text'], -300));

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
verifica('Export', 'copie.php salvează și documentele PDF, identice cu cele de pe site',
    @file_get_contents("$dosar_copie/fisiere/raport-anual-ai.pdf") === $pdf2 && count(glob("$dosar_copie/fisiere/*.pdf") ?: []) === count($exp_copie['fisiere'] ?? [-1]), $rc['iesire']);

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
verifica('OAuth', 'Claude folosește tokenul pe /mcp și vede toate comenzile', $r['cod'] === 200 && count($r['json']['result']['tools'] ?? []) === 26, $r['corp']);
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
    && count($r2['json']['result']['tools'] ?? []) === 13, $r['corp']);
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

// --- măsurarea cu GA4 (0.10): doar identificatorul, codul îl compune site-ul ---------------------

$u = unealta($ks, 'seteaza_site', ['ga4' => '<script>alert(1)</script>']);
verifica('Securitate', 'la „ga4" nu se poate trimite cod, doar identificatorul', $u['eroare'], $u['text']);
$u = unealta($ks, 'seteaza_site', ['ga4' => 'UA-12345-1']);
verifica('Securitate', 'un identificator care nu e GA4 e refuzat', $u['eroare'], $u['text']);

$u = unealta($ks, 'seteaza_site', ['ga4' => 'G-PROBA12345']);
$r = cerere('GET', '/');
$nonce_pagina = preg_match('/<script nonce="([^"]+)">window\.dataLayer/', $r['corp'], $mn) ? $mn[1] : '';
verifica('Măsurare', 'eticheta GA4 apare pe paginile publice, cu nonce, nu ca script liber',
    !$u['eroare'] && strpos($r['corp'], 'googletagmanager.com/gtag/js?id=G-PROBA12345') !== false
    && $nonce_pagina !== '' && strpos($r['corp'], "gtag('config','G-PROBA12345')") !== false, $u['text']);
$csp_ga = $r['antete']['content-security-policy'] ?? '';
verifica('Măsurare', 'CSP primește singur sursele de care are nevoie măsurarea, fără unsafe-inline',
    strpos($csp_ga, 'script-src') !== false && strpos($csp_ga, 'https://www.googletagmanager.com') !== false
    && strpos($csp_ga, 'https://*.google-analytics.com') !== false && strpos($csp_ga, 'unsafe-inline') === false, $csp_ga);

$r = cerere('POST', '/jurnal.php', http_build_query(['cheie' => $kc]), ['Content-Type' => 'application/x-www-form-urlencoded']);
$r2 = cerere('GET', '/cauta?q=test');
verifica('Măsurare', 'paginile care nu se indexează (jurnal, căutare) nu se măsoară',
    strpos($r['corp'], 'googletagmanager') === false && strpos($r2['corp'], 'googletagmanager') === false);

$u = unealta($ks, 'seteaza_site', ['ga4' => '']);
$r = cerere('GET', '/');
$csp_fara = $r['antete']['content-security-policy'] ?? '';
verifica('Măsurare', 'fără identificator nu se încarcă nimic și CSP-ul rămâne strâns',
    !$u['eroare'] && strpos($r['corp'], 'googletagmanager') === false
    && strpos($csp_fara, 'googletagmanager') === false, $csp_fara);

// --- 0.11: ce avea cinesunt.info și îi trebuie oricărui site ------------------------------------

$u = unealta($ks, 'seteaza_site', ['subsol' => 'Nu oferim <b>consiliere</b>; ținta o alegi tu.', 'nume_articole' => 'ghiduri']);
$r = cerere('GET', '/');
$subsol = preg_match('#<footer.*?</footer>#s', $r['corp'], $mf) ? $mf[0] : '';
verifica('Site', 'textul din subsol apare pe pagină, fără HTML', !$u['eroare'] && strpos($subsol, '<p>Nu oferim consiliere; ținta o alegi tu.</p>') !== false, $u['text']);
verifica('SEO', 'textul din subsol ajunge și în llms.txt', strpos(cerere('GET', '/llms.txt')['corp'], 'ținta o alegi tu') !== false);
verifica('Site', 'articolele se pot numi altfel: în meniu, pe prima pagină, în listă',
    strpos($r['corp'], '>Ghiduri</a>') !== false && strpos($r['corp'], 'Ultimele ghiduri') !== false && strpos($r['corp'], 'Toate ghidurile') !== false
    && strpos(cerere('GET', '/articole')['corp'], '<h1>Ghiduri</h1>') !== false);
$u = unealta($ks, 'seteaza_site', ['nume_articole' => 'Ghiduri <script>']);
$u2 = unealta($ks, 'seteaza_site', ['arata_data' => 'poate']);
verifica('Securitate', 'numele articolelor e un cuvânt cu litere mici, iar arata_data doar "da" sau ""', $u['eroare'] && $u2['eroare'], $u['text'] . ' / ' . $u2['text']);
$u = unealta($kc, 'despre_site');
verifica('Teme', 'despre_site arată ce spune fiecare temă despre ea (primul comentariu din foaie)',
    strpos((string) ($u['date']['teme']['despre']['simpluspv'] ?? ''), 'simpluspv') !== false, substr($u['text'], 0, 300));

foreach ([['legat-unu', 'Decizii', '2026-01-02 10:00'], ['legat-doi', 'Timp', '2026-01-03 10:00'], ['legat-trei', 'Decizii', '2026-01-01 10:00']] as [$s, $t, $la]) {
    unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => $s, 'titlu' => "Titlu $s", 'continut_html' => '<p>Text.</p>', 'etichete' => [$t], 'imagine' => $url_img]);
    unealta($ks, 'publica', ['tip' => 'articol', 'slug' => $s, 'la' => $la]);
}
$u = unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'cu-de-toate', 'titlu' => 'Cu de toate', 'etichete' => ['Decizii'], 'imagine' => $url_img,
    'continut_html' => '<p>Început.</p><figure><img src="' . $url_img . '" alt="Comparație"></figure><pre>Ești asistentul meu.</pre>'
        . '<h2>Întrebări frecvente</h2><h3>Prima întrebare?</h3><p>Primul răspuns.</p><h3>A doua întrebare?</h3><p>Al doilea răspuns.</p>'
        . '<aside class="final-articol"><p>Scrie-mi pe WhatsApp.</p></aside>']);
unealta($ks, 'publica', ['tip' => 'articol', 'slug' => 'cu-de-toate']);
$r = cerere('GET', '/cu-de-toate');
$legate = preg_match('#<aside class="legate">.*?</aside>#s', $r['corp'], $ml) ? $ml[0] : '';
verifica('Site', '„Citește mai departe": întâi articolele cu aceeași etichetă', strpos($legate, 'href="/legat-unu"') !== false
    && strpos($legate, 'href="/legat-trei"') !== false && strpos($legate, 'href="/legat-doi"') === false, $legate);
verifica('Site', 'coperta nu se repetă sus când imaginea e deja în text, dar rămâne imaginea de distribuire',
    strpos($r['corp'], 'class="coperta"') === false && strpos($r['corp'], 'property="og:image"') !== false
    && strpos(cerere('GET', '/legat-unu')['corp'], 'class="coperta"') !== false);
verifica('Site', 'fiecare pagină își spune tipul pe <body> (tema o poate așeza diferit)',
    strpos($r['corp'], '<body class="pagina-articol">') !== false && strpos(cerere('GET', '/')['corp'], '<body class="pagina-acasa">') !== false);
verifica('Site', 'etichetele poartă clasa lor, pe articol și pe carduri', strpos($r['corp'], 'class="eticheta eticheta-decizii"') !== false
    && strpos(cerere('GET', '/articole')['corp'], 'class="card-eticheta eticheta-decizii"') !== false);
verifica('Site', 'pagina etichetei are numele ei ca titlu', strpos(cerere('GET', '/eticheta/decizii')['corp'], '<h1>Decizii</h1>') !== false);
verifica('Site', 'butonul „Copiază" vine din șablon, cu nonce, doar pe paginile cu <pre>',
    preg_match('/<script nonce="[^"]+">\s*document\.querySelectorAll\(\'main pre\'\)/', $r['corp']) === 1
    && strpos(cerere('GET', '/legat-unu')['corp'], "querySelectorAll('main pre')") === false);
$faq = ld_de_tip(jsonld_din($r['corp']), 'FAQPage')['mainEntity'] ?? [];
verifica('SEO', 'blocul de la finalul articolului nu se lipește de ultimul răspuns din întrebările frecvente',
    count($faq) === 2 && ($faq[1]['acceptedAnswer']['text'] ?? '') === 'Al doilea răspuns.', json_encode($faq, JSON_UNESCAPED_UNICODE));
verifica('Site', 'data nu apare implicit', strpos($r['corp'], 'data-publicarii') === false && strpos(cerere('GET', '/articole')['corp'], '<p class="data">') === false);
$u = unealta($ks, 'seteaza_site', ['arata_data' => 'da']);
$r = cerere('GET', '/legat-unu');
verifica('Site', 'cu arata_data = "da", data apare pe articol și pe carduri', !$u['eroare'] && strpos($r['corp'], '<span class="data-publicarii">2 ianuarie 2026</span>') !== false
    && strpos(cerere('GET', '/articole')['corp'], '<p class="data">2 ianuarie 2026</p>') !== false, $u['text']);
unealta($ks, 'seteaza_site', ['subsol' => '', 'nume_articole' => '', 'arata_data' => '']);
foreach (['legat-unu', 'legat-doi', 'legat-trei', 'cu-de-toate'] as $s) unealta($ks, 'sterge', ['tip' => 'articol', 'slug' => $s]);
verifica('Site', 'fără nume ales, articolele se numesc din nou „articole", iar subsolul dispare',
    strpos(cerere('GET', '/')['corp'], 'Toate articolele') !== false && strpos(cerere('GET', '/')['corp'], 'nota-subsol') === false);

// --- 0.15: blocurile comune și paginile de pornire ------------------------------------------------

$u = unealta($kc, 'despre_site');
$blocuri = $u['date']['blocuri']['lista'] ?? [];
verifica('Pornire', 'despre_site arată cele 9 blocuri comune, fiecare cu rostul și un exemplu', count($blocuri) === 9
    && count(array_filter($blocuri, fn($b) => ($b['cand'] ?? '') !== '' && ($b['html'] ?? '') !== '')) === 9, substr($u['text'], 0, 300));
$css_baza = (string) file_get_contents("$tmp/site/assets/stil.css");
$clase_lipsa = [];
foreach ($blocuri as $b) {
    preg_match_all('/class="([^"]+)"/', (string) $b['html'], $mc);
    foreach (preg_split('/\s+/', implode(' ', $mc[1])) ?: [] as $cl) if ($cl !== '' && strpos($css_baza, ".$cl") === false) $clase_lipsa[] = $cl;
}
verifica('Pornire', 'fiecare clasă din exemple are reguli în stil.css, deci blocul merge sub orice temă', !$clase_lipsa, implode(', ', array_unique($clase_lipsa)));
$u = unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => 'proba-blocuri', 'titlu' => 'Proba blocurilor',
    'continut_html' => implode("\n", array_column($blocuri, 'html'))]);
verifica('Pornire', 'toate exemplele de blocuri trec prin filtrul HTML neatinse', !$u['eroare'] && ($u['date']['curatari'] ?? ['?']) === [],
    json_encode($u['date']['curatari'] ?? $u['text'], JSON_UNESCAPED_UNICODE));
unealta($ks, 'sterge', ['tip' => 'pagina', 'slug' => 'proba-blocuri']);

$ga4_initial = (string) (unealta($kc, 'despre_site')['date']['site']['ga4'] ?? '');
unealta($ks, 'seteaza_site', ['ga4' => '']);
$u = unealta($kc, 'pagini_de_pornire');
$schelete = $u['date']['pagini'] ?? [];
$exista_bine = array_filter($schelete, fn($p) => $p['exista_deja'] === (is_file("$tmp/site/date/pagini/{$p['slug']}.json") || is_file("$tmp/site/date/articole/{$p['slug']}.json")));
verifica('Pornire', 'pagini_de_pornire, cu cheia de citire: cele 5 schelete, fiecare cu locuri de completat, și știe ce e deja pe site',
    !$u['eroare'] && array_column($schelete, 'slug') === ['acasa', 'despre', 'servicii', 'contact', 'confidentialitate']
    && count($exista_bine) === 5 && min(array_column($schelete, 'locuri_de_completat') ?: [0]) > 0, substr($u['text'], 0, 300));
$conf = (string) (array_column($schelete, 'continut_html', 'slug')['confidentialitate'] ?? '');
verifica('Pornire', 'confidențialitatea spune doar ce e pornit: fără GA4 și fără Cloudflare, nimic despre ele',
    strpos($conf, 'cookie-uri proprii') !== false && strpos($conf, 'Google Analytics') === false && strpos($conf, 'Cloudflare') === false, $conf);
unealta($ks, 'seteaza_site', ['ga4' => 'G-TESTPORNIRE1']);
$u = unealta($kc, 'pagini_de_pornire');
$conf = (string) (array_column($u['date']['pagini'] ?? [], 'continut_html', 'slug')['confidentialitate'] ?? '');
verifica('Pornire', 'cu GA4 pornit, pagina îl numește, iar AI-ul e atenționat că lipsește acordul pentru cookie-uri',
    strpos($conf, 'Google Analytics') !== false && ($u['date']['atentie'] ?? []) !== [], json_encode($u['date']['atentie'] ?? null, JSON_UNESCAPED_UNICODE));
unealta($ks, 'seteaza_site', ['ga4' => $ga4_initial]);
$r = mcp($kc, 'tools/call', ['name' => 'pagini_de_pornire', 'arguments' => new stdClass()], 1, ['CF-Ray' => '8f00aa11bb22cc33-OTP']);
$conf = (string) (array_column(json_decode((string) ($r['json']['result']['content'][0]['text'] ?? '{}'), true)['pagini'] ?? [], 'continut_html', 'slug')['confidentialitate'] ?? '');
verifica('Pornire', 'în spatele Cloudflare (antetul CF-Ray, chiar dacă găzduirea pune IP-ul real în REMOTE_ADDR), pagina îl numește',
    strpos($conf, 'Cloudflare') !== false, substr($conf, 0, 200));

$curate = 0;
foreach ($schelete as $p) {
    $u = unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => "pornire-{$p['slug']}", 'titlu' => $p['titlu'], 'descriere' => $p['descriere'],
        'continut_html' => $p['continut_html']]);
    if (!$u['eroare'] && ($u['date']['curatari'] ?? ['?']) === [] && count($u['date']['locuri_de_completat'] ?? []) > 0) $curate++;
}
verifica('Pornire', 'scheletele se salvează ca ciorne, neatinse de filtru, iar răspunsul arată locurile rămase', $curate === 5, "$curate din 5");
$u = unealta($ks, 'publica', ['tip' => 'pagina', 'slug' => 'pornire-servicii']);
verifica('Pornire', 'un schelet necompletat nu se poate publica', $u['eroare'] && strpos($u['text'], 'locuri de completat') !== false
    && cerere('GET', '/pornire-servicii')['cod'] === 404, $u['text']);
$completat = fn(string $h) => (string) preg_replace_callback('/\[\[COMPLETEAZ[ĂA][^\]]*\]\]/u', function () { static $n = 0; return 'Text real ' . ++$n; }, $h);
$serv = array_column($schelete, null, 'slug')['servicii'];
$u = unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => 'pornire-servicii', 'titlu' => 'Servicii', 'descriere' => $completat($serv['descriere']),
    'continut_html' => $completat($serv['continut_html'])]);
$u2 = unealta($ks, 'publica', ['tip' => 'pagina', 'slug' => 'pornire-servicii']);
$r = cerere('GET', '/pornire-servicii');
$faq = ld_de_tip(jsonld_din($r['corp']), 'FAQPage')['mainEntity'] ?? [];
verifica('Pornire', 'completat, se publică: pașii și chemarea apar, iar întrebările frecvente devin FAQPage, fără chemarea de la final',
    !$u2['eroare'] && $r['cod'] === 200 && strpos($r['corp'], '<ol class="bloc-pasi">') !== false && strpos($r['corp'], '<aside class="bloc-actiune">') !== false
    && count($faq) === 3 && strpos(json_encode($faq, JSON_UNESCAPED_UNICODE), 'Scrie-ne') === false, $u2['text'] . ' ' . json_encode($faq, JSON_UNESCAPED_UNICODE));
$u = unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => 'pornire-servicii', 'continut_html' => '<p>[[COMPLETEAZĂ: ceva]]</p>']);
verifica('Pornire', 'pe o pagină publicată, un loc de completat e refuzat înainte să ajungă pe site',
    $u['eroare'] && strpos(cerere('GET', '/pornire-servicii')['corp'], 'COMPLETEAZ') === false, $u['text']);
foreach ($schelete as $p) unealta($ks, 'sterge', ['tip' => 'pagina', 'slug' => "pornire-{$p['slug']}"]);

// --- 0.12: imagini fără base64 prin conversație (după adresă, din browser, de pe calculator) ------------

$poza = base64_decode($png);
file_put_contents("$tmp/depozit/poza-din-url.png", $poza);
file_put_contents("$tmp/depozit/text.txt", "nu sunt o imagine\n");
file_put_contents("$tmp/depozit/muta.php", "<?php header('Location: /poza-din-url.png', true, 302);");
file_put_contents("$tmp/depozit/muta-intern.php", "<?php header('Location: http://127.0.0.1:1/x.png', true, 302);");
$in_media = fn() => count(glob("$tmp/site/media/*") ?: []);
$u = unealta($ks, 'urca_imagine', ['url' => "http://127.0.0.1:$port_depozit/poza-din-url.png"]);
verifica('Imagini', 'o imagine se urcă după adresă: serverul o descarcă singur, numele vine din adresă',
    !$u['eroare'] && preg_match('#^/media/poza-din-url-[a-f0-9]{8}\.png$#', (string) ($u['date']['url'] ?? '')) === 1
    && cerere('GET', (string) ($u['date']['url'] ?? ''))['corp'] === $poza, $u['text']);
$u = unealta($ks, 'urca_imagine', ['nume' => 'dupa-mutare', 'url' => "http://127.0.0.1:$port_depozit/muta.php"]);
verifica('Imagini', 'o redirecționare spre aceeași gazdă se urmărește', !$u['eroare'] && strpos((string) ($u['date']['url'] ?? ''), '/media/dupa-mutare-') === 0, $u['text']);
$inainte = $in_media();
$refuzate = [];
foreach (["http://127.0.0.1:$port_depozit/muta-intern.php" => 'redirecționare spre o adresă internă',
          'http://example.com/poza.png' => 'http, nu https', 'https://127.0.0.1/poza.png' => '127.0.0.1',
          'https://localhost/poza.png' => 'localhost', 'https://10.1.2.3/poza.png' => 'rețea privată',
          'https://169.254.169.254/latest/meta-data' => 'metadatele cloud', 'https://[::1]/poza.png' => 'IPv6 local',
          'https://[::ffff:127.0.0.1]/poza.png' => 'IPv4 ascuns în IPv6', 'https://2130706433/poza.png' => '127.0.0.1 scris ca număr',
          'https://exemplu.ro:8443/poza.png' => 'alt port', 'https://exemplu.ro/o poza.png' => 'spațiu în adresă',
          'https://user:parola@exemplu.ro/poza.png' => 'cont în adresă', 'file:///etc/passwd' => 'fișier local',
          "http://127.0.0.1:$port_depozit/text.txt" => 'un fișier care nu e imagine'] as $adresa => $ce) {
    $u = unealta($ks, 'urca_imagine', ['nume' => 'atac', 'url' => $adresa]);
    if (!$u['eroare']) $refuzate[] = "$ce: TRECUT";
}
verifica('Securitate', 'SSRF: adrese interne, IPv6 local, IP scris ca număr, alt port, http, redirecționare spre intern — toate refuzate, nimic scris',
    !$refuzate && $in_media() === $inainte, implode('; ', $refuzate));
// IPv4 ascuns în IPv6 sub alte forme decât ::ffff:. Se cere motivul exact: un refuz la conectare ar trece testul și pe codul vechi.
$scapate = [];
foreach (['::127.0.0.1', '::a9fe:a9fe', '::ffff:0:7f00:1', '2002:7f00:1::1', 'fe80::1'] as $ip6) {
    $u = unealta($ks, 'urca_imagine', ['nume' => 'atac', 'url' => "https://[$ip6]/poza.png"]);
    if (strpos($u['text'], 'adresă internă') === false) $scapate[] = "$ip6: {$u['text']}";
}
verifica('Securitate', 'SSRF: la IPv6 trec doar adresele globale — ::127.0.0.1, ::a9fe:a9fe (169.254.169.254), ::ffff:0:7f00:1, 6to4 refuzate înainte de conectare',
    !$scapate && $in_media() === $inainte, implode('; ', $scapate));
@unlink("$tmp/depozit/muta.php");   // ajutoarele serverului de probă; verificarea de la final caută orice .php nou
@unlink("$tmp/depozit/muta-intern.php");
$u = unealta($ks, 'urca_imagine', ['nume' => 'x', 'url' => "http://127.0.0.1:$port_depozit/poza-din-url.png", 'continut_base64' => $png]);
$u2 = unealta($ks, 'urca_imagine', ['nume' => 'x']);
verifica('Imagini', 'urca_imagine cere exact una dintre „url" și „continut_base64"', $u['eroare'] && $u2['eroare'], $u['text']);

// din browser, cu cheia de scriere
$multipart = function (array $campuri, array $fisiere): array {
    $b = '----minicms' . bin2hex(random_bytes(8));
    $corp = '';
    foreach ($campuri as $k => $v) $corp .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
    foreach ($fisiere as [$nume, $date]) $corp .= "--$b\r\nContent-Disposition: form-data; name=\"poze[]\"; filename=\"$nume\"\r\nContent-Type: image/png\r\n\r\n$date\r\n";
    return [$corp . "--$b--\r\n", ['Content-Type' => "multipart/form-data; boundary=$b"]];
};
$r = cerere('GET', '/imagini.php');
verifica('Imagini', 'pagina de urcare din browser există, nu se indexează și cere cheia', $r['cod'] === 200 && strpos($r['corp'], 'name="poze[]"') !== false
    && strpos($r['corp'], 'noindex') !== false && strpos((string) ($r['antete']['x-robots-tag'] ?? ''), 'noindex') !== false
    && strpos(cerere('GET', '/robots.txt')['corp'], 'Disallow: /imagini.php') !== false);
$inainte = $in_media();
[$corp, $ant] = $multipart(['cheie' => $kc], [['citire.png', $poza]]);
$r = cerere('POST', '/imagini.php', $corp, $ant);
[$corp, $ant] = $multipart(['cheie' => 'mcms_s_cheie-gresita-dar-lunga-destul'], [['gresit.png', $poza]]);
$r2 = cerere('POST', '/imagini.php', $corp, $ant);
elibereaza();
verifica('Securitate', 'din browser: cheia de citire e refuzată (403), o cheie greșită la fel (401), nimic scris',
    $r['cod'] === 403 && $r2['cod'] === 401 && $in_media() === $inainte, "{$r['cod']} / {$r2['cod']}");
[$corp, $ant] = $multipart(['cheie' => $ks], [['Poză de pe telefon.png', $poza], ['nu-e-imagine.png', 'text simplu']]);
$r = cerere('POST', '/imagini.php', $corp, $ant);
$jurnal_img = array_filter(array_map(fn($l) => json_decode($l, true),
    file("$tmp/site/date/jurnal/" . date('Y-m') . '.ndjson', FILE_IGNORE_NEW_LINES) ?: []), fn($j) => ($j['punct'] ?? '') === 'imagini' && ($j['cerere'] ?? '') === 'urcare');
verifica('Imagini', 'din browser, cu cheia de scriere: imaginea urcă, fișierul greșit e refuzat pe rând, totul în jurnal',
    $r['cod'] === 200 && preg_match('#/media/poza-de-pe-telefon-[a-f0-9]{8}\.png#', $r['corp']) === 1
    && strpos($r['corp'], 'nu-e-imagine.png') !== false && count($jurnal_img) >= 2, substr(strip_tags($r['corp']), 0, 400));

// linkul de urcare (0.13): AI-ul îl cere, omul alege poza fără nicio cheie
$u = unealta($kc, 'link_urcare');
verifica('Chei', 'cheia de citire nu poate cere un link de urcare', $u['eroare'], $u['text']);
$u = unealta($ks, 'link_urcare', ['minute' => 30]);
$link = (string) ($u['date']['url'] ?? '');
parse_str((string) parse_url($link, PHP_URL_QUERY), $q_link);
$r = cerere('GET', cale_din($link));
verifica('Imagini', 'linkul de urcare deschide pagina fără câmpul de cheie', !$u['eroare'] && $r['cod'] === 200
    && strpos($r['corp'], 'name="cheie"') === false && strpos($r['corp'], 'name="s"') !== false, $u['text']);
[$corp, $ant] = $multipart(['e' => (string) ($q_link['e'] ?? ''), 'c' => (string) ($q_link['c'] ?? ''), 's' => (string) ($q_link['s'] ?? '')], [['Poza din link.png', $poza]]);
$r = cerere('POST', '/imagini.php', $corp, $ant);
$nume_imagini = array_column((array) (unealta($kc, 'listeaza_imagini')['date']['imagini'] ?? []), 'nume');
verifica('Imagini', 'cu linkul, poza urcă fără cheie, iar AI-ul o găsește cu listeaza_imagini', $r['cod'] === 200 && strpos($r['corp'], 'Gata.') !== false
    && preg_grep('#^poza-din-link-[a-f0-9]{8}\.png$#', $nume_imagini), substr(strip_tags($r['corp']), 0, 300));
$inainte = $in_media();
$slug_prev = (string) ((unealta($kc, 'listeaza', ['tip' => 'articol'])['date']['articole'][0]['slug']) ?? '');
parse_str((string) parse_url((string) (unealta($ks, 'previzualizeaza', ['tip' => 'articol', 'slug' => $slug_prev])['date']['url'] ?? ''), PHP_URL_QUERY), $q_prev);
$coduri_link = [];
foreach ([['e' => (string) ($q_link['e'] ?? ''), 's' => str_repeat('0', 64)], ['e' => (string) (time() - 60), 's' => (string) ($q_link['s'] ?? '')],
          ['e' => (string) ($q_prev['e'] ?? ''), 's' => (string) ($q_prev['s'] ?? '')]] as $falsificat) {
    [$corp, $ant] = $multipart($falsificat, [['fals.png', $poza]]);
    $coduri_link[] = cerere('POST', '/imagini.php', $corp, $ant)['cod'];
}
elibereaza();
verifica('Securitate', 'un link de urcare falsificat, expirat sau luat dintr-o previzualizare e refuzat (403), nimic scris',
    $coduri_link === [403, 403, 403] && $in_media() === $inainte && $slug_prev !== '', implode(',', $coduri_link));

// capturile de telefon (imagini pe verticală) nu se întind pe toată lățimea: coperta, conținutul, cardurile
$portret = (string) (unealta($ks, 'urca_imagine', ['nume' => 'captura telefon.png',
    'continut_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAAECAIAAAArjXluAAAAFUlEQVR42mM8ISfHwMDAxMDAgEEBABueAQxuJioXAAAAAElFTkSuQmCC'])['date']['url'] ?? '');
$portret2 = (string) (unealta($ks, 'urca_imagine', ['nume' => 'alta captura.png',   // coperta nu se repetă în text, deci în text alt fișier
    'continut_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAAECAIAAAArjXluAAAAFUlEQVR42mM8ISfHwMDAxMDAgEEBABueAQxuJioXAAAAAElFTkSuQmCC'])['date']['url'] ?? '');
unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'de-pe-telefon', 'titlu' => 'De pe telefon', 'imagine' => $portret, 'etichete' => ['Telefon'],
    'continut_html' => '<p>Text.</p><p><img src="' . $portret2 . '" alt="captură"></p><figure><img src="' . $url_img . '" alt="pătrată"></figure>']);
unealta($ks, 'publica', ['tip' => 'articol', 'slug' => 'de-pe-telefon']);
$r = cerere('GET', '/de-pe-telefon');
verifica('Imagini', 'o captură de telefon ca copertă nu se întinde pe toată lățimea (coperta-portret)', strpos($r['corp'], 'class="coperta coperta-portret"') !== false);
verifica('Imagini', 'în text, doar imaginile pe verticală primesc clasa portret; ce e salvat nu se schimbă',
    preg_match('#<img class="portret" src="' . preg_quote($portret2, '#') . '"#', $r['corp']) === 1
    && strpos($r['corp'], '<img src="' . $url_img . '" alt="pătrată">') !== false
    && strpos((string) (unealta($kc, 'citeste', ['tip' => 'articol', 'slug' => 'de-pe-telefon'])['date']['continut_html'] ?? ''), 'portret') === false);
verifica('Imagini', 'pe carduri, captura se arată de sus, nu de la mijloc', strpos(cerere('GET', '/eticheta/telefon')['corp'], 'class="portret" alt=""') !== false);
unealta($ks, 'sterge', ['tip' => 'articol', 'slug' => 'de-pe-telefon']);

// de pe calculator, cu unealta: fără base64 prin conversație, fără cheie pe ecran
file_put_contents("$tmp/de-pe-calculator.png", $poza);
$rc = unealta_locala('urca-imagine.php', [$url, "$tmp/de-pe-calculator.png", '--local', "--dosar=$copii"]);
verifica('Imagini', 'unealta de pe calculator urcă fișierul și scrie adresa /media/..., fără să arate cheia',
    $rc['cod'] === 0 && preg_match('#/media/de-pe-calculator-[a-f0-9]{8}\.png#', $rc['iesire']) === 1 && strpos($rc['iesire'], $ks) === false, $rc['iesire']);
$rc = unealta_locala('urca-imagine.php', [$url, "$tmp/nu-exista.png", '--local', "--dosar=$copii"]);
verifica('Imagini', 'unealta spune limpede când un fișier lipsește', $rc['cod'] === 1 && strpos($rc['iesire'], 'nu există') !== false, $rc['iesire']);

// --- actualizarea codului de pe depozit (0.9) ----------------------------------------------------

elibereaza();
function actualizare(string $cheie, array $date): array
{
    $r = cerere('POST', '/actualizare.php', json_encode($date),
        ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Authorization' => 'Bearer ' . $cheie]);
    $r['json'] = json_decode($r['corp'], true) ?? [];
    return $r;
}
function fa_pachet_proba(array $schimba, array $in_plus = []): void   // pachetul pe care „depozitul" de probă îl servește
{
    global $tmp;
    static $n = 0;
    $n++;
    sterge_dosar("$tmp/pachet");
    copiaza_dosar("$tmp/site", "$tmp/pachet/mini-cms-mcp-main/site", ['date', 'media', 'app/config.php']);
    $rad = "$tmp/pachet/mini-cms-mcp-main/site";
    foreach ($schimba as $rel => $continut) {
        @mkdir(dirname("$rad/$rel"), 0755, true);
        file_put_contents("$rad/$rel", $continut);
    }
    foreach ($in_plus as $rel => $continut) {   // fișiere din afara dosarului site/: nu au voie să iasă de acolo
        @mkdir(dirname("$tmp/pachet/mini-cms-mcp-main/$rel"), 0755, true);
        file_put_contents("$tmp/pachet/mini-cms-mcp-main/$rel", $continut);
    }
    $zip_proba = "$tmp/pachet-$n.zip";   // nume nou de fiecare dată: Phar ține arhivele în cache, pe nume
    $phar = new PharData($zip_proba, 0, null, Phar::ZIP);
    $phar->buildFromDirectory("$tmp/pachet");
    copy($zip_proba, "$tmp/depozit/pachet.bin");
}

$nucleu_test = (string) file_get_contents("$tmp/site/app/nucleu.php");
$nucleu_nou = (string) preg_replace("/MINICMS_VERSIUNE = '[^']+'/", "MINICMS_VERSIUNE = '9.9.9'", $nucleu_test);
fa_pachet_proba(['app/nucleu.php' => $nucleu_nou, 'assets/proba-actualizare.txt' => 'pachetul nou a ajuns']);

$r = actualizare($ks, ['actiune' => 'stare']);
verifica('Securitate', 'actualizarea codului NU se deschide cu cheia de scriere (cea a AI-ului)',
    $r['cod'] === 401 && strpos((string) ($r['json']['eroare'] ?? ''), 'cheia de scriere') !== false, $r['corp']);
$r = actualizare($kc, ['actiune' => 'stare']);
verifica('Securitate', 'nici cu cheia de citire', $r['cod'] === 401, "cod {$r['cod']}");
$r = cerere('GET', '/actualizare.php');
verifica('Actualizare', 'pagina cere cheia de cod și nu arată nimic fără ea',
    $r['cod'] === 200 && strpos($r['corp'], 'Cheia de cod') !== false && strpos($r['corp'], 'name="cheie"') !== false, "cod {$r['cod']}");
$cfg_act = (string) file_get_contents("$tmp/site/app/config.php");
file_put_contents("$tmp/site/app/config.php", str_replace('return array (', "return array (\n  'actualizare' => false,", $cfg_act));
$r = cerere('GET', '/actualizare.php');
$r2 = actualizare($kd, ['actiune' => 'stare']);
file_put_contents("$tmp/site/app/config.php", $cfg_act);
verifica('Actualizare', "cu 'actualizare' => false pagina nu mai există nici la GET (404, fără formular), iar comanda primește motivul",
    $r['cod'] === 404 && strpos($r['corp'], 'name="cheie"') === false
    && $r2['cod'] === 404 && strpos((string) ($r2['json']['eroare'] ?? ''), 'oprită') !== false, "cod {$r['cod']} / {$r2['cod']}: {$r2['corp']}");

$r = actualizare($kd, ['actiune' => 'stare']);
$stare_cod = (array) ($r['json']['stare'] ?? []);
verifica('Actualizare', 'cu cheia de cod: spune ce versiune e în depozit și ce fișiere s-ar schimba',
    $r['cod'] === 200 && ($stare_cod['versiune_in_pachet'] ?? '') === '9.9.9'
    && in_array('app/nucleu.php', (array) ($stare_cod['de_schimbat'] ?? []), true)
    && in_array('assets/proba-actualizare.txt', (array) ($stare_cod['noi'] ?? []), true), $r['corp']);
verifica('Actualizare', 'starea nu scrie nimic pe server (versiunea de pe disc e neatinsă)',
    (string) file_get_contents("$tmp/site/app/nucleu.php") === $nucleu_test && !is_file("$tmp/site/assets/proba-actualizare.txt"));

$r = actualizare($kd, ['actiune' => 'sincronizeaza']);
$rez_cod = (array) ($r['json']['rezultat'] ?? []);
$copie_cod = (string) ($rez_cod['copie'] ?? '');
verifica('Actualizare', 'sincronizarea scrie fișierele, cu copie de siguranță și autocontrol',
    $r['cod'] === 200 && ($rez_cod['operatie'] ?? '') === 'actualizat' && (int) ($rez_cod['scrise'] ?? 0) >= 2
    && preg_match('/^[0-9]{8}-[0-9]{6}$/', $copie_cod) === 1
    && strpos((string) file_get_contents("$tmp/site/app/nucleu.php"), "'9.9.9'") !== false
    && (string) @file_get_contents("$tmp/site/assets/proba-actualizare.txt") === 'pachetul nou a ajuns', $r['corp']);
verifica('Actualizare', 'copia de siguranță conține versiunea dinainte',
    strpos((string) @file_get_contents("$tmp/site/date/versiuni/cod/$copie_cod/app/nucleu.php"), "'9.9.9'") === false
    && is_file("$tmp/site/date/versiuni/cod/$copie_cod/_versiune.txt"));
$r = cerere('GET', '/');
verifica('Actualizare', 'site-ul răspunde în continuare după actualizare', $r['cod'] === 200, "cod {$r['cod']}");

$r = actualizare($kd, ['actiune' => 'sincronizeaza']);
verifica('Actualizare', 'a doua oară nu mai are ce schimba', ($r['json']['rezultat']['operatie'] ?? '') === 'nimic de schimbat', $r['corp']);

$r = actualizare($kd, ['actiune' => 'restaureaza', 'copie' => $copie_cod]);
verifica('Actualizare', 'restaurarea pune înapoi versiunea dinainte',
    $r['cod'] === 200 && (string) file_get_contents("$tmp/site/app/nucleu.php") === $nucleu_test, $r['corp']);

fa_pachet_proba(['rau.sh' => 'rm -rf /'], ['in-afara.php' => '<?php echo "nu ai voie";']);
$r = actualizare($kd, ['actiune' => 'sincronizeaza']);
verifica('Securitate', 'un pachet cu fișiere din afara listei permise e refuzat, fără să scrie nimic',
    $r['cod'] === 400 && strpos((string) ($r['json']['eroare'] ?? ''), 'extensie nepermisă') !== false
    && !is_file("$tmp/site/rau.sh") && !is_file("$tmp/in-afara.php"), $r['corp']);

fa_pachet_proba(['app/config.php' => '<?php return ["chei" => ["scriere" => "al atacatorului"]];']);
$r = actualizare($kd, ['actiune' => 'sincronizeaza']);
$config_test = (string) file_get_contents("$tmp/site/app/config.php");
verifica('Securitate', 'app/config.php nu se atinge nici dacă vine în pachet',
    strpos($config_test, 'al atacatorului') === false && strpos($config_test, hash('sha256', $ks)) !== false, substr($config_test, 0, 120));

$nucleu_vechi = (string) preg_replace("/MINICMS_VERSIUNE = '[^']+'/", "MINICMS_VERSIUNE = '0.0.1'", $nucleu_test);
fa_pachet_proba(['app/nucleu.php' => $nucleu_vechi]);
$r = actualizare($kd, ['actiune' => 'sincronizeaza']);
verifica('Securitate', 'o versiune mai veche decât cea instalată e refuzată (fără forta)',
    $r['cod'] === 400 && strpos((string) ($r['json']['eroare'] ?? ''), 'mai veche') !== false
    && (string) file_get_contents("$tmp/site/app/nucleu.php") === $nucleu_test, $r['corp']);

// comanda de pe calculator, cap-coadă: cere starea, sincronizează, verifică din afară, apoi pune înapoi
fa_pachet_proba(['app/nucleu.php' => $nucleu_nou, 'assets/proba-actualizare.txt' => 'pachetul nou a ajuns']);
$dosar_act = "$tmp/actualizare-unealta";
@mkdir($dosar_act, 0755, true);
file_put_contents("$dosar_act/chei-127-0-0-1.json", json_encode([
    'citire' => ['cheie' => $kc, 'amprenta' => hash('sha256', $kc)],
    'scriere' => ['cheie' => $ks, 'amprenta' => hash('sha256', $ks)],
    'cod' => ['cheie' => $kd, 'amprenta' => hash('sha256', $kd)]]));
$rc = unealta_locala('actualizeaza.php', [$url, '--local', '--acum', "--dosar=$dosar_act"]);
verifica('Actualizare', 'comanda de pe calculator face tot drumul: stare, sincronizare, verificare din afară',
    $rc['cod'] === 0 && strpos($rc['iesire'], '9.9.9') !== false && strpos($rc['iesire'], 'fără zip') !== false
    && strpos($rc['iesire'], $kd) === false, substr($rc['iesire'], -400));
$copii_j = (array) (actualizare($kd, ['actiune' => 'stare'])['json']['copii'] ?? []);
$rc = unealta_locala('actualizeaza.php', [$url, '--local', '--pune=' . ($copii_j[0]['copie'] ?? ''), "--dosar=$dosar_act"]);
verifica('Actualizare', 'comanda pune înapoi o copie, la cerere',
    $rc['cod'] === 0 && (string) file_get_contents("$tmp/site/app/nucleu.php") === $nucleu_test, $rc['iesire']);
$ht_site = (string) file_get_contents("$radacina/site/.htaccess");
verifica('Securitate', 'copia de siguranță a codului nu are .htaccess propriu, iar punerea ei înapoi lasă .htaccess-ul site-ului neatins',
    !is_file("$tmp/site/date/versiuni/cod/" . ($copii_j[0]['copie'] ?? 'x') . '/.htaccess')
    && (string) file_get_contents("$tmp/site/.htaccess") === $ht_site && cerere('GET', '/')['cod'] === 200);
// o copie făcută de o versiune veche (înainte de 0.12) are în ea paza dosarului de date: nu ajunge niciodată în rădăcină
$veche = "$tmp/site/date/versiuni/cod/20200101-000000";
@mkdir("$veche/app", 0755, true);
file_put_contents("$veche/.htaccess", "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
copy("$tmp/site/app/nucleu.php", "$veche/app/nucleu.php");
file_put_contents("$veche/_versiune.txt", "0.11.0\n");
$ra = actualizare($kd, ['actiune' => 'restaureaza', 'copie' => '20200101-000000']);
verifica('Securitate', 'punerea înapoi a unei copii vechi nu scrie paza dosarului de date peste .htaccess-ul site-ului (site-ul nu se închide)',
    $ra['cod'] === 200 && (string) file_get_contents("$tmp/site/.htaccess") === $ht_site && cerere('GET', '/')['cod'] === 200, json_encode($ra['json'] ?? null));

// --- teme proprii (0.14): lucrări pentru un client, puse de om cu cheia de cod, ocolite de sincronizare ----------

elibereaza();
$b64 = fn(string $s): string => base64_encode($s);
$css_proprie = "/* Tema „client-proba\" — tema unui client, făcută doar pentru teste. */\n"
    . "@font-face{font-family:'Proba';src:url(client-proba/proba.woff2) format('woff2')}\nbody{font-family:'Proba',sans-serif}\n";
$font_proba = 'wOF2' . str_repeat("\0", 60);
$tema_proba = ['client-proba.css' => $b64($css_proprie), 'client-proba/proba.woff2' => $b64($font_proba),
               'client-proba/fundal.png' => $b64("\x89PNG\r\n\x1a\n" . str_repeat("\0", 20))];

$r = actualizare($ks, ['actiune' => 'pune_tema', 'nume' => 'client-proba', 'fisiere' => $tema_proba]);
verifica('Securitate', 'o temă proprie NU se poate pune cu cheia de scriere (AI-ul nu scrie CSS pe site)',
    $r['cod'] === 401 && !is_file("$tmp/site/assets/teme/client-proba.css"), "cod {$r['cod']}");

$r = actualizare($kd, ['actiune' => 'pune_tema', 'nume' => 'client-proba', 'fisiere' => $tema_proba]);
$rez_t = (array) ($r['json']['rezultat'] ?? []);
verifica('Teme proprii', 'cu cheia de cod, tema proprie se pune pe site și se servește de acolo',
    $r['cod'] === 200 && ($rez_t['operatie'] ?? '') === 'pusă' && ($rez_t['fisiere'] ?? 0) === 3
    && (string) @file_get_contents("$tmp/site/assets/teme/client-proba.css") === $css_proprie
    && cerere('GET', '/assets/teme/client-proba/proba.woff2')['corp'] === $font_proba, $r['corp']);
$u = unealta($kc, 'despre_site');
verifica('Teme proprii', 'AI-ul vede tema în despre_site, marcată proprie, cu descrierea din foaie',
    in_array('client-proba', $u['date']['teme']['disponibile'] ?? [], true) && ($u['date']['teme']['proprii'] ?? []) === ['client-proba']
    && strpos((string) ($u['date']['teme']['despre']['client-proba'] ?? ''), 'tema unui client') !== false, $u['text']);
$u = unealta($ks, 'seteaza_site', ['tema' => 'client-proba']);
verifica('Teme proprii', 'AI-ul o alege cu seteaza_site, ca pe oricare altă temă',
    !$u['eroare'] && strpos(cerere('GET', '/')['corp'], '/assets/teme/client-proba.css?v=') !== false, $u['text']);

$simpluspv_css = (string) file_get_contents("$tmp/site/assets/teme/simpluspv.css");
$r = actualizare($kd, ['actiune' => 'pune_tema', 'nume' => 'simpluspv', 'fisiere' => ['simpluspv.css' => $b64('body{color:red}')]]);
verifica('Teme proprii', 'o temă de bază (din depozit) nu poate fi înlocuită cu una proprie: se cere alt nume',
    $r['cod'] === 400 && strpos((string) ($r['json']['eroare'] ?? ''), 'temă de bază') !== false
    && (string) file_get_contents("$tmp/site/assets/teme/simpluspv.css") === $simpluspv_css, $r['corp']);

$rele = [
    'o cale care iese din dosar' => ['client-rau.css' => $b64('a{}'), 'client-rau/../../../app/x.css' => $b64('a{}')],
    'un fișier PHP' => ['client-rau.css' => $b64('a{}'), 'client-rau/x.php' => $b64('<?php echo 1;')],
    'un SVG' => ['client-rau.css' => $b64('a{}'), 'client-rau/x.svg' => $b64('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>')],
    'un fișier ascuns' => ['client-rau.css' => $b64('a{}'), 'client-rau/.htaccess' => $b64('Require all granted')],
    'foaia altei teme' => ['client-rau.css' => $b64('a{}'), 'simpluspv.css' => $b64('a{}')],
    'fără foaia de stil' => ['client-rau/x.woff2' => $b64('wOF2')],
];
$refuzate = [];
foreach ($rele as $ce => $f) {
    $r = actualizare($kd, ['actiune' => 'pune_tema', 'nume' => 'client-rau', 'fisiere' => $f]);
    if ($r['cod'] !== 400) $refuzate[] = "$ce: cod {$r['cod']}";
}
$r = actualizare($kd, ['actiune' => 'pune_tema', 'nume' => '../app', 'fisiere' => ['../app.css' => $b64('a{}')]]);
verifica('Securitate', 'tema proprie refuză căi în afara dosarului ei, PHP, SVG, fișiere ascunse, foaia altei teme și un nume care e cale',
    !$refuzate && $r['cod'] === 400 && !is_file("$tmp/site/assets/teme/client-rau.css") && !is_dir("$tmp/site/assets/teme/client-rau")
    && !is_file("$tmp/site/app/x.css") && !is_file("$tmp/site/app.css") && !is_file("$tmp/site/assets/app.css")
    && (string) file_get_contents("$tmp/site/assets/teme/simpluspv.css") === $simpluspv_css, implode('; ', $refuzate));

$css_proprie2 = str_replace('sans-serif}', 'serif}', $css_proprie);
$r = actualizare($kd, ['actiune' => 'pune_tema', 'nume' => 'client-proba',
    'fisiere' => ['client-proba.css' => $b64($css_proprie2), 'client-proba/proba.woff2' => $b64($font_proba)]]);
$rez_t = (array) ($r['json']['rezultat'] ?? []);
$copie_t = "$tmp/site/date/versiuni/teme/client-proba/" . ($rez_t['copie'] ?? 'x');
verifica('Teme proprii', 'înlocuirea păstrează întâi versiunea de pe server, apoi pune tema întreagă (ce lipsește din cea nouă iese)',
    $r['cod'] === 200 && ($rez_t['operatie'] ?? '') === 'înlocuită' && ($rez_t['scoase'] ?? []) === ['client-proba/fundal.png']
    && (string) @file_get_contents("$tmp/site/assets/teme/client-proba.css") === $css_proprie2 && !is_file("$tmp/site/assets/teme/client-proba/fundal.png")
    && (string) @file_get_contents("$copie_t/client-proba.css") === $css_proprie && is_file("$copie_t/client-proba/fundal.png"), $r['corp']);

fa_pachet_proba(['app/nucleu.php' => $nucleu_nou, 'assets/teme/client-proba.css' => 'body{color:red} /* din depozit */',
                 'assets/teme/client-proba/proba.woff2' => 'din depozit']);
$r = actualizare($kd, ['actiune' => 'stare']);
$st = (array) ($r['json']['stare'] ?? []);
verifica('Teme proprii', 'starea arată că depozitul are o temă cu același nume, iar tema proprie nu e printre fișierele de schimbat',
    ($st['teme_proprii_ocolite'] ?? []) === ['client-proba']
    && !preg_grep('#^assets/teme/client-proba#', array_merge((array) ($st['de_schimbat'] ?? []), (array) ($st['noi'] ?? []))), $r['corp']);
$r = actualizare($kd, ['actiune' => 'sincronizeaza']);
$copie_sinc = (string) ($r['json']['rezultat']['copie'] ?? '');
$sinc_ok = ($r['json']['rezultat']['operatie'] ?? '') === 'actualizat' && strpos((string) file_get_contents("$tmp/site/app/nucleu.php"), "'9.9.9'") !== false;
$ra = actualizare($kd, ['actiune' => 'restaureaza', 'copie' => $copie_sinc]);   // înapoi la codul testat
verifica('Teme proprii', 'sincronizarea cu depozitul nu scrie peste tema proprie, nici când depozitul are una cu același nume',
    $sinc_ok && (string) file_get_contents("$tmp/site/assets/teme/client-proba.css") === $css_proprie2
    && (string) file_get_contents("$tmp/site/assets/teme/client-proba/proba.woff2") === $font_proba
    && $ra['cod'] === 200 && (string) file_get_contents("$tmp/site/app/nucleu.php") === $nucleu_test, $r['corp']);

$mare = (string) json_encode(['actiune' => 'pune_tema', 'nume' => 'client-mare',
    'fisiere' => ['client-mare.css' => $b64('a{}'), 'client-mare/mare.woff2' => $b64(random_bytes(1 << 20))]]);
$r = cerere('POST', '/actualizare.php', $mare, ['Content-Type' => 'application/json', 'Accept' => 'application/json']);
verifica('Securitate', 'fără cheia de cod în antet, o cerere mare nu se citește (doar 20 KB)', $r['cod'] === 413
    && !is_file("$tmp/site/assets/teme/client-mare.css"), "cod {$r['cod']}");
$r = cerere('POST', '/actualizare.php', $mare, ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Authorization' => 'Bearer ' . $kd]);
verifica('Teme proprii', 'cu cheia de cod, o temă de 1 MB trece într-o singură cerere', $r['cod'] === 200
    && is_file("$tmp/site/assets/teme/client-mare/mare.woff2"), substr($r['corp'], 0, 300));
actualizare($kd, ['actiune' => 'scoate_tema', 'nume' => 'client-mare']);

// unealta de pe calculator: dosarul teme/ de lângă depozit, așezat ca assets/teme/
$dosar_teme = "$tmp/unealta-teme";
@mkdir("$dosar_teme/teme/client-unealta", 0755, true);
copy("$dosar_act/chei-127-0-0-1.json", "$dosar_teme/chei-127-0-0-1.json");
file_put_contents("$dosar_teme/teme/client-unealta.css", "/* Tema de probă a uneltei. */\n"
    . "@font-face{font-family:'U';src:url(client-unealta/u.woff2) format('woff2')}\n"
    . "@font-face{font-family:'V';src:url(cinesunt/inter-latin.woff2) format('woff2')}\nbody{font-family:'U'}\n");
file_put_contents("$dosar_teme/teme/client-unealta/u.woff2", $font_proba);
file_put_contents("$dosar_teme/teme/client-unealta/Thumbs.db", 'pus de Windows');
$rc = unealta_locala('tema.php', [$url, '--local', '--pune=client-unealta', "--dosar=$dosar_teme"]);
verifica('Teme proprii', 'unealta de pe calculator pune tema din dosarul teme/, o verifică din afară și nu arată cheia',
    $rc['cod'] === 0 && strpos($rc['iesire'], 'verificat din afară') !== false && strpos($rc['iesire'], $kd) === false
    && is_file("$tmp/site/assets/teme/client-unealta/u.woff2") && !is_file("$tmp/site/assets/teme/client-unealta/Thumbs.db"), $rc['iesire']);
verifica('Teme proprii', 'unealta atrage atenția când foaia încarcă fonturile altei teme (o temă copiată, redenumită pe jumătate)',
    strpos($rc['iesire'], 'cinesunt/inter-latin.woff2') !== false, $rc['iesire']);
$rc = unealta_locala('tema.php', [$url, '--local', "--dosar=$dosar_teme"]);
verifica('Teme proprii', 'fără opțiuni, unealta arată temele de bază și pe cele proprii',
    $rc['cod'] === 0 && strpos($rc['iesire'], 'simpluspv') !== false && strpos($rc['iesire'], 'proprie: client-unealta') !== false
    && strpos($rc['iesire'], 'proprie: client-proba') !== false, $rc['iesire']);
$rc = unealta_locala('tema.php', [$url, '--local', '--scoate=client-proba', "--dosar=$dosar_teme"]);
$r = cerere('GET', '/');
verifica('Teme proprii', 'scoaterea temei alese: site-ul revine la aspectul implicit, iar o copie rămâne pe server',
    $rc['cod'] === 0 && strpos($rc['iesire'], 'aspectul implicit') !== false && !is_file("$tmp/site/assets/teme/client-proba.css")
    && !is_dir("$tmp/site/assets/teme/client-proba") && $r['cod'] === 200 && strpos($r['corp'], '/assets/teme/') === false
    && count(glob("$tmp/site/date/versiuni/teme/client-proba/*") ?: []) === 2, $rc['iesire']);
$rc = unealta_locala('tema.php', [$url, '--local', '--scoate=simpluspv', "--dosar=$dosar_teme"]);
verifica('Teme proprii', 'o temă de bază nu se scoate de aici', $rc['cod'] === 1 && strpos($rc['iesire'], 'temă de bază') !== false
    && is_file("$tmp/site/assets/teme/simpluspv.css"), $rc['iesire']);
unealta($ks, 'seteaza_site', ['tema' => 'simpluspv']);

// --- 0.19: editorii — clientul scrie cu cheia lui, iar jurnalul și versiunile spun cine a făcut ce ----------------

elibereaza();
$ke = 'mcms_e_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$r = mcp($ke, 'tools/list');
verifica('Editori', 'înainte să fie adăugată, cheia editorului nu merge', $r['cod'] === 401, "cod {$r['cod']}");
$r = actualizare($ks, ['actiune' => 'adauga_editor', 'nume' => 'Maria Client', 'amprenta' => hash('sha256', $ke)]);
verifica('Securitate', 'un editor NU se poate adăuga cu cheia de scriere (AI-ul nu-și face singur chei)', $r['cod'] === 401
    && !is_file("$tmp/site/date/securitate/editori.json"), "cod {$r['cod']}");
$r = actualizare($kd, ['actiune' => 'adauga_editor', 'nume' => 'admin', 'amprenta' => hash('sha256', $ke)]);
verifica('Editori', 'numele rezervate în jurnal (admin, citire, link…) sunt refuzate', $r['cod'] === 400 && strpos($r['corp'], 'rezervat') !== false, $r['corp']);
$r = actualizare($kd, ['actiune' => 'adauga_editor', 'nume' => 'Hoțul', 'amprenta' => hash('sha256', $ks)]);
verifica('Securitate', 'cheia de scriere a site-ului nu poate deveni cheie de editor', $r['cod'] === 400, $r['corp']);
$r = actualizare($kd, ['actiune' => 'adauga_editor', 'nume' => 'Maria Client', 'amprenta' => hash('sha256', $ke)]);
$pe_disc = (string) @file_get_contents("$tmp/site/date/securitate/editori.json");
verifica('Editori', 'cu cheia de cod, editorul e adăugat; pe server stă doar amprenta cheii lui', $r['cod'] === 200
    && ($r['json']['editori'][0]['nume'] ?? '') === 'Maria Client' && strpos($pe_disc, $ke) === false
    && strpos($pe_disc, hash('sha256', $ke)) !== false && strpos($r['corp'], hash('sha256', $ke)) === false, $r['corp']);

$r = mcp($ke, 'tools/list');
$unelte_e = array_column($r['json']['result']['tools'] ?? [], 'name');
verifica('Editori', 'editorul vede 24 de comenzi: tot, fără seteaza_site și retrage_conexiune', count($unelte_e) === 24
    && in_array('publica', $unelte_e, true) && in_array('sterge', $unelte_e, true)
    && !in_array('seteaza_site', $unelte_e, true) && !in_array('retrage_conexiune', $unelte_e, true), implode(', ', $unelte_e));
$u = unealta($ke, 'seteaza_site', ['nume' => 'Site furat']);
verifica('Editori', 'seteaza_site cerut direct de editor → refuzat, identitatea rămâne', $u['eroare'] && strpos($u['text'], 'administratorul') !== false
    && strpos(cerere('GET', '/')['corp'], 'Site furat') === false, $u['text']);
$u = unealta($ke, 'despre_site');
verifica('Editori', 'despre_site îi spune AI-ului cine e și ce nu poate', ($u['date']['cine'] ?? '') === 'Maria Client'
    && ($u['date']['cheia_ta'] ?? '') === 'scriere' && isset($u['date']['limite_editor']), $u['text']);
$u = unealta($ks, 'despre_site');
verifica('Editori', 'cheia ta de scriere rămâne admin, fără limite', ($u['date']['cine'] ?? '') === 'admin' && !isset($u['date']['limite_editor']), $u['text']);

$u = unealta($ke, 'salveaza', ['tip' => 'pagina', 'slug' => 'pagina-clientului', 'titlu' => 'A clientului', 'continut_html' => '<p>Scrisă de client.</p>']);
$u2 = unealta($ke, 'publica', ['tip' => 'pagina', 'slug' => 'pagina-clientului']);
verifica('Editori', 'editorul creează și publică singur, fără aprobarea adminului', !$u['eroare'] && !$u2['eroare']
    && ($u2['date']['element']['modificat_de'] ?? '') === 'Maria Client' && strpos(cerere('GET', '/pagina-clientului')['corp'], 'Scrisă de client.') !== false, $u2['text']);
unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => 'pagina-clientului', 'continut_html' => '<p>Corectată de admin.</p>']);
$v = unealta($kc, 'listeaza_versiuni', ['tip' => 'pagina', 'slug' => 'pagina-clientului']);
$c = unealta($kc, 'citeste', ['tip' => 'pagina', 'slug' => 'pagina-clientului']);
verifica('Editori', 'versiunile spun cine a scris fiecare stare; elementul, cine l-a modificat ultimul',
    array_column($v['date']['versiuni'] ?? [], 'modificat_de') === ['Maria Client', 'Maria Client']
    && strpos($c['text'], '"modificat_de": "admin"') !== false, $v['text']);
$j = unealta($kc, 'citeste_jurnal', ['ultimele' => 40]);
$ale_ei = array_filter($j['date']['intrari'] ?? [], fn($i) => ($i['cine'] ?? '') === 'Maria Client' && in_array($i['unealta'] ?? '', ['salveaza', 'publica'], true));
$refuz = array_filter($j['date']['intrari'] ?? [], fn($i) => ($i['cine'] ?? '') === 'Maria Client' && ($i['unealta'] ?? '') === 'seteaza_site' && ($i['rezultat'] ?? '') === 'refuzat');
$ale_adminului = array_filter($j['date']['intrari'] ?? [], fn($i) => ($i['cine'] ?? '') === 'admin' && ($i['unealta'] ?? '') === 'salveaza');
verifica('Editori', 'jurnalul are numele ei la fiecare apel, inclusiv la refuz, și „admin” la ale tale', count($ale_ei) === 2 && $refuz && $ale_adminului, $j['text']);
[$corp, $ant] = formular(['cheie' => $kc]);
$r = cerere('POST', '/jurnal.php', $corp, $ant);
verifica('Editori', 'pagina jurnalului are coloana „Cine” cu numele ei', strpos($r['corp'], '<th>Cine</th>') !== false && strpos($r['corp'], 'Maria Client') !== false, "cod {$r['cod']}");

$u = unealta($ke, 'link_urcare');
$link = cale_din((string) ($u['date']['url'] ?? ''));
parse_str((string) parse_url($link, PHP_URL_QUERY), $q);
$falsificat = str_replace('c=' . rawurlencode('Maria Client'), 'c=admin', $link);
verifica('Editori', 'linkul de urcare poartă numele ei, semnat: schimbat, nu mai merge', ($q['c'] ?? '') === 'Maria Client'
    && cerere('GET', $link)['cod'] === 200 && cerere('GET', $falsificat)['cod'] === 403, $link);
elibereaza();

// Conectorul din claude.ai (0.19.1): editorul își ia singur codul de conectare, din pagina site-ului, cu cheia lui.
$r = cerere('GET', '/oauth/conectare');
verifica('Editori', 'pagina /oauth/conectare cere cheia, prin formular, și nu e indexată', $r['cod'] === 200
    && strpos($r['corp'], 'name="cheie"') !== false && strpos($r['corp'], 'noindex') !== false, "cod {$r['cod']}");
[$corp, $ant] = formular(['cheie' => $kc]);
$r = cerere('POST', '/oauth/conectare', $corp, $ant);
verifica('Securitate', 'OAuth: cheia de citire nu primește cod de conectare din pagină', $r['cod'] === 403
    && preg_match('/class="cod-conectare"/', $r['corp']) === 0, "cod {$r['cod']}");
elibereaza();
[$corp, $ant] = formular(['cheie' => $ke]);
$r = cerere('POST', '/oauth/conectare', $corp, $ant);
$COD = preg_match('/class="cod-conectare"[^>]*>([0-9]{3}) ([0-9]{3})</', $r['corp'], $m) ? $m[1] . $m[2] : '';
$j = unealta($kc, 'citeste_jurnal', ['ultimele' => 5]);
verifica('Editori', 'cu cheia ei, editorul primește din pagină codul de 6 cifre; deschiderea e în jurnal pe numele ei', $r['cod'] === 200
    && strlen($COD) === 6 && strpos($r['corp'], "$url/mcp") !== false
    && array_filter($j['date']['intrari'] ?? [], fn($i) => ($i['cerere'] ?? '') === 'fereastra' && ($i['cine'] ?? '') === 'Maria Client'), substr(strip_tags($r['corp']), 0, 300));
$r = cerere('POST', '/oauth/inregistrare', json_encode(['client_name' => 'Claude Maria', 'redirect_uris' => [$claude]]));
$client_e = (string) (json_decode($r['corp'], true)['client_id'] ?? '');
$ver = b64url(random_bytes(32));
$a = aproba($client_e, $claude, b64url(hash('sha256', $ver, true)), $ke);
$r = token(['grant_type' => 'authorization_code', 'code' => $a['cod'], 'redirect_uri' => $claude, 'client_id' => $client_e, 'code_verifier' => $ver]);
$acces_e = (string) ($r['json']['access_token'] ?? '');
$u = unealta($acces_e, 'despre_site');
$r2 = mcp($acces_e, 'tools/list');
verifica('Editori', 'aprobat cu cheia ei, tokenul OAuth e tot al ei: nume în jurnal, fără comenzile de admin',
    ($u['date']['cine'] ?? '') === 'Maria Client' && count($r2['json']['result']['tools'] ?? []) === 24, $u['text']);
$l = unealta($kc, 'listeaza_conexiuni');
$con = array_values(array_filter($l['date']['conexiuni'] ?? [], fn($c) => $c['client_id'] === $client_e));
verifica('Editori', 'listeaza_conexiuni spune cine a aprobat conexiunea', ($con[0]['aprobat_de'] ?? []) === ['Maria Client'], $l['text']);

$r = actualizare($kd, ['actiune' => 'scoate_editor', 'nume' => 'Maria Client']);
verifica('Editori', 'scoasă cu cheia de cod: cheia ei și tokenul ei OAuth mor pe loc', $r['cod'] === 200 && ($r['json']['editori'] ?? null) === []
    && mcp($ke, 'tools/list')['cod'] === 401 && mcp($acces_e, 'tools/list')['cod'] === 401, $r['corp']);
elibereaza();

// Comanda de pe calculator: cheia se scrie într-un fișier pentru client, nu pe ecran; a doua rulare n-o suprascrie.
$dosar_e = "$tmp/editori";
mkdir($dosar_e);
file_put_contents("$dosar_e/chei-proba.json", json_encode(['citire' => ['cheie' => $kc, 'amprenta' => hash('sha256', $kc)],
    'scriere' => ['cheie' => $ks, 'amprenta' => hash('sha256', $ks)], 'cod' => ['cheie' => $kd, 'amprenta' => hash('sha256', $kd)]]));
$arg_e = [$url, '--local', "--dosar=$dosar_e", '--nume=proba'];
$r = unealta_locala('editor.php', array_merge($arg_e, ['--adauga=Ștefan Țepeș']));
$fis_e = json_decode((string) @file_get_contents("$dosar_e/chei-proba-editor-stefan-tepes.json"), true) ?? [];
verifica('Editori', 'editor.php --adauga: cheia în chei-<site>-editor-<om>.json, cu comanda de conectare, nu pe ecran', $r['cod'] === 0
    && preg_match('/^mcms_e_/', (string) ($fis_e['cheie'] ?? '')) === 1 && strpos($r['iesire'], (string) ($fis_e['cheie'] ?? 'x')) === false
    && strpos((string) ($fis_e['claude_code'] ?? ''), "$url/mcp") !== false && mcp((string) ($fis_e['cheie'] ?? ''), 'tools/list')['cod'] === 200, $r['iesire']);
$r = unealta_locala('editor.php', $arg_e);
verifica('Editori', 'editor.php fără opțiuni arată editorii de pe site', $r['cod'] === 0 && strpos($r['iesire'], 'Ștefan Țepeș') !== false, $r['iesire']);
$r = unealta_locala('editor.php', array_merge($arg_e, ['--scoate=Ștefan Țepeș']));
verifica('Editori', 'editor.php --scoate: cheia nu mai merge, fișierul local rămâne', $r['cod'] === 0
    && mcp((string) ($fis_e['cheie'] ?? ''), 'tools/list')['cod'] === 401 && is_file("$dosar_e/chei-proba-editor-stefan-tepes.json"), $r['iesire']);
elibereaza();

// --- 0.20: site multilingv (RO + EN) — prefix /en/, adresă canonică pe limbă, comutator și hreflang ----
unealta($ks, 'seteaza_site', ['limbi' => ['ro', 'en'],
    'traduceri' => ['en' => ['nume' => 'Village EN Name', 'descriere' => 'EN description of the site', 'subsol' => 'EN footer note']]]);
unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => 'despremulti', 'titlu' => 'Despre proiect', 'continut_html' => '<p>RO continut despre</p>', 'grup' => 'grupmulti', 'meniu' => 5]);
unealta($ks, 'publica', ['tip' => 'pagina', 'slug' => 'despremulti']);
unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => 'aboutmulti', 'titlu' => 'About project', 'continut_html' => '<p>EN content about</p>', 'limba' => 'en', 'grup' => 'grupmulti', 'meniu' => 5]);
unealta($ks, 'publica', ['tip' => 'pagina', 'slug' => 'aboutmulti']);
unealta($ks, 'salveaza', ['tip' => 'pagina', 'slug' => 'homeen', 'titlu' => 'Home EN', 'continut_html' => '<p>EN home page</p>', 'limba' => 'en', 'grup' => 'acasa']);
unealta($ks, 'publica', ['tip' => 'pagina', 'slug' => 'homeen']);
unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'enarticol', 'titlu' => 'EN article', 'continut_html' => '<p>en</p>', 'limba' => 'en', 'etichete' => ['Tag One']]);
unealta($ks, 'publica', ['tip' => 'articol', 'slug' => 'enarticol']);
$m_enart = cerere('GET', '/en/enarticol');
verifica('Multilingv', 'eticheta unui articol EN duce la /en/eticheta/... (nu la lista în română)',
    $m_enart['cod'] === 200 && strpos($m_enart['corp'], 'href="/en/eticheta/tag-one"') !== false
    && strpos($m_enart['corp'], 'href="/eticheta/tag-one"') === false, "cod {$m_enart['cod']}");
$m_home_ui = cerere('GET', '/en');
verifica('Multilingv', 'interfața EN e tradusă: „Latest/All", link /en/articole, fără text românesc de interfață',
    strpos($m_home_ui['corp'], 'Latest') !== false && strpos($m_home_ui['corp'], 'href="/en/articole"') !== false
    && strpos($m_home_ui['corp'], 'Ultimele') === false && strpos($m_home_ui['corp'], 'Toate articolele') === false,
    'text de interfață netradus pe /en');

$m_ro = cerere('GET', '/despremulti');
$m_en = cerere('GET', '/en/aboutmulti');
$m_en_rad = cerere('GET', '/aboutmulti');       // pagina EN la rădăcină: nu se vede acolo
$m_ro_pref = cerere('GET', '/en/despremulti');  // pagina RO sub /en: nu se vede acolo
$m_home_en = cerere('GET', '/en');
$m_home_slug = cerere('GET', '/en/homeen');      // home-ul EN prin slug duce la /en

verifica('Multilingv', 'pagina se vede în limba ei: RO la /<slug>, EN la /en/<slug>',
    $m_ro['cod'] === 200 && $m_en['cod'] === 200 && strpos($m_ro['corp'], 'RO continut despre') !== false
    && strpos($m_en['corp'], 'EN content about') !== false, "ro {$m_ro['cod']} en {$m_en['cod']}");
verifica('Multilingv', 'fiecare pagină are o singură adresă canonică (prefixul greșit dă 404)',
    $m_en_rad['cod'] === 404 && $m_ro_pref['cod'] === 404, "en_la_radacina {$m_en_rad['cod']} ro_sub_en {$m_ro_pref['cod']}");
verifica('Multilingv', 'atributul html lang urmează limba paginii',
    strpos($m_en['corp'], '<html lang="en">') !== false && strpos($m_ro['corp'], '<html lang="ro">') !== false);
verifica('Multilingv', 'hreflang leagă perechea RO↔EN și pune x-default',
    strpos($m_ro['corp'], 'hreflang="en"') !== false && strpos($m_ro['corp'], '/en/aboutmulti') !== false
    && strpos($m_ro['corp'], 'hreflang="x-default"') !== false && strpos($m_en['corp'], 'hreflang="ro"') !== false,
    'lipsește hreflang sau adresa perechii');
verifica('Multilingv', 'comutatorul de limbă apare, cu ambele limbi',
    strpos($m_ro['corp'], 'class="limbi"') !== false && strpos($m_ro['corp'], '>EN<') !== false && strpos($m_ro['corp'], '>RO<') !== false);
verifica('Multilingv', 'prima pagină a limbii a doua stă la /en (slugul ei redirecționează acolo)',
    $m_home_en['cod'] === 200 && strpos($m_home_en['corp'], 'EN home page') !== false && $m_home_slug['cod'] === 301,
    "home_en {$m_home_en['cod']} home_slug {$m_home_slug['cod']}");
verifica('Multilingv', 'identitatea tradusă: pagina EN poartă numele și descrierea EN, RO pe cele de bază',
    strpos($m_en['corp'], 'og:site_name" content="Village EN Name"') !== false
    && strpos($m_en['corp'], 'EN description of the site') !== false
    && strpos($m_ro['corp'], 'Village EN Name') === false, 'numele/descrierea EN nu apar (sau apar și pe RO)');
// toleranță la clienți MCP cu schema veche: un câmp array/obiect trimis ca text JSON e acceptat decodat
$m_tol_site = unealta($ks, 'seteaza_site', ['limbi' => '["ro","en"]', 'traduceri' => '{"en":{"nume":"Text JSON EN"}}']);
$m_tol_art = unealta($ks, 'salveaza', ['tip' => 'articol', 'slug' => 'toljson', 'titlu' => 'Tol', 'continut_html' => '<p>x</p>', 'etichete' => '["Unu","Doi"]']);
$tol_site = $m_tol_site['date'] ?? [];
verifica('Multilingv', 'toleranță: array/obiect trimis ca text JSON (client cu schemă veche) e acceptat',
    !$m_tol_site['eroare'] && !$m_tol_art['eroare']
    && (($tol_site['site']['limbi'] ?? []) === ['ro', 'en'])
    && (($tol_site['site']['traduceri']['en']['nume'] ?? '') === 'Text JSON EN')
    && strpos($m_tol_art['text'], 'Unu') !== false,
    'nedecodat — limbi=' . json_encode($tol_site['site']['limbi'] ?? null) . ' text=' . substr($m_tol_site['text'], 0, 120));
unealta($ks, 'sterge', ['tip' => 'articol', 'slug' => 'toljson']);

$m_sitemap = cerere('GET', '/sitemap.xml');
verifica('Multilingv', 'sitemap: rădăcina /en apare și fiecare adresă își declară traducerile (xhtml:link)',
    $m_sitemap['cod'] === 200 && strpos($m_sitemap['corp'], 'xmlns:xhtml') !== false
    && strpos($m_sitemap['corp'], '/en</loc>') !== false && preg_match('#/en/aboutmulti</loc>#', $m_sitemap['corp'])
    && strpos($m_sitemap['corp'], 'hreflang="en"') !== false && strpos($m_sitemap['corp'], 'hreflang="x-default"') !== false,
    "cod {$m_sitemap['cod']}");

// curățenie: site-ul revine monolingv, ca testele următoare (și exportul deja rulat) să nu fie afectate
unealta($ks, 'sterge', ['tip' => 'pagina', 'slug' => 'despremulti']);
unealta($ks, 'sterge', ['tip' => 'pagina', 'slug' => 'aboutmulti']);
unealta($ks, 'sterge', ['tip' => 'pagina', 'slug' => 'homeen']);
unealta($ks, 'sterge', ['tip' => 'articol', 'slug' => 'enarticol']);
unealta($ks, 'seteaza_site', ['limbi' => [], 'traduceri' => []]);
$m_dupa = cerere('GET', '/despre');
verifica('Multilingv', 'după revenirea la o limbă, site-ul e din nou monolingv (fără comutator)',
    $m_dupa['cod'] === 200 && strpos($m_dupa['corp'], 'class="limbi"') === false && strpos($m_dupa['corp'], 'hreflang=') === false);

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

// Se scot din numărătoare pachetul de probă (nu e pe server, e „depozitul" nostru) și copiile de siguranță
// ale codului, făcute de actualizare — stau în date/, care e blocat din web și are testul lui separat.
$php_la_final = array_values(array_filter(fisiere($tmp, '/\.(php[0-9]?|phtml|phar)$/i'),
    fn($f) => strpos(str_replace('\\', '/', $f), '/pachet/') === false
        && strpos(str_replace('\\', '/', $f), '/date/versiuni/cod/') === false));
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
verifica('Export', 'copia păstrează și legăturile din subsol', strpos($acasa2['corp'], '>Celălalt site</a>') !== false);
verifica('Export', 'copia păstrează și mențiunea realizatorului, cu linkul ei',
    strpos($acasa2['corp'], '<a class="realizare" href="https://realizator.example/"') !== false);
$url = $url2;
$audit2 = cerere('GET', '/audit');
$url = $url_principal;
verifica('Export', 'copia păstrează și subpaginile (părintele e pus înaintea lor)', strpos($audit2['corp'], 'class="inapoi-sectiune"><a href="/servicii">') !== false
    && strpos($audit2['corp'], 'class="submeniu"') !== false, substr($audit2['corp'], 0, 300));
$rp2 = unealta_locala('copie.php', [$url2, '--local', "--dosar=$inst", "--pune=$dosar_copie"]);
verifica('Export', 'peste un site cu conținut, copia nu se pune fără --peste', $rp2['cod'] === 1 && strpos($rp2['iesire'], '--peste') !== false, $rp2['iesire']);
$exp_tema = json_decode((string) file_get_contents("$dosar_copie/export.json"), true);
$exp_tema = ['format' => $exp_tema['format'], 'exportat_la' => $exp_tema['exportat_la'] ?? '',
             'site' => ['tema' => 'tema-clientului'] + (array) $exp_tema['site'], 'pagini' => [], 'articole' => [], 'redirectionari' => [], 'imagini' => []];
$dosar_copie2 = "$tmp/copie-cu-tema-proprie";
@mkdir("$dosar_copie2/media", 0755, true);
file_put_contents("$dosar_copie2/export.json", json_encode($exp_tema));
$rp3 = unealta_locala('copie.php', [$url2, '--local', "--dosar=$inst", "--pune=$dosar_copie2", '--peste']);
$url = $url2;
$acasa3 = cerere('GET', '/');
$url = $url_principal;
verifica('Export', 'o copie cu o temă proprie care lipsește pe site-ul nou își pune restul identității și spune cum se urcă tema',
    $rp3['cod'] === 0 && strpos($rp3['iesire'], 'tema-clientului') !== false && strpos($rp3['iesire'], 'tema.php') !== false
    && strpos($acasa3['corp'], 'Atelierul Test') !== false, $rp3['iesire']);
$ri = instaleaza([$url2, '--verifica', '--local', "--dosar=$inst", '--fara-claude', "--tema=$dosar_teme/teme/client-unealta.css"]);
verifica('Instalare', '--tema pune tema proprie după verificare, pe drumul temelor proprii, nu în pachet',
    $ri['cod'] === 0 && strpos($ri['iesire'], 'Gata') !== false && is_file("$inst/server/assets/teme/client-unealta/u.woff2")
    && isset((json_decode((string) @file_get_contents("$inst/server/date/teme-proprii.json"), true) ?? [])['client-unealta'])
    && !preg_grep('#client-unealta#', $in_pachet), $ri['iesire']);
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
