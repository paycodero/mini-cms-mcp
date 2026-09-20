<?php
// Cheile și limitarea încercărilor. Aceleași praguri ca la cinesunt.info și gabriel.paycode.ro,
// dovedite pe server: 8 eșecuri în 5 minute = IP blocat 15 minute, pe TOATE punctele de intrare.
// Pe server stau doar amprentele SHA-256 ale cheilor, nu cheile.
//
// 0.6: adresa se pune într-o găleată, nu se numără exact — la IPv6 contează primii 64 de biți, fiindcă
// oricine are un /64 întreg și ar trece prin plafon schimbând adresa la fiecare cerere. Verificarea
// „sunt blocat?" nu mai rescrie fișierul (înainte lua lacăt exclusiv la fiecare cerere, inclusiv la cele
// bune). Punctele fără cheie (OAuth) au un plafon propriu, pe numărul de cereri, nu pe eșecuri.
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

const RATE_FEREASTRA = 300;
const RATE_PRAG = 8;
const RATE_BLOCARE = 900;
const RATE_MAX_ADRESE = 5000;     // câte găleți ținem minte; peste, le scoatem pe cele mai vechi
const RATE_CERERI_PRAG = 60;      // cereri fără cheie (OAuth) pe fereastră, de la aceeași găleată
                                  // (o conectare reală face vreo 6; un atac de umplere face sute)
const RATE_CERERI_FEREASTRA = 300;

function cheie_din_cerere(): string
{
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($auth === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower((string) $k) === 'authorization') $auth = (string) $v;
        }
    }
    if (preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m)) return $m[1];
    return trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
}

function chei_configurate(): bool
{
    $c = (string) config('chei.citire');
    $s = (string) config('chei.scriere');
    return preg_match('/^[a-f0-9]{64}$/', $c) === 1 && preg_match('/^[a-f0-9]{64}$/', $s) === 1 && $c !== $s;
}

// 'scriere', 'citire' sau null. Ambele comparații rulează mereu, cu hash_equals.
function rol_pentru_cheie(string $cheie): ?string
{
    if (strlen($cheie) < 20 || strlen($cheie) > 200) return null;
    $h = hash('sha256', $cheie);
    $scriere = hash_equals((string) config('chei.scriere'), $h);
    $citire = hash_equals((string) config('chei.citire'), $h);
    return $scriere ? 'scriere' : ($citire ? 'citire' : null);
}

// Găleata în care intră adresa: IPv4 întreg, IPv6 doar prefixul /64. Un atacator cu un bloc IPv6 are
// miliarde de adrese, dar o singură găleată — altfel plafonul de mai jos n-ar însemna nimic pentru el.
function rate_galeata(string $ip = ''): string
{
    $ip = $ip !== '' ? $ip : ip_client();
    $b = @inet_pton($ip);
    if ($b !== false && strlen($b) === 16) return bin2hex(substr($b, 0, 8)) . '::/64';
    return $ip;
}

function rate_fisier(): string
{
    return dir_date('securitate') . '/incercari.json';
}

// Citire fără lacăt exclusiv și fără scriere: e drumul parcurs de fiecare cerere, inclusiv de cele bune.
function rate_citeste(): array
{
    $fp = @fopen(rate_fisier(), 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $text = (string) stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $stare = json_decode($text ?: '{}', true);
    return is_array($stare) ? $stare : [];
}

// Scriere sub lacăt exclusiv: numai la eșecuri și la cererile fără cheie, nu la fiecare cerere.
// A treia cheie: cea de cod. NU e cheie de MCP — rol_pentru_cheie() n-o recunoaște niciodată, deci nici
// AI-ul, nici un token OAuth al conectorului din claude.ai nu pot ajunge la actualizarea codului.
// Stă doar pe calculatorul omului, iar pe server e tot o amprentă.
function verifica_acces_cod(string $cheie): array
{
    $amprenta = (string) config('chei.cod');
    if (config('actualizare') === false) {
        return ['cod' => 404, 'mesaj' => 'Actualizarea de pe depozit e oprită pe acest site („actualizare" => false în app/config.php).'];
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $amprenta)) {
        return ['cod' => 503, 'mesaj' => 'Cheia de cod nu e configurată în app/config.php. Rulează instalarea din nou și urcă pachetul.'];
    }
    if (ip_blocat()) {
        jurnal_scrie(['punct' => 'actualizare', 'rezultat' => 'blocat']);
        return ['cod' => 429, 'mesaj' => 'Prea multe încercări eșuate de pe această adresă. Reîncearcă peste 15 minute.'];
    }
    if (strlen($cheie) < 20 || strlen($cheie) > 200 || !hash_equals($amprenta, hash('sha256', $cheie))) {
        inregistreaza_esec();
        jurnal_scrie(['punct' => 'actualizare', 'rezultat' => 'auth_esuat', 'detalii' => ['cheie' => $cheie === '' ? 'lipsă' : 'greșită']]);
        return ['cod' => 401, 'mesaj' => 'Cheie de cod lipsă sau greșită. Nu e cheia de scriere: e a treia, din fișierul de chei.'];
    }
    return ['cod' => 200];
}

