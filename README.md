# mini-cms-mcp

Un CMS mic pentru site-uri de câteva pagini și un blog, administrat de un asistent AI prin MCP (Model Context Protocol).

**Principiul: AI-ul e singurul editor. Poate schimba conținutul, niciodată codul.**

- PHP simplu (8.0+). Fără Composer, fără bază de date, fără fișiere de pe alte servere, fără panou de administrare.
- Merge pe orice găzduire PHP obișnuită (Apache/cPanel). MCP prin Streamable HTTP fără sesiuni: fiecare cerere e un POST cu răspuns JSON.
- Circa 3.000 de rânduri PHP pe server, plus teste automate (250 de verificări, inclusiv instalarea, actualizarea, copia de siguranță, OAuth, SEO și măsurarea).
- Se leagă de Claude Code (cheie în antet) și de conectorul din claude.ai, web și telefon (OAuth, aprobat cu cheia site-ului).
- Instalarea: o comandă pe calculator și un zip urcat în cPanel.

## Structura

```
site/                    ← se urcă pe server, ca rădăcină a site-ului
  index.php              site-ul public (pagini, articole, sitemap, feed, robots, llms.txt)
  mcp.php                punctul de intrare pentru AI  →  https://site/mcp
  jurnal.php             jurnalul, pentru om (cheia se trimite prin formular)
  imagini.php            urcarea imaginilor din browser, pentru om (cheia de scriere, prin formular)
  .htaccess              reguli Apache (doar mod_rewrite și mod_headers, în IfModule)
  assets/stil.css        aspectul implicit
  assets/teme/           teme alese cu seteaza_site: <nume>.css + fonturile în <nume>/ (simpluspv, cinesunt)
  app/                   codul (blocat din web)
  sabloane/              șabloanele HTML (blocate din web)
  date/                  creat automat: pagini, articole, versiuni, jurnal (blocat din web)
  media/                 creat automat: imaginile urcate
teste/ruleaza.php        testele: pornesc o copie a site-ului și încearcă funcțiile și atacurile
unelte/instaleaza.php    instalarea: teste, chei, config.php, pachetul .zip, verificarea serverului, legarea Claude Code
unelte/actualizeaza.php  actualizarea codului de pe depozit, cerută de om (cheia de cod), cu copie și punere înapoi
unelte/copie.php         copia de siguranță: salvează tot site-ul pe calculator și îl poate pune la loc (sau pe alt site)
unelte/urca-imagine.php  urcă poze de pe calculator: le întoarce după telefon, le micșorează, scoate locația GPS
unelte/comun.php         funcțiile comune ale celor două
unelte/genereaza-cheie.php, unelte/router-local.php
```

## Instalare, în doi pași

Condiția: un domeniu sau subdomeniu doar pentru site (site-ul stă la rădăcină), cu PHP 8.0+ și HTTPS.

**1. Pe calculator**, din folderul repo-ului:

```
php unelte/instaleaza.php https://test.exemplu.ro
```

Comanda rulează testele, generează cele două chei, scrie `config.php` (adresa și **doar amprentele** cheilor) și face
pachetul `minicms-<versiune>-<nume>.zip`. Cheile și pachetul stau în folderul de deasupra repo-ului, în afara lui git:
`chei-<nume>.json` și `_livrare/<nume>/`. Cheile nu apar niciodată pe ecran; copiază-le în managerul de parole.
La o nouă rulare, cheile existente se refolosesc, iar pachetul și config-ul vechi se păstrează cu data în nume.

**2. În cPanel**: File Manager → folderul domeniului → Upload zip-ul → Extract → șterge zip-ul. Apoi Enter în terminal.
Comanda verifică serverul (`/mcp` 405; dosarele interne, jurnalul și zip-ul blocate — 403 sau 404, după găzduire, fără nimic
din fișier în răspuns; ambele chei), leagă Claude Code
de site cu cheia de scriere (pentru folderul proiectului) și arată adresa jurnalului.
Dacă ceva nu e în regulă, spune cauza și așteaptă să repari pe server. Verificarea se poate relua oricând:

```
php unelte/instaleaza.php https://test.exemplu.ro --verifica
```

După instalare, în Claude: *„Cheamă despre_site, apoi setează numele site-ului, descrierea și autorul.”*
Numele, descrierea, autorul, limba și culoarea sunt conținut: le schimbă AI-ul cu `seteaza_site`, rămân în jurnal și
au versiuni. `config.php` ține doar ce nu trebuie să schimbe AI-ul: adresa și amprentele. Nu intră în git și nu poate
fi modificat prin MCP; ajunge pe server o singură dată, în pachetul urcat de om.

