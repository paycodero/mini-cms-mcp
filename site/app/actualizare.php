<?php
// Actualizarea codului de pe GitHub, pornită de om — niciodată singură, niciodată de AI.
//
// Cum merge: ceri sincronizarea (dintr-o comandă de pe calculatorul tău sau din pagina /actualizare.php,
// cu CHEIA DE COD), iar serverul descarcă pachetul depozitului, îl verifică, salvează fișierele pe care le
// înlocuiește și abia apoi scrie. După scriere își cere singur prima pagină: dacă site-ul nu mai răspunde
// cum trebuie, pune la loc versiunea veche în aceeași cerere (codul vechi e deja încărcat în memorie).
//
// Ce NU se poate, oricât de mult ar cere cineva:
// - nimic din afara dosarului site/ din pachet, nicio cale cu ".." și nicio extensie din afara listei;
// - app/config.php nu se atinge niciodată (adresa și amprentele cheilor rămân ale tale);
// - date/ și media/ nu se ating (conținutul și jurnalul tău);
// - o versiune mai veche decât cea instalată e refuzată, dacă nu ceri anume asta;
// - cheia de scriere (cea a AI-ului) NU deschide nimic de aici: e nevoie de cheia de cod, care stă doar
//   pe calculatorul omului. Un token OAuth al conectorului din claude.ai, cu atât mai puțin.
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

const COD_EXTENSII = ['php', 'css', 'js', 'woff2', 'woff', 'ttf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'txt', 'ico', 'json', 'md'];
const COD_NEATINSE = ['app/config.php'];
const COD_MAX_FISIER = 4 * 1024 * 1024;
const COD_MAX_TOTAL = 40 * 1024 * 1024;

function cod_sursa(): string
{
    $direct = trim((string) config('depozit_zip'));
    if ($direct !== '') return $direct;
    $depozit = trim((string) config('depozit'));
    if (!preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $depozit)) return '';
    return "https://codeload.github.com/$depozit/zip/refs/heads/" . (trim((string) config('depozit_ramura')) ?: 'main');
}

// Descărcarea, cu curl dacă există (unele găzduiri închid allow_url_fopen), altfel prin fluxuri.
function cod_descarca(string $url, string $tinta): array
{
    $antete = ['User-Agent: mini-cms-mcp/' . MINICMS_VERSIUNE, 'Accept: application/zip'];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $f = fopen($tinta, 'w');
        curl_setopt_array($ch, [CURLOPT_FILE => $f, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => $antete]);
        $ok = curl_exec($ch);
        $cod = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $eroare = $ok === false ? (string) curl_error($ch) : '';
        curl_close($ch);
        fclose($f);
        if ($ok !== false && $cod === 200) return ['ok' => true, 'octeti' => (int) @filesize($tinta)];
        @unlink($tinta);
        return ['ok' => false, 'eroare' => $eroare !== '' ? $eroare : "serverul depozitului a răspuns $cod"];
    }
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $antete), 'timeout' => 60,
        'follow_location' => 1, 'max_redirects' => 5], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $date = @file_get_contents($url, false, $ctx);
    if ($date === false || $date === '') return ['ok' => false, 'eroare' => 'nu am putut descărca pachetul (allow_url_fopen închis?)'];
    if (file_put_contents($tinta, $date) === false) return ['ok' => false, 'eroare' => 'nu am putut scrie pachetul descărcat'];
    return ['ok' => true, 'octeti' => strlen($date)];
}

// Fișierele din dosarul site/ al pachetului, verificate una câte una. Orice cale suspectă oprește totul.
function cod_fisiere_din_pachet(string $zip): array
{
    $phar = new PharData($zip, 0, null, Phar::ZIP);
    $fisiere = [];
    $total = 0;
    // Calea din arhivă se taie față de rădăcina arhivei, nu căutând „/site/" oriunde: calea fișierului
    // descărcat conține ea însăși /site/ (dosarul de date stă implicit în site/date/), iar o căutare
    // naivă ar tăia în locul greșit și n-ar găsi niciun fișier.
    $radacina_arhiva = 'phar://' . str_replace('\\', '/', $zip) . '/';
    foreach (new RecursiveIteratorIterator($phar) as $f) {
        $cale = str_replace('\\', '/', $f->getPathname());
        if (strncmp($cale, $radacina_arhiva, strlen($radacina_arhiva)) !== 0) continue;
        $parti = explode('/', substr($cale, strlen($radacina_arhiva)));
        $poz_site = array_search('site', $parti, true);
        if ($poz_site === false || $poz_site > 1) continue;   // site/... sau <dosar-depozit>/site/...
        $rel = implode('/', array_slice($parti, $poz_site + 1));
        if ($rel === '' || strpos($rel, '..') !== false || $rel[0] === '/' || preg_match('#[\x00-\x1F]#', $rel)) {
            throw new EroareCms("cale nepermisă în pachet: $rel");
        }
        if (in_array($rel, COD_NEATINSE, true) || preg_match('#^(date|media)/#', $rel)) continue;
        $nume = basename($rel);
        $ext = strtolower((string) pathinfo($rel, PATHINFO_EXTENSION));
        if ($nume !== '.htaccess' && !in_array($ext, COD_EXTENSII, true)) throw new EroareCms("fișier cu extensie nepermisă în pachet: $rel");
        $continut = (string) file_get_contents($f->getPathname());
        if (strlen($continut) > COD_MAX_FISIER) throw new EroareCms("fișier prea mare în pachet: $rel");
        $total += strlen($continut);
        if ($total > COD_MAX_TOTAL) throw new EroareCms('pachetul depășește limita de 40 MB');
        $fisiere[$rel] = $continut;
    }
    if (!isset($fisiere['app/nucleu.php']) || !isset($fisiere['index.php']) || !isset($fisiere['mcp.php'])) {
        throw new EroareCms('pachetul nu arată a mini-cms-mcp: lipsesc index.php, mcp.php sau app/nucleu.php');
    }
    return $fisiere;
}

