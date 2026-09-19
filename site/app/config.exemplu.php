<?php
// Configurarea site-ului. De obicei o scrie singur unelte/instaleaza.php (adresa + amprentele cheilor) și o pune în
// pachetul de urcat. De mână: copiezi fișierul ca app/config.php și completezi 'url' și 'chei'.
// app/config.php NU intră în git și nu poate fi modificat prin MCP: ajunge pe server o singură dată, pus de om.
if (!defined('MINICMS')) { http_response_code(403); exit; }

return [
    'site' => [
        'url' => 'https://exemplu.ro',   // adresa publică, fără / la final
        // Numele, descrierea, autorul, limba și culoarea le setează AI-ul, cu seteaza_site. Scrise aici, sunt doar
        // valorile de pornire: 'nume' => '...', 'descriere' => '...', 'autor' => '...', 'culoare' => '#6d2be8'.
    ],

    // DOAR amprentele SHA-256 ale celor două chei (le generează instaleaza.php sau genereaza-cheie.php).
    // Cheile în clar stau la om (managerul de parole) și în configurarea clientului MCP, nu pe server.
    'chei' => [
        'citire' => '',    // vede conținutul, versiunile și jurnalul
        'scriere' => '',   // poate și crea, modifica, publica, șterge (reversibil), urca imagini
    ],

    // Opționale — valorile implicite se potrivesc singure:
    // 'date' => dirname(__DIR__, 2) . '/date-site',   // dosarul de date un nivel deasupra folderului public, dacă găzduirea
    //                                                 // permite; rămas în site/date, e protejat de .htaccess (pe nginx, vezi README)
    // 'cloudflare' => false,   // implicit 'auto': IP-ul real vine din CF-Connecting-IP doar când cererea vine chiar din Cloudflare
    // 'hsts' => false,         // implicit 'auto': antetul HSTS pe orice răspuns servit prin https
    // 'oauth_gazde' => [],     // gazde https în plus (pe lângă claude.ai și claude.com) la care OAuth poate trimite codul de aprobare
    // 'csp_extra' => [],       // surse în plus pentru Content-Security-Policy, ex. pentru Google Analytics:
    //                          // ['script-src' => ['https://www.googletagmanager.com'], 'connect-src' => ['https://*.google-analytics.com']]
];
