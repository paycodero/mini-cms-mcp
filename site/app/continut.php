<?php
// Paginile și articolele: câte un fișier JSON în date/pagini/ și date/articole/.
// Orice scriere salvează întâi versiunea existentă în date/versiuni/; „ștergerea" mută fișierul acolo.
// Nicio funcție de aici nu scrie altceva decât .json în dosarele de date — niciodată cod.
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

const TIPURI = ['pagina' => 'pagini', 'articol' => 'articole'];
const STARI = ['ciorna', 'publicat'];
const SLUGURI_REZERVATE = ['articole', 'eticheta', 'media', 'assets', 'app', 'sabloane', 'date', 'mcp', 'jurnal',
    'index', 'sitemap', 'feed', 'robots', 'llms', 'admin', 'wp-admin', 'wp-login', 'favicon', 'cauta', 'api'];
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

function listeaza_elemente(string $tip, string $stare = 'toate'): array
{
    $rez = [];
    foreach (glob(dir_date(TIPURI[$tip]) . '/*.json') ?: [] as $f) {
        $e = json_citeste($f);
        if (!$e || ($stare !== 'toate' && ($e['stare'] ?? '') !== $stare)) continue;
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
    if (url_sigur($v, false, ['https'])) return $v;
    throw new EroareCms('"imagine" trebuie să fie o adresă /media/... întoarsă de urca_imagine sau un URL https');
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
        if ($nou['stare'] === 'publicat') $rez['atentie'] = 'elementul e publicat: modificarea e deja vizibilă pe site';
        if ($vechi === null) $rez['atentie'] = 'creat ca ciornă — nu apare pe site până nu îl publici cu "publica"';
        return $rez;
    });
}

function schimba_stare(string $tip, string $slug, string $stare): array
{
    verifica_tip_slug($tip, $slug);
    return cu_blocare(function () use ($tip, $slug, $stare) {
        $e = citeste_element($tip, $slug);
        if ($e === null) throw new EroareCms("nu există $tip cu slugul \"$slug\"");
        if (($e['stare'] ?? '') === $stare) return ['operatie' => 'neschimbat', 'element' => rezumat_element($e)];
        $versiune = versioneaza_element($tip, $slug);
        $e['stare'] = $stare;
        if ($stare === 'publicat' && empty($e['publicat_la'])) $e['publicat_la'] = date('c');
        $e['actualizat'] = date('c');
        $json = json_text($e, true);
        if (!scrie_atomic(fisier_element($tip, $slug), $json)) throw new EroareCms('scrierea pe disc a eșuat');
        return ['operatie' => $stare === 'publicat' ? 'publicat' : 'retras (ciornă)', 'element' => rezumat_element($e),
                'versiune_anterioara' => $versiune, 'amprenta' => hash('sha256', $json)];
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
