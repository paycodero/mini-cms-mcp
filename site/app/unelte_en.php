<?php
// Titlurile și descrierile comenzilor, în engleză. Le vede AI-ul unui site a cărui limbă de bază nu e româna (de exemplu
// site-ul demo pentru verificatorii directorului de conectori Claude). Sursa rămâne textul românesc din unelte.php; aici e
// doar traducerea lui. Cheile parametrilor sunt căile din schemă ("eveniment.inceput", "legaturi[].url").
// O comandă sau un parametru care lipsește de aici rămâne în română. Răspunsurile comenzilor rămân în română.
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

const UNELTE_EN_TIP = '"pagina" = a fixed page (About, Contact; "acasa" is the home page) · "articol" = a blog article, with a date and tags';
const UNELTE_EN_SLUG = 'The address: lowercase letters without diacritics, digits, hyphens. E.g. "about-us" → /about-us. The page "acasa" is /.';

const UNELTE_EN = [
    'despre_site' => ['t' => 'About the site and its rules',
        'd' => 'The site\'s name, description and address, how many pages and articles it has, which HTML is allowed, how addresses are formed and what rights the key in use has. Call it first.'],
    'pagini_de_pornire' => ['t' => 'Starter pages for a new site',
        'd' => 'Skeletons of the home, about, services, contact and privacy pages, built from the shared blocks, with [[COMPLETEAZĂ: …]] placeholders. Creates nothing: you fill them in with what you learn from the person, save them as drafts with salveaza, then the person approves them. The privacy page describes what this site does technically and what is turned on right now (GA4, Cloudflare, video).'],
    'listeaza' => ['t' => 'List pages and articles',
        'd' => 'The list of pages and/or articles, without content: slug, title, status (ciorna = draft / publicat = published), address, dates.'],
    'citeste' => ['t' => 'Read a page or an article',
        'd' => 'The whole item, with its HTML content, exactly as saved.'],
    'cauta' => ['t' => 'Search the content',
        'd' => 'Searches for a text in the title, description and content of pages and articles (drafts included). Also returns the matching fragment.'],
    'listeaza_versiuni' => ['t' => 'Versions of an item',
        'd' => 'The versions saved automatically before every change, publication, unpublication or deletion. Newest first.'],
    'listeaza_imagini' => ['t' => 'List images',
        'd' => 'The images uploaded to /media/, newest first.'],
    'citeste_jurnal' => ['t' => 'Read the log',
        'd' => 'The latest log entries (who, when, from where, which command, which result) and the chain check: "intact" = no line was changed or removed.',
        'p' => ['doar_probleme' => 'only failed attempts, refusals, errors and blocks']],
    'vizite_ai' => ['t' => 'AI reads of the site',
        'd' => 'How many pages ChatGPT, Claude, Perplexity, Copilot and the other assistants opened, and how many people came from them, counted on the server (GA4 cannot see them: bots do not run JavaScript). By kind ("om" = the assistant read the page to answer someone; "cautare" = the crawler they pick sources from; "antrenare" = training; "vizitator" = a click from an assistant), by assistant, by page and by day, plus the errors bots ran into (e.g. 404 on old addresses). No IP addresses.',
        'p' => ['zile' => 'the last N days, today included', 'pagini' => 'how many pages in the most-read list']],
    'verifica_boti' => ['t' => 'Check whether AI crawlers can read the site',
        'd' => 'The site requests its own home page and newest article over the public route (through Cloudflare, if it is on), as a browser and then as OAI-SearchBot, ChatGPT-User, GPTBot, Claude, Perplexity, Bing and Google, and compares the answers. It says which crawler is blocked and by whom (Cloudflare, the hosting firewall, a robots.txt changed on the way). Takes up to a minute; at most once a minute. The point: robots.txt can say "Allow" while the crawlers are still blocked, with no visible sign.'],
    'previzualizeaza' => ['t' => 'Preview link',
        'd' => 'A temporary link where the person sees a draft (or a scheduled item) exactly as it will look on the site, before "publica". The link expires after "minute" (60 by default) and is not indexed.'],
    'listeaza_redirectionari' => ['t' => 'List redirects',
        'd' => 'The old addresses that redirect (301) to new addresses on the site.'],
    'exporta' => ['t' => 'Export all content',
        'd' => 'All the content, for a backup: the site identity, the full pages and articles (drafts included), the redirects and the list of images, with their fingerprints. Images are downloaded separately, from their addresses.'],
    'listeaza_conexiuni' => ['t' => 'List approved connections',
        'd' => 'The applications connected to the site through OAuth (e.g. the claude.ai connector): name, where they return, whether they have access now and with which rights. A connection with a key in the header (Claude Code) does not appear here.'],
    'salveaza' => ['t' => 'Create or edit a page or an article',
        'd' => 'Creates the item (as a draft) or edits it. When editing, send only the fields that change; the rest stay. If the item is published, the change appears on the site immediately. The previous version is saved automatically. The HTML is filtered; "curatari" in the reply says what was removed.',
        'p' => [
            'titlu' => 'required on creation; becomes the <h1>',
            'continut_html' => 'required on creation; HTML from <h2> down (see despre_site)',
            'descriere' => 'one sentence: shown in Google, in lists and in llms.txt',
            'etichete' => 'articles only',
            'imagine' => 'articles only: the cover, the /media/... address returned by urca_imagine',
            'imagine_alt' => 'articles only: a description of the cover',
            'limba' => 'multilingual sites only: the item\'s language (one of "limbi"); default = the base language. The second language gets an address prefix (/en/...)',
            'grup' => 'multilingual sites only: links this item to its translations (the same group in every language), for the language switcher and hreflang. E.g. both "despre" ↔ "about" have group "despre"',
            'meniu' => 'pages only: the position in the menu (for a subpage: in its parent\'s submenu); null = not in the menu',
            'parinte' => 'pages only: the slug of the menu page this one sits under, in its submenu (one level only); "" = no section',
            'autor' => 'articles only: the author, if not the site\'s default',
            'eveniment' => 'only for articles announcing an event; null = no event. The site builds the Event structured data (Google) from it, puts the event day on the card and lists upcoming events first on the home page.',
            'eveniment.inceput' => 'required: the day and time, Romanian time, e.g. "2026-10-27 19:00"',
            'eveniment.sfarsit' => 'optional, same format',
            'eveniment.tip' => 'default "Event"; a concert = "MusicEvent"',
            'eveniment.stare' => 'default "programat" (scheduled)',
            'eveniment.loc' => 'required: the venue name, e.g. "Romanian Athenaeum"',
            'eveniment.adresa' => 'street and number',
            'eveniment.tara' => 'default "RO"',
            'eveniment.artisti[].grup' => 'true = orchestra, choir, band',
            'eveniment.organizator' => 'default: the site name',
            'eveniment.bilete' => 'the https address of the ticket page',
        ]],
    'seteaza_site' => ['t' => 'Set the site name and description',
        'd' => 'Changes the site identity: the name (header, titles, feed), the description (Google, feed, llms.txt), the default author of articles, the language, the accent colour, the theme (look), the footer links and text, the credit for who built the site, what articles are called on the site and whether their date is shown. Send only the fields that change. The change appears on the whole site immediately; the previous values are kept as a version and returned in the reply.',
        'p' => [
            'descriere' => 'one sentence about the site',
            'autor' => 'the default author of new articles',
            'limba' => 'e.g. "en"',
            'limbi' => 'the site languages; the first is the default (served at /), the others get an address prefix (/en/...). E.g. ["ro","en"]. One language or [] = a single-language site',
            'traduceri' => 'the identity in the other languages: the key is the language code, the value holds nume/descriere/subsol/nume_articole. Whatever is missing in a language falls back to the base value. E.g. {"en":{"nume":"The New Journal…","descriere":"…"}}',
            'culoare' => 'the accent colour, e.g. "#6d2be8"',
            'logo' => 'the header logo: the /media/... address returned by urca_imagine; "" = no logo',
            'favicon' => 'the tab icon: the /media/... address of a square image (PNG); "" = none',
            'tema' => 'the site look: one of the themes listed in despre_site; "" = the default look',
            'ga4' => 'the Google Analytics 4 ID, e.g. "G-798XLP278H"; the site builds the tag itself, with a nonce and the sources added to the CSP. Send ONLY the ID, never code. "" = no measurement',
            'subsol' => 'a short note in the footer of every page (e.g. what the site does not offer, or the company and its tax ID); "" = none',
            'realizare' => 'who built the site, in the footer on the © line, e.g. "Website built with AI and miniCMS"; "" = none',
            'realizare_url' => 'the address the "realizare" credit links to (https://...); "" = the credit without a link. Not added to "sameAs": the builder is not a profile of the author',
            'nume_articole' => 'what articles are called on the site, one lowercase plural word, e.g. "guides": shown in the menu, on the home page and in lists ("Latest guides", "All guides"). The address stays /articole. "" = "articles"',
            'ga4_fara_acord' => '"" = GA4 loads only after "Accept" in the consent banner (default, since 0.23); "da" = GA4 loads directly, with no banner, as before 0.23. Only at the person\'s explicit request: in the EU, measurement cookies require the visitor\'s consent, and the owner is responsible for it.',
            'arata_data' => '"da" = the publication date appears on the article and on cards (suits a blog); "" = it does not (default). Dates always remain in the sitemap, feed and structured data.',
            'legaturi' => 'the same author\'s other sites and profiles: shown in the footer of every page and in the structured data as "sameAs" (this is how Google and Bing learn they belong to the same entity). An empty list removes them.',
            'legaturi[].titlu' => 'how it appears in the footer; empty = the host name',
            'legaturi[].url' => 'the address, with https://',
        ]],
    'publica' => ['t' => 'Publish',
        'd' => 'Makes the item visible on the site. Publish only after the person has approved the content (see previzualizeaza). With "la" in the future, the item is scheduled: it appears on its own at that time.',
        'p' => ['la' => 'optional: the publication date and time, e.g. "2026-10-01 09:00" (Romanian time). In the future = scheduled; in the past = keeps that date (e.g. when moving an old article).']],
    'retrage' => ['t' => 'Unpublish',
        'd' => 'Turns the item back into a draft: it disappears from the site and stays saved.'],
    'sterge' => ['t' => 'Delete (reversible)',
        'd' => 'Removes the item from the site and from the list, moving it among the versions. It can be brought back with "restaureaza".'],
    'restaureaza' => ['t' => 'Restore a version',
        'd' => 'Brings back a version (or a deleted item). It returns as a draft, so it can be checked before publishing; the current version is kept.',
        'p' => ['versiune' => 'the ID from listeaza_versiuni, e.g. "20260918-153000"']],
    'redirectioneaza' => ['t' => 'Redirect an old address',
        'd' => 'Sends visitors from an old address (e.g. after a slug change, or from an old site moved here) to a new one on this site, with a 301. It applies only when nothing is left at the old address. With an empty "la", it removes the redirect.',
        'p' => ['de' => 'the old address, e.g. "/about-us.html" or "/page.php?id=5"',
                'la' => 'the new address on the site, e.g. "/about"; "" or null = remove the redirect']],
    'retrage_conexiune' => ['t' => 'Revoke a connection',
        'd' => 'Revokes the access of an application connected through OAuth (client_id from listeaza_conexiuni): its tokens stop working, and to connect again it must be approved again, with the key. Only at the person\'s request.'],
    'urca_imagine' => ['t' => 'Upload an image',
        'd' => 'Uploads a JPEG/PNG/GIF/WebP image (5 MB at most) and returns the /media/... address to use in content or as a cover. Send EITHER "url" (the https address of a public image: the server downloads it itself, internal addresses are refused) OR "continut_base64" (small images only). When the picture is with the person (phone, computer, attached in the chat), do not encode it: call link_urcare and give the person the link.',
        'p' => ['nume' => 'a descriptive name, e.g. "morning-guide-cover.png"; the extension is taken from the content; with "url" it can be omitted (taken from the address)',
                'url' => 'the https address of the image, e.g. "https://example.com/pictures/cover.jpg"',
                'continut_base64' => 'the file encoded in base64 (data:image/...;base64,... is accepted too)']],
    'link_urcare' => ['t' => 'Photo upload link, for the person',
        'd' => 'A temporary link (30 minutes by default) where the person uploads photos from a phone or a computer, with no key and nothing to fill in: open the link, pick the photo, done. Use it whenever the person wants to put a photo on the site and the photo is with them (on the phone, or attached in the chat — you cannot forward a photo from the chat yourself). Once they say it is uploaded, call listeaza_imagini (newest first) and use the /media/... address where needed.'],
    'sterge_imagine' => ['t' => 'Delete an image (reversible)',
        'd' => 'Moves the image among the versions. Refuses if it is used anywhere, unless forteaza=true.',
        'p' => ['nume' => 'the name from listeaza_imagini']],
    'urca_fisier' => ['t' => 'Upload a PDF document',
        'd' => 'Uploads a PDF document (25 MB at most) and returns the address /fisiere/<name>.pdf, to put in a link in the content (e.g. <a href="/fisiere/report.pdf">Download the report (PDF)</a>). You choose the name and it stays the document\'s address. Send EITHER "url" (the https address of a public PDF: the server downloads it itself, internal addresses are refused; large documents work this way) OR "continut_base64" (about 6 MB at most). PDF only: the content is checked, not the name. If the name already exists with other content, replacing it requires inlocuieste=true: the address stays, the old version is kept.',
        'p' => ['nume' => 'the document name, which becomes its address: e.g. "annual-report-2025.pdf"; with "url" it can be omitted (taken from the address)',
                'url' => 'the https address of the PDF, e.g. "https://example.com/documents/report.pdf"',
                'continut_base64' => 'the file encoded in base64 (data:application/pdf;base64,... is accepted too)',
                'inlocuieste' => 'true = put the new content at the same address, over an existing document (the old one stays among the versions)']],
    'listeaza_fisiere' => ['t' => 'List documents',
        'd' => 'The uploaded PDF documents, with their /fisiere/... addresses, newest first.'],
    'sterge_fisier' => ['t' => 'Delete a document (reversible)',
        'd' => 'Moves the document among the versions: its address stops answering. Refuses if a page or an article links to it, unless forteaza=true.',
        'p' => ['nume' => 'the name from listeaza_fisiere, e.g. "annual-report-2025.pdf"']],
];

