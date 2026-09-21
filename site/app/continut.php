<?php
// Paginile și articolele: câte un fișier JSON în date/pagini/ și date/articole/.
// Orice scriere salvează întâi versiunea existentă în date/versiuni/; „ștergerea" mută fișierul acolo.
// Nicio funcție de aici nu scrie altceva decât .json în dosarele de date — niciodată cod.
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

const TIPURI = ['pagina' => 'pagini', 'articol' => 'articole'];
const STARI = ['ciorna', 'publicat'];
const SLUGURI_REZERVATE = ['articole', 'eticheta', 'media', 'assets', 'app', 'sabloane', 'date', 'mcp', 'jurnal',
    'index', 'sitemap', 'feed', 'robots', 'llms', 'admin', 'wp-admin', 'wp-login', 'favicon', 'cauta', 'api', 'previzualizare'];
const LIMITA_HTML = 1000000;   // octeți de HTML pe element

function tip_valid($tip): bool
{
    return is_string($tip) && isset(TIPURI[$tip]);
}

function slug_valid($slug): bool
{
    return is_string($slug) && strlen($slug) <= 80 && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1;
}

function verifica_tip_slug($tip, $slug): void
{
    if (!tip_valid($tip)) throw new EroareCms('tip necunoscut: folosește "pagina" sau "articol"');
    if (!slug_valid($slug)) {
        throw new EroareCms('slug invalid: doar litere mici fără diacritice, cifre și cratime (ex. "despre-noi"), cel mult 80 de caractere');
    }
}

function fisier_element(string $tip, string $slug): string
{
    return dir_date(TIPURI[$tip]) . '/' . $slug . '.json';
}

function citeste_element(string $tip, string $slug): ?array
{
    if (!tip_valid($tip) || !slug_valid($slug)) return null;
    return json_citeste(fisier_element($tip, $slug));
}

// Pe site se vede ce e publicat și are data publicării trecută; un articol programat e publicat, dar încă ascuns.
function e_vizibil(array $e): bool
{
    if (($e['stare'] ?? '') !== 'publicat') return false;
    $la = (string) ($e['publicat_la'] ?? '');
    return $la === '' || (int) strtotime($la) <= time();
}

// $stare: 'toate', 'ciorna', 'publicat' (inclusiv programate) sau 'vizibil' (ce apare acum pe site).
function listeaza_elemente(string $tip, string $stare = 'toate'): array
{
    $rez = [];
    foreach (glob(dir_date(TIPURI[$tip]) . '/*.json') ?: [] as $f) {
        $e = json_citeste($f);
        if (!$e || ($stare === 'vizibil' ? !e_vizibil($e) : ($stare !== 'toate' && ($e['stare'] ?? '') !== $stare))) continue;
        $rez[] = $e;
    }
    usort($rez, function ($a, $b) use ($tip) {
        if ($tip === 'articol') {
            return strcmp((string) ($b['publicat_la'] ?? $b['creat'] ?? ''), (string) ($a['publicat_la'] ?? $a['creat'] ?? ''));
        }
        $ma = $a['meniu'] ?? 999;
        $mb = $b['meniu'] ?? 999;
        return $ma === $mb ? strcmp((string) $a['titlu'], (string) $b['titlu']) : $ma <=> $mb;
    });
    return $rez;
}

function url_element(array $e): string
{
    return (($e['tip'] ?? '') === 'pagina' && ($e['slug'] ?? '') === 'acasa') ? '/' : '/' . $e['slug'];
}

function rezumat_element(array $e): array
{
    $r = ['tip' => $e['tip'], 'slug' => $e['slug'], 'titlu' => $e['titlu'] ?? '', 'stare' => $e['stare'] ?? '',
          'url' => url_absolut(url_element($e)), 'actualizat' => $e['actualizat'] ?? null, 'publicat_la' => $e['publicat_la'] ?? null];
    if ($e['tip'] === 'articol') $r['etichete'] = $e['etichete'] ?? [];
    if ($e['tip'] === 'pagina') $r['meniu'] = $e['meniu'] ?? null;
    if (($e['stare'] ?? '') === 'publicat' && !e_vizibil($e)) $r['programat_pentru'] = $e['publicat_la'];
    return $r;
}

// --- versiuni ----------------------------------------------------------------------------------

function dir_versiuni(string $tip, string $slug): string
{
    return dir_date('versiuni/' . TIPURI[$tip] . '/' . $slug);
}

function versioneaza_element(string $tip, string $slug, string $sufix = ''): ?string
{
    $sursa = fisier_element($tip, $slug);
    if (!is_file($sursa)) return null;
    $dir = dir_versiuni($tip, $slug);
    $id = date('Ymd-His') . $sufix;
    for ($n = 2; is_file("$dir/$id.json"); $n++) $id = date('Ymd-His') . '-' . $n . $sufix;
    if (!copy($sursa, "$dir/$id.json")) throw new EroareCms('nu am putut salva versiunea anterioară — scrierea a fost oprită');
    return $id;
}

