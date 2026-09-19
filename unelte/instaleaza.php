<?php
// Instalarea unui site nou, de pe calculatorul tău, în doi pași:
//
//   1. php unelte/instaleaza.php https://site.ro
//      rulează testele, generează cheile, scrie config.php și face pachetul .zip de urcat;
//   2. urci pachetul în cPanel (File Manager → Upload → Extract) și apeși Enter:
//      comanda verifică serverul, leagă Claude Code și îți arată jurnalul.
//
// Opțiuni:
//   --verifica       doar pasul 2 (după ce ai urcat pachetul)
//   --nume=scurt     numele conexiunii din Claude și al fișierelor (implicit din adresă: test.paycode.ro → test-paycode)
//   --dosar=CALE     unde stau cheile, pachetul și conexiunea Claude (implicit: folderul de deasupra repo-ului)
//   --chei-noi       schimbă cheile (dacă una a scăpat): cele vechi se păstrează cu data în nume, pachetul se reface,
//                    iar la verificare conexiunea Claude primește cheia nouă
//   --fara-claude    verifică serverul, dar nu leagă Claude Code
//   --fara-teste     sare peste teste (nerecomandat)
//   --local          acceptă http:// — doar pentru probe pe calculator
//
// Cheile se scriu într-un fișier, niciodată pe ecran. Nimic nu se suprascrie: ce există se refolosește
// (cheile) sau se păstrează cu data în nume (config.php, pachetul).
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

// --- argumentele (și după adresă, nu doar înainte) ----------------------------------------------

$opt = [];
$pozitionale = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opt[$m[1]] = $m[2] ?? true;
    else $pozitionale[] = $a;
}
$necunoscute = array_diff(array_keys($opt), ['verifica', 'nume', 'dosar', 'chei-noi', 'fara-claude', 'fara-teste', 'local']);
if ($necunoscute || count($pozitionale) !== 1) {
    fwrite(STDERR, "Folosire: php unelte/instaleaza.php https://site.ro [--verifica] [--chei-noi] [--nume=scurt] [--dosar=CALE] [--fara-claude]\n");
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

$gazda = preg_replace('/^www\./', '', strtolower($p['host']));
$parti = explode('.', $gazda);
if (count($parti) > 1 && !filter_var($gazda, FILTER_VALIDATE_IP)) array_pop($parti);
$nume = is_string($opt['nume'] ?? null) ? $opt['nume'] : trim((string) preg_replace('/[^a-z0-9]+/', '-', implode('-', $parti)), '-');
if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $nume)) opreste("--nume trebuie să aibă doar litere mici, cifre și cratime (acum: \"$nume\").");

$dosar = is_string($opt['dosar'] ?? null) ? rtrim($opt['dosar'], '/\\') : dirname($REPO);
if (!is_dir($dosar)) opreste("dosarul $dosar nu există.");
$dosar = (string) realpath($dosar);
$S = DIRECTORY_SEPARATOR;
$fisier_chei = "$dosar{$S}chei-$nume.json";
$livrare = "$dosar{$S}_livrare{$S}$nume";
$nume_zip = "minicms-$VERSIUNE-$nume.zip";

echo culoare("mini-cms-mcp $VERSIUNE — instalarea site-ului $site", '1'), "\n";
echo "Conexiunea Claude: $nume · cheile: $fisier_chei\n";

// --- funcții -----------------------------------------------------------------------------------

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
        if (!$doar_citeste) ok('cheile există deja — le refolosesc (nu se generează altele peste ele)');
        return $c;
    }
    if ($doar_citeste) opreste("nu există $fisier. Rulează întâi fără --verifica, ca să faci cheile și pachetul.");
    $c = [];
    foreach (['citire' => 'c', 'scriere' => 's'] as $rol => $pref) {
        $cheie = 'mcms_' . $pref . '_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $c[$rol] = ['cheie' => $cheie, 'amprenta' => hash('sha256', $cheie)];
    }
    if (file_put_contents($fisier, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) opreste("nu pot scrie $fisier.");
    @chmod($fisier, 0600);
    ok('două chei noi, scrise în fișier (nu pe ecran)');
    info('Păstrează o copie (manager de parole): pe server stau doar amprentele; fără fișier, le refaci cu --chei-noi.');
    return $c;
}

