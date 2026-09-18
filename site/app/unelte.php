<?php
// Comenzile pe care le vede AI-ul. Cheia de citire le vede doar pe cele marcate 'scriere' => false.
// Principiul: AI-ul e singurul editor — schimbă conținut, niciodată cod. Nicio comandă nu scrie .php,
// nu atinge șabloanele, configurarea sau jurnalul.
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

function schema_obiect(array $proprietati, array $obligatorii = []): array
{
    $s = ['type' => 'object', 'properties' => $proprietati ?: new stdClass(), 'additionalProperties' => false];
    if ($obligatorii) $s['required'] = $obligatorii;
    return $s;
}

function arg_text(array $a, string $k, bool $obligatoriu = true): ?string
{
    if (!array_key_exists($k, $a) || $a[$k] === null) {
        if ($obligatoriu) throw new EroareCms("lipsește parametrul \"$k\"");
        return null;
    }
    if (!is_string($a[$k])) throw new EroareCms("parametrul \"$k\" trebuie să fie text");
    return $a[$k];
}

function unelte(): array
{
    $tip = ['type' => 'string', 'enum' => ['pagina', 'articol'],
            'description' => '"pagina" = pagină fixă (Despre, Contact; "acasa" e prima pagină) · "articol" = articol de blog, cu dată și etichete'];
    $slug = ['type' => 'string', 'pattern' => '^[a-z0-9]+(-[a-z0-9]+)*$', 'maxLength' => 80,
             'description' => 'Adresa: litere mici fără diacritice, cifre, cratime. Ex. "despre-noi" → /despre-noi. Pagina "acasa" e /.'];
    $citire = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];

    return [
        'despre_site' => [
            'scriere' => false, 'titlu' => 'Despre site și regulile lui', 'adnotari' => $citire,
            'descriere' => 'Numele și adresa site-ului, câte pagini și articole are, ce HTML e permis, cum se formează adresele și ce drepturi are cheia folosită. Cheam-o prima.',
            'schema' => schema_obiect([]),
            'fn' => function (array $a, string $rol) {
                $numar = [];
                foreach (array_keys(TIPURI) as $t) {
                    $toate = listeaza_elemente($t);
                    $numar[$t] = ['total' => count($toate), 'publicate' => count(array_filter($toate, fn($e) => ($e['stare'] ?? '') === 'publicat'))];
                }
                return [
                    'site' => ['nume' => config('site.nume'), 'url' => url_site(), 'descriere' => config('site.descriere'), 'limba' => config('site.limba')],
                    'versiune' => MINICMS_VERSIUNE, 'cheia_ta' => $rol, 'continut' => $numar, 'imagini' => count(listeaza_imagini()),
                    'adrese' => ['/' => 'pagina "acasa" + ultimele articole', '/<slug>' => 'pagină sau articol publicat',
                                 '/articole' => 'lista articolelor', '/eticheta/<eticheta>' => 'articolele cu o etichetă',
                                 '/media/<fisier>' => 'imagini urcate', '/sitemap.xml, /feed.xml, /llms.txt, /robots.txt' => 'generate automat'],
                    'reguli' => [
                        'Tot ce creezi pleacă drept ciornă; pe site apare doar după "publica".',
                        'Paginile și articolele au adrese comune: un slug nu poate fi folosit de ambele.',
                        'Înainte de orice modificare se salvează o versiune; "sterge" mută elementul între versiuni.',
                        'HTML-ul trece printr-o listă de etichete permise; ce se scoate apare în "curatari" la răspuns.',
                        'Titlul elementului devine <h1>; în conținut începe cu <h2>.',
                        'Fiecare apel, inclusiv citirile, e scris în jurnal.',
                    ],
                    'html_permis' => array_keys(HTML_PERMISE),
                    'atribute_globale' => HTML_GLOBALE,
                    'iframe' => 'doar YouTube (youtube.com/embed, youtube-nocookie.com/embed) și Vimeo (player.vimeo.com/video)',
                    'imagini_acceptate' => 'JPEG, PNG, GIF, WebP, cel mult 5 MB; SVG nu',
                ];
            },
        ],
        'listeaza' => [
            'scriere' => false, 'titlu' => 'Listează paginile și articolele', 'adnotari' => $citire,
            'descriere' => 'Lista paginilor și/sau articolelor, fără conținut: slug, titlu, stare (ciorna/publicat), adresă, date.',
            'schema' => schema_obiect([
                'tip' => ['type' => 'string', 'enum' => ['pagina', 'articol', 'toate'], 'default' => 'toate'],
                'stare' => ['type' => 'string', 'enum' => ['ciorna', 'publicat', 'toate'], 'default' => 'toate'],
            ]),
            'fn' => function (array $a) {
                $tip = arg_text($a, 'tip', false) ?? 'toate';
                $stare = arg_text($a, 'stare', false) ?? 'toate';
                if ($tip !== 'toate' && !tip_valid($tip)) throw new EroareCms('tip necunoscut');
                if ($stare !== 'toate' && !in_array($stare, STARI, true)) throw new EroareCms('stare necunoscută');
                $rez = [];
                foreach ($tip === 'toate' ? array_keys(TIPURI) : [$tip] as $t) {
                    $rez[TIPURI[$t]] = array_map('rezumat_element', listeaza_elemente($t, $stare));
                }
                return $rez;
            },
        ],
        'citeste' => [
            'scriere' => false, 'titlu' => 'Citește o pagină sau un articol', 'adnotari' => $citire,
            'descriere' => 'Tot elementul, cu conținutul HTML, exact cum e salvat.',
            'schema' => schema_obiect(['tip' => $tip, 'slug' => $slug], ['tip', 'slug']),
            'fn' => function (array $a) {
                $tip = arg_text($a, 'tip'); $s = arg_text($a, 'slug');
                verifica_tip_slug($tip, $s);
                $e = citeste_element($tip, $s);
                if ($e === null) throw new EroareCms("nu există $tip cu slugul \"$s\"");
                $e['url'] = url_absolut(url_element($e));
                return $e;
            },
        ],
        'cauta' => [
            'scriere' => false, 'titlu' => 'Caută în conținut', 'adnotari' => $citire,
            'descriere' => 'Caută un text în titlul, descrierea și conținutul paginilor și articolelor (inclusiv ciorne). Întoarce și fragmentul găsit.',
            'schema' => schema_obiect(['text' => ['type' => 'string', 'minLength' => 2],
                                       'tip' => ['type' => 'string', 'enum' => ['pagina', 'articol']]], ['text']),
            'fn' => function (array $a) {
                $tip = arg_text($a, 'tip', false);
                if ($tip !== null && !tip_valid($tip)) throw new EroareCms('tip necunoscut');
                return ['rezultate' => cauta_elemente((string) arg_text($a, 'text'), $tip)];
            },
        ],
        'listeaza_versiuni' => [
            'scriere' => false, 'titlu' => 'Versiunile unui element', 'adnotari' => $citire,
            'descriere' => 'Versiunile salvate automat înainte de fiecare modificare, publicare, retragere sau ștergere. Cele mai noi primele.',
            'schema' => schema_obiect(['tip' => $tip, 'slug' => $slug], ['tip', 'slug']),
            'fn' => fn(array $a) => ['versiuni' => listeaza_versiuni((string) arg_text($a, 'tip'), (string) arg_text($a, 'slug'))],
        ],
        'listeaza_imagini' => [
            'scriere' => false, 'titlu' => 'Listează imaginile', 'adnotari' => $citire,
            'descriere' => 'Imaginile urcate în /media/, cele mai noi primele.',
            'schema' => schema_obiect([]),
            'fn' => fn(array $a) => ['imagini' => listeaza_imagini()],
        ],
        'citeste_jurnal' => [
            'scriere' => false, 'titlu' => 'Citește jurnalul', 'adnotari' => $citire,
            'descriere' => 'Ultimele intrări din jurnal (cine, când, de unde, ce comandă, ce rezultat) și verificarea lanțului: "intact" = niciun rând modificat sau scos.',
            'schema' => schema_obiect([
                'ultimele' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 50],
                'doar_probleme' => ['type' => 'boolean', 'default' => false, 'description' => 'doar încercări eșuate, refuzuri, erori, blocări'],
            ]),
            'fn' => function (array $a) {
                $n = max(1, min(500, (int) ($a['ultimele'] ?? 50)));
                return ['lant' => jurnal_verifica(), 'intrari' => jurnal_ultimele($n, !empty($a['doar_probleme']))];
            },
        ],
        'salveaza' => [
            'scriere' => true, 'titlu' => 'Creează sau modifică o pagină ori un articol',
            'adnotari' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            'descriere' => 'Creează elementul (ca ciornă) sau îl modifică. La modificare trimite doar câmpurile care se schimbă; restul rămân. '
                . 'Dacă elementul e publicat, modificarea apare imediat pe site. Versiunea anterioară se salvează automat. '
                . 'HTML-ul e filtrat; "curatari" din răspuns spune ce s-a scos.',
            'schema' => schema_obiect([
                'tip' => $tip, 'slug' => $slug,
                'titlu' => ['type' => 'string', 'maxLength' => 200, 'description' => 'obligatoriu la creare; devine <h1>'],
                'continut_html' => ['type' => 'string', 'description' => 'obligatoriu la creare; HTML de la <h2> în jos (vezi despre_site)'],
                'descriere' => ['type' => 'string', 'maxLength' => 320, 'description' => 'o frază: apare în Google, în listă și în llms.txt'],
                'etichete' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 10, 'description' => 'doar la articole'],
                'imagine' => ['type' => 'string', 'description' => 'doar la articole: coperta, adresa /media/... întoarsă de urca_imagine'],
                'imagine_alt' => ['type' => 'string', 'description' => 'doar la articole: descrierea copertei'],
                'meniu' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 99, 'description' => 'doar la pagini: poziția în meniu; null = nu apare în meniu'],
            ], ['tip', 'slug']),
            'fn' => function (array $a) {
                $campuri = $a;
                unset($campuri['tip'], $campuri['slug']);
                return salveaza_element((string) arg_text($a, 'tip'), (string) arg_text($a, 'slug'), $campuri);
            },
        ],
        'publica' => [
            'scriere' => true, 'titlu' => 'Publică',
            'adnotari' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            'descriere' => 'Face elementul vizibil pe site. Publică doar după ce omul a aprobat conținutul.',
            'schema' => schema_obiect(['tip' => $tip, 'slug' => $slug], ['tip', 'slug']),
            'fn' => fn(array $a) => schimba_stare((string) arg_text($a, 'tip'), (string) arg_text($a, 'slug'), 'publicat'),
        ],
        'retrage' => [
            'scriere' => true, 'titlu' => 'Retrage de pe site',
            'adnotari' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            'descriere' => 'Trece elementul înapoi în ciornă: dispare de pe site, rămâne salvat.',
            'schema' => schema_obiect(['tip' => $tip, 'slug' => $slug], ['tip', 'slug']),
            'fn' => fn(array $a) => schimba_stare((string) arg_text($a, 'tip'), (string) arg_text($a, 'slug'), 'ciorna'),
        ],
        'sterge' => [
            'scriere' => true, 'titlu' => 'Șterge (reversibil)',
            'adnotari' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
            'descriere' => 'Scoate elementul de pe site și din listă, mutându-l între versiuni. Se poate reface cu "restaureaza".',
            'schema' => schema_obiect(['tip' => $tip, 'slug' => $slug], ['tip', 'slug']),
            'fn' => fn(array $a) => sterge_element((string) arg_text($a, 'tip'), (string) arg_text($a, 'slug')),
        ],
        'restaureaza' => [
            'scriere' => true, 'titlu' => 'Restaurează o versiune',
            'adnotari' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
            'descriere' => 'Aduce înapoi o versiune (și un element șters). Revine ca ciornă, ca să fie verificată înainte de publicare; versiunea curentă se păstrează.',
            'schema' => schema_obiect(['tip' => $tip, 'slug' => $slug,
                'versiune' => ['type' => 'string', 'description' => 'identificatorul din listeaza_versiuni, ex. "20260918-153000"']], ['tip', 'slug', 'versiune']),
            'fn' => fn(array $a) => restaureaza_element((string) arg_text($a, 'tip'), (string) arg_text($a, 'slug'), (string) arg_text($a, 'versiune')),
        ],
        'urca_imagine' => [
            'scriere' => true, 'titlu' => 'Urcă o imagine',
            'adnotari' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            'descriere' => 'Urcă o imagine JPEG/PNG/GIF/WebP (cel mult 5 MB) și întoarce adresa /media/... de folosit în conținut sau drept copertă.',
            'schema' => schema_obiect([
                'nume' => ['type' => 'string', 'description' => 'nume descriptiv, ex. "coperta-ghid-dimineata.png"; extensia se stabilește din conținut'],
                'continut_base64' => ['type' => 'string', 'description' => 'fișierul codat base64 (se acceptă și data:image/...;base64,...)'],
            ], ['nume', 'continut_base64']),
            'fn' => fn(array $a) => urca_imagine((string) arg_text($a, 'nume'), (string) arg_text($a, 'continut_base64')),
        ],
        'sterge_imagine' => [
            'scriere' => true, 'titlu' => 'Șterge o imagine (reversibil)',
            'adnotari' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
            'descriere' => 'Mută imaginea între versiuni. Refuză dacă e folosită undeva, afară de cazul forteaza=true.',
            'schema' => schema_obiect(['nume' => ['type' => 'string', 'description' => 'numele din listeaza_imagini'],
                                       'forteaza' => ['type' => 'boolean', 'default' => false]], ['nume']),
            'fn' => fn(array $a) => sterge_imagine((string) arg_text($a, 'nume'), !empty($a['forteaza'])),
        ],
    ];
}

// Ce apare în jurnal drept „țintă" a unui apel.
function tinta_apel(array $a): string
{
    if (isset($a['tip'], $a['slug']) && is_string($a['tip']) && is_string($a['slug'])) return substr($a['tip'] . '/' . $a['slug'], 0, 100);
    if (isset($a['nume']) && is_string($a['nume'])) return 'media/' . substr($a['nume'], 0, 80);
    return '';
}