function listeaza_versiuni(string $tip, string $slug): array
{
    verifica_tip_slug($tip, $slug);
    $rez = [];
    foreach (glob(dir_versiuni($tip, $slug) . '/*.json') ?: [] as $f) {
        $id = basename($f, '.json');
        $e = json_citeste($f) ?? [];
        $rez[] = ['versiune' => $id, 'salvata_la' => date('c', (int) filemtime($f)), 'titlu' => $e['titlu'] ?? '',
                  'stare' => $e['stare'] ?? '', 'stearsa' => substr($id, -6) === '-sters', 'octeti' => filesize($f)];
    }
    usort($rez, fn($a, $b) => strcmp($b['versiune'], $a['versiune']));
    return $rez;
}

// --- scriere -----------------------------------------------------------------------------------

function etichete_valide($v): array
{
    if (!is_array($v)) throw new EroareCms('"etichete" trebuie să fie o listă de texte');
    $rez = [];
    foreach ($v as $t) {
        $t = text_simplu($t, 40);
        if ($t !== '' && slug_din_text($t) !== '' && !in_array($t, $rez, true)) $rez[] = $t;
    }
    return array_slice($rez, 0, 10);
}

function imagine_valida($v): string
{
    $v = trim((string) $v);
    if ($v === '') return '';
    if (preg_match('#^/media/([a-z0-9-]+\.(jpg|png|gif|webp))$#', $v, $m)) {
        if (!is_file(dir_media() . '/' . $m[1])) throw new EroareCms("imaginea $v nu există — urc-o întâi cu urca_imagine");
        return $v;
    }
    // Doar imagini de pe acest site: o copertă de pe alt domeniu ar trimite adresa IP a fiecărui vizitator acolo.
    throw new EroareCms('"imagine" e o adresă /media/... întoarsă de urca_imagine (imaginile de pe alte domenii nu se acceptă)');
}

function salveaza_element(string $tip, string $slug, array $campuri): array
{
    verifica_tip_slug($tip, $slug);
    if (in_array($slug, SLUGURI_REZERVATE, true)) throw new EroareCms("slugul \"$slug\" e rezervat de site");
    if ($tip === 'articol' && $slug === 'acasa') throw new EroareCms('"acasa" e rezervat pentru prima pagină');
    $celalalt = $tip === 'pagina' ? 'articol' : 'pagina';

    return cu_blocare(function () use ($tip, $slug, $campuri, $celalalt) {
        if (is_file(fisier_element($celalalt, $slug))) {
            throw new EroareCms("există deja un $celalalt cu slugul \"$slug\" — paginile și articolele au adrese comune");
        }
        $vechi = citeste_element($tip, $slug);
        if ($vechi === null) {
            foreach (['titlu', 'continut_html'] as $obligatoriu) {
                if (!isset($campuri[$obligatoriu])) throw new EroareCms("la creare, \"$obligatoriu\" e obligatoriu");
            }
        }
        $nou = $vechi ?? ['tip' => $tip, 'slug' => $slug, 'stare' => 'ciorna', 'creat' => date('c'), 'publicat_la' => null];
        $raport = [];
        if (isset($campuri['titlu'])) {
            $nou['titlu'] = text_simplu($campuri['titlu'], 200);
            if ($nou['titlu'] === '') throw new EroareCms('titlul nu poate fi gol');
        }
        if (array_key_exists('descriere', $campuri)) $nou['descriere'] = text_simplu($campuri['descriere'] ?? '', 320);
        if (isset($campuri['continut_html'])) {
            if (!is_string($campuri['continut_html'])) throw new EroareCms('"continut_html" trebuie să fie text');
            if (strlen($campuri['continut_html']) > LIMITA_HTML) throw new EroareCms('conținutul depășește 1 MB');
            $nou['continut_html'] = curata_html($campuri['continut_html'], $raport);
        }
        if ($tip === 'articol') {
            if (array_key_exists('etichete', $campuri)) $nou['etichete'] = etichete_valide($campuri['etichete'] ?? []);
            if (array_key_exists('imagine', $campuri)) $nou['imagine'] = imagine_valida($campuri['imagine'] ?? '');
            if (array_key_exists('imagine_alt', $campuri)) $nou['imagine_alt'] = text_simplu($campuri['imagine_alt'] ?? '', 200);
            if (array_key_exists('autor', $campuri)) $nou['autor'] = text_simplu($campuri['autor'] ?? '', 80);
            $nou += ['etichete' => [], 'imagine' => '', 'imagine_alt' => '', 'autor' => (string) config('site.autor')];
        } else {
            if (array_key_exists('meniu', $campuri)) {
                $nou['meniu'] = $campuri['meniu'] === null ? null : max(0, min(99, (int) $campuri['meniu']));
            }
            $nou += ['meniu' => null];
        }
        $nou += ['descriere' => ''];

        if ($vechi !== null) {   // nimic schimbat = nicio scriere, nicio versiune nouă
            $a = $vechi; $b = $nou;
            unset($a['actualizat'], $b['actualizat']);
            if (json_text($a) === json_text($b)) {
                return ['operatie' => 'neschimbat', 'element' => rezumat_element($vechi), 'curatari' => $raport];
            }
        }
        $nou['actualizat'] = date('c');
        $json = json_text($nou, true);
        $versiune = $vechi !== null ? versioneaza_element($tip, $slug) : null;
        if (!scrie_atomic(fisier_element($tip, $slug), $json)) throw new EroareCms('scrierea pe disc a eșuat');
        $rez = ['operatie' => $vechi === null ? 'creat' : 'actualizat', 'element' => rezumat_element($nou),
                'versiune_anterioara' => $versiune, 'curatari' => $raport, 'amprenta' => hash('sha256', $json)];
        if ($nou['stare'] === 'publicat') {
            $rez['atentie'] = 'elementul e publicat: modificarea e deja vizibilă pe site';
            if (e_vizibil($nou)) indexnow_pentru($nou);   // Bing află de schimbare fără să aștepte următorul crawl
        }
        if ($vechi === null) $rez['atentie'] = 'creat ca ciornă — nu apare pe site până nu îl publici cu "publica"';
        return $rez;
    });
}

