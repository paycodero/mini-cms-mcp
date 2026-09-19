# mini-cms-mcp

Un CMS mic pentru site-uri de câteva pagini și un blog, administrat de un asistent AI prin MCP (Model Context Protocol).

**Principiul: AI-ul e singurul editor. Poate schimba conținutul, niciodată codul.**

- PHP simplu (8.0+). Fără Composer, fără bază de date, fără fișiere de pe alte servere, fără panou de administrare.
- Merge pe orice găzduire PHP obișnuită (Apache/cPanel). MCP prin Streamable HTTP fără sesiuni: fiecare cerere e un POST cu răspuns JSON.
- Circa 1.750 de rânduri PHP pe server, plus teste automate (104 verificări, inclusiv instalarea cap-coadă).
- Instalarea: o comandă pe calculator și un zip urcat în cPanel.

## Structura

```
site/                    ← se urcă pe server, ca rădăcină a site-ului
  index.php              site-ul public (pagini, articole, sitemap, feed, robots, llms.txt)
  mcp.php                punctul de intrare pentru AI  →  https://site/mcp
  jurnal.php             jurnalul, pentru om (cheia se trimite prin formular)
  .htaccess              reguli Apache (doar mod_rewrite și mod_headers, în IfModule)
  assets/stil.css
  app/                   codul (blocat din web)
  sabloane/              șabloanele HTML (blocate din web)
  date/                  creat automat: pagini, articole, versiuni, jurnal (blocat din web)
  media/                 creat automat: imaginile urcate
teste/ruleaza.php        testele: pornesc o copie a site-ului și încearcă funcțiile și atacurile
unelte/instaleaza.php    instalarea: teste, chei, config.php, pachetul .zip, verificarea serverului, legarea Claude Code
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

## Schimbarea cheilor

Dacă o cheie a scăpat: `php unelte/instaleaza.php https://site --chei-noi`. Cheile vechi se păstrează cu data în nume,
config-ul și pachetul se refac. Urci pe server doar `app/config.php` din `_livrare/<nume>/` (sau tot pachetul): din acel
moment cheile vechi nu mai merg. Apoi `--verifica`, care înlocuiește și cheia din conexiunea Claude Code.

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
| `salveaza` | scriere | creează (ca ciornă) sau modifică o pagină ori un articol |
| `seteaza_site` | scriere | numele, descrierea, autorul, limba și culoarea site-ului; păstrează versiunea anterioară |
| `publica` / `retrage` | scriere | pune pe site / scoate de pe site (rămâne ciornă) |
| `sterge` | scriere | mută elementul între versiuni (reversibil) |
| `restaureaza` | scriere | aduce înapoi o versiune, ca ciornă |
| `urca_imagine` | scriere | JPEG/PNG/GIF/WebP, max 5 MB; extensia se stabilește din conținut |
| `sterge_imagine` | scriere | mută imaginea între versiuni; refuză dacă e folosită |

Paginile și articolele au adrese comune: `/despre`, `/primul-articol`. Pagina `acasa` e prima pagină.

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

- OAuth pentru conectorul claude.ai: în lucru. Până atunci, clienții care pot trimite un antet (Claude Code).
- `post_max_size` al găzduirii limitează mărimea imaginilor urcate (base64 adaugă ~33%).
- Căutarea și listele citesc toate fișierele JSON la fiecare cerere: potrivit pentru zeci sau sute de elemente, nu pentru zeci de mii.
