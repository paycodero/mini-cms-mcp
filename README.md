# mini-cms-mcp

Un CMS mic pentru site-uri de câteva pagini și un blog, administrat de un asistent AI prin MCP (Model Context Protocol).

**Principiul: AI-ul e singurul editor. Poate schimba conținutul, niciodată codul.**

- PHP simplu (8.0+). Fără Composer, fără bază de date, fără fișiere de pe alte servere, fără panou de administrare.
- Merge pe orice găzduire PHP obișnuită (Apache/cPanel). MCP prin Streamable HTTP fără sesiuni: fiecare cerere e un POST cu răspuns JSON.
- Circa 2.480 de rânduri PHP pe server, plus teste automate (164 de verificări, inclusiv instalarea, copia de siguranță și OAuth cap-coadă).
- Se leagă de Claude Code (cheie în antet) și de conectorul din claude.ai, web și telefon (OAuth, aprobat cu cheia site-ului).
- Instalarea: o comandă pe calculator și un zip urcat în cPanel.

## Structura

```
site/                    ← se urcă pe server, ca rădăcină a site-ului
  index.php              site-ul public (pagini, articole, sitemap, feed, robots, llms.txt)
  mcp.php                punctul de intrare pentru AI  →  https://site/mcp
  jurnal.php             jurnalul, pentru om (cheia se trimite prin formular)
  .htaccess              reguli Apache (doar mod_rewrite și mod_headers, în IfModule)
  assets/stil.css        aspectul implicit
  assets/teme/           teme alese cu seteaza_site: <nume>.css + fonturile în <nume>/ (ex. simpluspv)
  app/                   codul (blocat din web)
  sabloane/              șabloanele HTML (blocate din web)
  date/                  creat automat: pagini, articole, versiuni, jurnal (blocat din web)
  media/                 creat automat: imaginile urcate
teste/ruleaza.php        testele: pornesc o copie a site-ului și încearcă funcțiile și atacurile
unelte/instaleaza.php    instalarea: teste, chei, config.php, pachetul .zip, verificarea serverului, legarea Claude Code
unelte/copie.php         copia de siguranță: salvează tot site-ul pe calculator și îl poate pune la loc (sau pe alt site)
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

În claude.ai: **Settings → Connectors → Add custom connector**, cu adresa `https://site/mcp`. Claude se înregistrează singur la
site și deschide pagina de aprobare a site-ului: acolo introduci **cheia de scriere** (sau pe cea de citire, pentru acces doar
de citire) și apeși *Permite*. De acolo, Claude primește token-uri temporare; conectorul apare și în aplicația de telefon.

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
| `seteaza_site` | scriere | numele, descrierea, autorul, limba, culoarea, logo-ul, favicon-ul și tema; păstrează versiunea anterioară |
| `publica` / `retrage` | scriere | pune pe site / scoate de pe site (rămâne ciornă); `publica` cu `la` în viitor programează, în trecut păstrează data |
| `retrage_conexiune` | scriere | anulează accesul unei aplicații legate prin OAuth |
| `redirectioneaza` | scriere | adresă veche → adresă nouă de pe site, 301 (doar când la adresa veche nu mai e nimic); `la` gol o scoate |
| `sterge` | scriere | mută elementul între versiuni (reversibil) |
| `restaureaza` | scriere | aduce înapoi o versiune, ca ciornă |
| `urca_imagine` | scriere | JPEG/PNG/GIF/WebP, max 5 MB; extensia se stabilește din conținut |
| `sterge_imagine` | scriere | mută imaginea între versiuni; refuză dacă e folosită |

Paginile și articolele au adrese comune: `/despre`, `/primul-articol`. Pagina `acasa` e prima pagină.

Vizitatorii au căutare (`/cauta?q=…`, în antet): doar în ce e pe site, cu sau fără diacritice ("sedinta" găsește "ședința").
Articolele programate apar singure la ora lor: nu e nevoie de sarcini programate pe server.

## Securitate

- Nicio comandă nu scrie fișiere `.php`, șabloane, configurare sau jurnal. Conținutul stă în JSON, în `date/`.
- HTML-ul trece printr-o listă de etichete și atribute permise, la scriere și la afișare. Se scot `script`, `style`, formulare, SVG, evenimentele `on*`, `javascript:`, iframe-urile care nu sunt YouTube/Vimeo. Răspunsul spune AI-ului ce s-a scos.
- Două chei (citire / scriere). Pe server stau doar amprentele SHA-256, comparate cu `hash_equals`.
- 8 încercări eșuate în 5 minute = IP blocat 15 minute, pe toate punctele de intrare.
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