// La publicare, $la (opțional) e data publicării: în viitor = programat (apare singur la ora aceea, fără sarcini
// pe server), în trecut = se păstrează data (ex. la mutarea unui articol vechi). Fără $la: acum, sau data inițială
// dacă elementul a mai fost publicat.
function schimba_stare(string $tip, string $slug, string $stare, ?string $la = null): array
{
    verifica_tip_slug($tip, $slug);
    $moment = null;
    if ($la !== null && trim($la) !== '') {
        if ($stare !== 'publicat') throw new EroareCms('"la" se folosește doar la publicare');
        $ts = strtotime(trim($la));
        if ($ts === false || $ts < 0) throw new EroareCms('"la" nu e o dată validă — ex. "2026-10-01 09:00" (ora României)');
        $moment = date('c', $ts);
    }
    return cu_blocare(function () use ($tip, $slug, $stare, $moment) {
        $e = citeste_element($tip, $slug);
        if ($e === null) throw new EroareCms("nu există $tip cu slugul \"$slug\"");
        $data = $e['publicat_la'] ?? null;
        if ($stare === 'publicat') {
            $data = $moment ?? ((empty($data) || strtotime((string) $data) > time()) ? date('c') : $data);
        }
        if (($e['stare'] ?? '') === $stare && $data === ($e['publicat_la'] ?? null)) {
            return ['operatie' => 'neschimbat', 'element' => rezumat_element($e)];
        }
        $versiune = versioneaza_element($tip, $slug);
        $e['stare'] = $stare;
        $e['publicat_la'] = $data;
        $e['actualizat'] = date('c');
        $json = json_text($e, true);
        if (!scrie_atomic(fisier_element($tip, $slug), $json)) throw new EroareCms('scrierea pe disc a eșuat');
        $rez = ['operatie' => $stare === 'publicat' ? 'publicat' : 'retras (ciornă)', 'element' => rezumat_element($e),
                'versiune_anterioara' => $versiune, 'amprenta' => hash('sha256', $json)];
        if ($stare !== 'publicat' || e_vizibil($e)) indexnow_pentru($e);   // și retragerea e o schimbare de anunțat
        if ($stare === 'publicat' && !e_vizibil($e)) {
            $rez['operatie'] = 'programat';
            $rez['atentie'] = 'apare pe site singur la ' . $data . '; până atunci se vede doar prin previzualizeaza';
        }
        return $rez;
    });
}

function sterge_element(string $tip, string $slug): array
{
    verifica_tip_slug($tip, $slug);
    return cu_blocare(function () use ($tip, $slug) {
        if (!is_file(fisier_element($tip, $slug))) throw new EroareCms("nu există $tip cu slugul \"$slug\"");
        $versiune = versioneaza_element($tip, $slug, '-sters');
        if (!@unlink(fisier_element($tip, $slug))) throw new EroareCms('ștergerea a eșuat');
        return ['operatie' => 'șters (mutat între versiuni)', 'tip' => $tip, 'slug' => $slug, 'versiune' => $versiune,
                'refacere' => 'restaureaza cu versiunea ' . $versiune];
    });
}