Dacă folderul avea deja un `.htaccess` pus de cPanel (MultiPHP), după Extract alegi din nou versiunea de PHP în
MultiPHP Manager, ca cPanel să-și rescrie blocul.

## Teme

O temă e o foaie de stil pusă de om în `site/assets/teme/<nume>.css`, cu fonturile ei în `site/assets/teme/<nume>/`
(găzduite pe site: CSP-ul permite fonturi doar de pe același domeniu). Se încarcă după `assets/stil.css` și schimbă doar
aspectul, pe același HTML. AI-ul vede temele în `despre_site` și alege una cu `seteaza_site` (`tema`, `""` = aspectul
implicit); nu poate scrie CSS. O temă aleasă dar scoasă de pe server e ignorată, fără eroare.

Tema `simpluspv` copiază blogul de pe simpluspv.eu (Bricolage Grotesque, Hanken Grotesk, Newsreader, toate sub SIL OFL 1.1).
Culoarea principală rămâne cea din identitate. Știe și două clase din articolele de acolo: `p.aerisit` (spațiu mai mare
după paragraf) și `img.ingust` (captură de telefon, 340 px, centrată), plus containerul video `div.cai-video`.

Tema `cinesunt` e aspectul site-ului cinesunt.info, mutat aici din CMS-ul lui vechi (Space Grotesk, Inter, JetBrains Mono,
tot SIL OFL 1.1): erou pe două coloane cu o cartelă de chat, pașii metodei, pastile colorate pe domenii, carduri cu
eticheta peste imagine. Prima pagină se așază în aceeași grilă cu titlul din șablon, doar din CSS.

**Ce știe tema despre ea o spune AI-ului:** primul comentariu din foaia de stil apare în `despre_site` (`teme.despre`).
Acolo își descrie autorul temei blocurile (clasele) pe care le știe, ca AI-ul să le folosească în conținut fără să
ghicească. Pagina poartă și clasa tipului ei pe `<body>` (`pagina-acasa`, `pagina-articol`, `pagina-lista`…), iar
fiecare etichetă clasa `eticheta-<slug>`, deci o temă poate așeza diferit prima pagină și poate colora etichetele.

## Actualizarea codului, fără zip și fără cPanel

Din 0.9, site-ul își ia singur versiunea nouă din depozit — dar **numai când i-o ceri tu**, niciodată de la sine și
niciodată la cererea AI-ului. Prima instalare rămâne cu pachetul urcat de mână; de la ea încolo:

```
php unelte/actualizeaza.php https://site.ro
```

Comanda întreabă serverul ce versiune are și ce e în depozit, îți arată fișierele care s-ar schimba, apoi (după ce
confirmi, sau direct cu `--acum`) îi cere să se sincronizeze. Același lucru se poate face și din browser, pe
`https://site.ro/actualizare.php`, lipind cheia de cod în formular.

**Cheia de cod e a treia cheie**, alături de cea de citire și cea de scriere. Stă doar pe calculatorul tău, în
`chei-<nume>.json`, iar pe server e tot o amprentă. **Nu e cheie de MCP:** AI-ul nu o primește niciodată, iar un token
OAuth al conectorului din claude.ai nu deschide nimic din actualizare. Principiul rămâne cel de la început — asistentul
schimbă conținutul, nu codul.

Ce face serverul, în ordine, într-o singură cerere:

1. descarcă pachetul depozitului (`depozit` din `config.php`, implicit `paycodero/mini-cms-mcp`, ramura `main`);
2. verifică fiecare cale din el: doar din dosarul `site/`, fără `..`, doar extensii din lista permisă;
   `app/config.php`, `date/` și `media/` nu se ating **niciodată**;
3. refuză o versiune mai veche decât cea instalată (`forta` o acceptă, dacă chiar vrei să cobori);
4. salvează fișierele pe care urmează să le înlocuiască, în `date/versiuni/cod/<data>/`;
5. scrie doar ce diferă;
6. **își cere singur prima pagină**: dacă nu mai răspunde 200, pune la loc versiunea veche în aceeași cerere și îți
   spune ce a pățit. Dacă nu poate ajunge la el însuși (unele găzduiri nu permit), spune „necunoscut" și nu presupune
   nimic — verificarea din afară o face comanda de pe calculatorul tău, care pune și ea înapoi copia dacă site-ul
   nu răspunde cum trebuie.

