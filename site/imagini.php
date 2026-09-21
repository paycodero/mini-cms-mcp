<?php
// Urcarea imaginilor din browser (calculator sau telefon), de mâna omului, fără să treacă prin conversația cu AI-ul.
// Cere cheia de SCRIERE, trimisă prin formular (POST), niciodată în adresă. Fiecare fișier trece prin aceleași verificări
// ca urca_imagine din MCP (tip aflat din conținut, 5 MB, fără cod ascuns) și e scris în jurnal.
// Pagina micșorează pozele în browser înainte de trimitere (1600 px): pleacă mai repede de pe telefon și pierd locația GPS.
// 'pagina_imagini' => false în config.php o scoate cu totul.
declare(strict_types=1);
define('MINICMS', true);
require __DIR__ . '/app/nucleu.php';
require __DIR__ . '/app/site.php';

if (config('pagina_imagini') === false) { pagina_eroare(404); exit; }
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$limite = ['fisier' => (string) ini_get('upload_max_filesize'), 'total' => (string) ini_get('post_max_size'),
           'numar' => (int) ini_get('max_file_uploads')];
$v = ['rezultate' => null, 'mesaj' => '', 'limite' => $limite, 'noindex' => true, 'titlu_pagina' => 'Urcă imagini — ' . config('site.nume')];
$cod = 200;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $cod = 413;
        $v['mesaj'] = "Trimiterea depășește ce primește găzduirea deodată ({$limite['total']}). Urcă mai puține poze odată.";
    } else {
        $acces = verifica_acces('imagini', (string) ($_POST['cheie'] ?? ''));
        if ($acces['cod'] !== 200) {
            $cod = $acces['cod'];
            $v['mesaj'] = $acces['mesaj'];
        } elseif ($acces['rol'] !== 'scriere') {
            $cod = 403;
            $v['mesaj'] = 'Cheia de citire nu poate urca imagini: e nevoie de cheia de scriere.';
            jurnal_scrie(['punct' => 'imagini', 'cheie' => $acces['rol'], 'cerere' => 'urcare', 'rezultat' => 'refuzat',
                          'detalii' => ['motiv' => 'cheie de citire']]);
        } else {
            $v['rezultate'] = [];
            $f = $_FILES['poze'] ?? null;
            $n = is_array($f['name'] ?? null) ? count($f['name']) : 0;
            for ($i = 0; $i < min($n, 30); $i++) {
                $nume = (string) $f['name'][$i];
                $eroare = (int) $f['error'][$i];
                if ($eroare === UPLOAD_ERR_NO_FILE) continue;
                try {
                    if ($eroare === UPLOAD_ERR_INI_SIZE || $eroare === UPLOAD_ERR_FORM_SIZE) {
                        throw new EroareCms("e mai mare decât primește găzduirea ({$limite['fisier']} pe fișier)");
                    }
                    if ($eroare !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'][$i])) throw new EroareCms('nu a ajuns întreg (cod ' . $eroare . ')');
                    $r = urca_imagine_date($nume, (string) file_get_contents((string) $f['tmp_name'][$i]));
                    jurnal_scrie(['punct' => 'imagini', 'cheie' => 'scriere', 'cerere' => 'urcare', 'tinta' => $r['url'], 'rezultat' => 'ok',
                                  'detalii' => ['octeti' => $r['octeti'], 'operatie' => $r['operatie'], 'amprenta' => $r['amprenta']]]);
                    $v['rezultate'][] = ['nume' => $nume, 'ok' => true] + $r;
                } catch (EroareCms $e) {
                    jurnal_scrie(['punct' => 'imagini', 'cheie' => 'scriere', 'cerere' => 'urcare', 'rezultat' => 'refuzat',
                                  'detalii' => ['fisier' => text_simplu($nume, 120), 'motiv' => $e->getMessage()]]);
                    $v['rezultate'][] = ['nume' => $nume, 'ok' => false, 'eroare' => $e->getMessage()];
                }
            }
            if (!$v['rezultate']) $v['mesaj'] = 'N-ai ales nicio imagine.';
        }
    }
}
randeaza('imagini', $v, $cod);