function restaureaza_element(string $tip, string $slug, string $versiune): array
{
    verifica_tip_slug($tip, $slug);
    if (!preg_match('/^[0-9]{8}-[0-9]{6}(-[0-9]+)?(-sters)?$/', $versiune)) throw new EroareCms('identificator de versiune invalid');
    return cu_blocare(function () use ($tip, $slug, $versiune) {
        $e = json_citeste(dir_versiuni($tip, $slug) . "/$versiune.json");
        if ($e === null || ($e['tip'] ?? '') !== $tip || ($e['slug'] ?? '') !== $slug) throw new EroareCms('versiunea nu există');
        $curenta = versioneaza_element($tip, $slug);
        $e['continut_html'] = curata_html((string) ($e['continut_html'] ?? ''));
        $e['stare'] = 'ciorna';   // o versiune veche nu ajunge direct pe site: se verifică, apoi se publică
        $e['actualizat'] = date('c');
        $json = json_text($e, true);
        if (!scrie_atomic(fisier_element($tip, $slug), $json)) throw new EroareCms('scrierea pe disc a eșuat');
        return ['operatie' => 'restaurat ca ciornă', 'element' => rezumat_element($e), 'din_versiunea' => $versiune,
                'versiune_anterioara' => $curenta, 'amprenta' => hash('sha256', $json)];
    });
}

// --- identitatea site-ului (date/site.json) -----------------------------------------------------

function identitate_site(): array
{
    $s = (array) config('site');
    $rez = [];
    foreach (CAMPURI_IDENTITATE as $k) {
        $rez[$k] = in_array($k, CAMPURI_LISTA, true) ? array_values((array) ($s[$k] ?? [])) : (string) ($s[$k] ?? '');
    }
    return $rez;
}

// Legăturile site-ului: celelalte site-uri și conturi ale aceluiași om sau firme. Apar în subsol, pe fiecare
// pagină, și în datele structurate ca "sameAs" — de acolo află Google și Bing că profilurile sunt ale aceleiași
// entități. Se acceptă fie {"titlu": "...", "url": "..."}, fie doar adresa (titlul iese din ea).
function legaturi_valide($v): array
{
    if (!is_array($v)) throw new EroareCms('"legaturi" e o listă: fie adrese, fie {"titlu": "…", "url": "https://…"}');
    $rez = [];
    foreach ($v as $l) {
        $url = trim((string) (is_array($l) ? ($l['url'] ?? '') : $l));
        if ($url === '') continue;
        if (!url_sigur($url, false, ['https', 'http']) || strlen($url) > 300) throw new EroareCms("\"$url\" nu e o adresă http(s) validă");
        $titlu = text_simplu(is_array($l) ? ($l['titlu'] ?? '') : '', 60);
        if ($titlu === '') {   // fără titlu: numele gazdei, fără www și fără terminație, plus contul dacă e unul
            $p = parse_url($url);
            $gazda = (string) preg_replace('/^www\./', '', strtolower((string) ($p['host'] ?? '')));
            $cont = preg_match('#/@?([A-Za-z0-9._-]{2,40})/?$#', (string) ($p['path'] ?? ''), $m) ? $m[1] : '';
            $titlu = $gazda . ($cont !== '' ? ' · ' . $cont : '');
        }
        if ($titlu === '') continue;
        foreach ($rez as $g) if ($g['url'] === $url) continue 2;   // aceeași adresă, o singură dată
        $rez[] = ['titlu' => $titlu, 'url' => $url];
    }
    return array_slice($rez, 0, 15);
}

// Temele: foi de stil puse de om în assets/teme/<nume>.css (cu fonturile lor în assets/teme/<nume>/).
// AI-ul doar alege una dintre ele; nu poate scrie CSS. Fără temă, site-ul are aspectul din assets/stil.css.
function teme_disponibile(): array
{
    $teme = [];
    foreach (glob(dirname(__DIR__) . '/assets/teme/*.css') ?: [] as $f) {
        $nume = basename($f, '.css');
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $nume)) $teme[] = $nume;
    }
    sort($teme);
    return $teme;
}

// Ce spune tema despre ea: primul comentariu din foaia de stil. Acolo își descrie autorul temei blocurile (clasele)
// pe care le știe, ca AI-ul să le poată folosi în conținut fără să ghicească.
function tema_despre(string $tema): string
{
    if (!in_array($tema, teme_disponibile(), true)) return '';
    $css = (string) @file_get_contents(dirname(__DIR__) . "/assets/teme/$tema.css", false, null, 0, 8000);
    if (!preg_match('#^\s*/\*(.*?)\*/#s', $css, $m)) return '';
    return trim((string) preg_replace('/[ \t]*\n[ \t]*/', "\n", $m[1]));
}