// Pe un site a cărui limbă de bază nu e româna, AI-ul primește titlurile și descrierile comenzilor în engleză.
function unelte_in_engleza(): bool
{
    return limba_implicita() !== 'ro';
}

// Comanda, cu titlul, descrierea și descrierile parametrilor traduse (unde există traducere).
function unealta_tradusa(string $nume, array $u): array
{
    $en = UNELTE_EN[$nume] ?? null;
    if ($en === null) return $u;
    $u['titlu'] = $en['t'];
    $u['descriere'] = $en['d'];
    $u['schema'] = schema_tradusa($u['schema'], $en['p'] ?? [], '');
    return $u;
}

function schema_tradusa(array $s, array $p, string $cale): array
{
    if ($cale !== '' && isset($s['description'])) {
        $ultim = preg_replace('/^.*[.\]]/', '', $cale);
        if (isset($p[$cale])) $s['description'] = $p[$cale];
        elseif ($ultim === 'tip' && $cale === 'tip') $s['description'] = UNELTE_EN_TIP;
        elseif ($ultim === 'slug' && $cale === 'slug') $s['description'] = UNELTE_EN_SLUG;
    }
    if (isset($s['properties']) && is_array($s['properties'])) {
        foreach ($s['properties'] as $k => $v) {
            if (is_array($v)) $s['properties'][$k] = schema_tradusa($v, $p, ($cale === '' ? '' : "$cale.") . $k);
        }
    }
    if (isset($s['items']) && is_array($s['items'])) $s['items'] = schema_tradusa($s['items'], $p, $cale . '[]');
    return $s;
}