function cod_versiune_din(string $nucleu): string
{
    return preg_match("/MINICMS_VERSIUNE = '([^']+)'/", $nucleu, $m) ? $m[1] : '';
}

function cod_radacina(): string
{
    return dirname(__DIR__);
}

// Ce s-ar schimba, fără să se scrie nimic.
function cod_stare(): array
{
    $sursa = cod_sursa();
    if ($sursa === '') throw new EroareCms('depozitul nu e configurat („depozit" în app/config.php)');
    $dir = dir_date('actualizare');
    $zip = $dir . '/pachet-' . bin2hex(random_bytes(4)) . '.zip';
    $d = cod_descarca($sursa, $zip);
    if (!$d['ok']) throw new EroareCms('descărcarea a eșuat: ' . $d['eroare']);
    try {
        $fisiere = cod_fisiere_din_pachet($zip);
    } finally {
        @unlink($zip);
    }
    $rad = cod_radacina();
    $schimbate = $noi = [];
    foreach ($fisiere as $rel => $continut) {
        $cale = "$rad/$rel";
        if (!is_file($cale)) $noi[] = $rel;
        elseif (hash_file('sha256', $cale) !== hash('sha256', $continut)) $schimbate[] = $rel;
    }
    sort($schimbate);
    sort($noi);
    return ['versiune_instalata' => MINICMS_VERSIUNE, 'versiune_in_pachet' => cod_versiune_din($fisiere['app/nucleu.php']),
            'sursa' => $sursa, 'fisiere_in_pachet' => count($fisiere), 'de_schimbat' => $schimbate, 'noi' => $noi,
            '_fisiere' => $fisiere];
}

// Sincronizarea propriu-zisă: copie de siguranță, scriere, autocontrol, iar la nevoie restaurare.
function cod_sincronizeaza(bool $forta = false): array
{
    $start = microtime(true);
    $stare = cod_stare();
    $fisiere = $stare['_fisiere'];
    unset($stare['_fisiere']);
    $de_scris = array_merge($stare['de_schimbat'], $stare['noi']);
    $jurnal = ['punct' => 'actualizare', 'cerere' => 'sincronizare', 'cheie' => 'cod'];

    if ($stare['versiune_in_pachet'] === '') throw new EroareCms('nu găsesc versiunea în pachet');
    if (!$forta && version_compare($stare['versiune_in_pachet'], MINICMS_VERSIUNE, '<')) {
        jurnal_scrie($jurnal + ['rezultat' => 'respins', 'detalii' => ['motiv' => 'versiune mai veche în depozit']]);
        throw new EroareCms("depozitul are versiunea {$stare['versiune_in_pachet']}, mai veche decât cea instalată (" . MINICMS_VERSIUNE
            . '). Trimite forta=true dacă chiar vrei să cobori versiunea.');
    }
    if (!$de_scris) {
        jurnal_scrie($jurnal + ['rezultat' => 'ok', 'detalii' => ['motiv' => 'nimic de schimbat']]);
        return $stare + ['operatie' => 'nimic de schimbat'];
    }

    $rad = cod_radacina();
    $eticheta = date('Ymd-His');
    $copie = dir_date('versiuni/cod/' . $eticheta);
    return cu_blocare(function () use ($fisiere, $de_scris, $rad, $copie, $eticheta, $stare, $jurnal, $start) {
        $salvate = [];
        foreach ($de_scris as $rel) {          // 1. copia de siguranță, înainte de orice scriere
            $cale = "$rad/$rel";
            if (!is_file($cale)) continue;
            $tinta = "$copie/$rel";
            if (!is_dir(dirname($tinta)) && !@mkdir(dirname($tinta), 0755, true)) throw new EroareCms("nu pot pregăti copia pentru $rel");
            if (!@copy($cale, $tinta)) throw new EroareCms("nu am putut salva versiunea anterioară a $rel — nu scriu nimic");
            $salvate[] = $rel;
        }
        @file_put_contents("$copie/_versiune.txt", MINICMS_VERSIUNE . "\n");

        $scrise = [];
        foreach ($de_scris as $rel) {          // 2. scrierea
            $cale = "$rad/$rel";
            if (!is_dir(dirname($cale)) && !@mkdir(dirname($cale), 0755, true)) throw new EroareCms("nu pot crea dosarul pentru $rel");
            if (!scrie_atomic($cale, $fisiere[$rel])) {
                cod_pune_inapoi($eticheta, $scrise);
                throw new EroareCms("scrierea a eșuat la $rel — am pus înapoi ce apucasem să schimb");
            }
            $scrise[] = $rel;
        }

        $control = cod_autocontrol();   // 3. site-ul mai răspunde?
        $rez = $stare + ['operatie' => 'actualizat', 'scrise' => count($scrise), 'copie' => $eticheta,
                         'control' => $control, 'ms' => (int) round((microtime(true) - $start) * 1000)];
        if ($control['stare'] === 'stricat') {
            cod_pune_inapoi($eticheta, $scrise);
            $rez['operatie'] = 'pus înapoi';
            $rez['atentie'] = 'după scriere site-ul nu a mai răspuns cum trebuie (' . $control['detaliu'] . '), '
                . 'așa că am pus la loc versiunea ' . MINICMS_VERSIUNE . '. Nimic nu s-a pierdut.';
            jurnal_scrie($jurnal + ['rezultat' => 'eroare', 'detalii' => ['motiv' => 'autocontrol picat, restaurat', 'control' => $control]]);
            return $rez;
        }
        jurnal_scrie($jurnal + ['rezultat' => 'ok', 'tinta' => $stare['versiune_in_pachet'],
            'detalii' => ['de_la' => MINICMS_VERSIUNE, 'la' => $stare['versiune_in_pachet'], 'fisiere' => count($scrise),
                          'copie' => $eticheta, 'control' => $control['stare']]]);
        if ($control['stare'] === 'necunoscut') {
            $rez['atentie'] = 'nu am putut să-mi cer singur prima pagină (' . $control['detaliu'] . '), deci verific-o tu. '
                . 'Dacă ceva nu e în regulă, pune înapoi copia ' . $eticheta . '.';
        }
        return $rez;
    });
}

