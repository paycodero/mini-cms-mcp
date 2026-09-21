<?php
// Blocurile comune și paginile de pornire: piesele cu care un site nou arată terminat după prima conversație.
// Blocurile sunt clase din assets/stil.css, deci merg pe orice site și sub orice temă (tema le poate restiliza).
// Paginile de pornire sunt schelete de CONȚINUT: AI-ul le cere cu pagini_de_pornire, le completează cu ce află de la om
// și le salvează ca ciorne. Nimic nu se creează singur, iar un loc [[COMPLETEAZĂ: …]] rămas oprește publicarea.
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

const LOC_DE_COMPLETAT = '[[COMPLETEAZĂ';

// Blocurile, cu exemplul pe care îl vede AI-ul în despre_site. Testele verifică: fiecare exemplu trece prin filtrul HTML
// neatins, iar fiecare clasă folosită are reguli în stil.css.
function blocuri_comune(): array
{
    return [
        'carduri' => ['cand' => 'trei-șase lucruri de același fel, unul lângă altul: servicii, avantaje, produse (cu sau fără imagine)',
            'html' => '<div class="bloc-carduri"><div class="bloc-card"><h3>Titlu</h3><p>O frază.</p><p><a href="/servicii">Detalii →</a></p></div>'
                . '<div class="bloc-card"><h3>Titlu</h3><p>O frază.</p></div><div class="bloc-card"><h3>Titlu</h3><p>O frază.</p></div></div>'],
        'pasi' => ['cand' => 'un proces în ordine: cum lucrăm, cum comanzi, ce urmează după primul mesaj',
            'html' => '<ol class="bloc-pasi"><li><h3>Discutăm</h3><p>O frază.</p></li><li><h3>Propunem</h3><p>O frază.</p></li>'
                . '<li><h3>Livrăm</h3><p>O frază.</p></li></ol>'],
        'citat' => ['cand' => 'o părere REALĂ a unui client, cu acordul lui (niciodată inventată), sau un citat cu autor',
            'html' => '<blockquote class="bloc-citat"><p>Textul citatului.</p><cite>Nume, firmă</cite></blockquote>'],
        'actiune' => ['cand' => 'chemarea de la finalul paginii: ce face vizitatorul acum (scrie, sună, programează); aside, ca să încheie întrebările frecvente',
            'html' => '<aside class="bloc-actiune"><h2>Hai să vorbim</h2><p>O frază despre ce urmează.</p>'
                . '<p><a class="buton" href="/contact">Scrie-ne</a> <a class="buton buton-gol" href="tel:+40700000000">Sună</a></p></aside>'],
        'coloane' => ['cand' => 'două părți alăturate pe calculator, una sub alta pe telefon: text + listă, date de contact + program',
            'html' => '<div class="bloc-coloane"><div><h2>Stânga</h2><p>Text.</p></div><div><h2>Dreapta</h2><ul><li>Punct</li></ul></div></div>'],
        'galerie' => ['cand' => 'mai multe fotografii (lucrări, spațiu, echipă), fiecare cu o legendă scurtă; imaginile doar din /media/',
            'html' => '<div class="bloc-galerie"><figure><img src="/media/poza-a1b2c3d4.jpg" alt="Ce se vede"><figcaption>Legendă</figcaption></figure>'
                . '<figure><img src="/media/alta-e5f6a7b8.jpg" alt="Ce se vede"><figcaption>Legendă</figcaption></figure></div>'],
        'nota' => ['cand' => 'un lucru de reținut, scos în evidență: nota simplă (culoarea site-ului), nota-verde = bine de știut, nota-galbena = atenție, nota-rosie = interdicție sau risc',
            'html' => '<aside class="bloc-nota nota-galbena"><p><strong>Atenție:</strong> o frază.</p></aside>'],
        'cifre' => ['cand' => 'două-patru cifre REALE, verificabile (ani, proiecte, clienți); dacă omul nu le are, blocul nu se pune',
            'html' => '<div class="bloc-cifre"><div><strong>12</strong><span>ani de experiență</span></div><div><strong>140</strong><span>proiecte</span></div></div>'],
        'intrebari' => ['cand' => 'întrebările frecvente: DIRECT în conținut, fără cutie în jur (altfel Google nu le mai găsește); după ele, un aside',
            'html' => '<h2>Întrebări frecvente</h2><h3>Prima întrebare?</h3><p>Răspunsul.</p><h3>A doua întrebare?</h3><p>Răspunsul.</p>'],
    ];
}

