<?php
// Actualizarea codului de pe server, fără zip și fără cPanel:
//
//   php unelte/actualizeaza.php https://site.ro              arată ce s-ar schimba, apoi întreabă
//   php unelte/actualizeaza.php https://site.ro --acum       sincronizează fără să întrebe
//   php unelte/actualizeaza.php https://site.ro --copii      lista copiilor de siguranță de pe server
//   php unelte/actualizeaza.php https://site.ro --pune=AAAALLZZ-HHMMSS   pune înapoi o copie
//
// Comanda nu trimite cod: îi cere SERVERULUI să-și ia singur versiunea din depozit. Cheia folosită e a
// treia, cea de cod, din chei-<nume>.json — nu cheia de scriere a AI-ului.
declare(strict_types=1);

require __DIR__ . '/comun.php';

['opt' => $opt, 'site' => $site, 'nume' => $nume, 'fisier_chei' => $fisier_chei] = porneste($argv,
    ['acum', 'copii', 'pune', 'forta'],
    'php unelte/actualizeaza.php https://site.ro [--acum] [--copii] [--pune=COPIE] [--forta]');

$chei = cheile($fisier_chei, true);
$cheie = (string) ($chei['cod']['cheie'] ?? '');
if ($cheie === '') {
    opreste("fișierul $fisier_chei nu are cheia de cod. Rulează întâi: php unelte/instaleaza.php $site  (o adaugă singur, "
        . 'apoi urci pachetul o dată, ca amprenta ei să ajungă în config.php de pe server).');
}

echo culoare("mini-cms-mcp — actualizarea codului pe $site", '1'), "\n";

