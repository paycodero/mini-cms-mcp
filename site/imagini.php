<?php
// Urcarea imaginilor din browser (calculator sau telefon), de mâna omului, fără să treacă prin conversația cu AI-ul.
// Drumul obișnuit: AI-ul cheamă link_urcare și îi dă omului un link semnat, valabil puțin; cu el nu trebuie nicio cheie.
// Fără link, pagina cere cheia de SCRIERE, trimisă prin formular (POST), niciodată în adresă. Fiecare fișier trece prin
// aceleași verificări ca urca_imagine din MCP (tip aflat din conținut, 5 MB, fără cod ascuns) și e scris în jurnal.
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
$v = ['rezultate' => null, 'mesaj' => '', 'limite' => $limite, 'link' => null, 'noindex' => true, 'titlu_pagina' => 'Urcă imagini — ' . config('site.nume')];
$cod = 200;

// Linkul dat de AI (link_urcare): cu el nu mai trebuie cheia. Fără link, pagina cere cheia de scriere, ca până acum.
$link_e = (int) ($_POST['e'] ?? $_GET['e'] ?? 0);
$link_s = (string) ($_POST['s'] ?? $_GET['s'] ?? '');
$link_c = substr((string) ($_POST['c'] ?? $_GET['c'] ?? ''), 0, 60);   // cine a cerut linkul; semnat, deci nu se poate schimba
if ($link_e > 0 || $link_s !== '') {
    if (ip_blocat() || !link_urcare_valid($link_e, $link_s, $link_c)) {
        $cod = 403;
        $v['mesaj'] = 'Linkul a expirat sau nu e bun. Cere-i lui Claude unul nou.';
        jurnal_scrie(['punct' => 'imagini', 'cheie' => 'link', 'cerere' => 'deschidere', 'rezultat' => 'respins',
                      'detalii' => ['motiv' => $link_e > 0 && $link_e < time() ? 'link expirat' : 'semnătură greșită']]);
        if (!($link_e > 0 && $link_e < time())) inregistreaza_esec();
        randeaza('imagini', $v, $cod);
        exit;
    }
    $v['link'] = ['e' => $link_e, 's' => $link_s, 'c' => $link_c, 'pana_la' => date('H:i', $link_e)];
    $v['titlu_pagina'] = 'Urcă poza — ' . config('site.nume');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $cod = 413;
        $v['mesaj'] = "Trimiterea depășește ce primește găzduirea deodată ({$limite['total']}). Urcă mai puține poze odată.";
    } else {
        $acces = $v['link'] ? ['cod' => 200, 'rol' => 'scriere'] : verifica_acces('imagini', (string) ($_POST['cheie'] ?? ''));
        $cheie_jurnal = $v['link'] ? 'link' : 'scriere';
        $cine = $v['link'] ? ($link_c !== '' ? $link_c : 'link') : (string) ($acces['cine'] ?? '');
        if ($acces['cod'] !== 200) {
            $cod = $acces['cod'];
            $v['mesaj'] = $acces['mesaj'];
        } elseif ($acces['rol'] !== 'scriere') {
            $cod = 403;
            $v['mesaj'] = 'Cheia de citire nu poate urca imagini: e nevoie de cheia de scriere.';
            jurnal_scrie(['punct' => 'imagini', 'cheie' => $acces['rol'], 'cine' => $cine, 'cerere' => 'urcare', 'rezultat' => 'refuzat',
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
                    jurnal_scrie(['punct' => 'imagini', 'cheie' => $cheie_jurnal, 'cine' => $cine, 'cerere' => 'urcare', 'tinta' => $r['url'], 'rezultat' => 'ok',
                                  'detalii' => ['octeti' => $r['octeti'], 'operatie' => $r['operatie'], 'amprenta' => $r['amprenta']]]);
                    $v['rezultate'][] = ['nume' => $nume, 'ok' => true] + $r;
                } catch (EroareCms $e) {
                    jurnal_scrie(['punct' => 'imagini', 'cheie' => $cheie_jurnal, 'cine' => $cine, 'cerere' => 'urcare', 'rezultat' => 'refuzat',
                                  'detalii' => ['fisier' => text_simplu($nume, 120), 'motiv' => $e->getMessage()]]);
                    $v['rezultate'][] = ['nume' => $nume, 'ok' => false, 'eroare' => $e->getMessage()];
                }
            }
            if (!$v['rezultate']) $v['mesaj'] = 'N-ai ales nicio imagine.';
        }
    }
}
randeaza('imagini', $v, $cod);