// Temele proprii: puse pe acest site de om, cu cheia de cod (unelte/tema.php), nu venite din depozitul public — lucrări
// pentru un client, care nu au ce căuta pe GitHub. Registrul lor stă în date/, pe care sincronizarea nu-l atinge; după el,
// sincronizarea le ocolește, ca o temă publică nouă cu același nume să nu scrie peste ele.
function teme_proprii(): array
{
    $r = json_citeste(config('date') . '/teme-proprii.json') ?? [];
    return array_filter($r, fn($info, $nume) => is_string($nume) && is_array($info) && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $nume),
        ARRAY_FILTER_USE_BOTH);
}

// Tema aleasă, doar dacă foaia ei e încă pe server (o copie pusă pe alt site poate cere o temă pe care el nu o are).
function tema_activa(): string
{
    $t = (string) config('site.tema');
    return $t !== '' && in_array($t, teme_disponibile(), true) ? $t : '';
}

function seteaza_identitate(array $campuri): array
{
    $nou = [];
    if (array_key_exists('nume', $campuri)) {
        $nou['nume'] = text_simplu($campuri['nume'] ?? '', 80);
        if ($nou['nume'] === '') throw new EroareCms('numele site-ului nu poate fi gol');
    }
    if (array_key_exists('descriere', $campuri)) $nou['descriere'] = text_simplu($campuri['descriere'] ?? '', 300);
    if (array_key_exists('autor', $campuri)) $nou['autor'] = text_simplu($campuri['autor'] ?? '', 80);
    if (array_key_exists('limba', $campuri)) {
        $nou['limba'] = (string) ($campuri['limba'] ?? '');
        if (!preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $nou['limba'])) throw new EroareCms('"limba" e un cod ca "ro" sau "en-GB"');
    }
    if (array_key_exists('culoare', $campuri)) {
        $nou['culoare'] = strtolower((string) ($campuri['culoare'] ?? ''));
        if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $nou['culoare'])) throw new EroareCms('"culoare" e un cod hex, ex. "#6d2be8"');
    }
    foreach (['logo', 'favicon'] as $k) {
        if (!array_key_exists($k, $campuri)) continue;
        $nou[$k] = trim((string) ($campuri[$k] ?? ''));
        if ($nou[$k] !== '' && !preg_match('#^/media/[a-z0-9-]+\.(jpg|png|gif|webp)$#', $nou[$k])) {
            throw new EroareCms("\"$k\" e adresa unei imagini urcate, ex. \"/media/sigla-a1b2c3d4.png\" (gol = fără $k)");
        }
        if ($nou[$k] !== '' && !is_file(dir_media() . '/' . basename($nou[$k]))) throw new EroareCms("imaginea {$nou[$k]} nu există — urc-o întâi cu urca_imagine");
    }
    if (array_key_exists('legaturi', $campuri)) $nou['legaturi'] = legaturi_valide($campuri['legaturi'] ?? []);
    if (array_key_exists('ga4', $campuri)) {
        // Doar identificatorul, nu cod. Site-ul compune singur eticheta, cu nonce-ul paginii: așa se poate măsura
        // traficul fără ca AI-ul să poată pune vreodată JavaScript în pagină.
        $nou['ga4'] = strtoupper(trim((string) ($campuri['ga4'] ?? '')));
        if ($nou['ga4'] !== '' && !preg_match('/^G-[A-Z0-9]{6,14}$/', $nou['ga4'])) {
            throw new EroareCms('"ga4" e identificatorul de măsurare Google Analytics 4, ex. "G-798XLP278H" (gol = fără măsurare). '
                . 'Nu se trimite eticheta de script, doar identificatorul.');
        }
    }
    if (array_key_exists('tema', $campuri)) {
        $nou['tema'] = trim((string) ($campuri['tema'] ?? ''));
        $teme = teme_disponibile();
        if ($nou['tema'] !== '' && !in_array($nou['tema'], $teme, true)) {
            throw new EroareCms('"tema" e una dintre temele de pe server: ' . ($teme ? '"' . implode('", "', $teme) . '"' : 'nu e niciuna') . ' ("" = aspectul implicit)');
        }
    }
    // Textul din subsol, pe fiecare pagină: o mențiune care trebuie să fie peste tot (ce nu e site-ul, firma și CUI-ul).
    if (array_key_exists('subsol', $campuri)) $nou['subsol'] = text_simplu($campuri['subsol'] ?? '', 300);
    // Cum se numesc articolele pe site („ghiduri", „rețete"): în meniu, pe prima pagină, în liste. Adresa rămâne /articole.
    if (array_key_exists('nume_articole', $campuri)) {
        $nou['nume_articole'] = text_simplu($campuri['nume_articole'] ?? '', 30);
        if ($nou['nume_articole'] !== '' && !preg_match('/^[\p{Ll}]{3,30}$/u', $nou['nume_articole'])) {
            throw new EroareCms('"nume_articole" e un singur cuvânt la plural, cu litere mici, ex. "ghiduri" (gol = "articole")');
        }
    }
    if (array_key_exists('arata_data', $campuri)) {
        $nou['arata_data'] = (string) ($campuri['arata_data'] ?? '');
        if (!in_array($nou['arata_data'], ['', 'da'], true)) throw new EroareCms('"arata_data" e "da" (data publicării apare pe pagini) sau "" (nu apare)');
    }
    if (!$nou) throw new EroareCms('trimite cel puțin un câmp: nume, descriere, autor, limba, culoare, logo, favicon, tema, legaturi, ga4, '
        . 'subsol, nume_articole sau arata_data');

    return cu_blocare(function () use ($nou) {
        $fisier = dir_date() . '/site.json';
        $inainte = identitate_site();
        $salvat = json_citeste($fisier) ?? [];
        $dupa = [];
        foreach (CAMPURI_IDENTITATE as $k) $dupa[$k] = $nou[$k] ?? $inainte[$k];
        if ($dupa === $inainte) return ['operatie' => 'neschimbat', 'site' => $inainte];
        $versiune = null;
        if (is_file($fisier)) {
            $dir = dir_date('versiuni/site');
            $versiune = date('Ymd-His');
            for ($n = 2; is_file("$dir/$versiune.json"); $n++) $versiune = date('Ymd-His') . '-' . $n;
            if (!copy($fisier, "$dir/$versiune.json")) throw new EroareCms('nu am putut salva versiunea anterioară — scrierea a fost oprită');
        }
        $json = json_text(array_intersect_key($nou + $salvat, array_flip(CAMPURI_IDENTITATE)) + ['actualizat' => date('c')], true);
        if (!scrie_atomic($fisier, $json)) throw new EroareCms('scrierea pe disc a eșuat');
        return ['operatie' => 'actualizat', 'site' => $dupa, 'inainte' => $inainte, 'versiune_anterioara' => $versiune,
                'atentie' => 'schimbarea e deja vizibilă pe tot site-ul', 'amprenta' => hash('sha256', $json)];
    });
}

