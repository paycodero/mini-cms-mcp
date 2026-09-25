<?php
// Pagina și punctul de intrare pentru actualizarea codului de pe GitHub, pornită de om.
// Cheia de cod se trimite prin formular (POST) sau în antet, niciodată în adresă — o cheie pusă în URL
// rămâne în istoricul browserului și în jurnalele serverului.
declare(strict_types=1);
define('MINICMS', true);
require __DIR__ . '/app/nucleu.php';
require __DIR__ . '/app/site.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$json = strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
    || strpos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false;

function raspunde_json(int $cod, array $date): void
{
    http_response_code($cod);
    header('Content-Type: application/json; charset=utf-8');
    antete_securitate("default-src 'none'; frame-ancestors 'none'");
    echo json_text($date, true);
    exit;
}

// 'actualizare' => false în config.php scoate pagina cu totul, și la GET, ca 'pagina_imagini' => false la imagini.php.
// Comanda de pe calculator primește în continuare motivul, în JSON.
if (config('actualizare') === false) {
    if ($json) raspunde_json(404, ['eroare' => verifica_acces_cod('')['mesaj']]);
    pagina_eroare(404);
    exit;
}

// Cheia din antet se verifică înainte de a citi corpul: o cerere mare (o temă, cu fonturile ei) se citește
// întreagă doar pentru cine are deja cheia de cod. Fără ea, corpul se citește până la 20 KB.
$acces_antet = null;
$corp = [];
if ($json && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $cheie_antet = cheie_din_cerere();
    if ($cheie_antet !== '') $acces_antet = verifica_acces_cod($cheie_antet);
    $limita = ($acces_antet['cod'] ?? 0) === 200 ? TEMA_MAX_CERERE : 20000;
    $brut = (string) file_get_contents('php://input', false, null, 0, $limita + 1);
    $corp = json_decode($brut, true);
    if ($brut !== '' && !is_array($corp)) {
        raspunde_json(strlen($brut) > $limita ? 413 : 400, ['eroare' => strlen($brut) > $limita
            ? 'cererea depășește ' . ($limita >= 1 << 20 ? ($limita >> 20) . ' MB' : ($limita >> 10) . ' KB') . ' (fără cheia de cod în antet, 20 KB)'
            : 'corpul cererii nu e JSON']);
    }
    $corp = (array) ($corp ?: []);
}
$p = $corp + $_POST;

$v = ['mesaj' => '', 'rezultat' => null, 'stare' => null, 'copii' => [], 'teme' => null, 'noindex' => true,
      'titlu_pagina' => 'Actualizare — ' . config('site.nume')];
$cod_http = 200;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $cheie = (string) ($p['cheie'] ?? '');
    // cheia din antet, verificată deja mai sus: a doua verificare ar număra de două ori o încercare greșită
    $acces = $cheie === '' && $acces_antet !== null ? $acces_antet : verifica_acces_cod($cheie !== '' ? $cheie : cheie_din_cerere());
    if ($acces['cod'] !== 200) {
        if ($json) raspunde_json($acces['cod'], ['eroare' => $acces['mesaj']]);
        $cod_http = $acces['cod'];
        $v['mesaj'] = $acces['mesaj'];
    } else {
        $actiune = (string) ($p['actiune'] ?? 'stare');
        try {
            if ($actiune === 'sincronizeaza') {
                $v['rezultat'] = cod_sincronizeaza(!empty($p['forta']));
            } elseif ($actiune === 'restaureaza') {
                $v['rezultat'] = cod_restaureaza((string) ($p['copie'] ?? ''));
            } elseif ($actiune === 'pune_tema') {
                if (!$json) throw new EroareCms('o temă se pune din comanda php unelte/tema.php, nu din formular');
                $v['rezultat'] = tema_pune((string) ($p['nume'] ?? ''), (array) ($p['fisiere'] ?? []));
            } elseif ($actiune === 'scoate_tema') {
                $v['rezultat'] = tema_scoate((string) ($p['nume'] ?? ''));
            } elseif ($actiune === 'adauga_editor') {
                if (!$json) throw new EroareCms('un editor se adaugă din comanda php unelte/editor.php, nu din formular');
                $v['rezultat'] = editor_adauga((string) ($p['nume'] ?? ''), (string) ($p['amprenta'] ?? ''));
            } elseif ($actiune === 'scoate_editor') {
                if (!$json) throw new EroareCms('un editor se scoate din comanda php unelte/editor.php, nu din formular');
                $v['rezultat'] = editor_scoate((string) ($p['nume'] ?? ''));
            } elseif ($actiune !== 'teme' && $actiune !== 'editori') {
                $s = cod_stare();
                unset($s['_fisiere']);
                $v['stare'] = $s;
            }
            $v['copii'] = cod_copii();
            $v['teme'] = teme_stare();
            $v['editori'] = editori_stare();
        } catch (Throwable $e) {
            $cod_http = 400;
            $v['mesaj'] = $e instanceof EroareCms ? $e->getMessage() : 'Eroare internă. Detaliile sunt în jurnal.';
            if (!($e instanceof EroareCms)) {
                jurnal_scrie(['punct' => 'actualizare', 'cerere' => 'eroare', 'cheie' => 'cod', 'rezultat' => 'eroare',
                              'detalii' => ['intern' => get_class($e) . ': ' . substr($e->getMessage(), 0, 200)]]);
            }
        }
        if ($json) {
            raspunde_json($cod_http, $v['mesaj'] !== '' ? ['eroare' => $v['mesaj']]
                : ['rezultat' => $v['rezultat'], 'stare' => $v['stare'], 'copii' => $v['copii'], 'teme' => $v['teme'], 'editori' => $v['editori'] ?? []]);
        }
    }
} elseif ($json) {
    raspunde_json(405, ['eroare' => 'Trimite POST, cu cheia de cod.']);
}

randeaza('actualizare', $v, $cod_http);