function cere(string $site, string $cheie, array $date): array
{
    $r = cerere('POST', "$site/actualizare.php", json_text_local($date),
        ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Authorization' => 'Bearer ' . $cheie], 180);
    $j = json_decode($r['corp'], true);
    if ($r['cod'] === 0) opreste('site-ul nu răspunde: ' . ($r['eroare'] ?: 'fără răspuns'));
    if ($r['cod'] === 404) opreste("site-ul nu are pagina de actualizare: are o versiune mai veche de 0.9 sau 'actualizare' => false în config.php.");
    if ($r['cod'] !== 200 || !is_array($j)) {
        opreste('serverul a răspuns ' . $r['cod'] . ': ' . substr(trim((string) (($j['eroare'] ?? '') ?: $r['corp'])), 0, 300));
    }
    return $j;
}

function json_text_local($v): string
{
    return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function arata_copii(array $copii): void
{
    if (!$copii) { info('nicio copie de siguranță pe server (nu s-a făcut încă nicio actualizare)'); return; }
    titlu('Copii de siguranță pe server');
    foreach (array_slice($copii, 0, 10) as $c) {
        info(sprintf('%-18s versiunea %-8s %s', $c['copie'], $c['versiune'] ?: '?', substr((string) $c['cand'], 0, 19)));
    }
    info('Se pune înapoi cu: php unelte/actualizeaza.php ' . $GLOBALS['site'] . ' --pune=<copie>');
}

// --- copii / restaurare -------------------------------------------------------------------------

if (isset($opt['pune'])) {
    $copie = is_string($opt['pune']) ? $opt['pune'] : '';
    titlu('Pun înapoi copia ' . $copie);
    $j = cere($site, $cheie, ['actiune' => 'restaureaza', 'copie' => $copie]);
    ok(($j['rezultat']['fisiere'] ?? 0) . ' fișiere puse la loc din copia ' . $copie);
    exit(0);
}
if (!empty($opt['copii'])) {
    $j = cere($site, $cheie, ['actiune' => 'stare']);
    arata_copii((array) ($j['copii'] ?? []));
    exit(0);
}

// --- ce s-ar schimba ----------------------------------------------------------------------------

titlu('Întreb serverul ce are și ce e în depozit');
$j = cere($site, $cheie, ['actiune' => 'stare']);
$s = (array) ($j['stare'] ?? []);
$de_schimbat = (array) ($s['de_schimbat'] ?? []);
$noi = (array) ($s['noi'] ?? []);
ok('pe server: ' . ($s['versiune_instalata'] ?? '?') . ' · în depozit: ' . ($s['versiune_in_pachet'] ?? '?'));
info('sursa: ' . ($s['sursa'] ?? '?'));
if (!$de_schimbat && !$noi) {
    ok('nimic de schimbat — codul de pe server e deja cel din depozit');
    exit(0);
}
ok(count($de_schimbat) . ' fișiere de schimbat, ' . count($noi) . ' noi');
foreach (array_slice(array_merge($de_schimbat, $noi), 0, 30) as $f) info("  $f");
if (count($de_schimbat) + count($noi) > 30) info('  … și altele');

if (empty($opt['acum'])) {
    if (!$INTERACTIV) { info('Rulează din nou cu --acum ca să sincronizezi.'); exit(0); }
    echo "\nSincronizez? (d = da, orice altceva = nu): ";
    $r = trim((string) fgets(STDIN));
    if (strtolower($r) !== 'd' && strtolower($r) !== 'da') { echo "N-am schimbat nimic.\n"; exit(0); }
}

// --- sincronizarea ------------------------------------------------------------------------------

titlu('Serverul își ia versiunea din depozit');
$j = cere($site, $cheie, ['actiune' => 'sincronizeaza'] + (!empty($opt['forta']) ? ['forta' => true] : []));
$rez = (array) ($j['rezultat'] ?? []);
$operatie = (string) ($rez['operatie'] ?? '?');
if ($operatie === 'pus înapoi') {
    gresit('actualizarea a stricat site-ul, iar serverul a pus singur versiunea veche înapoi');
    info((string) ($rez['atentie'] ?? ''));
    exit(1);
}
ok(($rez['scrise'] ?? 0) . ' fișiere scrise · copia de siguranță: ' . ($rez['copie'] ?? '?'));
ok('autocontrolul serverului: ' . (($rez['control']['detaliu'] ?? '') ?: '?'));
if (isset($rez['atentie'])) atentie((string) $rez['atentie']);

// --- verificarea de aici, din afară -------------------------------------------------------------

titlu('Verific site-ul din afară');
$bune = true;
foreach ([['/', 200], ['/mcp', 405]] as [$cale, $asteptat]) {
    $r = cerere($cale === '/mcp' ? 'GET' : 'GET', $site . $cale);
    if ($r['cod'] === $asteptat) ok(sprintf('%-8s %d', $cale, $r['cod']));
    else { gresit(sprintf('%-8s %d, așteptam %d', $cale, $r['cod'], $asteptat)); $bune = false; }
}
$dupa = cere($site, $cheie, ['actiune' => 'stare']);
$acum = (string) ($dupa['stare']['versiune_instalata'] ?? '?');
if ($acum === (string) ($s['versiune_in_pachet'] ?? '')) ok("versiunea de pe server e acum $acum");
else { gresit("versiunea de pe server e $acum, așteptam " . ($s['versiune_in_pachet'] ?? '?')); $bune = false; }

if (!$bune) {
    atentie('ceva nu e în regulă. Pun înapoi copia ' . ($rez['copie'] ?? '?') . '…');
    cere($site, $cheie, ['actiune' => 'restaureaza', 'copie' => (string) ($rez['copie'] ?? '')]);
    gresit('am pus înapoi versiunea dinainte. Verifică site-ul și spune-mi ce scrie în jurnal.');
    exit(1);
}

titlu('Gata');
ok("site-ul rulează versiunea $acum, fără zip și fără cPanel");
info('Copia dinainte a rămas pe server: ' . ($rez['copie'] ?? '?') . ' (se pune înapoi cu --pune=…)');
exit(0);