// --- redirecționări (date/redirectionari.json) ------------------------------------------------
// Pentru adrese schimbate și pentru site-uri vechi mutate aici: /vechi → /nou, 301. Doar spre adrese de pe acest site.
// Se aplică numai când la adresa veche nu mai e nimic (în locul paginii 404).

const PREFIXE_REZERVATE = ['/mcp', '/mcp.php', '/app', '/date', '/media', '/assets', '/sabloane', '/jurnal.php', '/index.php',
    '/sitemap.xml', '/feed.xml', '/robots.txt', '/llms.txt', '/cauta', '/previzualizare', '/articole', '/eticheta', '/.well-known'];

function cale_redirectionare($v, string $camp): string
{
    $v = trim((string) $v);
    if (preg_match('#^https?://#i', $v)) {   // adresa întreagă de pe site-ul vechi: contează doar calea
        $p = parse_url($v);
        $v = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    }
    if ($v === '' || $v[0] !== '/' || strlen($v) > 300 || preg_match('#^//|[\x00-\x20\x7F\\\\]#', $v)) {
        throw new EroareCms("\"$camp\" e o cale de pe site, ex. \"/despre-noi.html\" (fără spații, cel mult 300 de caractere)");
    }
    [$cale, $query] = array_pad(explode('?', $v, 2), 2, '');
    $cale = rawurldecode($cale);
    if (preg_match('#[\x00-\x1F\x7F]|(^|/)\.\.?(/|$)#', $cale)) throw new EroareCms("\"$camp\" conține caractere sau segmente nepermise");
    $cale = rtrim($cale, '/') ?: '/';
    return $cale . ($query !== '' ? '?' . $query : '');
}

function citeste_redirectionari(): array
{
    return json_citeste(config('date') . '/redirectionari.json') ?? [];
}

