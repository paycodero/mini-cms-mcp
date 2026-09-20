<?php
// Funcțiile comune ale uneltelor de pe calculator (instaleaza.php, copie.php): argumentele, cheile, cererile HTTP
// și apelurile MCP. Nu ajunge pe server.
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
