<?php
// Cheile și limitarea încercărilor. Aceleași praguri ca la cinesunt.info și gabriel.paycode.ro,
// dovedite pe server: 8 eșecuri în 5 minute = IP blocat 15 minute, pe TOATE punctele de intrare.
// Pe server stau doar amprentele SHA-256 ale cheilor, nu cheile.
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

const RATE_FEREASTRA = 300;
const RATE_PRAG = 8;
const RATE_BLOCARE = 900;

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

function rate_actualizeaza(callable $callback)
{
    $fp = @fopen(dir_date('securitate') . '/incercari.json', 'c+');
    if (!$fp) return null;
    flock($fp, LOCK_EX);
    $stare = json_decode(stream_get_contents($fp) ?: '{}', true);
    if (!is_array($stare)) $stare = [];
    $acum = time();
    foreach ($stare as $ip => $intrare) {   // curățăm intrările expirate, ca fișierul să nu crească
        $recente = array_filter($intrare['esecuri'] ?? [], fn($t) => ($acum - $t) < RATE_FEREASTRA);
        if (!$recente && ($intrare['blocat_pana'] ?? 0) <= $acum) unset($stare[$ip]);
    }
    $rezultat = $callback($stare, $acum);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($stare));
    flock($fp, LOCK_UN);
    fclose($fp);
    return $rezultat;
}

function ip_blocat(): bool
{
    $ip = ip_client();
    return (bool) rate_actualizeaza(function (array &$stare, int $acum) use ($ip) {
        return ($stare[$ip]['blocat_pana'] ?? 0) > $acum;
    });
}

function inregistreaza_esec(): void
{
    $ip = ip_client();
    rate_actualizeaza(function (array &$stare, int $acum) use ($ip) {
        $intrare = $stare[$ip] ?? ['esecuri' => [], 'blocat_pana' => 0];
        $intrare['esecuri'] = array_values(array_filter($intrare['esecuri'], fn($t) => ($acum - $t) < RATE_FEREASTRA));
        $intrare['esecuri'][] = $acum;
        if (count($intrare['esecuri']) >= RATE_PRAG) $intrare['blocat_pana'] = $acum + RATE_BLOCARE;
        $stare[$ip] = $intrare;
        return null;
    });
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
