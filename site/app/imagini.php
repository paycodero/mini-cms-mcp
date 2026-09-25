<?php
// Imaginile: singurele fișiere pe care AI-ul le pune în dosarul public (media/).
// Tipul se află din conținut, nu din numele trimis: extensia o alegem noi (jpg/png/gif/webp),
// deci „shell.php" cu conținut de imagine ajunge „shell-a1b2c3d4.png". SVG nu se acceptă (poate conține script).
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

const IMAGINE_MAX_OCTETI = 5 * 1024 * 1024;
const IMAGINE_TIPURI = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
const IMAGINE_NUME = '/^[a-z0-9-]+\.(jpg|png|gif|webp)$/';

function dir_media(): string
{
    $d = (string) config('media');
    if (!is_dir($d) && !@mkdir($d, 0755, true) && !is_dir($d)) throw new RuntimeException('nu pot crea dosarul media');
    return $d;
}

function urca_imagine(string $nume, string $base64): array
{
    $base64 = (string) preg_replace('/^data:[^,]*,/', '', trim($base64));
    $date = base64_decode($base64, true);
    if ($date === false || $date === '') throw new EroareCms('conținutul nu e base64 valid');
    return urca_imagine_date($nume, $date);
}

// Aceleași verificări, oricum ar veni imaginea: prin MCP (base64 sau adresă), din unealta de pe calculator sau din browser.
function urca_imagine_date(string $nume, string $date): array
{
    if ($date === '') throw new EroareCms('fișierul e gol');
    if (strlen($date) > IMAGINE_MAX_OCTETI) throw new EroareCms('imaginea depășește 5 MB');
    $info = @getimagesizefromstring($date);
    if (!$info || !isset(IMAGINE_TIPURI[$info[2]])) {
        throw new EroareCms('nu e o imagine acceptată: doar JPEG, PNG, GIF sau WebP (SVG nu, poate conține cod)');
    }
    if ($info[0] < 1 || $info[1] < 1 || $info[0] > 10000 || $info[1] > 10000) throw new EroareCms('dimensiuni de imagine nepermise');
    if (preg_match('/<\?php|<script/i', $date)) throw new EroareCms('fișierul conține cod ascuns în imagine — refuzat');

    $baza = substr(slug_din_text((string) pathinfo($nume, PATHINFO_FILENAME)), 0, 60) ?: 'imagine';
    $fisier = rtrim($baza, '-') . '-' . substr(hash('sha256', $date), 0, 8) . '.' . IMAGINE_TIPURI[$info[2]];
    $tinta = dir_media() . '/' . $fisier;
    return cu_blocare(function () use ($tinta, $date, $fisier, $info) {
        $exista = is_file($tinta);
        if (!$exista && !scrie_atomic($tinta, $date)) throw new EroareCms('scrierea imaginii a eșuat');
        return ['operatie' => $exista ? 'exista deja (același conținut)' : 'urcată', 'url' => '/media/' . $fisier,
                'url_absolut' => url_absolut('/media/' . $fisier), 'latime' => $info[0], 'inaltime' => $info[1],
                'octeti' => strlen($date), 'amprenta' => hash('sha256', $date)];
    });
}

// --- imagini urcate după adresă: serverul descarcă singur, cu apărare împotriva SSRF ------------------------------------
// Pericolul: cine poate cere serverului „descarcă adresa X" îl poate trimite spre adrese la care numai serverul ajunge
// (127.0.0.1, rețeaua internă a găzduirii, 169.254.169.254 la cloud). De aceea: doar https, doar portul 443, numele se
// rezolvă o dată și TOATE adresele găsite trebuie să fie publice, iar conexiunea se face exact la adresa verificată
// (nu se mai întreabă DNS-ul a doua oară, deci nu poate fi păcălit între verificare și descărcare). Redirecționările
// se urmăresc de mână, cel mult 3, și fiecare trece prin aceleași verificări. Fără curl și fără allow_url_fopen.

const IMAGINE_URL_SALTURI = 3;
const IMAGINE_URL_SECUNDE = 20;
const RETELE_INTERZISE = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
    '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
    '224.0.0.0/4', '240.0.0.0/4', '::/96', '::ffff:0:0/96', '::ffff:0:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64',
    '2001::/32', '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8'];