Două lecții de pe o găzduire reală (cinesunt.info, 21 septembrie 2026), reparate în 0.12:

- **Memoria PHP a găzduirii (OPcache).** Unele găzduiri țin codul compilat și nu se uită după fișierul schimbat: pe disc
  e versiunea nouă, dar site-ul rulează tot codul vechi și raportează versiunea veche. Acum serverul își golește memoria
  pentru fiecare fișier PHP pe care îl scrie. Iar comanda de pe calculator pune copia înapoi **numai dacă site-ul nu mai
  răspunde**; dacă merge dar raportează încă versiunea veche, așteaptă și reverifică, apoi spune ce vede, fără să atingă ceva.
- **Punerea înapoi a unei copii nu mai poate închide site-ul.** Copiile făcute înainte de 0.12 conțineau paza dosarului de
  date (un `.htaccess` cu „Require all denied”), iar punerea înapoi o scria peste `.htaccess`-ul site-ului: 403 pe orice
  adresă, fără cale de reparat din afară. Acum copiile nu mai au paza lor, iar punerea înapoi sare peste ea și la copiile vechi.
  Dacă ți s-a întâmplat pe o versiune mai veche: în cPanel urci din nou `site/.htaccess` peste cel din rădăcină, apoi
  alegi din nou versiunea PHP în MultiPHP Manager.

Copiile rămân pe server: `php unelte/actualizeaza.php https://site.ro --copii` le listează, iar `--pune=<copie>` pune
una înapoi. `'actualizare' => false` în `config.php` scoate cu totul pagina și comanda.

⚠️ **Prețul acestei comodități, spus pe față:** serverul are încredere în depozit. Cine ajunge la contul de GitHub poate
pune cod pe site. Ce se poate fără GitHub — cale în afara dosarului `site/`, extensie nepermisă, atingerea configurării,
coborârea versiunii — e refuzat de server. Dacă vrei și apărare împotriva unui depozit compromis, pasul următor e
semnarea pachetului cu o cheie privată de pe calculatorul tău; nu e construită.

## Imaginile, fără base64 prin conversație

`urca_imagine` primea imaginea codată base64: bun pentru o siglă mică, prea scump și prea lent pentru o poză. O poză atașată
în chat nu ajută nici ea: ajunge la AI ca imagine de privit, nu ca fișier pe care să-l poată trimite mai departe. Drumurile:

- **Poza e la om (telefon, calculator, chat) — drumul obișnuit, din 0.13:** AI-ul cheamă `link_urcare` și îi dă omului un link
  semnat, valabil 30 de minute (5–120). Omul îl deschide, alege poza, apasă „Urcă”; nicio cheie, nimic de completat. Apoi AI-ul
  o găsește cu `listeaza_imagini` și o pune unde trebuie. Semnătura folosește cheia previzualizărilor într-un domeniu separat,
  deci un link de previzualizare nu merge ca link de urcare; un link falsificat se numără ca încercare eșuată.

- **E deja pe internet:** `urca_imagine` cu `url` (doar https, portul 443). Serverul o descarcă singur. Apărarea contra
  SSRF: numele se rezolvă o dată și **toate** adresele găsite trebuie să fie publice (nu 127.x, 10.x, 192.168.x, 169.254.x,
  rețelele IPv6 locale, IPv4 ascuns în IPv6 și celelalte rezervate); conexiunea se face exact la adresa verificată, deci
  DNS-ul nu poate fi schimbat între verificare și descărcare; redirecționările, cel mult 3, trec fiecare prin aceleași
  verificări. Fără curl și fără `allow_url_fopen`. `'imagini_url' => false` în `config.php` oprește drumul.
- **E pe calculator (Claude Code):**

  ```
  php unelte/urca-imagine.php https://site.ro poza.jpg [alta.png …] [--latime=1600] [--original]
  ```

  Întoarce poza după orientarea din telefon, o micșorează la 1600 px pe latura mare și o rescrie, deci JPEG-ul pierde
  datele EXIF, inclusiv locația GPS. La final scrie adresele `/media/...`. Are nevoie de GD; pe Windows, dacă `php_gd.dll`
  stă lângă PHP dar e oprit în `php.ini`, comanda îl pornește doar pentru ea. Fără GD, pozele pleacă neatinse.