function text_config(string $site, array $chei, string $versiune): string
{
    $e = fn($s) => var_export($s, true);
    return "<?php\n"
        . "// Scris de unelte/instaleaza.php (mini-cms-mcp $versiune) la " . date('Y-m-d H:i') . ".\n"
        . "// Pe server stau doar amprentele cheilor. Numele, descrierea și restul identității le setează AI-ul (seteaza_site).\n"
        . "// Opțiunile posibile sunt descrise în app/config.exemplu.php.\n"
        . "if (!defined('MINICMS')) { http_response_code(403); exit; }\n\n"
        . "return [\n"
        . "    'site' => ['url' => " . $e($site) . "],\n"
        . "    'chei' => [\n"
        . "        'citire' => " . $e($chei['citire']['amprenta']) . ",\n"
        . "        'scriere' => " . $e($chei['scriere']['amprenta']) . ",\n"
        . "    ],\n"
        . "];\n";
}

function fa_pachetul(string $repo, string $zip, string $config): int
{
    $sursa = "$repo/site";
    $pachet = new PharData($zip, 0, null, Phar::ZIP);
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sursa, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($sursa) + 1));
        if ($rel === 'app/config.php' || preg_match('#^(date|media)/#', $rel) || preg_match('/\.zip$/i', $rel)) continue;
        $pachet->addFile($f->getPathname(), $rel);
        $n++;
    }
    $pachet->addFromString('app/config.php', $config);
    return $n + 1;
}

