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
$corp = [];
if ($json && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $corp = (array) (json_decode((string) file_get_contents('php://input', false, null, 0, 20000), true) ?: []);
}
$p = $corp + $_POST;

function raspunde_json(int $cod, array $date): void
{
    http_response_code($cod);
    header('Content-Type: application/json; charset=utf-8');
    antete_securitate("default-src 'none'; frame-ancestors 'none'");
    echo json_text($date, true);
    exit;
}

$v = ['mesaj' => '', 'rezultat' => null, 'stare' => null, 'copii' => [], 'noindex' => true,
      'titlu_pagina' => 'Actualizare — ' . config('site.nume')];
$cod_http = 200;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $cheie = (string) ($p['cheie'] ?? '');
    if ($cheie === '') $cheie = cheie_din_cerere();
    $acces = verifica_acces_cod($cheie);
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
            } else {
                $s = cod_stare();
                unset($s['_fisiere']);
                $v['stare'] = $s;
            }
            $v['copii'] = cod_copii();
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
                : ['rezultat' => $v['rezultat'], 'stare' => $v['stare'], 'copii' => $v['copii']]);
        }
    }
} elseif ($json) {
    raspunde_json(405, ['eroare' => 'Trimite POST, cu cheia de cod.']);
}

randeaza('actualizare', $v, $cod_http);
