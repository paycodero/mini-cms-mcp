<?php
// Vizualizarea jurnalului, pentru om. Cheia se trimite prin formular (POST), nu în adresă:
// o cheie pusă în URL rămâne în istoricul browserului și în jurnalele serverului.
declare(strict_types=1);
define('MINICMS', true);
require __DIR__ . '/app/nucleu.php';
require __DIR__ . '/app/site.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
$v =['intrari' => null, 'lant' => null, 'mesaj' => '', 'noindex' => true, 'titlu_pagina' => 'Jurnal — ' . config('site.nume')];
$cod = 200;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $acces = verifica_acces('jurnal', (string) ($_POST['cheie'] ?? ''));
    if ($acces['cod'] === 200) {
        jurnal_scrie(['punct' => 'jurnal', 'cheie' => $acces['rol'], 'cine' => $acces['cine'], 'cerere' => 'vizualizare', 'rezultat' => 'ok']);
        $v['lant'] = jurnal_verifica();
        $v['intrari'] = jurnal_ultimele(300, !empty($_POST['doar_probleme']));
    } else {
        $cod = $acces['cod'];
        $v['mesaj'] = $acces['mesaj'];
    }
}
randeaza('jurnal', $v, $cod);
