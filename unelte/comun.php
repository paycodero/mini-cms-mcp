<?php
// Funcțiile comune ale uneltelor de pe calculator (instaleaza.php, copie.php, tema.php și celelalte): argumentele, cheile,
// cererile HTTP, apelurile MCP și temele proprii. Nu ajunge pe server.
declare(strict_types=1);

date_default_timezone_set(ini_get('date.timezone') ?: 'Europe/Bucharest');   // datele din numele fișierelor păstrate
$REPO = dirname(__DIR__);
$VERSIUNE = preg_match("/MINICMS_VERSIUNE = '([^']+)'/", (string) file_get_contents("$REPO/site/app/nucleu.php"), $m) ? $m[1] : '0';
$CULORI = stream_isatty(STDOUT) && (DIRECTORY_SEPARATOR === '/' || (function_exists('sapi_windows_vt100_support') && @sapi_windows_vt100_support(STDOUT, true)));
$INTERACTIV = stream_isatty(STDIN);

function culoare(string $t, string $cod): string
{
    global $CULORI;
    return $CULORI ? "\033[{$cod}m$t\033[0m" : $t;
}
function titlu(string $t): void { echo "\n", culoare($t, '1;34'), "\n"; }
function ok(string $t): void { echo '  ', culoare('OK', '1;32'), "   $t\n"; }
function info(string $t): void { echo "       $t\n"; }
function atentie(string $t): void { echo '  ', culoare('!!', '1;33'), "   $t\n"; }
function gresit(string $t): void { echo '  ', culoare('GREȘIT', '1;31'), " $t\n"; }
function opreste(string $t): void { echo "\n", culoare("Oprit: $t", '1;31'), "\n"; exit(1); }

// Argumentele (și după adresă, nu doar înainte), adresa site-ului, numele scurt și dosarul proiectului.
// --nume, --dosar și --local sunt comune tuturor uneltelor; restul opțiunilor permise le dă fiecare unealtă.
function porneste(array $argv, array $permise, string $folosire): array
{
    global $REPO;
    $opt = [];
    $pozitionale = [];
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opt[$m[1]] = $m[2] ?? true;
        else $pozitionale[] = $a;
    }
    $necunoscute = array_diff(array_keys($opt), array_merge($permise, ['nume', 'dosar', 'local']));
    if ($necunoscute || count($pozitionale) !== 1) {
        fwrite(STDERR, "Folosire: $folosire\n");
        if ($necunoscute) fwrite(STDERR, 'Opțiuni necunoscute: --' . implode(', --', $necunoscute) . "\n");
        exit(1);
    }

    $site = rtrim(trim($pozitionale[0]), '/');
    $p = parse_url($site);
    $schema = strtolower((string) ($p['scheme'] ?? ''));
    if (!$p || empty($p['host']) || !in_array($schema, ['https', 'http'], true) || isset($p['user']) || isset($p['query']) || isset($p['fragment'])) {
        opreste("\"$site\" nu e o adresă de site. Exemplu: https://test.paycode.ro");
    }
    if (isset($p['path']) && $p['path'] !== '') opreste('site-ul stă la rădăcina domeniului: fără /cale după domeniu. Pentru un site separat, folosește un subdomeniu.');
    if ($schema === 'http' && empty($opt['local'])) opreste('adresa trebuie să fie https:// — cheia circulă în antet, deci doar criptat.');
    $site = $schema . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');

    $gazda = (string) preg_replace('/^www\./', '', strtolower($p['host']));
    $parti = explode('.', $gazda);
    if (count($parti) > 1 && !filter_var($gazda, FILTER_VALIDATE_IP)) array_pop($parti);
    $nume = is_string($opt['nume'] ?? null) ? $opt['nume'] : trim((string) preg_replace('/[^a-z0-9]+/', '-', implode('-', $parti)), '-');
    if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $nume)) opreste("--nume trebuie să aibă doar litere mici, cifre și cratime (acum: \"$nume\").");

    $dosar = is_string($opt['dosar'] ?? null) ? rtrim($opt['dosar'], '/\\') : dirname($REPO);
    if (!is_dir($dosar)) opreste("dosarul $dosar nu există.");
    $dosar = (string) realpath($dosar);
    return ['opt' => $opt, 'site' => $site, 'nume' => $nume, 'dosar' => $dosar,
            'fisier_chei' => $dosar . DIRECTORY_SEPARATOR . "chei-$nume.json"];
}

function ruleaza(array $comanda, ?string $dosar_lucru = null): array
{
    if (DIRECTORY_SEPARATOR === '\\' && $comanda[0] === 'claude') array_unshift($comanda, 'cmd', '/c');   // claude e claude.cmd
    $proc = @proc_open($comanda, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dosar_lucru);
    if (!is_resource($proc)) return ['cod' => 127, 'iesire' => ''];
    fclose($pipes[0]);
    $iesire = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['cod' => proc_close($proc), 'iesire' => (string) $iesire];
}

