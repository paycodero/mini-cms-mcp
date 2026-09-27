<?php
// Site-ul demo (app/demo.php): instantaneul curat și resetarea, cu cheia de cod din chei-<nume>.json.
//
//   php unelte/demo.php https://demo.site.ro                  starea: ora resetării, instantaneul, ultima resetare
//   php unelte/demo.php https://demo.site.ro --instantaneu    conținutul de acum devine starea curată (îl înlocuiește pe cel vechi)
//   php unelte/demo.php https://demo.site.ro --reseteaza      pune la loc instantaneul acum, fără să aștepte ora
//
// Modul demo se pornește din config.php ('demo' => ['resetare' => '03:00']); comanda doar îl folosește.
declare(strict_types=1);

require __DIR__ . '/comun.php';

['opt' => $opt, 'site' => $site, 'fisier_chei' => $fisier_chei] = porneste($argv, ['instantaneu', 'reseteaza'],
    'php unelte/demo.php https://demo.site.ro [--instantaneu | --reseteaza] [--nume=scurt] [--dosar=CALE]');
$cheie_cod = (string) (cheile($fisier_chei, true)['cod']['cheie'] ?? '');

echo culoare("mini-cms-mcp — site-ul demo $site", '1'), "\n";

try {
    if (isset($opt['instantaneu']) && isset($opt['reseteaza'])) opreste('alege una: --instantaneu sau --reseteaza');
    $actiune = isset($opt['instantaneu']) ? 'demo_instantaneu' : (isset($opt['reseteaza']) ? 'demo_reseteaza' : 'demo');
    $j = cerere_cod($site, $cheie_cod, ['actiune' => $actiune]);
    if (!array_key_exists('demo', $j)) opreste("site-ul are o versiune mai veche de 0.26 și nu știe de modul demo. Actualizează-l întâi: php unelte/actualizeaza.php $site");
    if ($actiune === 'demo_instantaneu') ok('instantaneu făcut: ' . (int) ($j['rezultat']['fisiere'] ?? 0) . ' fișiere puse deoparte');
    if ($actiune === 'demo_reseteaza') ok('site resetat la instantaneu: ' . (int) ($j['rezultat']['fisiere'] ?? 0) . ' fișiere puse la loc');
    $d = (array) $j['demo'];
    titlu('Starea');
    if (empty($d['pornit'])) {
        info("modul demo NU e pornit: în config.php lipsește 'demo' => ['resetare' => '03:00']");
    } else {
        info('resetarea zilnică: ' . $d['resetare'] . ' (fusul orar din config.php)');
        info('instantaneul: ' . ($d['instantaneu'] ? substr((string) $d['instantaneu']['facut'], 0, 16) . ', ' . (int) $d['instantaneu']['fisiere'] . ' fișiere' : 'încă nu există (--instantaneu)'));
        info('ultima resetare: ' . ($d['ultima_resetare'] ? substr((string) $d['ultima_resetare']['resetat'], 0, 16) . ' (' . $d['ultima_resetare']['motiv'] . ')' : 'niciuna'));
    }
} catch (RuntimeException $e) {
    opreste($e->getMessage());
}
