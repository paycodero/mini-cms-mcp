<?php
// Modul demo: un site pe care îl pot încerca oamenii din afară (de exemplu verificatorii directorului de conectori Claude)
// și care revine singur, o dată pe zi, la un conținut curat. Se pornește DOAR din config.php, pe care AI-ul nu-l poate
// schimba:   'demo' => ['resetare' => '03:00'],
//
// Instantaneul curat se face de pe calculator, cu cheia de cod: php unelte/demo.php https://site --instantaneu.
// Resetarea o declanșează prima cerere care vine după ora aleasă (fusul orar din config), o dată pe zi; nu e nevoie de
// sarcini programate pe server. Se pun la loc paginile, articolele, documentele, imaginile, redirecționările, identitatea
// site-ului și versiunile lor. NU se ating: jurnalul (urma a ce au făcut vizitatorii), cheile și editorii, conexiunile
// OAuth (un verificator aflat în mijlocul testului nu își pierde conectorul), citirile AI, copiile de cod și temele proprii.
// În modul demo, site-ul nu se indexează, nu anunță IndexNow, nu măsoară cu GA4 și arată o bandă „Site demo” sus.
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

function demo_activ(): bool
{
    $d = config('demo');
    return is_array($d) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($d['resetare'] ?? '')) === 1;
}

function demo_ora(): string
{
    return (string) (config('demo')['resetare'] ?? '');
}

// Ce se resetează: căi relative la dosarul de date, plus dosarul imaginilor (sub numele „media” în instantaneu).
function demo_continut(): array
{
    $c = [];
    foreach (TIPURI as $dosar) $c[$dosar] = dir_date() . '/' . $dosar;
    $c['fisiere'] = dir_date() . '/fisiere';
    $c['site.json'] = dir_date() . '/site.json';
    $c['redirectionari.json'] = dir_date() . '/redirectionari.json';
    foreach (['pagini', 'articole', 'site', 'media', 'fisiere', 'redirectionari'] as $v) $c["versiuni/$v"] = dir_date() . "/versiuni/$v";
    $c['media'] = dir_media();
    return $c;
}

function demo_dir(): string
{
    return dir_date('demo');
}

function demo_sterge(string $cale): void
{
    if (is_link($cale) || is_file($cale)) { @unlink($cale); return; }
    if (!is_dir($cale)) return;
    foreach (scandir($cale) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        demo_sterge("$cale/$f");
    }
    @rmdir($cale);
}

// Copiază un fișier sau un dosar întreg. Paza unui dosar de date (.htaccess) se refolosește din țintă, nu se copiază.
function demo_copiaza(string $sursa, string $tinta): int
{
    if (is_file($sursa)) {
        if (!is_dir(dirname($tinta))) @mkdir(dirname($tinta), 0755, true);
        if (!@copy($sursa, $tinta)) throw new RuntimeException('nu pot copia ' . basename($sursa));
        return 1;
    }
    if (!is_dir($sursa)) return 0;
    if (!is_dir($tinta) && !@mkdir($tinta, 0755, true) && !is_dir($tinta)) throw new RuntimeException('nu pot crea ' . basename($tinta));
    $n = 0;
    foreach (scandir($sursa) ?: [] as $f) {
        if ($f === '.' || $f === '..' || $f === '.htaccess') continue;
        $n += demo_copiaza("$sursa/$f", "$tinta/$f");
    }
    return $n;
}

