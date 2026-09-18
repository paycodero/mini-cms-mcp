<?php
// Jurnalul: câte un rând JSON pentru fiecare apel — citire, scriere, încercare eșuată, blocare.
// Un fișier pe lună (date/jurnal/AAAA-LL.ndjson), fără rotație care să șteargă istoric.
// Fiecare rând poartă amprenta "h" = sha256(amprenta rândului anterior + rândul curent): un rând
// modificat, scos sau adăugat pe dinafară rupe lanțul, iar jurnal_verifica() arată unde.
// Nicio comandă MCP nu scrie în jurnal altfel decât prin jurnal_scrie(); AI-ul îl poate doar citi.
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

const LANT_INCEPUT = '0000000000000000000000000000000000000000000000000000000000000000';

function jurnal_dir(): string
{
    return dir_date('jurnal');
}

function jurnal_scrie(array $eveniment): bool
{
    $intrare = ['t' => date('c'), 'ip' => ip_client()] + $eveniment;
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
    $ok = file_put_contents($dir . '/' . date('Y-m') . '.ndjson', $linie, FILE_APPEND) !== false;
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

// Ultimele $n intrări, cele mai noi primele. $doar_probleme = tot ce nu are rezultat "ok".
function jurnal_ultimele(int $n, bool $doar_probleme = false): array
{
    $rez = [];
    foreach (array_reverse(jurnal_fisiere()) as $f) {
        $linii = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
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