// La IPv6, doar adresele globale (2000::/3); tot restul e refuzat din start. Așa nu contează în ce formă e ascunsă o adresă
// IPv4 internă (::127.0.0.1, ::a9fe:a9fe = 169.254.169.254, ::ffff:0:7f00:1): nu trebuie ghicită fiecare formă în parte.
// filter_var e un strat în plus, nu cel de bază: judecă după forma scrisă, deci ::a9fe:a9fe îi scapă.
function ip_public(string $ip): bool   // ip_in_retea() e cea din nucleu.php, folosită și pentru rețelele Cloudflare
{
    if (@inet_pton($ip) === false) return false;
    if (strpos($ip, ':') !== false && !ip_in_retea($ip, '2000::/3')) return false;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;
    foreach (RETELE_INTERZISE as $r) if (ip_in_retea($ip, $r)) return false;
    return true;
}

// [schema, gazda, port, cale, e_exceptie]. Excepțiile (config 'imagini_url_permise', ex. ["127.0.0.1:18100"]) există doar
// pentru testele automate: acolo se acceptă și http și adrese locale. Pe un site adevărat lista rămâne goală.
function adresa_imagine(string $url): array
{
    if (strlen($url) > 2000 || !preg_match('/^[\x21-\x7E]+$/', $url)) throw new EroareCms('adresa imaginii are caractere nepermise (spațiile și diacriticele se scriu codat, %20)');
    $p = parse_url($url);
    $schema = strtolower((string) ($p['scheme'] ?? ''));
    $gazda = strtolower(trim((string) ($p['host'] ?? ''), '[]'));
    if (!$p || $gazda === '' || isset($p['user']) || isset($p['pass'])) throw new EroareCms('"url" trebuie să fie o adresă întreagă, ex. https://exemplu.ro/poza.jpg');
    $port = (int) ($p['port'] ?? ($schema === 'http' ? 80 : 443));
    $exceptie = in_array("$gazda:$port", (array) config('imagini_url_permise'), true);
    if ($schema !== 'https' && !($exceptie && $schema === 'http')) throw new EroareCms('doar adrese https:// (o imagine adusă prin http poate fi schimbată pe drum)');
    if ($port !== 443 && !$exceptie) throw new EroareCms('doar adrese pe portul obișnuit (443)');
    $cale = ($p['path'] ?? '') !== '' ? $p['path'] : '/';
    if (isset($p['query'])) $cale .= '?' . $p['query'];
    return [$schema, $gazda, $port, $cale, $exceptie];
}

function ip_pentru_descarcare(string $gazda, bool $exceptie): string
{
    if (filter_var($gazda, FILTER_VALIDATE_IP)) {
        $ips = [$gazda];
    } else {
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $gazda)) {
            throw new EroareCms("numele $gazda nu e valid (un domeniu cu diacritice se scrie în forma xn--)");
        }
        $ips = [];
        foreach ((array) @dns_get_record($gazda, DNS_A + DNS_AAAA) as $r) {
            if (isset($r['ip'])) $ips[] = (string) $r['ip'];
            if (isset($r['ipv6'])) $ips[] = (string) $r['ipv6'];
        }
        if (!$ips) $ips = (array) @gethostbynamel($gazda);
    }
    $ips = array_values(array_filter($ips, 'is_string'));
    if (!$ips) throw new EroareCms("nu găsesc adresa lui $gazda");
    if (!$exceptie) {
        foreach ($ips as $ip) if (!ip_public($ip)) throw new EroareCms("$gazda duce la o adresă internă ($ip): refuzat");
    }
    foreach ($ips as $ip) if (strpos($ip, ':') === false) return $ip;   // întâi IPv4: merge pe orice găzduire
    return $ips[0];
}