// Pune conținutul de acum deoparte, ca stare curată. Cel vechi se înlocuiește.
function demo_instantaneu(): array
{
    if (!demo_activ()) throw new EroareCms('modul demo nu e pornit: în config.php lipsește \'demo\' => [\'resetare\' => \'03:00\']');
    return cu_blocare(function () {
        $nou = demo_dir() . '/instantaneu-nou';
        demo_sterge($nou);
        @mkdir($nou, 0755, true);
        $n = 0;
        foreach (demo_continut() as $nume => $cale) {
            if (file_exists($cale)) $n += demo_copiaza($cale, "$nou/$nume");
        }
        $vechi = demo_dir() . '/instantaneu';
        demo_sterge($vechi);
        if (!@rename($nou, $vechi)) throw new RuntimeException('nu pot pune instantaneul la locul lui');
        $meta = ['facut' => date('c'), 'fisiere' => $n];
        scrie_atomic(demo_dir() . '/instantaneu.json', json_text($meta, true));
        jurnal_scrie(['punct' => 'demo', 'cerere' => 'instantaneu', 'cheie' => 'cod', 'rezultat' => 'ok', 'detalii' => $meta]);
        return $meta;
    });
}

// Pune la loc instantaneul. $motiv: „ora” (resetarea zilnică) sau „manual” (comanda de pe calculator).
function demo_reseteaza(string $motiv = 'manual', int $prag = 0): array
{
    if (!demo_activ()) throw new EroareCms('modul demo nu e pornit');
    $inst = demo_dir() . '/instantaneu';
    if (!is_dir($inst)) throw new EroareCms('nu există încă un instantaneu: fă-l întâi cu php unelte/demo.php <site> --instantaneu');
    return cu_blocare(function () use ($inst, $motiv, $prag) {
        // două cereri venite deodată după ora resetării: a doua găsește resetarea deja făcută și nu o repetă
        $ultima = json_citeste(demo_dir() . '/resetare.json');
        if ($prag > 0 && (strtotime((string) ($ultima['resetat'] ?? '')) ?: 0) >= $prag) return $ultima;
        $n = 0;
        foreach (demo_continut() as $nume => $cale) {
            if ($nume === 'media' || is_dir($cale)) {   // dosarele: se golesc, dar paza lor (.htaccess) rămâne
                foreach (is_dir($cale) ? (scandir($cale) ?: []) : [] as $f) {
                    if ($f !== '.' && $f !== '..' && $f !== '.htaccess') demo_sterge("$cale/$f");
                }
            } else {
                demo_sterge($cale);
            }
            if (file_exists("$inst/$nume")) $n += demo_copiaza("$inst/$nume", $cale);
        }
        $meta = ['resetat' => date('c'), 'motiv' => $motiv, 'fisiere' => $n];
        scrie_atomic(demo_dir() . '/resetare.json', json_text($meta, true));
        jurnal_scrie(['punct' => 'demo', 'cerere' => 'resetare', 'cheie' => $motiv === 'manual' ? 'cod' : '', 'rezultat' => 'ok', 'detalii' => $meta]);
        return $meta;
    });
}

// Chemată la fiecare cerere: resetează o dată pe zi, după ora aleasă. O eroare nu oprește niciodată site-ul.
function demo_reseteaza_daca_e_timpul(): void
{
    if (!demo_activ() || !is_dir(demo_dir() . '/instantaneu')) return;
    $prag = strtotime(date('Y-m-d') . ' ' . demo_ora());
    if ($prag === false || time() < $prag) return;
    $ultima = json_citeste(demo_dir() . '/resetare.json');
    $cand = strtotime((string) ($ultima['resetat'] ?? '')) ?: 0;
    if ($cand >= $prag) return;
    try {
        demo_reseteaza('ora', $prag);
    } catch (Throwable $e) {
        jurnal_scrie(['punct' => 'demo', 'cerere' => 'resetare', 'rezultat' => 'eroare',
                      'detalii' => ['intern' => get_class($e) . ': ' . substr($e->getMessage(), 0, 200)]]);
    }
}

function demo_stare(): array
{
    if (!demo_activ()) return ['pornit' => false];
    return ['pornit' => demo_activ(), 'resetare' => demo_ora(),
            'instantaneu' => json_citeste(demo_dir() . '/instantaneu.json'), 'ultima_resetare' => json_citeste(demo_dir() . '/resetare.json')];
}