- **Fără AI:** `https://site.ro/imagini.php`, cu cheia de scriere în formular (niciodată în adresă, ca la jurnal).
  Pagina micșorează pozele în browser înainte de trimitere, deci pleacă repede și fără locația GPS; cheia de citire e
  refuzată, fiecare fișier e verificat și scris în jurnal, pagina nu se indexează. Apoi îi dai AI-ului adresa. Se scoate
  cu `'pagina_imagini' => false`.

Toate trei trec prin aceleași verificări ca base64: tipul aflat din conținut, cel mult 5 MB, fără cod ascuns, fără SVG.

## Copia de siguranță

```
php unelte/copie.php https://site.ro
```

Salvează tot site-ul în `_copii/<nume>/<data>/` (în folderul de deasupra repo-ului): `export.json` cu paginile și articolele
(inclusiv ciornele și cele programate), identitatea și redirecționările, plus imaginile, verificate după amprentă. Folosește
cheia de citire. Nicio copie nu se scrie peste alta.

```
php unelte/copie.php https://site-nou.ro --pune=_copii/<nume>/<data>
```

Pune copia pe un site (de obicei unul nou, gol), cu cheia de scriere: imaginile cu aceleași adrese, apoi identitatea,
elementele cu starea, data publicării și autorul lor, și redirecționările. Pe un site care are deja conținut cere și
`--peste`; elementele cu același slug se modifică, cu versiunea anterioară păstrată.

## Schimbarea cheilor

Dacă o cheie a scăpat: `php unelte/instaleaza.php https://site --chei-noi`. Cheile vechi se păstrează cu data în nume,
config-ul și pachetul se refac. Urci pe server doar `app/config.php` din `_livrare/<nume>/` (sau tot pachetul): din acel
moment cheile vechi nu mai merg. Apoi `--verifica`, care înlocuiește și cheia din conexiunea Claude Code.

## Conectorul din claude.ai (web și telefon)

