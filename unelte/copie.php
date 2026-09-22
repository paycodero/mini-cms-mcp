<?php
// Copia de siguranță a unui site, de pe calculatorul tău, prin MCP:
//
//   php unelte/copie.php https://site.ro
//      salvează tot conținutul (pagini, articole și ciorne, identitate, redirecționări, imagini) în
//      <dosar>/_copii/<nume>/<data>/ — export.json + media/. Folosește cheia de citire.
//
//   php unelte/copie.php https://site.ro --pune=DOSAR
//      pune copia din DOSAR pe site (ex. pe un site nou, gol). Folosește cheia de scriere. Pe un site care are deja
//      conținut, cere și --peste: elementele cu același slug se modifică (versiunea lor anterioară se păstrează).
//
// Opțiuni comune cu instaleaza.php: --nume=scurt, --dosar=CALE, --local. Cheile sunt cele din chei-<nume>.json.
declare(strict_types=1);

require __DIR__ . '/comun.php';

['opt' => $opt, 'site' => $site, 'nume' => $nume, 'dosar' => $dosar, 'fisier_chei' => $fisier_chei] = porneste($argv, ['pune', 'peste'],
    'php unelte/copie.php https://site.ro [--pune=DOSARUL-COPIEI [--peste]] [--nume=scurt] [--dosar=CALE]');
$chei = cheile($fisier_chei, true);
$S = DIRECTORY_SEPARATOR;

function numara(array $export): string
{
    $ciorne = count(array_filter(array_merge($export['pagini'] ?? [], $export['articole'] ?? []), fn($e) => ($e['stare'] ?? '') !== 'publicat'));
    return count($export['pagini'] ?? []) . ' pagini, ' . count($export['articole'] ?? []) . ' articole'
        . ($ciorne ? " ($ciorne ciorne)" : '') . ', ' . count((array) ($export['redirectionari'] ?? [])) . ' redirecționări, '
        . count($export['imagini'] ?? []) . ' imagini';
}

// --- salvarea copiei -------------------------------------------------------------------------