function rate_actualizeaza(callable $callback)
{
    $fp = @fopen(rate_fisier(), 'c+');
    if (!$fp) return null;
    flock($fp, LOCK_EX);
    $stare = json_decode(stream_get_contents($fp) ?: '{}', true);
    if (!is_array($stare)) $stare = [];
    $acum = time();
    foreach ($stare as $k => $intrare) {   // curățăm intrările expirate, ca fișierul să nu crească
        $recente = array_filter($intrare['esecuri'] ?? [], fn($t) => ($acum - $t) < RATE_FEREASTRA);
        $cereri = array_filter($intrare['cereri'] ?? [], fn($t) => ($acum - $t) < RATE_CERERI_FEREASTRA);
        if (!$recente && !$cereri && ($intrare['blocat_pana'] ?? 0) <= $acum) unset($stare[$k]);
    }
    $rezultat = $callback($stare, $acum);
    if (count($stare) > RATE_MAX_ADRESE) {   // plafon dur: un val de adrese noi nu umflă fișierul la nesfârșit
        uasort($stare, fn($a, $b) => ($b['ultima'] ?? 0) <=> ($a['ultima'] ?? 0));
        $stare = array_slice($stare, 0, RATE_MAX_ADRESE, true);
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($stare));
    flock($fp, LOCK_UN);
    fclose($fp);
    return $rezultat;
}

function ip_blocat(): bool
{
    $k = rate_galeata();
    $stare = rate_citeste();
    return ($stare[$k]['blocat_pana'] ?? 0) > time();
}

function inregistreaza_esec(): void
{
    $k = rate_galeata();
    rate_actualizeaza(function (array &$stare, int $acum) use ($k) {
        $intrare = $stare[$k] ?? ['esecuri' => [], 'blocat_pana' => 0];
        $intrare['esecuri'] = array_values(array_filter($intrare['esecuri'] ?? [], fn($t) => ($acum - $t) < RATE_FEREASTRA));
        $intrare['esecuri'][] = $acum;
        $intrare['ultima'] = $acum;
        if (count($intrare['esecuri']) >= RATE_PRAG) $intrare['blocat_pana'] = $acum + RATE_BLOCARE;
        $stare[$k] = $intrare;
        return null;
    });
}

// Plafon pentru punctele care răspund FĂRĂ cheie (înregistrarea OAuth, tokenul, pagina de aprobare):
// acolo nu există „eșec de autentificare" de numărat, deci se numără cererile. Peste prag, găleata
// intră în aceeași blocare de 15 minute ca la chei greșite. Întoarce true dacă cererea trece.
function limita_cereri(string $punct): bool
{
    if (ip_blocat()) {
        jurnal_scrie(['punct' => $punct, 'rezultat' => 'blocat', 'detalii' => ['motiv' => 'prea multe cereri']]);
        return false;
    }
    $k = rate_galeata();
    $peste = rate_actualizeaza(function (array &$stare, int $acum) use ($k) {
        $intrare = $stare[$k] ?? ['esecuri' => [], 'cereri' => [], 'blocat_pana' => 0];
        $intrare['cereri'] = array_values(array_filter($intrare['cereri'] ?? [], fn($t) => ($acum - $t) < RATE_CERERI_FEREASTRA));
        $intrare['cereri'][] = $acum;
        $intrare['ultima'] = $acum;
        $peste = count($intrare['cereri']) > RATE_CERERI_PRAG;
        if ($peste) $intrare['blocat_pana'] = $acum + RATE_BLOCARE;
        $stare[$k] = $intrare;
        return $peste;
    });
    if ($peste) {
        jurnal_scrie(['punct' => $punct, 'rezultat' => 'blocat', 'detalii' => ['motiv' => 'prea multe cereri fără cheie']]);
        return false;
    }
    return true;
}

// Poarta comună pentru mcp.php, jurnal.php și aprobarea OAuth. Scrie singură în jurnal eșecurile.
// Pe /mcp se acceptă și token-urile OAuth (conectorul din claude.ai); la jurnal și la aprobare, doar cheile.
// Întoarce ['cod' => 200, 'rol' => ..., 'conexiune' => ...?] sau ['cod' => 401|429|503, 'mesaj' => ...].
function verifica_acces(string $punct, string $cheie): array
{
    if (!chei_configurate()) {
        jurnal_scrie(['punct' => $punct, 'rezultat' => 'eroare', 'detalii' => ['motiv' => 'chei neconfigurate']]);
        return ['cod' => 503, 'mesaj' => 'Cheile nu sunt configurate în app/config.php (două amprente SHA-256 diferite).'];
    }
    if (ip_blocat()) {
        jurnal_scrie(['punct' => $punct, 'rezultat' => 'blocat']);
        return ['cod' => 429, 'mesaj' => 'Prea multe încercări eșuate de pe această adresă. Reîncearcă peste 15 minute.'];
    }
    $rol = rol_pentru_cheie($cheie);
    $conexiune = null;
    if ($rol === null && $punct === 'mcp' && strncmp($cheie, 'mcms_t_', 7) === 0 && ($t = rol_token_oauth(hash('sha256', $cheie)))) {
        $rol = $t['rol'];
        $conexiune = $t['conexiune'];
    }
    if ($rol === null) {
        inregistreaza_esec();
        jurnal_scrie(['punct' => $punct, 'rezultat' => 'auth_esuat', 'detalii' => ['cheie' => $cheie === '' ? 'lipsă' : 'greșită']]);
        return ['cod' => 401, 'mesaj' => 'Cheie lipsă sau greșită.'];
    }
    return ['cod' => 200, 'rol' => $rol] + ($conexiune !== null ? ['conexiune' => $conexiune] : []);
}