Legarea se face într-o **fereastră deschisă de tine**, de pe calculator (din 0.6 — înainte, oricine putea porni o aprobare pe
site-ul tău, cu numele „Claude", și dacă o aprobai primea el token-urile):

```
php unelte/instaleaza.php https://site.ro --oauth
```

Comanda cere serverului, cu cheia de scriere, să deschidă înregistrarea 15 minute și îți arată în terminal un **cod de conectare**
de 6 cifre. Apoi, în claude.ai: **Settings → Connectors → Add custom connector**, cu adresa `https://site/mcp`. Claude se
înregistrează singur și deschide pagina de aprobare a site-ului: acolo introduci **codul din terminal** și **cheia de scriere**
(sau pe cea de citire, pentru acces doar de citire) și apeși *Permite*. După aprobare fereastra se închide singură; o închizi mai
devreme cu `--oauth --inchide`. De acolo, Claude primește token-uri temporare; conectorul apare și în aplicația de telefon.

Reînnoirea token-urilor merge oricând, și cu fereastra închisă: o conexiune aprobată nu se rupe.

- În afara ferestrei, `/oauth/inregistrare` și pagina de aprobare răspund „închis": un link de aprobare trimis de un străin nu
  deschide nimic. Codul de 6 cifre e singurul lucru pe care nu-l poate avea cineva care îți citește codul sursă.
- `'oauth' => 'deschis'` în `config.php` readuce purtarea din 0.5 (fără fereastră, fără cod), iar `false` scoate OAuth cu totul.
- OAuth 2.1 cu PKCE (S256) obligatoriu, înregistrare automată a clientului (RFC 7591), descoperire prin
  `/.well-known/oauth-protected-resource` și `/.well-known/oauth-authorization-server`.
- Codul de aprobare poate fi trimis doar spre `claude.ai`, `claude.com` sau calculatorul omului (`localhost`, pentru Claude Code);
  alte gazde se adaugă în `config.php`, la `'oauth_gazde'`.
- Token de acces 1 oră, de reînnoire 60 de zile, rotit la fiecare folosire. Pe server stau doar amprentele lor.
- Un token are drepturile cheii cu care a fost aprobat și nu mai merge după `--chei-noi`. Accesul se vede cu
  `listeaza_conexiuni` și se retrage cu `retrage_conexiune`. Fiecare pas e în jurnal, fără token-uri sau coduri.

## Legarea de mână la Claude Code

`instaleaza.php` o face singur. De mână, cu cheia citită din fișier (PowerShell), din folderul proiectului:

```
$k = (Get-Content chei-<nume>.json -Raw | ConvertFrom-Json).scriere.cheie
claude mcp add --transport http <nume> https://site/mcp --header "Authorization: Bearer $k"
```

Dacă găzduirea nu transmite antetul `Authorization` către PHP, se folosește `--header "X-API-Key: $k"`
(`instaleaza.php` detectează singur cazul). Cheia de citire se folosește pentru un asistent care doar verifică.
Conectorul din claude.ai (web, telefon) cere OAuth, care e în lucru (vezi mai jos).

## Comenzile

| Comanda | Cheie | Ce face |
|---|---|---|
| `despre_site` | citire | regulile site-ului, HTML-ul permis, rolul cheii |
| `listeaza` | citire | paginile și articolele, fără conținut |
| `citeste` | citire | un element întreg |
| `cauta` | citire | caută în titluri, descrieri, conținut |
| `listeaza_versiuni` | citire | versiunile salvate automat ale unui element |
| `listeaza_imagini` | citire | imaginile din `/media/` |
| `citeste_jurnal` | citire | ultimele intrări și verificarea lanțului |
| `previzualizeaza` | citire | link temporar (implicit 60 de minute) la care omul vede o ciornă exact ca pe site, înainte de publicare |
| `listeaza_redirectionari` | citire | adresele vechi care trimit spre adrese noi |
| `exporta` | citire | tot conținutul, pentru copia de siguranță (vezi `unelte/copie.php`) |
| `listeaza_conexiuni` | citire | aplicațiile legate prin OAuth (conectorul claude.ai): cine, cu ce drepturi, dacă au acces acum |
| `salveaza` | scriere | creează (ca ciornă) sau modifică o pagină ori un articol |
| `seteaza_site` | scriere | numele, descrierea, autorul, limba, culoarea, logo-ul, favicon-ul, tema, legăturile și textul din subsol, identificatorul GA4, numele articolelor și data vizibilă; păstrează versiunea anterioară |
| `publica` / `retrage` | scriere | pune pe site / scoate de pe site (rămâne ciornă); `publica` cu `la` în viitor programează, în trecut păstrează data |
| `retrage_conexiune` | scriere | anulează accesul unei aplicații legate prin OAuth |
| `redirectioneaza` | scriere | adresă veche → adresă nouă de pe site, 301 (doar când la adresa veche nu mai e nimic); `la` gol o scoate |
| `sterge` | scriere | mută elementul între versiuni (reversibil) |
| `restaureaza` | scriere | aduce înapoi o versiune, ca ciornă |
| `link_urcare` | scriere | un link temporar la care omul urcă poze fără cheie; AI-ul le găsește apoi cu `listeaza_imagini` |
| `urca_imagine` | scriere | JPEG/PNG/GIF/WebP, max 5 MB, din `url` (https, cu apărare SSRF) sau `continut_base64`; extensia se stabilește din conținut |
| `sterge_imagine` | scriere | mută imaginea între versiuni; refuză dacă e folosită |

Paginile și articolele au adrese comune: `/despre`, `/primul-articol`. Pagina `acasa` e prima pagină.

Vizitatorii au căutare (`/cauta?q=…`, în antet): doar în ce e pe site, cu sau fără diacritice ("sedinta" găsește "ședința").
Articolele programate apar singure la ora lor: nu e nevoie de sarcini programate pe server.

## SEO și citit de agenți

Site-ul e făcut ca să fie găsit de oameni prin Google și Bing, dar și citit de asistenți AI, fără nicio unealtă în plus.

- **Adrese generate singure:** `/sitemap.xml` (cu `lastmod`, coperțile ca imagini și paginile de etichetă), `/feed.xml`
  (RSS cu legătură spre el însuși, autor și etichete), `/robots.txt` (îi numește pe rând pe Googlebot, Bingbot, GPTBot,
  ClaudeBot, PerplexityBot și restul, și arată unde e `llms.txt`), `/llms.txt` (rezumatul site-ului pentru modele:
  adresă, limbă, autor, lista paginilor și a articolelor cu descriere, dată și etichete).
- **Date structurate (JSON-LD):** `Article` cu editor, limbă, etichete și pagina-părinte · `WebSite` cu `SearchAction`
  (căutarea site-ului, pentru Google) · `BreadcrumbList` pe fiecare pagină · **`FAQPage` construit singur** dintr-o
  secțiune `<h2>Întrebări frecvente</h2>` cu `<h3>` întrebare + răspuns, dacă articolul are una.
- **Cardurile sociale:** `og:` complet, cu `og:locale`, măsurile copertei, textul ei alternativ, data publicării și a
  modificării, etichetele. Titlul din bara browserului nu repetă numele site-ului când e deja în el.
- **Verificarea în Search Console și Bing Webmaster Tools:** `'verificari' => ['google-site-verification' => '…',
  'msvalidate.01' => '…']` în `config.php` pune etichetele meta cerute.
- **IndexNow:** la publicare, modificare sau retragere, adresa pleacă singură spre Bing (și Yandex, Seznam, Naver).
  Cheia stă în `date/securitate/` și se servește la `https://site/<cheie>.txt`, fără niciun fișier pus în rădăcină.
  Se oprește cu `'indexnow' => false`. Google nu are un punct echivalent: acolo rămâne sitemap-ul.
- **Măsurarea (GA4):** `seteaza_site` (`ga4`) primește DOAR identificatorul, ex. `G-798XLP278H` — eticheta o compune
  site-ul, cu nonce-ul paginii, iar sursele de care are nevoie intră singure în CSP. AI-ul poate porni măsurarea, dar
  nu poate pune JavaScript în pagină: identificatorul e validat cu un tipar, iar orice altceva e refuzat. Se încarcă
  numai pe paginile publice — jurnalul, actualizarea, căutarea, previzualizările și aprobările nu se măsoară.
- **Rețeaua autorului:** `seteaza_site` (`legaturi`) pune celelalte site-uri și conturi în subsol, pe fiecare
  pagină, și aceleași adrese în `sameAs` din datele structurate — de acolo află Google și Bing că profilurile sunt
  ale aceleiași entități. Sunt conținut, nu configurare: rămân la locul lor când urci un pachet nou.
- **Textul din subsol:** `seteaza_site` (`subsol`) pune o mențiune scurtă pe fiecare pagină și în llms.txt — ce nu oferă
  site-ul, sau firma și CUI-ul. Text simplu, fără HTML.
- **Cum se numesc articolele:** `seteaza_site` (`nume_articole`, ex. `ghiduri`) schimbă cuvântul din meniu, de pe prima
  pagină și din liste („Ultimele ghiduri", „Toate ghidurile"). Adresa rămâne `/articole`.
- **Data pe pagini:** implicit nu apare (pe un site de documentație o dată lângă titlu face conținutul să pară vechi);
  `arata_data` = `da` o pune pe articol și pe carduri, pentru un blog. În sitemap, feed și datele structurate e oricum.
- **Sub articol:** „Citește mai departe" cu două articole, întâi cele cu aceeași primă etichetă. Coperta nu se repetă sus
  când aceeași imagine e deja în text. Blocurile `<pre>` primesc un buton „Copiază", pus de șablon (cu nonce).
- **Întrebările frecvente** (`FAQPage`) se opresc la primul `<aside>` sau `<section>`: un bloc de final pus după ele nu
  se mai lipește de ultimul răspuns.
- **Iconița site-ului:** o pui cu `seteaza_site` (`favicon`), iar `/apple-touch-icon.png` trimite spre ea, pentru telefon.
  ⚠️ Adresa `/favicon.ico` e singura care nu se poate rezolva din cod pe un domeniu prin **Cloudflare**: e prinsă la
  margine și nu ajunge niciodată la PHP (verificat: `/altceva.ico` și `/favicon.ICO` ajung, `/favicon.ico` nu).
  Dacă o vrei și pe aceea, omul pune un `favicon.ico` adevărat în rădăcina site-ului, o singură dată.
- **Fără sărituri la încărcare:** coperțile și miniaturile primesc `width`/`height` din fișier, iar coperta articolului
  are `fetchpriority="high"` (e candidatul LCP).

## Securitate

- Nicio comandă nu scrie fișiere `.php`, șabloane, configurare sau jurnal. Conținutul stă în JSON, în `date/`.
- HTML-ul trece printr-o listă de etichete și atribute permise, la scriere și la afișare. Se scot `script`, `style`, formulare, SVG, evenimentele `on*`, `javascript:`, iframe-urile care nu sunt YouTube/Vimeo. Răspunsul spune AI-ului ce s-a scos.
- Două chei (citire / scriere). Pe server stau doar amprentele SHA-256, comparate cu `hash_equals`.
- 8 încercări eșuate în 5 minute = adresă blocată 15 minute, pe toate punctele de intrare. La IPv6 se blochează prefixul /64,
  nu adresa exactă: cine are un bloc întreg nu trece prin plafon schimbând adresa la fiecare cerere.
- Adresele care răspund fără cheie (fluxul OAuth) au și un plafon pe numărul de cereri, nu doar pe eșecuri.
- Cererile din browser de pe alt site (antet `Origin` străin) sunt refuzate. Cererile peste 8 MB sunt refuzate înainte de a fi citite.
- Înainte de orice modificare se salvează o versiune. Ștergerea mută fișierul între versiuni.
- Paginile publice au Content-Security-Policy cu nonce (fără `unsafe-inline`), `nosniff`, `X-Frame-Options: DENY`.
- Imaginile: tipul se află din conținut; fișierele cu cod PHP ascuns și SVG-urile sunt refuzate.
- Arhivele și copiile de siguranță (`.zip`, `.tar`, `.gz`, `.sql`, `.bak` etc.) nu se servesc: pachetul de instalare uitat pe server nu se poate descărca.
- IP-ul real din `CF-Connecting-IP` e crezut doar când cererea vine chiar din rețeaua Cloudflare; altfel antetul e ignorat. Setarea se potrivește singură, cu sau fără Cloudflare (`'cloudflare' => false` o oprește).
- HSTS pe orice răspuns servit prin https (`'hsts' => false` îl oprește).
- Linkurile de previzualizare sunt semnate (HMAC, cheie în `date/securitate/`), expiră în cel mult 24 de ore, nu se indexează și sunt scrise în jurnal, inclusiv încercările cu semnătură greșită.
- Redirecționările duc doar spre adrese de pe același site; adresele site-ului (`/mcp`, `/app`, `/date`, …) nu se pot redirecționa, iar buclele sunt refuzate.

## Jurnalul

Fiecare apel (citire, scriere, încercare eșuată, blocare) e un rând JSON în `date/jurnal/AAAA-LL.ndjson`: când, IP, cheie, comandă, țintă, rezultat, amprenta conținutului scris, durata. Fără rotație care să șteargă istoric.

Fiecare rând poartă amprenta rândului anterior (lanț SHA-256): un rând modificat, scos sau adăugat pe dinafară rupe lanțul, iar verificarea arată unde. AI-ul poate citi jurnalul, nu îl poate modifica.

Lanțul e **tamper-evident, nu tamper-proof**: prinde editarea sau ștergerea unui rând, dar cine are drept de scriere pe `date/`
poate recalcula tot lanțul și rescrie `.lant`. Pentru dovadă în fața cuiva din afară, copiază periodic amprenta de final în altă
parte. Adresele vizitatorilor care deschid linkuri de previzualizare se scriu trunchiate (ultimul octet la IPv4, prefixul /64 la
IPv6). La 16 MB, fișierul lunii se arhivează singur sub un nume care îi păstrează locul în lanț.

## Teste

```
php teste/ruleaza.php
```

Pornesc o copie a site-ului într-un dosar temporar, cu chei de unică folosință, pe serverul PHP încorporat, și verifică protocolul, cheile, conținutul, versiunile, imaginile, accesul la fișierele interne, antetele și jurnalul, inclusiv atacurile: cod PHP trimis ca conținut, imagini cu cod ascuns, `../` în adrese, lanț de jurnal modificat de mână.

## nginx

`.htaccess` nu se aplică pe nginx. Echivalentul minim:

```
location ~ ^/(app|sabloane|date)(/|$) { deny all; }
location ~ /\.(?!well-known/) { deny all; }
location ~* \.(zip|tar|gz|tgz|7z|rar|sql|bak|old|orig|swp)$ { deny all; }
location ~* ^/media/.+\.(php[0-9]?|phtml|phar|pht|shtml|cgi|pl|py|sh)$ { deny all; }
location = /mcp { rewrite ^ /mcp.php last; }
location / { try_files $uri /index.php$is_args$args; }
```

## Limitări cunoscute

- `post_max_size` al găzduirii limitează mărimea imaginilor urcate (base64 adaugă ~33%).
- Căutarea și listele citesc toate fișierele JSON la fiecare cerere: potrivit pentru zeci sau sute de elemente, nu pentru zeci de mii.
