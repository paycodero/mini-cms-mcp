<?php
// Instalarea unui site nou, de pe calculatorul tău, în doi pași:
//
//   1. php unelte/instaleaza.php https://site.ro
//      rulează testele, generează cheile, scrie config.php și face pachetul .zip de urcat;
//   2. urci pachetul în cPanel (File Manager → Upload → Extract) și apeși Enter:
//      comanda verifică serverul, leagă Claude Code și îți arată jurnalul.
//
// Opțiuni:
//   --oauth[=minute] deschide fereastra în care Claude se poate lega de site (implicit 15 minute) și arată
//                    codul de conectare cerut pe pagina de aprobare; --oauth --inchide o închide imediat
//   --date-afara     dosarul de date iese din rădăcina publică (../date-<nume>), ca protecția lui să nu depindă
//                    de .htaccess; alegerea se păstrează la fiecare pachet următor
//   --verifica       doar pasul 2 (după ce ai urcat pachetul)
//   --nume=scurt     numele conexiunii din Claude și al fișierelor (implicit din adresă: test.paycode.ro → test-paycode)
//   --dosar=CALE     unde stau cheile, pachetul și conexiunea Claude (implicit: folderul de deasupra repo-ului)
//   --chei-noi       schimbă cheile (dacă una a scăpat): cele vechi se păstrează cu data în nume, pachetul se reface,
//                    iar la verificare conexiunea Claude primește cheia nouă
//   --fara-claude    verifică serverul, dar nu leagă Claude Code
//   --fara-teste     sare peste teste (nerecomandat)
//   --tema=NUME      după verificare pune pe site tema proprie din <dosar>/teme/NUME.css + NUME/ (sau --tema=CALE);
//                    temele proprii nu intră în pachet și nici în depozit — vezi unelte/tema.php
//   --local          acceptă http:// — doar pentru probe pe calculator
//
// Cheile se scriu într-un fișier, niciodată pe ecran. Nimic nu se suprascrie: ce există se refolosește
// (cheile) sau se păstrează cu data în nume (config.php, pachetul).
declare(strict_types=1);

require __DIR__ . '/comun.php';

['opt' => $opt, 'site' => $site, 'nume' => $nume, 'dosar' => $dosar, 'fisier_chei' => $fisier_chei] = porneste($argv,
    ['verifica', 'chei-noi', 'fara-claude', 'fara-teste', 'oauth', 'inchide', 'date-afara', 'tema'],
    'php unelte/instaleaza.php https://site.ro [--verifica] [--chei-noi] [--oauth[=minute]] [--tema=NUME] [--nume=scurt] [--dosar=CALE] [--fara-claude]');
$S = DIRECTORY_SEPARATOR;
$livrare = "$dosar{$S}_livrare{$S}$nume";
$nume_zip = "minicms-$VERSIUNE-$nume.zip";

echo culoare("mini-cms-mcp $VERSIUNE — instalarea site-ului $site", '1'), "\n";
echo "Conexiunea Claude: $nume · cheile: $fisier_chei\n";
// Tema proprie se citește de la început, ca o cale greșită să oprească totul înainte de pachet, nu după urcare.
if (isset($opt['tema']) && (!is_string($opt['tema']) || $opt['tema'] === '')) opreste('--tema are nevoie de numele temei sau de calea ei, ex. --tema=client');
$tema = isset($opt['tema']) ? tema_locala($opt['tema'], $dosar) : null;

// --- funcții -----------------------------------------------------------------------------------

function text_config(string $site, array $chei, string $versiune, string $date = ''): string
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
        . "        'cod' => " . $e((string) ($chei['cod']['amprenta'] ?? '')) . ",   // doar actualizarea codului; AI-ul n-o primește niciodată\n"
        . "    ],\n"
        . ($date !== '' ? "    // dosarul de date, în afara rădăcinii publice (--date-afara): nu depinde de .htaccess\n"
            . "    'date' => $date,\n" : '')
        . "];\n";
}

