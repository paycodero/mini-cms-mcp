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
        // Editorii (clientul) nu stau aici: au cheile lor, puse de pe calculator cu php unelte/editor.php (cheia de cod).
    ],

    // Opționale — valorile implicite se potrivesc singure:
    // 'depozit' => 'paycodero/mini-cms-mcp',   // de unde își ia site-ul versiunea nouă, când i-o ceri tu
    // 'depozit_ramura' => 'main',
    // 'depozit_zip' => '',      // adresa exactă a pachetului, dacă nu e GitHub
    // 'actualizare' => false,   // implicit true: pagina /actualizare.php și comanda actualizeaza.php, cu cheia de cod
    // 'date' => dirname(__DIR__, 2) . '/date-site',   // dosarul de date un nivel deasupra folderului public, dacă găzduirea
    //                                                 // permite; rămas în site/date, e protejat de .htaccess (pe nginx, vezi README)
    // 'cloudflare' => false,   // implicit 'auto': IP-ul real vine din CF-Connecting-IP doar când cererea vine chiar din Cloudflare
    // 'hsts' => false,         // implicit 'auto': antetul HSTS pe orice răspuns servit prin https
    // 'oauth' => 'fereastra',  // implicit: o aplicație se poate înregistra și aproba DOAR în fereastra scurtă pe care o
    //                          // deschide omul cu "php unelte/instaleaza.php <site> --oauth" (cod de 6 cifre în terminal).
    //                          // 'deschis' = ca în 0.5 (oricine poate porni o aprobare) · false = fără OAuth deloc
    // 'oauth_gazde' => [],     // gazde https în plus (pe lângă claude.ai și claude.com) la care OAuth poate trimite codul de aprobare
    // 'verificari' => [],      // etichetele meta cerute de Search Console și Bing Webmaster Tools, ex.:
    //                          // ['google-site-verification' => 'abc...', 'msvalidate.01' => 'DEF...']
    // 'indexnow' => false,     // implicit 'auto': la publicare/modificare/retragere, adresa pleacă spre Bing (IndexNow),
    //                          // cu o cheie ținută în date/securitate/ și servită la /<cheie>.txt. Google nu are așa ceva.
    // 'csp_extra' => [],       // surse în plus pentru Content-Security-Policy, ex. pentru Google Analytics:
    //                          // ['script-src' => ['https://www.googletagmanager.com'], 'connect-src' => ['https://*.google-analytics.com']]
];