// $la gol = scoate redirecționarea.
function seteaza_redirectionare($de, $la): array
{
    $de = cale_redirectionare($de, 'de');
    $cale_de = explode('?', $de, 2)[0];
    if ($cale_de === '/') throw new EroareCms('prima pagină nu se poate redirecționa');
    foreach (PREFIXE_REZERVATE as $p) {
        if ($cale_de === $p || strpos($cale_de, $p . '/') === 0) throw new EroareCms("adresa $de e folosită de site și nu se poate redirecționa");
    }
    $sterge = $la === null || trim((string) $la) === '';
    if (!$sterge) {
        $gazda = parse_url(trim((string) $la), PHP_URL_HOST);
        if ($gazda !== null && strtolower((string) $gazda) !== strtolower((string) parse_url(url_site(), PHP_URL_HOST))) {
            throw new EroareCms('"la" trebuie să fie o adresă de pe acest site (ex. "/despre"), nu de pe alt domeniu');
        }
        $la = cale_redirectionare($la, 'la');
        if ($la === $de) throw new EroareCms('adresa țintă e aceeași cu cea veche');
    }
    return cu_blocare(function () use ($de, $la, $sterge, $cale_de) {
        $fisier = dir_date() . '/redirectionari.json';
        $toate = citeste_redirectionari();
        if ($sterge) {
            if (!isset($toate[$de])) throw new EroareCms("nu există o redirecționare de la $de");
            unset($toate[$de]);
        } else {
            if (($toate[$de]['la'] ?? null) === $la) return ['operatie' => 'neschimbat', 'de' => $de, 'la' => $la];
            $toate[$de] = ['la' => $la, 'creat' => date('c')];
            for ($pas = $de, $vazute = []; isset($toate[$pas]); $pas = $toate[$pas]['la']) {   // lanțul nu are voie să se închidă
                if (isset($vazute[$pas])) throw new EroareCms("redirecționarea ar face o buclă: $de → … → $pas");
                $vazute[$pas] = true;
            }
        }
        $versiune = null;
        if (is_file($fisier)) {
            $dir = dir_date('versiuni/redirectionari');
            $versiune = date('Ymd-His');
            for ($n = 2; is_file("$dir/$versiune.json"); $n++) $versiune = date('Ymd-His') . '-' . $n;
            if (!copy($fisier, "$dir/$versiune.json")) throw new EroareCms('nu am putut salva versiunea anterioară — scrierea a fost oprită');
        }
        ksort($toate);
        $json = json_text($toate ?: new stdClass(), true);
        if (!scrie_atomic($fisier, $json)) throw new EroareCms('scrierea pe disc a eșuat');
        $rez = ['operatie' => $sterge ? 'scoasă' : 'adăugată', 'de' => $de, 'la' => $sterge ? null : $la,
                'versiune_anterioara' => $versiune, 'amprenta' => hash('sha256', $json)];
        $slug = ltrim($cale_de, '/');
        if (!$sterge && slug_valid($slug) && (citeste_element('pagina', $slug) || citeste_element('articol', $slug))) {
            $rez['atentie'] = "la $cale_de există o pagină sau un articol: redirecționarea se aplică doar dacă elementul dispare de pe site";
        }
        return $rez;
    });
}

function cauta_redirectionare(string $cale, string $query): ?string
{
    $toate = citeste_redirectionari();
    $cale = rtrim($cale, '/') ?: '/';
    if ($query !== '' && isset($toate["$cale?$query"]['la'])) return (string) $toate["$cale?$query"]['la'];
    return isset($toate[$cale]['la']) ? (string) $toate[$cale]['la'] : null;
}

// --- căutarea pentru vizitatori ----------------------------------------------------------------
// Doar ce se vede pe site. Fără diacritice în căutare: „sedinta" găsește și „ședința".

const DIACRITICE = ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
                    'Ă' => 'a', 'Â' => 'a', 'Î' => 'i', 'Ș' => 's', 'Ş' => 's', 'Ț' => 't', 'Ţ' => 't'];

function model_fara_diacritice(string $cuvant): string
{
    $clase = ['a' => '[aăâ]', 'i' => '[iî]', 's' => '[sșş]', 't' => '[tțţ]'];
    $r = '';
    foreach (preg_split('//u', $cuvant, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $c) $r .= $clase[$c] ?? preg_quote($c, '/');
    return $r;
}

// Căutarea vizitatorilor e singura pagină publică ce scanează tot conținutul, deci are un plafon:
// primii 200 KB dintr-un element. Altfel, câteva articole foarte lungi ar transforma /cauta în pârghie de CPU.
const CAUTARE_MAX_OCTETI = 200000;

function text_simplu_element(array $e): string
{
    $html = substr((string) ($e['continut_html'] ?? ''), 0, CAUTARE_MAX_OCTETI);
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $html)),
        ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

function cauta_public(string $q): array
{
    $q = strtolower(strtr(text_simplu($q, 100), DIACRITICE));
    $cuvinte = array_slice(array_values(array_unique(array_filter(preg_split('/\s+/u', $q) ?: [], fn($c) => strlen($c) >= 2))), 0, 8);
    if (!$cuvinte) return ['modele' => [], 'rezultate' => []];
    $modele = array_map('model_fara_diacritice', $cuvinte);
    $rez = [];
    foreach (array_keys(TIPURI) as $tip) {
        foreach (listeaza_elemente($tip, 'vizibil') as $e) {
            $text = text_simplu_element($e);
            $tot = ($e['titlu'] ?? '') . ' ' . ($e['descriere'] ?? '') . ' ' . $text;
            $scor = 0;
            foreach ($modele as $m) {
                if (!preg_match("/$m/iu", $tot)) continue 2;
                if (preg_match("/$m/iu", (string) ($e['titlu'] ?? ''))) $scor += 10;
                $scor += min(5, preg_match_all("/$m/iu", $text));
            }
            $fragment = '';
            foreach ($modele as $m) {
                if (preg_match('/\S.{0,90}' . $m . '.{0,140}/isu', ' ' . $text, $g)) { $fragment = trim($g[0]); break; }
            }
            $rez[] = ['e' => $e, 'scor' => $scor, 'fragment' => $fragment !== '' ? '…' . $fragment . '…' : (string) ($e['descriere'] ?? '')];
        }
    }
    usort($rez, fn($a, $b) => [$b['scor'], (string) ($b['e']['publicat_la'] ?? '')] <=> [$a['scor'], (string) ($a['e']['publicat_la'] ?? '')]);
    return ['modele' => $modele, 'rezultate' => array_slice($rez, 0, 50)];
}

