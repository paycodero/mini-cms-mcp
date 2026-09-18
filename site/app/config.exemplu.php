<?php
// Copiază fișierul ca app/config.php și completează-l. app/config.php NU intră în git și nu poate
// fi modificat prin MCP: se pune pe server o singură dată, de om (FTP sau panoul găzduirii).
if (!defined('MINICMS')) { http_response_code(403); exit; }

return [
    'site' => [
        'nume' => 'Numele site-ului',
        'descriere' => 'O frază despre site: apare în Google, în feed și în llms.txt.',
        'url' => 'https://exemplu.ro',   // adresa publică, fără / la final
        'limba' => 'ro',
        'autor' => 'Prenume Nume',       // autorul implicit al articolelor
        'culoare' => '#6d2be8',          // culoarea de accent
    ],

    // DOAR amprentele SHA-256 ale celor două chei, generate cu: php unelte/genereaza-cheie.php
    // Cheile în clar stau la om (managerul de parole) și în configurarea clientului MCP, nu pe server.
    'chei' => [
        'citire' => '',    // vede conținutul, versiunile și jurnalul
        'scriere' => '',   // poate și crea, modifica, publica, șterge (reversibil), urca imagini
    ],

    // Dosarul de date. Mai sigur în afara folderului public, dacă găzduirea permite, de ex.:
    // 'date' => dirname(__DIR__, 3) . '/date-site',
    // Rămas în site/date, e protejat de .htaccess (Apache). Pe nginx, vezi README.

    'cloudflare' => false,   // true dacă site-ul e în spatele Cloudflare (IP-ul real vine din CF-Connecting-IP)
    'hsts' => false,         // true după ce HTTPS merge sigur pe domeniu
    'csp_extra' => [],       // surse în plus pentru Content-Security-Policy, ex. pentru Google Analytics:
                             // ['script-src' => ['https://www.googletagmanager.com'], 'connect-src' => ['https://*.google-analytics.com']]
];