// Locurile de completat rămase într-un element (titlu, descriere, conținut): publicarea se oprește până nu mai e niciunul.
function locuri_de_completat(array $e): array
{
    $text = implode("\n", [(string) ($e['titlu'] ?? ''), (string) ($e['descriere'] ?? ''), (string) ($e['continut_html'] ?? '')]);
    preg_match_all('/\[\[COMPLETEAZ[ĂA][^\]]{0,160}\]\]/u', $text, $m);
    return array_values(array_unique($m[0]));
}

// Ce e pornit acum pe site și contează pentru pagina de confidențialitate. Doar fapte pe care site-ul le poate ști singur.
function fapte_confidentialitate(): array
{
    $video = false;
    foreach (array_keys(TIPURI) as $tip) {
        foreach (listeaza_elemente($tip, 'vizibil') as $e) {
            if (preg_match('#<iframe[^>]+(youtube|vimeo)#i', (string) ($e['continut_html'] ?? ''))) { $video = true; break 2; }
        }
    }
    // Cloudflare: după adresa cererii (din_cloudflare) SAU după antetul CF-Ray, pe care Cloudflare îl trimite mereu serverului.
    // LiteSpeed-ul de pe găzduirea paycode.ro pune singur IP-ul real în REMOTE_ADDR, deci doar adresa nu ajunge (21 sept 2026).
    $cf = din_cloudflare() || (string) ($_SERVER['HTTP_CF_RAY'] ?? '') !== '';
    return ['ga4' => (string) config('site.ga4'), 'cloudflare' => $cf, 'video' => $video];
}