// Textul, cu potrivirile marcate: fiecare bucată e escapată separat, deci nimic din text nu devine HTML.
function evidentiaza(string $text, array $modele): string
{
    if (!$modele) return esc($text);
    $parti = preg_split('/(' . implode('|', $modele) . ')/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
    $html = '';
    foreach ($parti as $i => $p) $html .= $i % 2 ? '<mark>' . esc($p) . '</mark>' : esc($p);
    return $html;
}

// --- export: tot conținutul, pentru copia de siguranță (unelte/copie.php) -----------------------

function exporta_continut(): array
{
    $rez = ['format' => 'mini-cms-mcp/export', 'versiune' => MINICMS_VERSIUNE, 'exportat_la' => date('c'),
            'site' => ['url' => url_site()] + identitate_site()];
    foreach (TIPURI as $tip => $plural) $rez[$plural] = listeaza_elemente($tip);
    $rez['redirectionari'] = citeste_redirectionari() ?: new stdClass();
    $rez['imagini'] = [];
    foreach (listeaza_imagini() as $i) $rez['imagini'][] = $i + ['amprenta' => hash_file('sha256', dir_media() . '/' . $i['nume'])];
    return $rez;
}

// --- previzualizarea ciornelor ---------------------------------------------------------------
// Un link semnat, valabil un timp scurt: /previzualizare/<slug>?e=<expirare>&s=<semnătură>.
// Cheia de semnare stă în date/securitate/ și se creează la prima cerere de link.

function cheie_previzualizare(bool $creeaza): string
{
    $f = config('date') . '/securitate/previzualizare.cheie';
    $cheie = is_file($f) ? trim((string) file_get_contents($f)) : '';
    if ($cheie === '' && $creeaza) {
        $f = dir_date('securitate') . '/previzualizare.cheie';
        $cheie = bin2hex(random_bytes(32));
        if (!scrie_atomic($f, $cheie)) throw new EroareCms('nu am putut crea cheia de previzualizare');
    }
    return $cheie;
}

function link_previzualizare(string $tip, string $slug, int $minute): array
{
    verifica_tip_slug($tip, $slug);
    $e = citeste_element($tip, $slug);
    if ($e === null) throw new EroareCms("nu există $tip cu slugul \"$slug\"");
    $minute = max(5, min(1440, $minute));
    $expira = time() + $minute * 60;
    $semn = hash_hmac('sha256', "$slug|$expira", cheie_previzualizare(true));
    return ['url' => url_absolut("/previzualizare/$slug?e=$expira&s=$semn"), 'expira' => date('c', $expira),
            'stare' => $e['stare'] ?? '', 'pe_site' => e_vizibil($e),
            'atentie' => 'linkul arată elementul exact ca pe site, cu oricine îl primește, până la expirare'];
}

function previzualizare_valida(string $slug, int $expira, string $semn): bool
{
    $cheie = cheie_previzualizare(false);
    if ($cheie === '' || $expira < time() || $expira > time() + 86400 + 60) return false;
    return hash_equals(hash_hmac('sha256', "$slug|$expira", $cheie), $semn);
}

function cauta_elemente(string $text, ?string $tip): array
{
    $text = text_simplu($text, 100);
    if (strlen($text) < 2) throw new EroareCms('textul căutat trebuie să aibă cel puțin 2 caractere');
    $model = '/.{0,60}' . preg_quote($text, '/') . '.{0,100}/isu';   // potrivirea cu context, pe caractere, nu pe octeți
    $rez = [];
    foreach ($tip ? [$tip] : array_keys(TIPURI) as $t) {
        foreach (listeaza_elemente($t) as $e) {
            $simplu = (string) preg_replace('/\s+/u', ' ', ($e['titlu'] ?? '') . ' — ' . ($e['descriere'] ?? '') . ' — '
                . html_entity_decode(strip_tags((string) ($e['continut_html'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (!preg_match($model, $simplu, $m)) continue;
            $r = rezumat_element($e);
            $r['fragment'] = '…' . $m[0] . '…';
            $rez[] = $r;
        }
    }
    return $rez;
}