function adresa_absoluta(string $baza, string $locatie): string
{
    if (preg_match('#^https?://#i', $locatie)) return $locatie;
    $p = parse_url($baza);
    $origine = $p['scheme'] . '://' . (strpos($p['host'], ':') !== false ? '[' . $p['host'] . ']' : $p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
    if (strncmp($locatie, '//', 2) === 0) return $p['scheme'] . ':' . $locatie;
    if (($locatie[0] ?? '') === '/') return $origine . $locatie;
    return $origine . rtrim(dirname($p['path'] ?? '/'), '/\\') . '/' . $locatie;
}

// O cerere GET, direct la adresa IP verificată. Întoarce codul, antetul Location și corpul (cel mult $max octeți:
// 5 MB la imagini, mai mult la documente — vezi fisiere.php).
function cerere_imagine(string $schema, string $gazda, string $ip, int $port, string $cale, float $termen,
                        int $max = IMAGINE_MAX_OCTETI, string $accept = 'image/*', string $ce = 'imaginea'): array
{
    $ctx = stream_context_create(['ssl' => ['peer_name' => $gazda, 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $tinta = ($schema === 'https' ? 'ssl://' : 'tcp://') . (strpos($ip, ':') !== false ? "[$ip]" : $ip) . ":$port";
    $s = @stream_socket_client($tinta, $nr, $text, max(1.0, $termen - microtime(true)), STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) throw new EroareCms("nu mă pot conecta la $gazda" . ($schema === 'https' ? ' (sau certificatul lui nu e valid)' : ''));
    $implicit = ($schema === 'https' && $port === 443) || ($schema === 'http' && $port === 80);
    $antet_gazda = (strpos($gazda, ':') !== false ? "[$gazda]" : $gazda) . ($implicit ? '' : ":$port");
    fwrite($s, "GET $cale HTTP/1.1\r\nHost: $antet_gazda\r\nUser-Agent: mini-cms-mcp/" . MINICMS_VERSIUNE
        . "\r\nAccept: $accept\r\nAccept-Encoding: identity\r\nConnection: close\r\n\r\n");
    $brut = '';
    $limita = $max + 65536;
    while (!feof($s)) {
        $ramas = $termen - microtime(true);
        if ($ramas <= 0) { fclose($s); throw new EroareCms('descărcarea a durat prea mult'); }
        stream_set_timeout($s, (int) ceil($ramas));
        $bucata = fread($s, 65536);
        if ($bucata === false || ($bucata === '' && !empty(stream_get_meta_data($s)['timed_out']))) break;
        $brut .= $bucata;
        if (strlen($brut) > $limita) { fclose($s); throw new EroareCms("$ce depășește " . intdiv($max, 1024 * 1024) . ' MB'); }
    }
    fclose($s);
    $sep = strpos($brut, "\r\n\r\n");
    if ($sep === false || !preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $brut, $m)) throw new EroareCms("$gazda nu a răspuns ca un server web");
    $antete = [];
    foreach (explode("\r\n", substr($brut, 0, $sep)) as $linie) {
        if (strpos($linie, ':') !== false) { [$k, $v] = explode(':', $linie, 2); $antete[strtolower(trim($k))] = trim($v); }
    }
    $corp = substr($brut, $sep + 4);
    if (stripos($antete['transfer-encoding'] ?? '', 'chunked') !== false) {
        $decodat = '';
        while ($corp !== '' && ($rand = strpos($corp, "\r\n")) !== false) {
            $marime = hexdec(trim(explode(';', substr($corp, 0, $rand))[0]));
            if ($marime <= 0) break;
            $decodat .= substr($corp, $rand + 2, $marime);
            $corp = substr($corp, $rand + 2 + $marime + 2);
        }
        $corp = $decodat;
    }
    if (($antete['content-encoding'] ?? 'identity') !== 'identity') throw new EroareCms("$gazda a trimis conținutul comprimat, nu îl pot citi");
    return ['cod' => (int) $m[1], 'locatie' => (string) ($antete['location'] ?? ''), 'corp' => $corp];
}

// Descărcarea după adresă, cu toate verificările de mai sus la fiecare salt. Întoarce [corpul, adresa finală].
// Folosită de imagini și de documente (fisiere.php), cu limitele lor.
function descarca_dupa_url(string $url, int $max = IMAGINE_MAX_OCTETI, string $accept = 'image/*',
                           string $ce = 'imaginea', int $secunde = IMAGINE_URL_SECUNDE): array
{
    if (config('imagini_url') === false) throw new EroareCms('urcarea după adresă e oprită pe acest site (\'imagini_url\' => false în config.php)');
    $termen = microtime(true) + $secunde;
    for ($salt = 0; ; $salt++) {
        [$schema, $gazda, $port, $cale, $exceptie] = adresa_imagine($url);
        $ip = ip_pentru_descarcare($gazda, $exceptie);
        $r = cerere_imagine($schema, $gazda, $ip, $port, $cale, $termen, $max, $accept, $ce);
        if (in_array($r['cod'], [301, 302, 303, 307, 308], true) && $r['locatie'] !== '') {
            if ($salt >= IMAGINE_URL_SALTURI) throw new EroareCms('prea multe redirecționări');
            $url = adresa_absoluta($url, $r['locatie']);
            continue;
        }
        if ($r['cod'] !== 200) throw new EroareCms("serverul a răspuns {$r['cod']} (adresa: $url)");
        return [$r['corp'], $url];
    }
}

function urca_imagine_din_url(string $url, string $nume): array
{
    $inceput = $url;
    [$corp, $url] = descarca_dupa_url($url);
    if ($nume === '') $nume = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH))) ?: 'imagine';
    return urca_imagine_date($nume, $corp) + ['sursa' => $inceput] + ($url !== $inceput ? ['sursa_finala' => $url] : []);
}