function pastreaza_existent(string $fisier): ?string   // redenumește fișierul existent cu data în nume; întoarce noul nume
{
    if (!is_file($fisier)) return null;
    $info = pathinfo($fisier);
    $nou = $info['dirname'] . DIRECTORY_SEPARATOR . $info['filename'] . '.' . date('Y-m-d_His', (int) filemtime($fisier)) . '.' . $info['extension'];
    for ($n = 2; is_file($nou); $n++) $nou = $info['dirname'] . DIRECTORY_SEPARATOR . $info['filename'] . '.' . date('Y-m-d_His') . "-$n." . $info['extension'];
    if (!rename($fisier, $nou)) opreste("nu am putut păstra $fisier înainte de a scrie unul nou.");
    return $nou;
}

function cheile(string $fisier, bool $doar_citeste = false): array
{
    if (is_file($fisier)) {
        $c = json_decode((string) file_get_contents($fisier), true);
        foreach (['citire', 'scriere'] as $rol) {
            if (!preg_match('/^mcms_[cs]_[A-Za-z0-9_-]{40,}$/', (string) ($c[$rol]['cheie'] ?? ''))) opreste("$fisier există, dar nu are forma unui fișier de chei.");
        }
        // A treia cheie (0.9), adăugată la fișierele făcute înainte: deschide doar actualizarea codului.
        if (!preg_match('/^mcms_d_[A-Za-z0-9_-]{40,}$/', (string) ($c['cod']['cheie'] ?? ''))) {
            $cheie = 'mcms_d_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $c['cod'] = ['cheie' => $cheie, 'amprenta' => hash('sha256', $cheie)];
            if (file_put_contents($fisier, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "
", LOCK_EX) === false) opreste("nu pot scrie $fisier.");
            if (!$doar_citeste) ok('cheia de cod (a treia) e nouă — deschide doar actualizarea codului, nu și conținutul');
        }
        if (!$doar_citeste) ok('cheile există deja — le refolosesc (nu se generează altele peste ele)');
        return $c;
    }
    if ($doar_citeste) opreste("nu există $fisier. Cheile se fac la instalare: php unelte/instaleaza.php <adresa site-ului>.");
    $c = [];
    foreach (['citire' => 'c', 'scriere' => 's', 'cod' => 'd'] as $rol => $pref) {
        $cheie = 'mcms_' . $pref . '_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $c[$rol] = ['cheie' => $cheie, 'amprenta' => hash('sha256', $cheie)];
    }
    if (file_put_contents($fisier, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) opreste("nu pot scrie $fisier.");
    @chmod($fisier, 0600);
    ok('trei chei noi, scrise în fișier (nu pe ecran): citire, scriere și cod');
    info('Păstrează o copie (manager de parole): pe server stau doar amprentele; fără fișier, le refaci cu --chei-noi.');
    return $c;
}

function cerere(string $metoda, string $url, ?string $corp = null, array $antete = [], int $secunde = 20): array
{
    global $VERSIUNE;
    $h = "User-Agent: mini-cms-mcp-unelte/$VERSIUNE\r\n";
    foreach ($antete as $k => $v) $h .= "$k: $v\r\n";
    $ctx = stream_context_create(['http' => ['method' => $metoda, 'header' => $h, 'content' => $corp ?? '',
        'ignore_errors' => true, 'timeout' => $secunde, 'follow_location' => 0]]);
    $raspuns = @file_get_contents($url, false, $ctx);
    $eroare = $raspuns === false ? (string) (error_get_last()['message'] ?? '') : '';
    $brute = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    $cod = 0;
    foreach ($brute as $linie) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linie, $m)) $cod = (int) $m[1];
    return ['cod' => $cod, 'corp' => $raspuns === false ? '' : $raspuns, 'eroare' => $eroare];
}

function unelte_mcp(string $site, string $cheie, string $antet): array
{
    $valoare = $antet === 'Authorization' ? "Bearer $cheie" : $cheie;
    $r = cerere('POST', "$site/mcp", '{"jsonrpc":"2.0","id":1,"method":"tools/list"}',
        ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', $antet => $valoare]);
    $r['unelte'] = array_column((array) (json_decode($r['corp'], true)['result']['tools'] ?? []), 'name');
    return $r;
}

// Un apel de comandă MCP (tools/call). Întoarce rezultatul structurat; aruncă RuntimeException cu mesajul serverului.
function apel_mcp(string $site, string $cheie, string $antet, string $unealta, array $argumente = []): array
{
    $valoare = $antet === 'Authorization' ? "Bearer $cheie" : $cheie;
    $corp = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $unealta, 'arguments' => (object) $argumente]],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $r = cerere('POST', "$site/mcp", (string) $corp, ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', $antet => $valoare], 120);
    $j = json_decode($r['corp'], true);
    if (!is_array($j)) throw new RuntimeException("serverul a răspuns {$r['cod']}" . ($r['eroare'] !== '' ? ": {$r['eroare']}" : ''));
    if (isset($j['error'])) throw new RuntimeException((string) ($j['error']['message'] ?? 'eroare'));
    $rez = (array) ($j['result'] ?? []);
    if (!empty($rez['isError'])) throw new RuntimeException((string) ($rez['content'][0]['text'] ?? 'eroare'));
    return (array) ($rez['structuredContent'] ?? []);
}

// Antetul prin care cheia ajunge la PHP: 'Authorization' sau, dacă găzduirea îl taie, 'X-API-Key'. Null = cheia nu merge.
function antet_pentru(string $site, string $cheie): ?string
{
    foreach (['Authorization', 'X-API-Key'] as $antet) {
        $r = unelte_mcp($site, $cheie, $antet);
        if ($r['cod'] === 200) return $antet;
        if ($r['cod'] !== 401) return null;
    }
    return null;
}

// --- temele proprii (tema.php și instaleaza.php --tema) -------------------------------------------

// O cerere către /actualizare.php, cu cheia de cod în antet (niciodată în adresă sau în corp).
function cerere_cod(string $site, string $cheie, array $date, int $secunde = 180): array
{
    $r = cerere('POST', "$site/actualizare.php", (string) json_encode($date, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Authorization' => 'Bearer ' . $cheie], $secunde);
    $j = json_decode($r['corp'], true);
    if ($r['cod'] === 0) throw new RuntimeException('site-ul nu răspunde: ' . ($r['eroare'] ?: 'fără răspuns'));
    if ($r['cod'] === 404) throw new RuntimeException("site-ul nu are pagina de actualizare: are o versiune mai veche de 0.9 sau 'actualizare' => false în config.php.");
    if ($r['cod'] !== 200 || !is_array($j)) {
        throw new RuntimeException('serverul a răspuns ' . $r['cod'] . ': ' . substr(trim((string) (($j['eroare'] ?? '') ?: $r['corp'])), 0, 300));
    }
    // Un site dinainte de 0.14 nu știe de teme proprii: răspunde cu starea actualizării, fără „teme".
    if (!array_key_exists('teme', $j)) throw new RuntimeException("site-ul are o versiune mai veche de 0.14 și nu știe de teme proprii. Actualizează-l întâi: php unelte/actualizeaza.php $site");
    return $j;
}

// O temă de pe calculator, așezată ca în assets/teme/: foaia <nume>.css și, lângă ea, dosarul <nume>/ cu fonturi și imagini.
// $cale e numele temei (se caută în <dosar>/teme/), foaia <nume>.css sau dosarul <nume>.
function tema_locala(string $cale, string $dosar): array
{
    $S = DIRECTORY_SEPARATOR;
    $cale = rtrim($cale, '/\\');
    if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $cale) && !file_exists($cale) && !file_exists("$cale.css")) $cale = "$dosar{$S}teme{$S}$cale";
    if (strtolower(substr($cale, -4)) === '.css') $cale = substr($cale, 0, -4);
    $nume = basename($cale);
    if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $nume)) opreste("numele temei, \"$nume\", poate avea doar litere mici, cifre și cratime.");
    if (!is_file("$cale.css")) {
        opreste("nu găsesc foaia de stil $cale.css. O temă stă în dosarul teme/ la fel ca în assets/teme/: $nume.css și, lângă ea, dosarul $nume/ cu fonturile.");
    }
    $fisiere = ["$nume.css" => (string) file_get_contents("$cale.css")];
    $sarite = [];
    if (is_dir($cale)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cale, FilesystemIterator::SKIP_DOTS)) as $f) {
            if (!$f->isFile()) continue;
            $rel = $nume . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($cale) + 1));
            $baza = $f->getFilename();
            if ($baza[0] === '.' || in_array(strtolower($baza), ['thumbs.db', 'desktop.ini'], true)) { $sarite[] = $rel; continue; }   // puse de sistem
            $fisiere[$rel] = (string) file_get_contents($f->getPathname());
        }
    }
    ksort($fisiere);
    return ['nume' => $nume, 'fisiere' => $fisiere, 'sarite' => $sarite, 'cale' => $cale];
}