function pagini_de_pornire(): array
{
    $c = LOC_DE_COMPLETAT;
    $f = fapte_confidentialitate();
    $email = "{$c}-email]]";

    $actiune = '<aside class="bloc-actiune"><h2>' . "$c: invitația, ex. „Hai să vorbim”]]" . '</h2><p>' . "$c: ce se întâmplă după primul mesaj, într-o frază]]"
        . '</p><p><a class="buton" href="/contact">Scrie-ne</a></p></aside>';
    $pasi = '<ol class="bloc-pasi">'
        . "<li><h3>$c: primul pas, ex. „Discutăm”]]</h3><p>$c: ce se întâmplă aici]]</p></li>"
        . "<li><h3>$c: al doilea pas]]</h3><p>$c: ce se întâmplă aici]]</p></li>"
        . "<li><h3>$c: al treilea pas]]</h3><p>$c: ce primește clientul la final]]</p></li></ol>";
    $card = fn(int $n) => "<div class=\"bloc-card\"><h3>$c: serviciul $n]]</h3><p>$c: ce primește clientul, într-o frază]]</p>"
        . '<p><a href="/servicii">Detalii →</a></p></div>';

    $pagini = [];
    $pagini[] = ['slug' => 'acasa', 'meniu' => null, 'rost' => 'prima pagină (/): cine ești, ce oferi, cum lucrezi, ce face vizitatorul acum',
        'titlu' => "$c: ce faci și pentru cine, într-un titlu scurt]]",
        'descriere' => "$c: o frază despre ce găsește vizitatorul aici, 120–155 de caractere (apare și în Google)]]",
        'continut_html' => "<p>$c: două-trei fraze: cine ești, pe cine ajuți și ce problemă rezolvi]]</p>\n"
            . '<div class="bloc-carduri">' . $card(1) . $card(2) . $card(3) . "</div>\n"
            . "<h2>Cum lucrăm</h2>\n$pasi\n"
            . "<blockquote class=\"bloc-citat\"><p>$c: o părere REALĂ a unui client, cu acordul lui; dacă nu există, scoate tot blocul]]</p>"
            . "<cite>$c: numele și firma clientului]]</cite></blockquote>\n$actiune"];
    $pagini[] = ['slug' => 'despre', 'meniu' => 1, 'rost' => 'cine e în spatele site-ului și de ce să ai încredere',
        'titlu' => 'Despre', 'descriere' => "$c: cine suntem, într-o frază de 120–155 de caractere]]",
        'continut_html' => '<div class="bloc-coloane">'
            . "<div><h2>Cine suntem</h2><p>$c: povestea pe scurt: de când, de ce, cine lucrează]]</p></div>"
            . "<div><h2>Ce ne deosebește</h2><ul><li>$c: primul lucru]]</li><li>$c: al doilea]]</li><li>$c: al treilea]]</li></ul></div></div>\n"
            . '<div class="bloc-cifre">'
            . "<div><strong>$c: o cifră reală]]</strong><span>$c: ce măsoară, ex. ani de experiență]]</span></div>"
            . "<div><strong>$c: o cifră reală]]</strong><span>$c: ce măsoară]]</span></div>"
            . "<div><strong>$c: o cifră reală]]</strong><span>$c: ce măsoară]]</span></div></div>\n"
            . "<p>$c: dacă omul nu are cifre verificabile, scoate blocul de cifre de deasupra]]</p>\n$actiune"];
    $pagini[] = ['slug' => 'servicii', 'meniu' => 2, 'rost' => 'ce oferi, în detaliu, cu întrebările pe care le pun clienții (Google le citește ca FAQPage)',
        'titlu' => 'Servicii', 'descriere' => "$c: ce oferim, într-o frază de 120–155 de caractere]]",
        'continut_html' => "<p>$c: o frază de deschidere: pentru cine sunt serviciile]]</p>\n"
            . "<h2>$c: serviciul 1]]</h2><p>$c: ce include, pentru cine e, ce rezultat are]]</p>\n"
            . "<h2>$c: serviciul 2]]</h2><p>$c: ce include, pentru cine e, ce rezultat are]]</p>\n"
            . "<h2>$c: serviciul 3]]</h2><p>$c: ce include, pentru cine e, ce rezultat are]]</p>\n"
            . "<h2>Cum lucrăm</h2>\n$pasi\n"
            . "<h2>Întrebări frecvente</h2>\n"
            . "<h3>$c: o întrebare pe care o pun clienții, cu semnul întrebării]]</h3><p>$c: răspunsul, direct]]</p>\n"
            . "<h3>$c: a doua întrebare]]</h3><p>$c: răspunsul]]</p>\n"
            . "<h3>$c: a treia întrebare]]</h3><p>$c: răspunsul]]</p>\n$actiune"];
    $pagini[] = ['slug' => 'contact', 'meniu' => 3, 'rost' => 'cum ajunge vizitatorul la tine; site-ul nu are formular, deci doar legături directe',
        'titlu' => 'Contact', 'descriere' => "$c: cum ne poți scrie sau suna, într-o frază]]",
        'continut_html' => '<div class="bloc-coloane">'
            . "<div><h2>Scrie-ne sau sună</h2><p><strong>E-mail:</strong> <a href=\"mailto:$email\">$email</a></p>"
            . "<p><strong>Telefon:</strong> <a href=\"tel:$c-telefon]]\">$c-telefon]]</a></p><p><strong>Program:</strong> $c: zilele și orele]]</p></div>"
            . "<div><h2>Unde ne găsești</h2><p>$c: adresa, sau „lucrăm online, în toată țara”]]</p><p>$c: firma și CUI-ul, dacă omul vrea să apară]]</p></div></div>\n"
            . "<aside class=\"bloc-nota\"><p>Site-ul nu are formular: ne scrii direct, iar mesajul ajunge la un om. Răspundem $c: în cât timp, ex. „în aceeași zi lucrătoare”]].</p></aside>\n"
            . '<p><a href="/confidentialitate">Cum folosim datele</a></p>'];

    $sectiuni = "<h2>Cine răspunde de date</h2><p>$c: numele firmei sau al persoanei, CUI-ul și adresa]], numit mai jos „noi”. "
        . "Ne scrii la <a href=\"mailto:$email\">$email</a>.</p>\n"
        . "<h2>Ce nu facem</h2><ul><li>Site-ul nu pune cookie-uri proprii și nu are formulare: nu completezi nimic pe el.</li>"
        . '<li>Nu avem conturi de vizitatori și nu îți cerem date ca să citești paginile.</li>'
        . '<li>Ce cauți în caseta de căutare nu se păstrează.</li>'
        . '<li>Fonturile și imaginile vin chiar de pe site, nu de pe alte servere.</li></ul>' . "\n"
        . "<h2>Ce se înregistrează</h2><ul><li>Serverul de găzduire ($c: numele furnizorului de găzduire]]) păstrează, ca orice server web, "
        . "jurnale de acces: adresa IP, ora, pagina cerută și browserul. Le folosește pentru funcționare și securitate, $c: cât timp le păstrează furnizorul]].</li>"
        . '<li>Jurnalul de securitate al site-ului înregistrează doar administrarea site-ului și încercările de a intra în ea (adresa IP, ora, browserul), '
        . 'nu vizitele obișnuite. Îl păstrăm ca să putem lămuri un incident de securitate.</li>';
    if ($f['cloudflare']) {
        $sectiuni .= '<li>Site-ul trece prin Cloudflare, pentru protecție și viteză. Cloudflare vede adresa IP a vizitatorului și o prelucrează după '
            . '<a href="https://www.cloudflare.com/privacypolicy/" target="_blank" rel="noopener">politica proprie</a>.</li>';
    }
    if ($f['ga4'] !== '') {
        $sectiuni .= '<li>Folosim Google Analytics 4, ca să aflăm câte vizite are site-ul și de unde vin. Google Analytics pune cookie-uri și prelucrează '
            . "adresa IP, după <a href=\"https://policies.google.com/privacy\" target=\"_blank\" rel=\"noopener\">politica Google</a>. $c: cum își dă vizitatorul acordul — site-ul nu are încă un banner de consimțământ]]</li>";
    }
    if ($f['video']) {
        $sectiuni .= '<li>Unele pagini au video de pe YouTube sau Vimeo. Când îl pornești, platforma respectivă poate pune cookie-uri și îți vede adresa IP.</li>';
    }
    $sectiuni .= "</ul>\n<h2>Drepturile tale</h2><p>Poți cere acces la datele tale, corectarea sau ștergerea lor și te poți opune prelucrării: "
        . 'ne scrii la adresa de mai sus. Dacă ai o nemulțumire, te poți adresa Autorității Naționale de Supraveghere a Prelucrării Datelor cu Caracter Personal '
        . '(<a href="https://www.dataprotection.ro" target="_blank" rel="noopener">dataprotection.ro</a>).</p>' . "\n"
        . '<p>Actualizat la ' . date('j') . ' ' . ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie',
            'octombrie', 'noiembrie', 'decembrie'][(int) date('n') - 1] . ' ' . date('Y') . '.</p>';   // data_ro() e în site.php, pe care MCP nu-l încarcă
    $pagini[] = ['slug' => 'confidentialitate', 'meniu' => null,
        'rost' => 'ce date ajung la proprietar când cineva vizitează site-ul; legată din pagina de contact',
        'titlu' => 'Confidențialitate', 'descriere' => 'Ce date ajung la noi când vizitezi site-ul și ce facem cu ele.', 'continut_html' => $sectiuni];

    foreach ($pagini as &$p) {
        $p['exista_deja'] = citeste_element('pagina', $p['slug']) !== null || citeste_element('articol', $p['slug']) !== null;
        $p['locuri_de_completat'] = count(locuri_de_completat($p));
    }
    unset($p);

    $atentie = [];
    if ($f['ga4'] !== '') $atentie[] = 'GA4 e pornit, dar site-ul nu are banner de consimțământ: spune-i omului că, în UE, cookie-urile de măsurare cer acordul vizitatorului.';
    return [
        'cum_se_folosesc' => [
            'Întreabă-l întâi pe om ce lipsește (vezi intrebari_pentru_om). Nu inventa nimic: nume, cifre, clienți, citate, adrese, prețuri. Ce nu știi, întrebi sau scoți blocul.',
            'Înlocuiește fiecare ' . LOC_DE_COMPLETAT . ': …]]. Cât timp rămâne unul, "publica" refuză elementul.',
            'Paginile cu exista_deja = true sunt deja pe site: nu le suprascrie fără acordul omului.',
            'Salvează-le cu salveaza (tip "pagina", slugul și meniul din listă), ca ciorne. Trimite-i omului linkurile din previzualizeaza, apoi publică ce aprobă.',
            'Pagina de confidențialitate descrie ce face tehnic acest site (verificat în codul lui) și ce e pornit acum pe el. Nu e consultanță juridică: proprietarul o verifică înainte de publicare.',
            'Blocurile (clasele) sunt descrise în despre_site, la "blocuri". Poți să le muți, să le scoți sau să adaugi altele din listă.',
            'Scheletele sunt în română; pe un site în altă limbă, traduce-le.',
        ],
        'intrebari_pentru_om' => ['Ce faci, pentru cine și ce problemă rezolvi?', 'Care sunt serviciile (două-patru), cu ce primește clientul la fiecare?',
            'Cum decurge o colaborare, pas cu pas?', 'Ce întrebări îți pun clienții cel mai des?', 'Ai o părere reală de la un client, pe care o poți publica?',
            'Ai cifre verificabile (ani, proiecte, clienți)?', 'E-mail, telefon, program, adresă; firma și CUI-ul să apară?',
            'Pentru confidențialitate: cine e furnizorul de găzduire?'],
        'fapte_confidentialitate' => $f,
        'atentie' => $atentie,
        'pagini' => $pagini,
    ];
}
