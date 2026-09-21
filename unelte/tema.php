<?php
// Temele proprii ale unui site — lucrări pentru un client, care stau doar pe calculatorul tău și pe serverul acelui
// client, niciodată în depozitul public:
//
//   php unelte/tema.php https://site.ro                   temele de pe site: cele de bază (din depozit) și cele proprii
//   php unelte/tema.php https://site.ro --pune=client     pune sau înlocuiește tema din <dosar>/teme/client.css + client/
//   php unelte/tema.php https://site.ro --pune=CALE       la fel, din altă parte (CALE = foaia .css sau dosarul temei)
//   php unelte/tema.php https://site.ro --scoate=client   scoate o temă proprie de pe site
//
// Dosarul teme/ e așezat ca assets/teme/ din depozit: client.css și, lângă ea, dosarul client/ cu fonturile și imaginile.
// Pe server, versiunea înlocuită sau scoasă se păstrează în date/versiuni/teme/. Sincronizarea cu depozitul nu atinge
// temele proprii, nici dacă depozitul capătă o temă cu același nume. Cheia folosită e cea de cod, din chei-<nume>.json:
// AI-ul nu poate pune CSS pe site, doar alege tema cu seteaza_site.
declare(strict_types=1);

require __DIR__ . '/comun.php';

['opt' => $opt, 'site' => $site, 'dosar' => $dosar, 'fisier_chei' => $fisier_chei] = porneste($argv, ['pune', 'scoate'],
    'php unelte/tema.php https://site.ro [--pune=NUME|CALE] [--scoate=NUME] [--nume=scurt] [--dosar=CALE]');
$cheie = (string) (cheile($fisier_chei, true)['cod']['cheie'] ?? '');

echo culoare("mini-cms-mcp — temele site-ului $site", '1'), "\n";

if (isset($opt['pune'])) {
    if (!is_string($opt['pune']) || $opt['pune'] === '') opreste('--pune are nevoie de numele temei sau de calea ei, ex. --pune=client');
    $tema = tema_locala($opt['pune'], $dosar);
    titlu("Pun tema {$tema['nume']} pe site");
    exit(pune_tema($site, $cheie, $tema) ? 0 : 1);
}

try {
    if (isset($opt['scoate'])) {
        if (!is_string($opt['scoate']) || $opt['scoate'] === '') opreste('--scoate are nevoie de numele temei, ex. --scoate=client');
        titlu("Scot tema {$opt['scoate']} de pe site");
        $j = cerere_cod($site, $cheie, ['actiune' => 'scoate_tema', 'nume' => $opt['scoate']]);
        $rez = (array) ($j['rezultat'] ?? []);
        ok("tema {$opt['scoate']} e scoasă" . (!empty($rez['copie']) ? ' · o copie a rămas pe server: date/versiuni/teme/' . $opt['scoate'] . '/' . $rez['copie'] : ''));
        if (isset($rez['atentie'])) atentie((string) $rez['atentie']);
    } else {
        $j = cerere_cod($site, $cheie, ['actiune' => 'teme']);
    }
} catch (RuntimeException $e) {
    opreste($e->getMessage());
}
arata_teme((array) $j['teme']);
if (!isset($opt['scoate'])) info("O temă proprie se pune cu: php unelte/tema.php $site --pune=<nume din " . $dosar . DIRECTORY_SEPARATOR . 'teme>');
exit(0);
