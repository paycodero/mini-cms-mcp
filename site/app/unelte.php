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
            'descriere' => 'Numele, descrierea și adresa site-ului, câte pagini și articole are, ce HTML e permis, cum se formează adresele și ce drepturi are cheia folosită. Cheam-o prima.',
            'schema' => schema_obiect([]),
            'fn' => function (array $a, string $rol) {
                $numar = [];
                foreach (array_keys(TIPURI) as $t) {
                    $toate = listeaza_elemente($t);
                    $numar[$t] = ['total' => count($toate), 'publicate' => count(array_filter($toate, fn($e) => ($e['stare'] ?? '') === 'publicat'))];
                }
                return [
                    'site' => ['url' => url_site()] + identitate_site(),
                    'versiune' => MINICMS_VERSIUNE, 'cheia_ta' => $rol, 'continut' => $numar, 'imagini' => count(listeaza_imagini()),
                    'adrese' => ['/' => 'pagina "acasa" + ultimele articole', '/<slug>' => 'pagină sau articol publicat',
                                 '/articole' => 'lista articolelor', '/eticheta/<eticheta>' => 'articolele cu o etichetă',
                                 '/cauta?q=' => 'căutarea pentru vizitatori (doar ce e pe site)',
                                 '/media/<fisier>' => 'imagini urcate', '/sitemap.xml, /feed.xml, /llms.txt, /robots.txt' => 'generate automat'],
                    'reguli' => [
                        'Tot ce creezi pleacă drept ciornă; pe site apare doar după "publica".',
                        'Paginile și articolele au adrese comune: un slug nu poate fi folosit de ambele.',
                        'Înainte de orice modificare se salvează o versiune; "sterge" mută elementul între versiuni.',
                        'HTML-ul trece printr-o listă de etichete permise; ce se scoate apare în "curatari" la răspuns.',
                        'Titlul elementului devine <h1>; în conținut începe cu <h2>.',
                        'Fiecare apel, inclusiv citirile, e scris în jurnal.',
                        'Numele, descrierea, autorul, limba, culoarea, logo-ul, favicon-ul, tema, textul din subsol, mențiunea realizatorului și numele articolelor se schimbă cu seteaza_site, după acordul omului.',
                        'Înainte de "publica", trimite-i omului linkul din previzualizeaza: vede pagina exact ca pe site.',
                        '"publica" cu "la" în viitor programează elementul: apare singur la ora aceea.',
                        'Când se schimbă adresa unui element, o redirecționare (redirectioneaza) duce vizitatorii de la adresa veche la cea nouă.',
                        'Meniul are două niveluri: o pagină cu "parinte" (slugul unei pagini din meniu) apare în submeniul ei, în ordinea din "meniu". '
                            . 'Adresa rămâne /<slug>. Pagina-părinte își listează singură subpaginile, iar subpagina are un link înapoi: nu le scrie în conținut. '
                            . 'Un singur nivel de subpagini; o pagină cu subpagini nu se poate șterge până nu le muți.',
                        'Un articol care anunță un eveniment (concert, curs, lansare) primește câmpul "eveniment" (început, loc, adresă, artiști, bilete): '
                            . 'site-ul compune din el datele structurate Event și pune ziua evenimentului pe card. Nu scrie <script> JSON-LD în conținut: filtrul îl scoate.',
                        'Un site nou sau gol: cheamă pagini_de_pornire (acasă, despre, servicii, contact, confidențialitate) și completează-le cu omul.',
                        'Un loc [[COMPLETEAZĂ: …]] rămas în titlu, descriere sau conținut oprește publicarea.',
                    ],
                    'blocuri' => ['nota' => 'Blocurile comune: merg sub orice temă (tema le poate restiliza). Folosește-le în conținut exact ca în exemple; '
                        . 'poți schimba textele și numărul de elemente. Nu inventa cifre, citate sau clienți ca să umpli un bloc.',
                        'lista' => blocuri_comune()],
                    'html_permis' => array_keys(HTML_PERMISE),
                    'atribute_globale' => HTML_GLOBALE,
                    'iframe' => 'doar YouTube (youtube.com/embed, youtube-nocookie.com/embed) și Vimeo (player.vimeo.com/video)',
                    'imagini_acceptate' => 'JPEG, PNG, GIF, WebP, cel mult 5 MB; SVG nu',
                    'imagini_fara_base64' => 'Nu trimite poze prin base64. Poza e la om (telefon, calculator, atașată în chat): cheamă '
                        . 'link_urcare, dă-i linkul, el alege poza fără nicio cheie, apoi listeaza_imagini (cele mai noi primele). '
                        . 'O imagine publică: urca_imagine cu "url" (https). Un fișier de pe calculator, când rulezi în Claude Code: '
                        . 'php unelte/urca-imagine.php <site> <fișiere>. Nu-i cere omului cheia de scriere.',
                    'teme' => ['disponibile' => teme_disponibile(), 'activa' => tema_activa(),
                               'proprii' => array_values(array_intersect(array_keys(teme_proprii()), teme_disponibile())),
                               'despre' => array_filter(array_combine(teme_disponibile(), array_map('tema_despre', teme_disponibile())) ?: []),
                               'nota' => 'Aspectul site-ului. Se alege cu seteaza_site (tema); "" = aspectul implicit. Temele noi le pune omul pe server; '
                                   . '"proprii" sunt cele făcute anume pentru acest site. '
                                   . 'În "despre", fiecare temă spune ce blocuri (clase) știe; folosește-le în conținut așa cum sunt descrise.'],
                    'pe_pagini' => 'Fiecare etichetă poartă clasa eticheta-<slug> (tema o poate colora). Blocurile <pre> primesc singure un buton „Copiază". '
                        . 'Sub articol apar singure două articole înrudite. Coperta nu se repetă sus dacă imaginea e deja în conținut.',
                ];
            },
        ],
        'pagini_de_pornire' => [
            'scriere' => false, 'titlu' => 'Paginile de pornire ale unui site nou', 'adnotari' => $citire,
            'descriere' => 'Scheletele paginilor acasă, despre, servicii, contact și confidențialitate, făcute din blocurile comune, cu locuri '
                . '[[COMPLETEAZĂ: …]]. Nu creează nimic: le completezi cu ce afli de la om, le salvezi ca ciorne cu salveaza, apoi omul le aprobă. '
                . 'Pagina de confidențialitate descrie ce face tehnic acest site și ce e pornit acum pe el (GA4, Cloudflare, video).',
            'schema' => schema_obiect([]),
            'fn' => fn(array $a) => pagini_de_pornire(),
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
        'previzualizeaza' => [
            'scriere' => false, 'titlu' => 'Link de previzualizare', 'adnotari' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
            'descriere' => 'Un link temporar la care omul vede o ciornă (sau un element programat) exact cum va arăta pe site, înainte de "publica". '
                . 'Linkul expiră după "minute" (implicit 60) și nu e indexat.',
            'schema' => schema_obiect(['tip' => $tip, 'slug' => $slug,
                'minute' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 1440, 'default' => 60]], ['tip', 'slug']),
            'fn' => fn(array $a) => link_previzualizare((string) arg_text($a, 'tip'), (string) arg_text($a, 'slug'), (int) ($a['minute'] ?? 60)),
        ],
        'listeaza_redirectionari' => [
            'scriere' => false, 'titlu' => 'Listează redirecționările', 'adnotari' => $citire,
            'descriere' => 'Adresele vechi care trimit (301) spre adrese noi de pe site.',
            'schema' => schema_obiect([]),
            'fn' => fn(array $a) => ['redirectionari' => citeste_redirectionari() ?: new stdClass()],
        ],
        'exporta' => [
            'scriere' => false, 'titlu' => 'Exportă tot conținutul', 'adnotari' => $citire,
            'descriere' => 'Tot conținutul, pentru o copie de siguranță: identitatea site-ului, paginile și articolele întregi (și ciornele), '
                . 'redirecționările și lista imaginilor, cu amprentele lor. Imaginile se descarcă separat, de la adresele lor.',
            'schema' => schema_obiect([]),
            'fn' => fn(array $a) => exporta_continut(),
        ],
        'listeaza_conexiuni' => [
            'scriere' => false, 'titlu' => 'Listează conexiunile aprobate', 'adnotari' => $citire,
            'descriere' => 'Aplicațiile legate de site prin OAuth (ex. conectorul din claude.ai): nume, unde întorc, dacă au acces acum și cu ce drepturi. '
                . 'Legătura prin cheie în antet (Claude Code) nu apare aici.',
            'schema' => schema_obiect([]),
            'fn' => fn(array $a) => ['conexiuni' => conexiuni_oauth()],
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
                'meniu' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 99, 'description' => 'doar la pagini: poziția în meniu (la o subpagină: în submeniul părintelui); null = nu apare în meniu'],
                'parinte' => ['type' => ['string', 'null'], 'maxLength' => 80, 'description' => 'doar la pagini: slugul paginii din meniu sub care stă în submeniu (un singur nivel); "" = nicio secțiune'],
                'autor' => ['type' => 'string', 'maxLength' => 80, 'description' => 'doar la articole: autorul, dacă nu e cel implicit al site-ului'],
                'eveniment' => ['type' => ['object', 'null'], 'description' => 'doar la articole care anunță un eveniment; null = fără eveniment. '
                    . 'Site-ul compune din el datele structurate Event (Google), pune ziua evenimentului pe card și așază evenimentele care urmează primele pe prima pagină.',
                    'properties' => [
                        'inceput' => ['type' => 'string', 'description' => 'obligatoriu: ziua și ora, ora României, ex. "2026-10-27 19:00"'],
                        'sfarsit' => ['type' => 'string', 'description' => 'opțional, la fel'],
                        'tip' => ['type' => 'string', 'enum' => EVENIMENT_TIPURI, 'description' => 'implicit "Event"; concert = "MusicEvent"'],
                        'stare' => ['type' => 'string', 'enum' => array_keys(EVENIMENT_STARI), 'description' => 'implicit "programat"'],
                        'loc' => ['type' => 'string', 'maxLength' => 120, 'description' => 'obligatoriu: numele locului, ex. "Ateneul Român"'],
                        'adresa' => ['type' => 'string', 'maxLength' => 200, 'description' => 'strada și numărul'],
                        'oras' => ['type' => 'string', 'maxLength' => 80],
                        'tara' => ['type' => 'string', 'pattern' => '^[A-Za-z]{2}$', 'description' => 'implicit "RO"'],
                        'artisti' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'object', 'properties' => [
                            'nume' => ['type' => 'string', 'maxLength' => 120], 'grup' => ['type' => 'boolean', 'description' => 'true = orchestră, cor, formație']],
                            'required' => ['nume']]],
                        'organizator' => ['type' => 'string', 'maxLength' => 120, 'description' => 'implicit numele site-ului'],
                        'bilete' => ['type' => 'string', 'description' => 'adresa https a paginii de bilete'],
                    ], 'required' => ['inceput', 'loc']],
            ], ['tip', 'slug']),
            'fn' => function (array $a) {
                $campuri = $a;
                unset($campuri['tip'], $campuri['slug']);
                return salveaza_element((string) arg_text($a, 'tip'), (string) arg_text($a, 'slug'), $campuri);
            },
        ],
        'seteaza_site' => [
            'scriere' => true, 'titlu' => 'Setează numele și descrierea site-ului',
            'adnotari' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            'descriere' => 'Schimbă identitatea site-ului: numele (antet, titluri, feed), descrierea (Google, feed, llms.txt), '
                . 'autorul implicit al articolelor, limba, culoarea de accent, tema (aspectul), legăturile și textul din subsol, '
                . 'mențiunea despre cine a făcut site-ul, '
                . 'cum se numesc articolele pe site și dacă li se vede data. '
                . 'Trimite doar câmpurile care se schimbă. '
                . 'Schimbarea apare imediat pe tot site-ul; valorile anterioare se păstrează ca versiune și apar în răspuns.',
            'schema' => schema_obiect([
                'nume' => ['type' => 'string', 'maxLength' => 80],
                'descriere' => ['type' => 'string', 'maxLength' => 300, 'description' => 'o frază despre site'],
                'autor' => ['type' => 'string', 'maxLength' => 80, 'description' => 'autorul implicit al articolelor noi'],
                'limba' => ['type' => 'string', 'pattern' => '^[a-z]{2,3}(-[A-Z]{2})?$', 'description' => 'ex. "ro"'],
                'culoare' => ['type' => 'string', 'pattern' => '^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$', 'description' => 'culoarea de accent, ex. "#6d2be8"'],
                'logo' => ['type' => 'string', 'description' => 'sigla din antet: adresa /media/... întoarsă de urca_imagine; "" = fără logo'],
                'favicon' => ['type' => 'string', 'description' => 'iconița din tab: adresa /media/... a unei imagini pătrate (PNG); "" = fără'],
                'tema' => ['type' => 'string', 'enum' => array_merge([''], teme_disponibile()),
                           'description' => 'aspectul site-ului: una dintre temele din despre_site; "" = aspectul implicit'],
                'ga4' => ['type' => 'string', 'pattern' => '^(G-[A-Z0-9]{6,14})?$',
                    'description' => 'identificatorul Google Analytics 4, ex. "G-798XLP278H"; site-ul compune singur eticheta, '
                        . 'cu nonce și cu sursele adăugate în CSP. Se trimite DOAR identificatorul, niciodată cod. "" = fără măsurare'],
                'subsol' => ['type' => 'string', 'maxLength' => 300,
                    'description' => 'o mențiune scurtă, în subsol, pe fiecare pagină (ex. ce nu oferă site-ul, sau firma și CUI-ul); "" = fără'],
                'realizare' => ['type' => 'string', 'maxLength' => 80,
                    'description' => 'cine a făcut site-ul, în subsol, pe rândul cu ©, ex. "Website realizat cu AI și miniCMS"; "" = fără'],
                'realizare_url' => ['type' => 'string',
                    'description' => 'adresa spre care duce mențiunea "realizare" (https://...); "" = mențiunea fără link. '
                        . 'Nu intră în "sameAs": realizatorul nu e un profil al autorului'],
                'nume_articole' => ['type' => 'string', 'maxLength' => 30,
                    'description' => 'cum se numesc articolele pe site, un cuvânt la plural cu litere mici, ex. "ghiduri": apare în meniu, '
                        . 'pe prima pagină și în liste ("Ultimele ghiduri", "Toate ghidurile"). Adresa rămâne /articole. "" = "articole"'],
                'arata_data' => ['type' => 'string', 'enum' => ['', 'da'],
                    'description' => '"da" = data publicării apare pe articol și pe carduri (potrivit unui blog); "" = nu apare (implicit). '
                        . 'Datele rămân oricum în sitemap, feed și datele structurate.'],
                'legaturi' => ['type' => 'array', 'maxItems' => 15,
                    'description' => 'celelalte site-uri și conturi ale aceluiași autor: apar în subsol, pe fiecare pagină, '
                        . 'și în datele structurate ca "sameAs" (așa știu Google și Bing că sunt ale aceleiași entități). '
                        . 'Lista goală le scoate.',
                    'items' => ['type' => 'object', 'additionalProperties' => false,
                        'properties' => ['titlu' => ['type' => 'string', 'maxLength' => 60, 'description' => 'cum apare în subsol; gol = numele gazdei'],
                                         'url' => ['type' => 'string', 'description' => 'adresa, cu https://']],
                        'required' => ['url']]],
            ]),
            'fn' => fn(array $a) => seteaza_identitate($a),
        ],
        'publica' => [
            'scriere' => true, 'titlu' => 'Publică',
            'adnotari' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            'descriere' => 'Face elementul vizibil pe site. Publică doar după ce omul a aprobat conținutul (vezi previzualizeaza). '
                . 'Cu "la" în viitor, elementul e programat: apare singur la ora aceea.',
            'schema' => schema_obiect(['tip' => $tip, 'slug' => $slug,
                'la' => ['type' => 'string', 'description' => 'opțional: data și ora publicării, ex. "2026-10-01 09:00" (ora României). '
                    . 'În viitor = programat; în trecut = păstrează data (ex. la mutarea unui articol vechi).']], ['tip', 'slug']),
            'fn' => fn(array $a) => schimba_stare((string) arg_text($a, 'tip'), (string) arg_text($a, 'slug'), 'publicat', arg_text($a, 'la', false)),
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
        'redirectioneaza' => [
            'scriere' => true, 'titlu' => 'Redirecționează o adresă veche',
            'adnotari' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            'descriere' => 'Trimite vizitatorii de la o adresă veche (ex. după schimbarea unui slug, sau de pe un site vechi mutat aici) spre una nouă, '
                . 'de pe acest site, cu 301. Se aplică doar când la adresa veche nu mai e nimic. Cu "la" gol, scoate redirecționarea.',
            'schema' => schema_obiect([
                'de' => ['type' => 'string', 'description' => 'adresa veche, ex. "/despre-noi.html" sau "/pagina.php?id=5"'],
                'la' => ['type' => ['string', 'null'], 'description' => 'adresa nouă de pe site, ex. "/despre"; "" sau null = scoate redirecționarea'],
            ], ['de', 'la']),
            'fn' => fn(array $a) => seteaza_redirectionare(arg_text($a, 'de'), arg_text($a, 'la', false)),
        ],
        'retrage_conexiune' => [
            'scriere' => true, 'titlu' => 'Retrage accesul unei conexiuni',
            'adnotari' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
            'descriere' => 'Anulează accesul unei aplicații legate prin OAuth (client_id din listeaza_conexiuni): token-urile ei nu mai merg, '
                . 'iar ca să se lege din nou trebuie aprobată iar, cu cheia. Doar la cererea omului.',
            'schema' => schema_obiect(['client_id' => ['type' => 'string']], ['client_id']),
            'fn' => fn(array $a) => retrage_conexiune_oauth((string) arg_text($a, 'client_id')),
        ],
        'urca_imagine' => [
            'scriere' => true, 'titlu' => 'Urcă o imagine',
            'adnotari' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            'descriere' => 'Urcă o imagine JPEG/PNG/GIF/WebP (cel mult 5 MB) și întoarce adresa /media/... de folosit în conținut sau drept copertă. '
                . 'Trimite FIE "url" (adresa https a unei imagini publice: serverul o descarcă singur, adresele interne sunt refuzate), '
                . 'FIE "continut_base64" (doar pentru imagini mici). Când poza e la om (telefon, calculator, atașată în chat), nu o '
                . 'coda: cheamă link_urcare și dă-i omului linkul.',
            'schema' => schema_obiect([
                'nume' => ['type' => 'string', 'description' => 'nume descriptiv, ex. "coperta-ghid-dimineata.png"; extensia se stabilește din conținut; '
                    . 'la "url" poate lipsi (se ia din adresă)'],
                'url' => ['type' => 'string', 'description' => 'adresa https a imaginii, ex. "https://exemplu.ro/poze/coperta.jpg"'],
                'continut_base64' => ['type' => 'string', 'description' => 'fișierul codat base64 (se acceptă și data:image/...;base64,...)'],
            ]),
            'fn' => function (array $a) {
                $url = arg_text($a, 'url', false);
                $b64 = arg_text($a, 'continut_base64', false);
                if (($url === null) === ($b64 === null)) throw new EroareCms('trimite fie "url", fie "continut_base64" (exact unul)');
                if ($url !== null) return urca_imagine_din_url(trim($url), (string) arg_text($a, 'nume', false));
                return urca_imagine((string) arg_text($a, 'nume'), $b64);
            },
        ],
        'link_urcare' => [
            'scriere' => true, 'titlu' => 'Link de urcare a pozelor, pentru om',
            'adnotari' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
            'descriere' => 'Un link temporar (implicit 30 de minute) la care omul urcă poze de pe telefon sau de pe calculator, fără cheie și fără '
                . 'nimic de completat: deschide linkul, alege poza, gata. Folosește-l ori de câte ori omul vrea să pună o poză pe site și poza e '
                . 'la el (în telefon, sau atașată în chat — o poză din chat NU o poți trimite tu mai departe). După ce spune că a urcat-o, '
                . 'cheamă listeaza_imagini (cele mai noi sunt primele) și folosește adresa /media/... unde trebuie.',
            'schema' => schema_obiect(['minute' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 120, 'default' => 30]]),
            'fn' => fn(array $a) => link_urcare((int) ($a['minute'] ?? 30)),
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
function tinta_apel(array $a, string $unealta = ''): string
{
    if ($unealta === 'seteaza_site') return 'site';
    if ($unealta === 'retrage_conexiune' && isset($a['client_id']) && is_string($a['client_id'])) return 'conexiune ' . substr($a['client_id'], 0, 40);
    if ($unealta === 'redirectioneaza' && isset($a['de']) && is_string($a['de'])) return 'redirectionare ' . substr($a['de'], 0, 90);
    if (isset($a['tip'], $a['slug']) && is_string($a['tip']) && is_string($a['slug'])) return substr($a['tip'] . '/' . $a['slug'], 0, 100);
    if (isset($a['nume']) && is_string($a['nume'])) return 'media/' . substr($a['nume'], 0, 80);
    return '';
}