// Dosarul de date ales la o instalare anterioară: se păstrează la fiecare pachet nou, altfel un config
// rescris ar trimite site-ul spre alt dosar, iar conținutul ar părea dispărut.
function date_din_config(string $fisier): string
{
    if (!is_file($fisier)) return '';
    return preg_match("/^\s*'date'\s*=>\s*(.+?),\s*$/m", (string) file_get_contents($fisier), $m) ? trim($m[1]) : '';
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
                '/date/securitate/previzualizare.cheie', '/date/oauth/tokenuri.json', '/date/site.json',
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

// --- fereastra de conectare (OAuth), deschisă de om, de aici ------------------------------------
// Din 0.6, nimeni nu se poate înregistra ca aplicație pe site dacă omul n-a deschis o fereastră scurtă.
// Codul de 6 cifre se arată o singură dată, aici, și se cere pe pagina de aprobare, lângă cheie.
if (isset($opt['oauth'])) {
    $chei = cheile($fisier_chei, true);
    $minute = is_string($opt['oauth']) ? max(1, min(60, (int) $opt['oauth'])) : 15;
    $inchide = isset($opt['inchide']);
    titlu($inchide ? 'Închid fereastra de conectare' : 'Deschid fereastra de conectare');
    $corp = json_encode($inchide ? ['inchide' => true] : ['minute' => $minute]);
    $r = ['cod' => 0];
    foreach (['Authorization' => 'Bearer ' . $chei['scriere']['cheie'], 'X-API-Key' => $chei['scriere']['cheie']] as $antet => $valoare) {
        $r = cerere('POST', "$site/oauth/deschide", (string) $corp, ['Content-Type' => 'application/json', $antet => $valoare]);
        if ($r['cod'] !== 401) break;
    }
    $d = json_decode($r['corp'], true);
    if ($r['cod'] === 404) opreste("site-ul are OAuth închis din config.php ('oauth' => false) sau o versiune mai veche de 0.6.");
    if ($r['cod'] !== 200 || !is_array($d)) opreste('serverul a răspuns ' . ($r['cod'] ?: 'nimic') . ': ' . substr(trim((string) ($d['error_description'] ?? $r['corp'] ?: $r['eroare'])), 0, 200));
    if ($inchide) { ok('fereastra e închisă: nicio aplicație nu se mai poate înregistra sau aproba'); exit(0); }
    ok("fereastra e deschisă $minute minute (până la " . date('H:i', (int) strtotime((string) $d['expira'])) . ')');
    echo "\n       ", culoare('CODUL DE CONECTARE:  ' . $d['cod'], '1;33'), "\n\n";
    info('Îl ceri pe pagina de aprobare a site-ului, lângă cheia de scriere. Nu-l trimite nimănui: e singurul lucru');
    info('pe care un străin nu-l poate avea, chiar dacă îți citește codul sursă și îți trimite un link de aprobare.');
    info("Acum, în claude.ai: Settings → Connectors → Add custom connector → $site/mcp");
    info('După aprobare, fereastra se închide singură. O închizi manual cu: --oauth --inchide');
    exit(0);
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
    $fisier_config = "$livrare{$S}config.php";
    $date_config = date_din_config($fisier_config);
    if (isset($opt['date-afara'])) {
        $date_config = "dirname(__DIR__, 2) . '/date-$nume'";
    } elseif ($date_config !== '') {
        info("dosarul de date rămâne cel ales înainte: $date_config");
    }
    $config = text_config($site, $chei, $VERSIUNE, $date_config);
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
    if ($date_config !== '') {
        atentie("dosarul de date e în afara rădăcinii publice: $date_config");
        info('La un site nou se creează singur. Dacă site-ul are deja conținut în <rădăcină>/date, mută dosarul acolo');
        info('ÎNAINTE de a urca pachetul — altfel site-ul pornește gol (conținutul rămâne pe disc, în vechiul dosar).');
    }

    titlu('Acum pe server, în cPanel');
    echo "  1. File Manager → folderul domeniului (" . parse_url($site, PHP_URL_HOST) . ")\n";
    echo "  2. Upload → $nume_zip\n";
    echo "  3. Clic dreapta pe zip → Extract. Apoi șterge zip-ul.\n";
    echo "     Dacă folderul avea deja un .htaccess de la cPanel, după Extract alegi din nou versiunea\n";
    echo "     de PHP în MultiPHP Manager (8.0 sau mai nouă), ca cPanel să-și pună blocul înapoi.\n";

    if (!$INTERACTIV) {
        echo "\nDupă ce ai urcat pachetul: php unelte/instaleaza.php $site --verifica" . ($tema ? " --tema=\"{$opt['tema']}\"" : '') . "\n";
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

// Tema proprie merge pe drumul temelor proprii (cheia de cod), nu în pachet: așa o recunoaște serverul ca proprie,
// iar sincronizarea cu depozitul n-o atinge niciodată.
if ($tema) {
    titlu("Tema proprie {$tema['nume']}");
    if (!pune_tema($site, (string) $chei['cod']['cheie'], $tema)) {
        opreste("site-ul e instalat, dar tema nu s-a pus. După ce repari, o pui cu: php unelte/tema.php $site --pune=\"{$opt['tema']}\"");
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