// Adresele din foaie spre fișiere care nu vin cu tema. Greșeala tipică: o temă făcută din copia alteia, redenumită
// doar pe jumătate, care încarcă încă fonturile temei vechi.
function tema_adrese_lipsa(array $tema): array
{
    $nume = $tema['nume'];
    $probleme = [];
    preg_match_all('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', $tema['fisiere']["$nume.css"], $m);
    foreach (array_unique(array_map('trim', $m[2])) as $u) {
        if (preg_match('#^(https?:)?//#i', $u)) {
            if (preg_match('#\.(woff2?|ttf|otf|css)([?\#]|$)#i', $u)) $probleme[] = "$u — fonturile și foile de stil se încarcă doar de pe site (CSP): pune fișierul în $nume/";
            continue;
        }
        if (preg_match('#^(data:|/|\#)#i', $u)) continue;
        $fara = (string) preg_replace('/[?#].*$/', '', $u);
        if (isset($tema['fisiere'][$fara])) continue;
        $probleme[] = strpos($fara, "$nume/") === 0 ? "$u — fișierul lipsește din dosarul $nume/" : "$u — nu e în dosarul temei ($nume/), deci nu vine cu ea";
    }
    return $probleme;
}

function arata_teme(array $teme): void
{
    titlu('Temele de pe site');
    info('de bază, din depozit: ' . ($teme['de_baza'] ? implode(', ', $teme['de_baza']) : 'niciuna'));
    if (!$teme['proprii']) info('proprii: niciuna');
    foreach ($teme['proprii'] as $t) {
        info(sprintf('proprie: %-22s %2d fișiere, %4d KB, pusă la %s', $t['nume'], (int) ($t['fisiere'] ?? 0),
            (int) round(((int) ($t['octeti'] ?? 0)) / 1024), str_replace('T', ' ', substr((string) ($t['pusa_la'] ?? ''), 0, 16))));
    }
    info('aleasă acum: ' . (($teme['activa'] ?? '') !== '' ? $teme['activa'] : 'niciuna (aspectul implicit)'));
}