// --- linkul de urcare: omul alege poza, AI-ul o folosește ----------------------------------------------------------------
// O poză atașată în chat ajunge la AI ca imagine de privit, nu ca fișier pe care să-l poată trimite mai departe. Așa că AI-ul
// îi dă omului un link semnat, valabil puțin: omul îl deschide, alege poza, iar AI-ul o găsește apoi cu listeaza_imagini.
// Fără cheie și fără nimic de completat. Semnătura folosește cheia previzualizărilor, dar într-un domeniu separat
// („imagini:urcare|" conține „:", deci nu poate fi un slug): un link de previzualizare nu devine niciodată link de urcare.

// Linkul poartă și numele celui care l-a cerut (admin sau editorul), semnat odată cu expirarea:
// pozele urcate cu el apar în jurnal pe numele lui, nu doar ca „link".
function link_urcare(int $minute): array
{
    $minute = max(5, min(120, $minute));
    $expira = time() + $minute * 60;
    $cine = (string) (identitate_curenta()['cine'] ?? '');
    $semn = hash_hmac('sha256', "imagini:urcare|$expira|$cine", cheie_previzualizare(true));
    return ['url' => url_absolut("/imagini.php?e=$expira" . ($cine !== '' ? '&c=' . rawurlencode($cine) : '') . "&s=$semn"), 'expira' => date('c', $expira), 'minute' => $minute,
            'pasul_urmator' => 'Dă-i omului linkul. După ce spune că a urcat, cheamă listeaza_imagini: cele mai noi sunt primele.',
            'atentie' => 'oricine are linkul poate urca imagini până la expirare (doar imagini, verificate, scrise în jurnal)'];
}

function link_urcare_valid(int $expira, string $semn, string $cine = ''): bool
{
    $cheie = cheie_previzualizare(false);
    if ($cheie === '' || $semn === '' || $expira < time() || $expira > time() + 120 * 60 + 60) return false;
    $mesaj = $cine !== '' ? "imagini:urcare|$expira|$cine" : "imagini:urcare|$expira";   // fără nume: linkurile de dinainte de 0.19
    return hash_equals(hash_hmac('sha256', $mesaj, $cheie), $semn);
}

function listeaza_imagini(): array
{
    $rez = [];
    foreach (scandir(dir_media()) ?: [] as $f) {
        if (!preg_match(IMAGINE_NUME, $f)) continue;
        $cale = dir_media() . '/' . $f;
        $rez[] = ['nume' => $f, 'url' => '/media/' . $f, 'octeti' => filesize($cale), 'urcata_la' => date('c', (int) filemtime($cale))];
    }
    usort($rez, fn($a, $b) => strcmp($b['urcata_la'], $a['urcata_la']));
    return $rez;
}

// Unde e folosită o imagine: logo, favicon, coperta articolelor sau conținutul paginilor/articolelor.
function imagine_folosita_in(string $fisier): array
{
    $unde = [];
    foreach (['logo', 'favicon'] as $k) if ((string) config("site.$k") === '/media/' . $fisier) $unde[] = "site/$k";
    foreach (array_keys(TIPURI) as $tip) {
        foreach (listeaza_elemente($tip) as $e) {
            if (($e['imagine'] ?? '') === '/media/' . $fisier || strpos((string) ($e['continut_html'] ?? ''), '/media/' . $fisier) !== false) {
                $unde[] = $tip . '/' . $e['slug'];
            }
        }
    }
    return $unde;
}

function sterge_imagine(string $fisier, bool $forteaza): array
{
    if (!preg_match(IMAGINE_NUME, $fisier)) throw new EroareCms('nume de imagine invalid (ex. "coperta-a1b2c3d4.png", din listeaza_imagini)');
    return cu_blocare(function () use ($fisier, $forteaza) {
        $sursa = dir_media() . '/' . $fisier;
        if (!is_file($sursa)) throw new EroareCms("imaginea $fisier nu există");
        $unde = imagine_folosita_in($fisier);
        if ($unde && !$forteaza) {
            throw new EroareCms('imaginea e folosită în: ' . implode(', ', $unde) . ' — scoate-o de acolo sau cere ștergerea cu forteaza=true');
        }
        $dest = dir_date('versiuni/media') . '/' . $fisier . '.' . date('Ymd-His');
        if (!@rename($sursa, $dest) && !(@copy($sursa, $dest) && @unlink($sursa))) throw new EroareCms('mutarea imaginii a eșuat');
        return ['operatie' => 'ștearsă (mutată între versiuni)', 'nume' => $fisier, 'era_folosita_in' => $unde];
    });
}
