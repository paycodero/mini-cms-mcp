<?php
// Editorii unui site — oameni (de obicei clientul) care scriu pe site cu cheia lor, iar în jurnal și în versiuni apare
// numele lor. Au tot ce are cheia de scriere, afară de identitatea site-ului (seteaza_site) și conexiunile OAuth.
//
//   php unelte/editor.php https://site.ro                            editorii de pe site
//   php unelte/editor.php https://site.ro --adauga="Maria Popescu"   cheie nouă pentru Maria, pusă pe site
//   php unelte/editor.php https://site.ro --scoate="Maria Popescu"   cheia Mariei nu mai merge (nici conexiunile ei OAuth)
//
// Cheia editorului se scrie într-un fișier al lui, <dosar>/chei-<nume>-editor-<om>.json, niciodată pe ecran: pe acela
// i-l dai omului (cu adresa MCP și comanda de conectare, scrise tot acolo). Pe server ajunge doar amprenta.
// Comanda folosește cheia de cod din chei-<nume>.json: AI-ul nu poate adăuga editori.
declare(strict_types=1);

require __DIR__ . '/comun.php';

['opt' => $opt, 'site' => $site, 'nume' => $nume, 'dosar' => $dosar, 'fisier_chei' => $fisier_chei] = porneste($argv, ['adauga', 'scoate'],
    'php unelte/editor.php https://site.ro [--adauga="Nume Prenume"] [--scoate="Nume Prenume"] [--nume=scurt] [--dosar=CALE]');
$cheie_cod = (string) (cheile($fisier_chei, true)['cod']['cheie'] ?? '');

echo culoare("mini-cms-mcp — editorii site-ului $site", '1'), "\n";

function fisier_editor(string $dosar, string $nume, string $om): string
{
    $scurt = strtr(litere_mici($om), ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
    $scurt = trim((string) preg_replace('/[^a-z0-9]+/', '-', $scurt), '-') ?: 'editor';
    return $dosar . DIRECTORY_SEPARATOR . "chei-$nume-editor-$scurt.json";
}

function litere_mici(string $t): string   // fără mbstring: literele românești mari, apoi ASCII
{
    return strtolower(strtr($t, ['Ă' => 'ă', 'Â' => 'â', 'Î' => 'î', 'Ș' => 'ș', 'Ş' => 'ş', 'Ț' => 'ț', 'Ţ' => 'ţ']));
}

function arata_editori(array $editori): void
{
    titlu('Editorii de pe site');
    if (!$editori) { info('niciunul — doar cheile tale (citire, scriere, cod)'); return; }
    foreach ($editori as $e) info('· ' . $e['nume'] . ($e['adaugat'] !== '' ? '  (adăugat ' . substr($e['adaugat'], 0, 10) . ')' : ''));
}

function cere(string $site, string $cheie, array $date): array
{
    $j = cerere_cod($site, $cheie, $date);
    if (!array_key_exists('editori', $j)) opreste("site-ul are o versiune mai veche de 0.19 și nu știe de editori. Actualizează-l întâi: php unelte/actualizeaza.php $site");
    return $j;
}

try {
    foreach (['adauga', 'scoate'] as $k) {
        if (isset($opt[$k]) && (!is_string($opt[$k]) || trim($opt[$k]) === '')) opreste("--$k are nevoie de numele omului, ex. --$k=\"Maria Popescu\"");
    }
    if (isset($opt['adauga'])) {
        $om = trim($opt['adauga']);
        titlu("Adaug editorul $om");
        $fisier = fisier_editor($dosar, $nume, $om);
        // Fișierul există deja (o încercare întreruptă): refolosesc cheia din el, nu fac alta peste ea.
        $c = is_file($fisier) ? json_decode((string) file_get_contents($fisier), true) : null;
        if (is_file($fisier) && !preg_match('/^mcms_e_[A-Za-z0-9_-]{40,}$/', (string) ($c['cheie'] ?? ''))) opreste("$fisier există, dar nu are forma unui fișier de cheie de editor.");
        if (!$c) {
            $cheie = 'mcms_e_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $c = ['site' => $site, 'editor' => $om, 'cheie' => $cheie, 'amprenta' => hash('sha256', $cheie),
                  'mcp' => "$site/mcp", 'antet' => 'Authorization: Bearer ' . $cheie,
                  'claude_code' => "claude mcp add --transport http $nume $site/mcp --header \"Authorization: Bearer $cheie\"",
                  'creat' => date('c')];
            if (file_put_contents($fisier, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX) === false) opreste("nu pot scrie $fisier.");
            @chmod($fisier, 0600);
            ok('cheie nouă, scrisă în fișier (nu pe ecran): ' . basename($fisier));
        } else {
            info('refolosesc cheia din ' . basename($fisier));
        }
        $j = cere($site, $cheie_cod, ['actiune' => 'adauga_editor', 'nume' => $om, 'amprenta' => (string) $c['amprenta']]);
        ok("$om poate scrie pe site; în jurnal și în versiuni apare cu numele lui");
        info('Dă-i omului fișierul ' . $fisier . ' (pe un canal sigur): are adresa MCP, antetul și comanda pentru Claude Code.');
        info('Pe claude.ai (conectorul OAuth), își ia singur codul de conectare de pe ' . $site . '/oauth/conectare, cu cheia lui.');
    } elseif (isset($opt['scoate'])) {
        $om = trim($opt['scoate']);
        titlu("Scot editorul $om");
        $j = cere($site, $cheie_cod, ['actiune' => 'scoate_editor', 'nume' => $om]);
        ok("cheia lui $om nu mai merge, nici conexiunile aprobate cu ea");
        if (is_file($f = fisier_editor($dosar, $nume, $om))) info('fișierul lui local a rămas: ' . basename($f) . ' — cheia din el e moartă.');
    } else {
        $j = cere($site, $cheie_cod, ['actiune' => 'editori']);
    }
} catch (RuntimeException $e) {
    opreste($e->getMessage());
}
arata_editori((array) $j['editori']);
exit(0);
