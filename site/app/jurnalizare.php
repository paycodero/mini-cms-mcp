<?php
// Jurnalul: câte un rând JSON pentru fiecare apel — citire, scriere, încercare eșuată, blocare.
// Un fișier pe lună (date/jurnal/AAAA-LL.ndjson), fără rotație care să șteargă istoric.
// Fiecare rând poartă amprenta "h" = sha256(amprenta rândului anterior + rândul curent): un rând
// modificat, scos sau adăugat pe dinafară rupe lanțul, iar jurnal_verifica() arată unde.
// Nicio comandă MCP nu scrie în jurnal altfel decât prin jurnal_scrie(); AI-ul îl poate doar citi.
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

const LANT_INCEPUT = '0000000000000000000000000000000000000000000000000000000000000000';
const JURNAL_MAX_OCTETI = 16 * 1024 * 1024;   // peste atât, luna curentă se arhivează și se începe un fișier nou
const JURNAL_CITIRE_MAX = 512 * 1024;         // cât se citește de la coada unui fișier, la afișare

function jurnal_dir(): string
{
    return dir_date('jurnal');
}

// Adresa unui vizitator obișnuit (linkurile de previzualizare se deschid fără cheie) nu se păstrează întreagă:
// ultimul octet la IPv4, prefixul /64 la IPv6. Destul ca să vezi un tipar, nu destul cât să urmărești un om.
function ip_trunchiat(string $ip): string
{
    $b = @inet_pton($ip);
    if ($b === false) return 'necunoscut';
    if (strlen($b) === 4) return (string) (@inet_ntop(substr($b, 0, 3) . "\0") ?: 'necunoscut');
    return (string) (@inet_ntop(substr($b, 0, 8) . str_repeat("\0", 8)) ?: 'necunoscut') . '/64';
}

function jurnal_scrie(array $eveniment): bool
{
    $ip = ip_client();
    if (($eveniment['punct'] ?? '') === 'previzualizare') $ip = ip_trunchiat($ip);
    $intrare = ['t' => date('c'), 'ip' => $ip] + $eveniment;
    if (!isset($intrare['ua'])) $intrare['ua'] = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160);
    $json = json_text($intrare);
    try {
        $dir = jurnal_dir();
    } catch (Throwable $e) {
        return false;
    }
    $fp = @fopen($dir . '/.lant', 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    $anterior = trim((string) stream_get_contents($fp));
    if (!preg_match('/^[a-f0-9]{64}$/', $anterior)) $anterior = LANT_INCEPUT;
    $h = hash('sha256', $anterior . $json);
    $linie = substr($json, 0, -1) . ',"h":"' . $h . '"}' . "\n";
    $fisier = $dir . '/' . date('Y-m') . '.ndjson';
    // Un val de cereri (sau un atac) nu are voie să facă un fișier pe care pagina jurnalului nu-l mai poate deschide:
    // la 16 MB, luna curentă se arhivează sub un nume care rămâne ÎNAINTEA ei la sortare, deci lanțul se verifică la fel.
    if (is_file($fisier) && filesize($fisier) > JURNAL_MAX_OCTETI) {
        for ($k = 1; is_file($dir . '/' . date('Y-m') . '-' . $k . '.ndjson'); $k++);
        @rename($fisier, $dir . '/' . date('Y-m') . '-' . $k . '.ndjson');
    }
    $ok = file_put_contents($fisier, $linie, FILE_APPEND) !== false;
    if ($ok) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $h);
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

function jurnal_fisiere(): array
{
    $f = glob(jurnal_dir() . '/*.ndjson') ?: [];
    sort($f);
    return $f;
}

// Recalculează tot lanțul. Întoarce ['intact' => bool, 'intrari' => n, 'unde' => fișier:rând la prima ruptură].
function jurnal_verifica(): array
{
    $anterior = LANT_INCEPUT;
    $n = 0;
    foreach (jurnal_fisiere() as $f) {
        $fh = fopen($f, 'r');
        $nr = 0;
        while (($linie = fgets($fh)) !== false) {
            $nr++;
            $linie = rtrim($linie, "\r\n");
            if ($linie === '') continue;
            if (!preg_match('/^(\{.*),"h":"([a-f0-9]{64})"\}$/s', $linie, $m)
                || !hash_equals(hash('sha256', $anterior . $m[1] . '}'), $m[2])) {
                fclose($fh);
                return ['intact' => false, 'intrari' => $n, 'unde' => basename($f) . ', rândul ' . $nr];
            }
            $anterior = $m[2];
            $n++;
        }
        fclose($fh);
    }
    $stare = trim((string) @file_get_contents(jurnal_dir() . '/.lant'));
    if ($stare !== '' && $stare !== $anterior) {
        return ['intact' => false, 'intrari' => $n, 'unde' => 'finalul jurnalului — lipsesc rânduri'];
    }
    return ['intact' => true, 'intrari' => $n];
}

// Ultimele linii ale unui fișier, citite de la coadă: un jurnal mare nu se mai încarcă întreg în memorie.
function jurnal_coada(string $f, int $octeti): array
{
    $fh = @fopen($f, 'rb');
    if (!$fh) return [];
    $marime = (int) @filesize($f);
    $de_la = max(0, $marime - $octeti);
    if ($de_la > 0) {
        fseek($fh, $de_la);
        fgets($fh);   // prima linie e tăiată la jumătate: o aruncăm
    }
    $linii = [];
    while (($l = fgets($fh)) !== false) {
        $l = rtrim($l, "\r\n");
        if ($l !== '') $linii[] = $l;
    }
    fclose($fh);
    return $linii;
}

// Ultimele $n intrări, cele mai noi primele. $doar_probleme = tot ce nu are rezultat "ok".
function jurnal_ultimele(int $n, bool $doar_probleme = false): array
{
    $rez = [];
    foreach (array_reverse(jurnal_fisiere()) as $f) {
        $linii = jurnal_coada($f, JURNAL_CITIRE_MAX);
        for ($i = count($linii) - 1; $i >= 0 && count($rez) < $n; $i--) {
            $d = json_decode($linii[$i], true);
            if (!is_array($d)) continue;
            if ($doar_probleme && ($d['rezultat'] ?? '') === 'ok') continue;
            unset($d['h']);
            $rez[] = $d;
        }
        if (count($rez) >= $n) break;
    }
    return $rez;
}
