# mini-cms-mcp

Un CMS mic pentru site-uri de câteva pagini și un blog, administrat de un asistent AI prin MCP (Model Context Protocol).

**Principiul: AI-ul e singurul editor. Poate schimba conținutul, niciodată codul.**

- PHP simplu (8.0+). Fără Composer, fără bază de date, fără fișiere de pe alte servere, fără panou de administrare.
- Merge pe orice găzduire PHP obișnuită (Apache/cPanel). MCP prin Streamable HTTP fără sesiuni: fiecare cerere e un POST cu răspuns JSON.
- Circa 1.650 de rânduri PHP pe server, plus teste automate (81 de verificări).

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
unelte/genereaza-cheie.php, unelte/router-local.php
```

## Instalare

1. Urci conținutul lui `site/` pe server.
2. Generezi cheile local: `php unelte/genereaza-cheie.php chei-site.json`. Cheile rămân în acel fișier (și în managerul de parole), nu pe server.
3. Copiezi `site/app/config.exemplu.php` ca `site/app/config.php`, completezi datele site-ului și pui **doar amprentele** celor două chei. `config.php` se pune pe server o singură dată, de om; nu intră în git și nu poate fi modificat prin MCP.
4. Verifici: `https://site/` răspunde; `https://site/app/config.php`, `https://site/date/` → 403; `GET https://site/mcp` → 405.

## Legarea la Claude Code

```
claude mcp add --transport http site https://site/mcp --header "Authorization: Bearer <cheia de scriere>"
```

Cheia de citire se folosește pentru un asistent care doar verifică sau raportează.
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
location ~* ^/media/.+\.(php[0-9]?|phtml|phar|pht|shtml|cgi|pl|py|sh)$ { deny all; }
location = /mcp { rewrite ^ /mcp.php last; }
location / { try_files $uri /index.php$is_args$args; }
```

## Limitări cunoscute

- OAuth pentru conectorul claude.ai: în lucru. Până atunci, clienții care pot trimite un antet (Claude Code).
- `post_max_size` al găzduirii limitează mărimea imaginilor urcate (base64 adaugă ~33%).
- Căutarea și listele citesc toate fișierele JSON la fiecare cerere: potrivit pentru zeci sau sute de elemente, nu pentru zeci de mii.