function cerere(string $metoda, string $url, ?string $corp = null, array $antete = []): array
{
    global $VERSIUNE;
    $h = "User-Agent: mini-cms-mcp-instalare/$VERSIUNE\r\n";
    foreach ($antete as $k => $v) $h .= "$k: $v\r\n";
    $ctx = stream_context_create(['http' => ['method' => $metoda, 'header' => $h, 'content' => $corp ?? '',
        'ignore_errors' => true, 'timeout' => 20, 'follow_location' => 0]]);
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

// Întoarce antetul prin care cheia ajunge la PHP ('Authorization' sau 'X-API-Key'), ori null dacă ceva nu e în regulă.
function verifica_serverul(string $site, array $chei, string $nume_zip): ?string
{
    titlu('Verific serverul');
    $prima = cerere('GET', "$site/");
    if ($prima['cod'] === 0) {
        gresit("$site nu răspunde: " . ($prima['eroare'] ?: 'fără răspuns'));
        info(stripos($prima['eroare'], 'getaddrinfo') !== false || stripos($prima['eroare'], 'resolve') !== false
            ? 'Subdomeniul abia creat nu e încă în DNS. Mai așteaptă câteva minute.'
            : 'Verifică adresa și certificatul HTTPS al domeniului.');
        return null;
    }
    $bune = true;
    foreach (['/' => 200, '/mcp' => 405] as $cale => $cod) {
        $r = $cale === '/' ? $prima : cerere('GET', $site . $cale);
        if ($r['cod'] === $cod) { ok(sprintf('%-34s %d', $cale, $r['cod'])); continue; }
        $bune = false;
        gresit(sprintf('%-34s %d, trebuia %d', $cale, $r['cod'], $cod));
        if ($cale === '/' && $r['cod'] === 503) info(trim($r['corp']));
        if ($cale === '/' && $r['cod'] === 500) info('Eroare PHP: alege PHP 8.0 sau mai nou în MultiPHP Manager (și dacă era deja ales — cPanel își rescrie blocul în .htaccess).');
        if ($cale === '/' && $r['cod'] === 404) info('Pachetul nu pare despachetat în folderul acestui domeniu.');
        if ($cale === '/mcp' && $r['cod'] === 404) info('Lipsește .htaccess din rădăcină: despachetează din nou pachetul.');
    }
    // Adresele interne trebuie să fie blocate. Unele găzduiri răspund la blocare cu 404 în loc de 403 (trimit eroarea
    // spre pagina de eroare a site-ului): ambele sunt bune, cu condiția ca din răspuns să nu scape nimic din fișier.
    // Jurnalul lunii există sigur: cererea GET de mai sus, pe /mcp, tocmai a fost scrisă în el.
    $blocate = ['/app/config.php', '/date/', '/date/jurnal/' . date('Y-m') . '.ndjson', '/sabloane/baza.php',
                '/.htaccess', '/media/proba.php', "/$nume_zip"];
    $urme = ['<?php', 'RewriteEngine', 'Require all denied', '{"t":"', 'Index of /', "PK\x03\x04"];
    foreach ($blocate as $cale) {
        $r = cerere('GET', $site . $cale);
        $scapat = array_values(array_filter($urme, fn($u) => strpos($r['corp'], $u) !== false));
        if (in_array($r['cod'], [403, 404], true) && !$scapat) { ok(sprintf('%-34s %d, blocat', $cale, $r['cod'])); continue; }
        $bune = false;
        gresit(sprintf('%-34s %d, trebuia să fie blocat', $cale, $r['cod']));
        if ($cale === "/$nume_zip") info('Pachetul se poate descărca: șterge-l din File Manager.');
        elseif ($r['cod'] === 200 || $scapat) info('Se vede din internet: .htaccess lipsește sau serverul îl ignoră (nginx). Nu lega AI-ul până nu e blocat.');
    }
    if (!$bune) return null;

    $antet = 'Authorization';
    $r = unelte_mcp($site, $chei['citire']['cheie'], $antet);
    if ($r['cod'] === 401) {
        $r2 = unelte_mcp($site, $chei['citire']['cheie'], 'X-API-Key');
        if ($r2['cod'] === 200) {
            atentie('găzduirea nu transmite antetul Authorization către PHP; folosesc antetul X-API-Key');
            $antet = 'X-API-Key';
            $r = $r2;
        }
    }
    if ($r['cod'] === 429) { gresit('adresa ta e blocată 15 minute după prea multe încercări greșite. Reia după aceea cu --verifica.'); return null; }
    if ($r['cod'] === 401) {
        gresit('serverul nu recunoaște cheile: config.php de pe server nu e cel din acest pachet.');
        info('Fiecare verificare greșită consumă 2 din cele 8 încercări permise în 5 minute.');
        return null;
    }
    if ($r['cod'] !== 200 || !in_array('despre_site', $r['unelte'], true) || in_array('salveaza', $r['unelte'], true)) {
        gresit("cheia de citire: răspuns neașteptat (cod {$r['cod']})");
        return null;
    }
    ok('cheia de citire: ' . count($r['unelte']) . ' comenzi, doar de citire');
    $r = unelte_mcp($site, $chei['scriere']['cheie'], $antet);
    if ($r['cod'] !== 200 || !in_array('salveaza', $r['unelte'], true)) { gresit("cheia de scriere: răspuns neașteptat (cod {$r['cod']})"); return null; }
    ok('cheia de scriere: ' . count($r['unelte']) . ' comenzi, inclusiv scrierea');
    return $antet;
}

function leaga_claude(string $nume, string $site, string $cheie, string $antet, string $dosar): bool
{
    titlu('Leg Claude Code de site');
    $gasit = DIRECTORY_SEPARATOR === '\\' ? ruleaza(['where', 'claude']) : ruleaza(['sh', '-c', 'command -v claude']);
    if ($gasit['cod'] !== 0) {
        atentie('nu găsesc comanda claude pe acest calculator. Legătura se face de mână, cu comanda din ghid.');
        return false;
    }
    $exista = ruleaza(['claude', 'mcp', 'get', $nume], $dosar);
    if ($exista['cod'] === 0) {   // răspunsul conține și cheia din conexiune: nu se afișează niciodată
        $aceeasi_adresa = strpos($exista['iesire'], "$site/mcp") !== false;
        if ($aceeasi_adresa && strpos($exista['iesire'], $cheie) !== false) { ok("conexiunea \"$nume\" există deja, cu adresa și cheia de acum"); return true; }
        if (!$aceeasi_adresa || stripos($exista['iesire'], 'Local config') === false) {
            atentie("există deja o conexiune \"$nume\" " . ($aceeasi_adresa ? 'cu altă cheie' : 'spre altă adresă')
                . ", în alt loc decât acest folder. O scoți cu: claude mcp remove $nume — apoi reiei cu --verifica.");
            return false;
        }
        $scoasa = ruleaza(['claude', 'mcp', 'remove', $nume, '-s', 'local'], $dosar);
        if ($scoasa['cod'] !== 0) { gresit("nu am putut înlocui conexiunea veche: claude mcp remove $nume -s local"); return false; }
        info('conexiunea veche avea o cheie retrasă — o înlocuiesc');
    }
    $valoare = $antet === 'Authorization' ? "Bearer $cheie" : $cheie;
    $r = ruleaza(['claude', 'mcp', 'add', '--transport', 'http', $nume, "$site/mcp", '--header', "$antet: $valoare"], $dosar);
    if ($r['cod'] !== 0) {
        gresit('claude mcp add a eșuat: ' . trim(str_replace($cheie, '***', $r['iesire'])));
        return false;
    }
    ok("conexiunea \"$nume\" e adăugată, cu cheia de scriere");
    info("Merge în sesiunile de Claude deschise în $dosar.");
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

// --- pasul 1: pe calculator --------------------------------------------------------------------

$verifica = !empty($opt['verifica']);
if ($verifica) {
    $chei = cheile($fisier_chei, true);
} else {
    if (empty($opt['fara-teste'])) {
        titlu('Rulez testele');
        $t = ruleaza([PHP_BINARY, "$REPO/teste/ruleaza.php"], $REPO);
        $ultima = trim((string) substr($t['iesire'], (int) strrpos(rtrim($t['iesire']), "\n")));
        if ($t['cod'] !== 0) {
            foreach (preg_split('/\R/', $t['iesire']) as $linie) if (strpos($linie, 'ESEC') !== false) gresit(trim($linie));
            opreste("testele nu trec ($ultima). Nu se urcă nimic până nu trec toate.");
        }
        ok($ultima);
    }

    titlu('Cheile');
    if (!empty($opt['chei-noi']) && is_file($fisier_chei)) info('cheile vechi păstrate ca ' . basename((string) pastreaza_existent($fisier_chei)) . ' — pe server nu mai merg după ce urci noul config.php');
    $chei = cheile($fisier_chei);

    titlu('Pachetul de urcat');
    if (!is_dir($livrare) && !mkdir($livrare, 0755, true)) opreste("nu pot crea $livrare.");
    $config = text_config($site, $chei, $VERSIUNE);
    $fisier_config = "$livrare{$S}config.php";
    $vechi = is_file($fisier_config) ? (string) file_get_contents($fisier_config) : null;
    $fara_data = fn(string $t) => preg_replace('/^\/\/ Scris de .*$/m', '', $t);
    if ($vechi !== null && $fara_data($vechi) === $fara_data($config)) {
        $config = $vechi;
    } else {
        if ($vechi !== null) info('config.php anterior păstrat ca ' . basename((string) pastreaza_existent($fisier_config)));
        file_put_contents($fisier_config, $config);
    }
    ok("config.php: adresa $site și amprentele cheilor (o copie în $livrare)");
    $zip = "$livrare{$S}$nume_zip";
    $anterior = pastreaza_existent($zip);
    if ($anterior) info('pachetul anterior păstrat ca ' . basename($anterior));
    try {
        $n = fa_pachetul($REPO, $zip, $config);
    } catch (Throwable $e) {
        opreste('nu am putut face pachetul: ' . $e->getMessage());
    }
    $continut = [];
    foreach (new RecursiveIteratorIterator(new PharData($zip)) as $f) {
        $cale = str_replace('\\', '/', $f->getPathname());
        $continut[] = substr($cale, (int) strpos($cale, "$nume_zip/") + strlen("$nume_zip/"));
    }
    $lipsa = array_diff(['.htaccess', 'index.php', 'mcp.php', 'app/.htaccess', 'app/config.php', 'sabloane/.htaccess'], $continut);
    if ($lipsa) opreste('pachetul e incomplet, lipsesc: ' . implode(', ', $lipsa));
    ok("$n fișiere, inclusiv cele trei .htaccess și config.php");
    info($zip);

    titlu('Acum pe server, în cPanel');
    echo "  1. File Manager → folderul domeniului (" . parse_url($site, PHP_URL_HOST) . ")\n";
    echo "  2. Upload → $nume_zip\n";
    echo "  3. Clic dreapta pe zip → Extract. Apoi șterge zip-ul.\n";
    echo "     Dacă folderul avea deja un .htaccess de la cPanel, după Extract alegi din nou versiunea\n";
    echo "     de PHP în MultiPHP Manager (8.0 sau mai nouă), ca cPanel să-și pună blocul înapoi.\n";

    if (!$INTERACTIV) {
        echo "\nDupă ce ai urcat pachetul: php unelte/instaleaza.php $site --verifica\n";
        exit(0);
    }
    if (!asteapta_enter('Apasă Enter după Extract (sau q și Enter ca să ieși; reiei cu --verifica):')) exit(0);
}

// --- pasul 2: verificarea serverului și legarea ------------------------------------------------

while (($antet = verifica_serverul($site, $chei, $nume_zip)) === null) {
    if (!$INTERACTIV || !asteapta_enter('Repari pe server și apeși Enter ca să verific din nou (q = ieșire):')) {
        echo "\nReiei oricând cu: php unelte/instaleaza.php $site --verifica\n";
        exit(1);
    }
}

$legat = empty($opt['fara-claude']) ? leaga_claude($nume, $site, $chei['scriere']['cheie'], $antet, $dosar) : false;

titlu('Gata');
ok("site-ul răspunde, dosarele interne sunt închise, cheile merg");
if ($legat) ok("Claude Code: deschide o sesiune nouă în $dosar și scrie, de exemplu:");
else info("Claude Code: comanda de legare e în ghid (pasul „Legarea”), cu antetul $antet.");
if ($legat) info('„Cheamă despre_site, apoi setează numele site-ului, descrierea și autorul, și fă o pagină acasa ca ciornă.”');
$pus = $INTERACTIV && in_clipboard($chei['citire']['cheie']);
ok("jurnalul: $site/jurnal.php" . ($pus ? ' — cheia de citire e în clipboard, o lipești în formular' : " — cheia de citire e în $fisier_chei"));
exit(0);