// Pune tema pe site și o verifică din afară. Întoarce true dacă totul a mers.
function pune_tema(string $site, string $cheie, array $tema): bool
{
    $nume = $tema['nume'];
    $octeti = array_sum(array_map('strlen', $tema['fisiere']));
    ok("$nume: " . count($tema['fisiere']) . ' fișiere, ' . round($octeti / 1024) . ' KB, din ' . $tema['cale'] . '.css' . (count($tema['fisiere']) > 1 ? " și $nume" . DIRECTORY_SEPARATOR : ''));
    foreach ($tema['sarite'] as $s) info("sărit (pus de sistem): $s");
    foreach (tema_adrese_lipsa($tema) as $p) atentie($p);
    try {
        $j = cerere_cod($site, $cheie, ['actiune' => 'pune_tema', 'nume' => $nume, 'fisiere' => array_map('base64_encode', $tema['fisiere'])]);
    } catch (RuntimeException $e) {
        gresit($e->getMessage());
        return false;
    }
    $rez = (array) ($j['rezultat'] ?? []);
    ok("tema $nume e " . ($rez['operatie'] ?? '?') . ' pe site' . (!empty($rez['copie']) ? ' · versiunea de dinainte e în copia ' . $rez['copie'] . ' de pe server' : ''));
    if (!empty($rez['scoase'])) info('au ieșit fișierele care nu mai sunt în temă: ' . implode(', ', (array) $rez['scoase']));
    // Din afară, cu o adresă nouă de fiecare dată: Cloudflare ține foile de stil în memoria lui, pe adresă.
    $r = cerere('GET', "$site/assets/teme/$nume.css?v=" . time());
    if ($r['cod'] !== 200 || $r['corp'] !== $tema['fisiere']["$nume.css"]) {
        gresit("$site/assets/teme/$nume.css răspunde {$r['cod']}" . ($r['cod'] === 200 ? ', dar cu alt conținut' : ''));
        return false;
    }
    ok("verificat din afară: /assets/teme/$nume.css e cea de pe calculator");
    if (!empty($rez['activa'])) ok('e tema aleasă a site-ului: se vede deja pe pagini');
    else info("AI-ul o alege cu seteaza_site, tema = \"$nume\" (o vede în despre_site).");
    arata_teme((array) $j['teme']);
    return true;
}

function in_clipboard(string $text): bool
{
    if (DIRECTORY_SEPARATOR === '\\') $cmd = ['clip'];
    elseif (PHP_OS_FAMILY === 'Darwin') $cmd = ['pbcopy'];
    else return false;
    $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) return false;
    fwrite($pipes[0], $text);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($proc) === 0;
}

function asteapta_enter(string $intrebare): bool   // true = continuă, false = ieșire
{
    echo "\n", culoare($intrebare, '1;33'), ' ';
    $raspuns = fgets(STDIN);
    return $raspuns !== false && strtolower(trim($raspuns)) !== 'q';
}