// Serverul își cere singur prima pagină și punctul MCP. Dacă nu poate ajunge la el însuși (unele găzduiri
// nu permit), spune „necunoscut" — nu presupune că e stricat și nu strică o actualizare bună.
function cod_autocontrol(): array
{
    $secunde = max(2, (int) (config('autocontrol_secunde') ?: 10));
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => $secunde, 'ignore_errors' => true,
        'header' => "User-Agent: mini-cms-mcp-autocontrol/" . MINICMS_VERSIUNE . "\r\n"], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $corp = @file_get_contents(url_site() . '/', false, $ctx);
    $cod = 0;
    foreach (($http_response_header ?? []) as $linie) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linie, $m)) $cod = (int) $m[1];
    if ($cod === 0) return ['stare' => 'necunoscut', 'detaliu' => 'nu am primit răspuns de la mine însumi'];
    if ($cod >= 500 || $corp === false || $corp === '') return ['stare' => 'stricat', 'detaliu' => "prima pagină a răspuns $cod"];
    if ($cod !== 200) return ['stare' => 'stricat', 'detaliu' => "prima pagină a răspuns $cod"];
    return ['stare' => 'bun', 'detaliu' => 'prima pagină răspunde 200'];
}

function cod_copii(): array
{
    $rez = [];
    foreach (glob(config('date') . '/versiuni/cod/*', GLOB_ONLYDIR) ?: [] as $d) {
        $rez[] = ['copie' => basename($d), 'versiune' => trim((string) @file_get_contents("$d/_versiune.txt")),
                  'cand' => date('c', (int) filemtime($d))];
    }
    usort($rez, fn($a, $b) => strcmp($b['copie'], $a['copie']));
    return $rez;
}

function cod_pune_inapoi(string $eticheta, ?array $doar = null): int
{
    if (!preg_match('/^[0-9]{8}-[0-9]{6}$/', $eticheta)) throw new EroareCms('identificator de copie invalid');
    $copie = config('date') . '/versiuni/cod/' . $eticheta;
    if (!is_dir($copie)) throw new EroareCms("nu există copia $eticheta");
    $rad = cod_radacina();
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($copie, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($copie) + 1));
        if ($rel === '_versiune.txt' || strpos($rel, '..') !== false) continue;
        if ($doar !== null && !in_array($rel, $doar, true)) continue;
        if (scrie_atomic("$rad/$rel", (string) file_get_contents($f->getPathname()))) $n++;
    }
    return $n;
}

function cod_restaureaza(string $eticheta): array
{
    $n = cod_pune_inapoi($eticheta);
    jurnal_scrie(['punct' => 'actualizare', 'cerere' => 'restaurare', 'cheie' => 'cod', 'tinta' => $eticheta,
                  'rezultat' => 'ok', 'detalii' => ['fisiere' => $n]]);
    return ['operatie' => 'pus înapoi', 'copie' => $eticheta, 'fisiere' => $n,
            'atentie' => 'versiunea de dinaintea acelei actualizări e din nou pe server'];
}