if (!isset($opt['pune'])) {
    echo culoare("mini-cms-mcp $VERSIUNE — copia site-ului $site", '1'), "\n";
    $antet = antet_pentru($site, $chei['citire']['cheie']);
    if ($antet === null) opreste("serverul nu primește cheia de citire din $fisier_chei.");
    titlu('Exportul');
    try {
        $export = apel_mcp($site, $chei['citire']['cheie'], $antet, 'exporta');
    } catch (Throwable $e) {
        opreste('exportul a eșuat: ' . $e->getMessage());
    }
    if (($export['format'] ?? '') !== 'mini-cms-mcp/export') opreste('serverul nu a întors un export mini-cms-mcp.');
    ok(numara($export));

    $tinta = "$dosar{$S}_copii{$S}$nume{$S}" . date('Y-m-d_His');
    for ($n = 2; is_dir($tinta); $n++) $tinta = "$dosar{$S}_copii{$S}$nume{$S}" . date('Y-m-d_His') . "-$n";   // nicio copie nu se scrie peste alta
    if (!mkdir("$tinta{$S}media", 0755, true)) opreste("nu pot crea $tinta.");
    file_put_contents("$tinta{$S}export.json", json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

    titlu('Imaginile');
    $bune = 0;
    foreach ($export['imagini'] ?? [] as $img) {
        $numef = (string) ($img['nume'] ?? '');
        if (!preg_match('/^[a-z0-9-]+\.(jpg|png|gif|webp)$/', $numef)) { gresit("nume de imagine neașteptat: $numef"); continue; }
        $r = cerere('GET', "$site/media/$numef", null, [], 60);
        if ($r['cod'] !== 200 || hash('sha256', $r['corp']) !== ($img['amprenta'] ?? '')) { gresit("$numef: nu s-a descărcat întreagă (cod {$r['cod']})"); continue; }
        file_put_contents("$tinta{$S}media{$S}$numef", $r['corp']);
        $bune++;
    }
    ok("$bune din " . count($export['imagini'] ?? []) . ' imagini, verificate după amprentă');

    titlu('Gata');
    ok("copia e în $tinta");
    info("Se pune înapoi cu: php unelte/copie.php $site --pune=\"$tinta\"");
    exit($bune === count($export['imagini'] ?? []) ? 0 : 1);
}

// --- punerea copiei pe site --------------------------------------------------------------------

$sursa = rtrim((string) $opt['pune'], '/\\');
$export = json_decode((string) @file_get_contents("$sursa{$S}export.json"), true);
if (!is_array($export) || ($export['format'] ?? '') !== 'mini-cms-mcp/export') opreste("în $sursa nu există un export.json de la mini-cms-mcp.");
echo culoare("mini-cms-mcp $VERSIUNE — pun copia pe $site", '1'), "\n";
echo 'Copia: ' . numara($export) . ', exportată la ' . ($export['exportat_la'] ?? '?') . " de pe " . ($export['site']['url'] ?? '?') . "\n";
$ks = $chei['scriere']['cheie'];
$antet = antet_pentru($site, $ks);
if ($antet === null) opreste("serverul nu primește cheia de scriere din $fisier_chei.");
$mcp = fn(string $unealta, array $argumente = []) => apel_mcp($site, $ks, $antet, $unealta, $argumente);

$existente = $mcp('listeaza');
if ((($existente['pagini'] ?? []) || ($existente['articole'] ?? [])) && empty($opt['peste'])) {
    opreste('site-ul are deja conținut. Ca să pui copia peste el (elementele cu același slug se modifică, cu versiunea anterioară păstrată), adaugă --peste.');
}
$greseli = 0;

titlu('Imaginile');
$mapare = [];   // adresa din copie → adresa de pe site, dacă diferă
foreach (glob("$sursa{$S}media{$S}*") ?: [] as $f) {
    $numef = basename($f);
    if (!preg_match('/^([a-z0-9-]+)\.(jpg|png|gif|webp)$/', $numef, $m)) continue;
    $date = (string) file_get_contents($f);
    // Serverul pune în nume primele 8 caractere din amprentă; le scoatem, ca să rezulte exact același nume.
    $baza = preg_replace('/-' . substr(hash('sha256', $date), 0, 8) . '$/', '', $m[1]);
    try {
        $r = $mcp('urca_imagine', ['nume' => "$baza.{$m[2]}", 'continut_base64' => base64_encode($date)]);
        if (($r['url'] ?? '') !== "/media/$numef") $mapare["/media/$numef"] = (string) $r['url'];
    } catch (Throwable $e) {
        gresit("$numef: " . $e->getMessage());
        $greseli++;
    }
}
ok(count(glob("$sursa{$S}media{$S}*") ?: []) . ' imagini urcate' . ($mapare ? ', ' . count($mapare) . ' cu alt nume (adresele din conținut se corectează)' : ''));
$schimba = fn(string $t) => strtr($t, $mapare);

titlu('Identitatea');
$identitate = [];
foreach (['nume', 'descriere', 'autor', 'limba', 'culoare', 'logo', 'favicon', 'tema', 'ga4', 'subsol', 'nume_articole', 'arata_data'] as $k) {
    $v = (string) ($export['site'][$k] ?? '');
    if ($v !== '') $identitate[$k] = $schimba($v);
}
// Copia ține doar numele temei. O temă proprie (făcută pentru acel client) nu vine cu site-ul nou: fără ea, restul
// identității se pune oricum, iar tema se alege după ce e urcată.
if (isset($identitate['tema'])) {
    try {
        $teme_site = (array) ($mcp('despre_site')['teme']['disponibile'] ?? []);
    } catch (Throwable $e) {
        $teme_site = [];
    }
    if (!in_array($identitate['tema'], $teme_site, true)) {
        atentie("tema \"{$identitate['tema']}\" nu e pe $site, deci site-ul rămâne deocamdată cu aspectul implicit.");
        info("O pui cu: php unelte/tema.php $site --pune={$identitate['tema']} — apoi AI-ul o alege cu seteaza_site.");
        unset($identitate['tema']);
    }
}
if (!empty($export['site']['legaturi']) && is_array($export['site']['legaturi'])) $identitate['legaturi'] = array_values($export['site']['legaturi']);
try {
    if ($identitate) $mcp('seteaza_site', $identitate);
    ok($identitate ? 'numele, descrierea și restul identității puse' : 'copia nu are identitate proprie');
} catch (Throwable $e) {
    gresit('identitatea: ' . $e->getMessage());
    $greseli++;
}

titlu('Paginile și articolele');
$puse = 0;
// paginile-părinte întâi: o subpagină se poate pune doar sub o pagină care există deja
if (is_array($export['pagini'] ?? null)) usort($export['pagini'], fn($a, $b) => (int) !empty($a['parinte']) <=> (int) !empty($b['parinte']));
foreach (['pagina' => 'pagini', 'articol' => 'articole'] as $tip => $plural) {
    foreach ($export[$plural] ?? [] as $e) {
        $argumente = ['tip' => $tip, 'slug' => (string) $e['slug'], 'titlu' => (string) ($e['titlu'] ?? ''),
                      'continut_html' => $schimba((string) ($e['continut_html'] ?? '')), 'descriere' => (string) ($e['descriere'] ?? '')];
        if ($tip === 'articol') {
            $argumente += ['etichete' => (array) ($e['etichete'] ?? []), 'imagine' => $schimba((string) ($e['imagine'] ?? '')),
                           'imagine_alt' => (string) ($e['imagine_alt'] ?? ''), 'autor' => (string) ($e['autor'] ?? '')];
        } else {
            $argumente['meniu'] = $e['meniu'] ?? null;
            // doar când copia are câmpul (0.16+): un site mai vechi nu-l cunoaște și ar refuza pagina
            if (array_key_exists('parinte', $e)) $argumente['parinte'] = (string) ($e['parinte'] ?? '');
        }
        try {
            $mcp('salveaza', $argumente);
            if (($e['stare'] ?? '') === 'publicat') $mcp('publica', ['tip' => $tip, 'slug' => (string) $e['slug'], 'la' => (string) ($e['publicat_la'] ?? '')]);
            $puse++;
        } catch (Throwable $ex) {
            gresit("$tip/{$e['slug']}: " . $ex->getMessage());
            $greseli++;
        }
    }
}
ok("$puse elemente puse, cu starea și data publicării de pe site-ul vechi");

titlu('Redirecționările');
$r_puse = 0;
foreach ((array) ($export['redirectionari'] ?? []) as $de => $r) {
    try {
        $mcp('redirectioneaza', ['de' => (string) $de, 'la' => (string) ($r['la'] ?? '')]);
        $r_puse++;
    } catch (Throwable $e) {
        gresit("$de: " . $e->getMessage());
        $greseli++;
    }
}
ok("$r_puse redirecționări puse");

titlu($greseli ? 'Terminat, cu greșeli' : 'Gata');
if ($greseli) { gresit("$greseli elemente nu s-au putut pune — detaliile sunt mai sus și în jurnalul site-ului."); exit(1); }
ok("copia e pe $site. Totul e scris în jurnalul site-ului.");
exit(0);
