<?php
// OAuth 2.1 pentru conectorul din claude.ai (web, telefon): Claude se înregistrează singur, omul aprobă legătura
// pe o pagină a site-ului introducând cheia site-ului, iar Claude primește token-uri temporare.
//
// - Descoperirea: /.well-known/oauth-protected-resource (RFC 9728) și /.well-known/oauth-authorization-server (RFC 8414).
// - Înregistrarea clientului (RFC 7591), doar cu adrese de întoarcere permise: claude.ai, claude.com și calculatorul
//   omului (localhost), plus ce adaugă config.php în 'oauth_gazde'.
// - PKCE obligatoriu (S256). Codurile sunt de unică folosință și expiră în 10 minute.
// - Token-urile: acces 1 oră, reînnoire 60 de zile, rotită la fiecare folosire. Pe server stau doar amprentele lor.
// - Un token moștenește drepturile cheii cu care a fost aprobat și moare când cheia se schimbă (--chei-noi).
// - Totul e scris în jurnal; niciun cod sau token nu ajunge în jurnal.
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

const OAUTH_ACCES_SECUNDE = 3600;
const OAUTH_REINNOIRE_SECUNDE = 60 * 86400;
const OAUTH_COD_SECUNDE = 600;
const OAUTH_MAX_CLIENTI = 50;
const OAUTH_CLIENT_NEAPROBAT = 600;        // un client înregistrat și neaprobat dispare după 10 minute
const OAUTH_FEREASTRA_MAX = 3600;          // cât poate ține cel mult o fereastră de conectare
const OAUTH_FEREASTRA_INREGISTRARI = 5;    // câte aplicații se pot înregistra într-o fereastră

// --- fereastra de conectare (0.6) ---------------------------------------------------------------
// Înregistrarea unei aplicații și pagina de aprobare NU sunt deschise permanent: se deschid de om, de pe
// calculatorul lui, cu cheia de scriere (php unelte/instaleaza.php <site> --oauth). Atunci serverul dă un
// cod de 6 cifre, afișat o singură dată în terminal, care se cere pe pagina de aprobare, lângă cheie.
// Motivul: altfel oricine îți citește codul poate porni o aprobare de pe site-ul TĂU, cu numele „Claude",
// și, dacă o aprobi, primește el token-urile. Codul din terminal e lucrul pe care un străin nu-l poate avea.
function oauth_mod(): string
{
    $m = config('oauth');
    if ($m === false || $m === 'inchis') return 'inchis';
    return $m === 'deschis' ? 'deschis' : 'fereastra';
}

function fereastra_stare(): ?array
{
    $f = oauth_citeste('fereastra');
    return ($f && ($f['expira'] ?? 0) > time()) ? $f : null;
}

function fereastra_deschisa(): bool
{
    return oauth_mod() === 'deschis' || fereastra_stare() !== null;
}

// Codul cerut pe pagina de aprobare. Cu 'deschis' nu se cere niciun cod (comportamentul vechi).
function fereastra_cod_valid(string $cod): bool
{
    if (oauth_mod() === 'deschis') return true;
    $f = fereastra_stare();
    return $f !== null && hash_equals((string) ($f['cod'] ?? ''), hash('sha256', trim($cod)));
}

function fereastra_deschide(int $minute, string $cine = 'admin'): array
{
    $minute = max(1, min((int) (OAUTH_FEREASTRA_MAX / 60), $minute));
    $cod = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expira = time() + $minute * 60;
    cu_blocare(function () use ($cod, $expira) {
        oauth_scrie('fereastra', ['cod' => hash('sha256', $cod), 'expira' => $expira, 'creat' => time(), 'inregistrari' => 0]);
    });
    jurnal_scrie(['punct' => 'oauth', 'cerere' => 'fereastra', 'rezultat' => 'ok', 'cheie' => 'scriere', 'cine' => $cine,
                  'detalii' => ['minute' => $minute]]);
    return ['cod' => $cod, 'expira' => date('c', $expira), 'minute' => $minute];
}

function fereastra_inchide(): void
{
    cu_blocare(function () { oauth_scrie('fereastra', []); });
}

function oauth_emitent(): string { return url_site(); }
function oauth_resursa(): string { return url_site() . '/mcp'; }
function aleator(string $prefix): string { return $prefix . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }

function oauth_citeste(string $nume): array
{
    return json_citeste(config('date') . "/oauth/$nume.json") ?? [];
}

function oauth_scrie(string $nume, array $date): void
{
    if (!scrie_atomic(dir_date('oauth') . "/$nume.json", json_text($date ?: new stdClass(), true))) throw new RuntimeException("nu pot scrie oauth/$nume");
}

function oauth_json(int $cod, array $date): void
{
    http_response_code($cod);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow');
    antete_securitate("default-src 'none'; frame-ancestors 'none'");
    echo json_text($date);
}

function oauth_eroare(int $cod, string $eroare, string $descriere): void
{
    oauth_json($cod, ['error' => $eroare, 'error_description' => $descriere]);
}

// Adresele la care Claude primește codul. Orice altceva e refuzat încă de la înregistrare.
function redirect_permis(string $uri): bool
{
    $p = parse_url($uri);
    if (!$p || empty($p['scheme']) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || strlen($uri) > 500) return false;
    $gazda = strtolower((string) $p['host']);
    $scheme = strtolower((string) $p['scheme']);
    if ($scheme === 'https' && in_array($gazda, array_merge(['claude.ai', 'claude.com'], array_map('strtolower', (array) config('oauth_gazde'))), true)) return true;
    return $scheme === 'http' && in_array($gazda, ['localhost', '127.0.0.1', '[::1]'], true);   // Claude Code, pe calculatorul omului
}

// --- rutele ------------------------------------------------------------------------------------

function ruleaza_oauth(string $cale): void
{
    $metoda = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

    // Site-ul care nu folosește conectorul din claude.ai poate închide tot fluxul din config: nu există adresă.
    if (oauth_mod() === 'inchis') { oauth_eroare(404, 'invalid_request', 'adresă necunoscută'); return; }

    // Toate adresele de aici răspund FĂRĂ cheie, deci au plafon pe numărul de cereri, nu pe eșecuri.
    if (!limita_cereri('oauth')) { oauth_eroare(429, 'temporarily_unavailable', 'prea multe cereri de la această adresă'); return; }

    // Deschiderea ferestrei de conectare: singura adresă OAuth care cere cheia de scriere.
    if ($cale === '/oauth/deschide') {
        if ($metoda !== 'POST') { header('Allow: POST'); oauth_eroare(405, 'invalid_request', 'metodă nepermisă'); return; }
        $acces = verifica_acces('oauth', cheie_din_cerere());
        if ($acces['cod'] !== 200 || $acces['rol'] !== 'scriere') {
            if ($acces['cod'] === 200) inregistreaza_esec();
            oauth_eroare($acces['cod'] === 200 ? 403 : $acces['cod'], 'invalid_client', $acces['cod'] === 200 ? 'cere cheia de scriere' : $acces['mesaj']);
            return;
        }
        $j = json_decode((string) file_get_contents('php://input', false, null, 0, 2000), true);
        if (($j['inchide'] ?? false) === true) {
            fereastra_inchide();
            oauth_json(200, ['fereastra' => 'închisă']);
            return;
        }
        oauth_json(200, fereastra_deschide((int) ($j['minute'] ?? 15), $acces['cine']) + ['mod' => oauth_mod()]);
        return;
    }

    if (preg_match('#^/\.well-known/oauth-protected-resource(/mcp)?$#', $cale)) {
        oauth_json(200, ['resource' => oauth_resursa(), 'authorization_servers' => [oauth_emitent()], 'scopes_supported' => ['citire', 'scriere'],
                         'bearer_methods_supported' => ['header'], 'resource_name' => (string) config('site.nume')]);
        return;
    }
    if (preg_match('#^/\.well-known/(oauth-authorization-server|openid-configuration)$#', $cale)) {
        oauth_json(200, ['issuer' => oauth_emitent(), 'authorization_endpoint' => url_absolut('/oauth/autorizare'),
                         'token_endpoint' => url_absolut('/oauth/token'), 'registration_endpoint' => url_absolut('/oauth/inregistrare'),
                         'response_types_supported' => ['code'], 'grant_types_supported' => ['authorization_code', 'refresh_token'],
                         'code_challenge_methods_supported' => ['S256'], 'scopes_supported' => ['citire', 'scriere'],
                         'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
                         'authorization_response_iss_parameter_supported' => true]);
        return;
    }
    if ($cale === '/oauth/inregistrare' && $metoda === 'POST') { oauth_inregistrare(); return; }
    if ($cale === '/oauth/token' && $metoda === 'POST') { oauth_token(); return; }
    if ($cale === '/oauth/autorizare' && ($metoda === 'GET' || $metoda === 'POST')) { oauth_autorizare($metoda); return; }
    if (in_array($cale, ['/oauth/inregistrare', '/oauth/token', '/oauth/autorizare'], true)) {
        header('Allow: ' . ($cale === '/oauth/autorizare' ? 'GET, POST' : 'POST'));
        oauth_eroare(405, 'invalid_request', 'metodă nepermisă');
        return;
    }
    oauth_eroare(404, 'invalid_request', 'adresă necunoscută');
}

// --- înregistrarea clientului (RFC 7591) -----------------------------------------------------------

function oauth_inregistrare(): void
{
    if (!fereastra_deschisa()) {   // fără fereastră deschisă de om, nimeni nu se poate înregistra
        jurnal_scrie(['punct' => 'oauth', 'cerere' => 'inregistrare', 'rezultat' => 'respins', 'detalii' => ['motiv' => 'fereastră închisă']]);
        oauth_eroare(403, 'access_denied', 'Înregistrarea e închisă pe acest site. Omul o deschide pentru câteva minute, de pe calculatorul lui, '
            . 'cu "php unelte/instaleaza.php <site> --oauth". Reîncearcă după aceea.');
        return;
    }
    $j = json_decode((string) file_get_contents('php://input', false, null, 0, 20000), true);
    if (!is_array($j)) { oauth_eroare(400, 'invalid_client_metadata', 'corpul trebuie să fie JSON'); return; }
    $uris = array_values(array_filter((array) ($j['redirect_uris'] ?? []), 'is_string'));
    if (!$uris || count($uris) > 5) { oauth_eroare(400, 'invalid_redirect_uri', 'între 1 și 5 redirect_uris'); return; }
    foreach ($uris as $u) {
        if (!redirect_permis($u)) {
            jurnal_scrie(['punct' => 'oauth', 'cerere' => 'inregistrare', 'rezultat' => 'respins', 'detalii' => ['redirect' => substr($u, 0, 120)]]);
            oauth_eroare(400, 'invalid_redirect_uri', 'adresa de întoarcere nu e permisă de acest site: ' . substr($u, 0, 120));
            return;
        }
    }
    $metoda = (string) ($j['token_endpoint_auth_method'] ?? 'none');
    if (!in_array($metoda, ['none', 'client_secret_post', 'client_secret_basic'], true)) { oauth_eroare(400, 'invalid_client_metadata', 'token_endpoint_auth_method necunoscut'); return; }
    $nume = text_simplu($j['client_name'] ?? 'Client fără nume', 100) ?: 'Client fără nume';
    $client_id = 'mcms_k_' . bin2hex(random_bytes(12));
    $secret = $metoda === 'none' ? null : aleator('mcms_x_');
    try {
        cu_blocare(function () use ($client_id, $nume, $uris, $metoda, $secret) {
        $clienti = oauth_citeste('clienti');
        $tokenuri = oauth_citeste('tokenuri');
        $folositi = array_flip(array_column($tokenuri, 'client'));
        foreach ($clienti as $id => $c) {   // clienții înregistrați și niciodată aprobați dispar în 10 minute
            if (!isset($folositi[$id]) && ($c['creat'] ?? 0) < time() - OAUTH_CLIENT_NEAPROBAT) unset($clienti[$id]);
        }
        if (count($clienti) >= OAUTH_MAX_CLIENTI) throw new EroareCms('prea mulți clienți înregistrați');
        // Într-o fereastră deschisă de om încap câteva înregistrări, nu oricâte: un client real cere una.
        $f = oauth_citeste('fereastra');
        if ($f && ($f['expira'] ?? 0) > time()) {
            if ((int) ($f['inregistrari'] ?? 0) >= OAUTH_FEREASTRA_INREGISTRARI) {
                throw new EroareCms('s-au înregistrat deja ' . OAUTH_FEREASTRA_INREGISTRARI . ' aplicații în această fereastră');
            }
            $f['inregistrari'] = (int) ($f['inregistrari'] ?? 0) + 1;
            oauth_scrie('fereastra', $f);
        }
        $clienti[$client_id] = ['nume' => $nume, 'redirect_uris' => $uris, 'metoda' => $metoda,
                                'secret' => $secret ? hash('sha256', $secret) : null, 'creat' => time()];
        oauth_scrie('clienti', $clienti);
        });
    } catch (EroareCms $e) {
        jurnal_scrie(['punct' => 'oauth', 'cerere' => 'inregistrare', 'rezultat' => 'respins', 'detalii' => ['motiv' => $e->getMessage()]]);
        oauth_eroare(400, 'invalid_client_metadata', $e->getMessage());
        return;
    }
    jurnal_scrie(['punct' => 'oauth', 'cerere' => 'inregistrare', 'rezultat' => 'ok', 'tinta' => $nume,
                  'detalii' => ['redirect' => parse_url($uris[0], PHP_URL_HOST)]]);
    $rasp = ['client_id' => $client_id, 'client_id_issued_at' => time(), 'client_name' => $nume, 'redirect_uris' => $uris,
             'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'], 'token_endpoint_auth_method' => $metoda];
    if ($secret) $rasp += ['client_secret' => $secret, 'client_secret_expires_at' => 0];
    oauth_json(201, $rasp);
}

// --- autorizarea: pagina pe care omul aprobă legătura ----------------------------------------------

function oauth_autorizare(string $metoda): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    $p = $metoda === 'POST' ? $_POST : $_GET;
    $cerere = [];
    foreach (['response_type', 'client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'scope', 'resource'] as $k) {
        $cerere[$k] = is_string($p[$k] ?? null) ? substr($p[$k], 0, 1000) : '';
    }
    if (!fereastra_deschisa()) {   // pagina care cere cheia nu se poate deschide oricând, de către oricine
        pagina_autorizare_eroare('Conectarea nu e deschisă acum. Fereastra se deschide de pe calculatorul omului, '
            . 'cu „php unelte/instaleaza.php <site> --oauth”, și ține câteva minute.');
        return;
    }
    $client = oauth_citeste('clienti')[$cerere['client_id']] ?? null;
    // Fără client cunoscut și adresă de întoarcere înregistrată nu se trimite nimic nicăieri: doar o pagină de eroare.
    if (!$client || !in_array($cerere['redirect_uri'], (array) ($client['redirect_uris'] ?? []), true)) {
        pagina_autorizare_eroare('Cererea de conectare nu e valabilă: aplicația nu e înregistrată la acest site sau adresa ei de întoarcere nu se potrivește.');
        return;
    }
    $problema = null;
    if ($cerere['response_type'] !== 'code') $problema = 'response_type trebuie să fie "code"';
    elseif (!preg_match('/^[A-Za-z0-9_-]{43}$/', $cerere['code_challenge']) || $cerere['code_challenge_method'] !== 'S256') $problema = 'PKCE (S256) e obligatoriu';
    elseif ($cerere['resource'] !== '' && rtrim($cerere['resource'], '/') !== oauth_resursa()) $problema = 'resursa cerută nu e acest site';
    if ($problema) {
        pagina_autorizare_eroare('Cererea de conectare nu e completă: ' . $problema . '.');
        return;
    }

    $v = ['cerere' => $cerere, 'client' => $client, 'gazda_intoarcere' => (string) parse_url($cerere['redirect_uri'], PHP_URL_HOST), 'mesaj' => '',
          'cere_cod' => oauth_mod() !== 'deschis', 'varsta' => max(0, time() - (int) ($client['creat'] ?? time()))];
    if ($metoda === 'POST') {
        if (($_POST['decizie'] ?? '') !== 'permite') {
            jurnal_scrie(['punct' => 'oauth', 'cerere' => 'autorizare', 'rezultat' => 'refuzat', 'tinta' => $client['nume']]);
            redirectioneaza_client($cerere, ['error' => 'access_denied', 'error_description' => 'omul a refuzat conectarea']);
            return;
        }
        $acces = verifica_acces('oauth', (string) ($_POST['cheie'] ?? ''));
        // Codul de conectare, afișat o singură dată în terminalul omului: un străin nu-l are nici dacă are linkul.
        if ($acces['cod'] === 200 && !fereastra_cod_valid((string) ($_POST['cod_conectare'] ?? ''))) {
            inregistreaza_esec();
            jurnal_scrie(['punct' => 'oauth', 'cerere' => 'autorizare', 'rezultat' => 'respins', 'tinta' => $client['nume'],
                          'detalii' => ['motiv' => 'cod de conectare greșit']]);
            $acces = ['cod' => 401, 'mesaj' => 'Codul de conectare e greșit sau fereastra s-a închis. Codul apare în terminal, '
                . 'când deschizi conectarea de pe calculatorul tău. Dacă n-ai pornit tu conectarea, închide pagina.'];
        }
        if ($acces['cod'] === 200) {
            $cod = aleator('mcms_a_');
            $rol = $acces['rol'];
            $amprenta = $acces['amprenta'];
            cu_blocare(function () use ($cod, $cerere, $rol, $amprenta) {
                $coduri = array_filter(oauth_citeste('coduri'), fn($c) => ($c['expira'] ?? 0) > time());
                $coduri[hash('sha256', $cod)] = ['client' => $cerere['client_id'], 'redirect_uri' => $cerere['redirect_uri'],
                    'provocare' => $cerere['code_challenge'], 'rol' => $rol, 'amprenta_cheie' => $amprenta,
                    'expira' => time() + OAUTH_COD_SECUNDE];
                oauth_scrie('coduri', $coduri);
            });
            fereastra_inchide();   // o fereastră = o conectare; ce urmează (schimbul de token) nu mai are nevoie de ea
            jurnal_scrie(['punct' => 'oauth', 'cerere' => 'autorizare', 'rezultat' => 'ok', 'cheie' => $rol, 'cine' => $acces['cine'], 'tinta' => $client['nume'],
                          'detalii' => ['intoarcere' => $v['gazda_intoarcere']]]);
            redirectioneaza_client($cerere, ['code' => $cod]);
            return;
        }
        http_response_code($acces['cod']);
        $v['mesaj'] = $acces['mesaj'];
    }
    // Formularul trimite spre site, iar răspunsul redirecționează spre aplicație: CSP trebuie să permită ambele.
    $origine = parse_url($cerere['redirect_uri'], PHP_URL_SCHEME) . '://' . parse_url($cerere['redirect_uri'], PHP_URL_HOST)
        . (parse_url($cerere['redirect_uri'], PHP_URL_PORT) ? ':' . parse_url($cerere['redirect_uri'], PHP_URL_PORT) : '');
    randeaza('autorizare', $v + ['noindex' => true, 'titlu_pagina' => 'Conectare — ' . config('site.nume')], http_response_code() ?: 200,
             ['form-action' => [$origine]]);
}

function redirectioneaza_client(array $cerere, array $parametri): void
{
    if ($cerere['state'] !== '') $parametri['state'] = $cerere['state'];
    $parametri['iss'] = oauth_emitent();
    $u = $cerere['redirect_uri'];
    header('Location: ' . $u . (strpos($u, '?') === false ? '?' : '&') . http_build_query($parametri), true, 302);
}

function pagina_autorizare_eroare(string $mesaj): void
{
    jurnal_scrie(['punct' => 'oauth', 'cerere' => 'autorizare', 'rezultat' => 'respins', 'detalii' => ['motiv' => substr($mesaj, 0, 160)]]);
    randeaza('eroare', ['cod' => 400, 'mesaj' => $mesaj, 'noindex' => true, 'articole' => [], 'titlu_pagina' => 'Conectare — ' . config('site.nume')], 400);
}

// --- token-urile ----------------------------------------------------------------------------------

function oauth_token(): void
{
    $p = $_POST;
    $client_id = is_string($p['client_id'] ?? null) ? $p['client_id'] : '';
    $secret = is_string($p['client_secret'] ?? null) ? $p['client_secret'] : '';
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Basic\s+(\S+)$/i', $auth, $m) && ($dec = base64_decode($m[1], true)) !== false && strpos($dec, ':') !== false) {
        [$client_id, $secret] = array_map('rawurldecode', explode(':', $dec, 2));
    }
    $client = oauth_citeste('clienti')[$client_id] ?? null;
    if (!$client || ($client['secret'] && !hash_equals((string) $client['secret'], hash('sha256', $secret)))) {
        jurnal_scrie(['punct' => 'oauth', 'cerere' => 'token', 'rezultat' => 'respins', 'detalii' => ['motiv' => 'client necunoscut sau secret greșit']]);
        oauth_eroare(401, 'invalid_client', 'client necunoscut sau secret greșit');
        return;
    }
    $tip = (string) ($p['grant_type'] ?? '');
    try {
        $rez = cu_blocare(function () use ($tip, $p, $client_id) {
            if ($tip === 'authorization_code') {
                $coduri = oauth_citeste('coduri');
                $h = hash('sha256', (string) ($p['code'] ?? ''));
                $c = $coduri[$h] ?? null;
                unset($coduri[$h]);   // un cod se folosește o singură dată, chiar și când cererea e greșită
                oauth_scrie('coduri', array_filter($coduri, fn($x) => ($x['expira'] ?? 0) > time()));
                if (!$c || $c['expira'] < time()) throw new EroareCms('codul nu e valabil (expirat sau deja folosit)');
                if ($c['client'] !== $client_id || $c['redirect_uri'] !== (string) ($p['redirect_uri'] ?? '')) throw new EroareCms('codul a fost emis pentru alt client sau altă adresă');
                $verificator = (string) ($p['code_verifier'] ?? '');
                if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verificator)
                    || !hash_equals($c['provocare'], rtrim(strtr(base64_encode(hash('sha256', $verificator, true)), '+/', '-_'), '='))) {
                    throw new EroareCms('code_verifier greșit (PKCE)');
                }
                return emite_tokenuri($client_id, $c['rol'], $c['amprenta_cheie']);
            }
            if ($tip === 'refresh_token') {
                $tokenuri = oauth_citeste('tokenuri');
                $h = hash('sha256', (string) ($p['refresh_token'] ?? ''));
                $t = $tokenuri[$h] ?? null;
                if (!$t || $t['tip'] !== 'reinnoire' || $t['client'] !== $client_id || !token_valid($t)) throw new EroareCms('token de reînnoire nevalabil');
                unset($tokenuri[$h]);   // rotire: cel vechi nu mai merge
                oauth_scrie('tokenuri', $tokenuri);
                return emite_tokenuri($client_id, $t['rol'], $t['amprenta_cheie']);
            }
            throw new EroareCms('grant_type necunoscut');
        });
    } catch (EroareCms $e) {
        jurnal_scrie(['punct' => 'oauth', 'cerere' => 'token', 'rezultat' => 'respins', 'tinta' => $client['nume'], 'detalii' => ['motiv' => $e->getMessage()]]);
        oauth_eroare(400, $tip === 'authorization_code' || $tip === 'refresh_token' ? 'invalid_grant' : 'unsupported_grant_type', $e->getMessage());
        return;
    }
    jurnal_scrie(['punct' => 'oauth', 'cerere' => 'token', 'rezultat' => 'ok', 'cheie' => $rez['scope'], 'tinta' => $client['nume'],
                  'detalii' => ['fel' => $tip === 'refresh_token' ? 'reînnoire' : 'prima emitere']]);
    oauth_json(200, $rez);
}

function emite_tokenuri(string $client_id, string $rol, string $amprenta_cheie): array
{
    $acces = aleator('mcms_t_');
    $reinnoire = aleator('mcms_r_');
    $tokenuri = array_filter(oauth_citeste('tokenuri'), fn($t) => ($t['expira'] ?? 0) > time());
    $baza = ['client' => $client_id, 'rol' => $rol, 'amprenta_cheie' => $amprenta_cheie, 'creat' => time()];
    $tokenuri[hash('sha256', $acces)] = $baza + ['tip' => 'acces', 'expira' => time() + OAUTH_ACCES_SECUNDE];
    $tokenuri[hash('sha256', $reinnoire)] = $baza + ['tip' => 'reinnoire', 'expira' => time() + OAUTH_REINNOIRE_SECUNDE];
    oauth_scrie('tokenuri', $tokenuri);
    return ['access_token' => $acces, 'token_type' => 'Bearer', 'expires_in' => OAUTH_ACCES_SECUNDE, 'refresh_token' => $reinnoire, 'scope' => $rol];
}

// Un token merge cât nu a expirat și cât cheia cu care a fost aprobat e încă în config.php, cu același rol.
// Cheia unui editor scoasă din config = token-urile aprobate cu ea mor pe loc.
function token_identitate(array $t): ?array
{
    if (($t['expira'] ?? 0) <= time()) return null;
    $id = identitate_pentru_amprenta((string) ($t['amprenta_cheie'] ?? ''));
    return $id !== null && $id['rol'] === ($t['rol'] ?? '') ? $id : null;
}

function token_valid(array $t): bool
{
    return token_identitate($t) !== null;
}

// Pentru /mcp: identitatea (rol, cine) și numele conexiunii unui token de acces, sau null.
function rol_token_oauth(string $amprenta): ?array
{
    $t = oauth_citeste('tokenuri')[$amprenta] ?? null;
    if (!$t || ($t['tip'] ?? '') !== 'acces' || !($id = token_identitate($t))) return null;
    return $id + ['conexiune' => (string) (oauth_citeste('clienti')[$t['client']]['nume'] ?? $t['client'])];
}

// --- conexiunile, pentru comenzile listeaza_conexiuni și retrage_conexiune --------------------------

function conexiuni_oauth(): array
{
    $tokenuri = oauth_citeste('tokenuri');
    $rez = [];
    foreach (oauth_citeste('clienti') as $id => $c) {
        $active = array_filter($tokenuri, fn($t) => $t['client'] === $id && $t['tip'] === 'reinnoire' && token_valid($t));
        $rez[] = ['client_id' => $id, 'nume' => $c['nume'], 'intoarcere' => array_values(array_unique(array_map(fn($u) => parse_url($u, PHP_URL_HOST), $c['redirect_uris']))),
                  'inregistrat' => date('c', (int) $c['creat']), 'aprobat' => (bool) $active,
                  'drepturi' => $active ? array_values(array_unique(array_column($active, 'rol'))) : [],
                  'aprobat_de' => array_values(array_unique(array_map(fn($t) => token_identitate($t)['cine'] ?? '?', $active))),
                  'ultima_aprobare_sau_reinnoire' => $active ? date('c', max(array_column($active, 'creat'))) : null];
    }
    return $rez;
}

function retrage_conexiune_oauth(string $client_id): array
{
    return cu_blocare(function () use ($client_id) {
        $clienti = oauth_citeste('clienti');
        if (!isset($clienti[$client_id])) throw new EroareCms('nu există o conexiune cu acest client_id (vezi listeaza_conexiuni)');
        $nume = $clienti[$client_id]['nume'];
        unset($clienti[$client_id]);
        $tokenuri = oauth_citeste('tokenuri');
        $inainte = count($tokenuri);
        $tokenuri = array_filter($tokenuri, fn($t) => $t['client'] !== $client_id);
        oauth_scrie('clienti', $clienti);
        oauth_scrie('tokenuri', $tokenuri);
        return ['operatie' => 'retrasă', 'conexiune' => $nume, 'tokenuri_anulate' => $inainte - count($tokenuri),
                'atentie' => 'aplicația nu mai are acces; ca să se lege din nou, trebuie aprobată iar, cu cheia'];
    });
}
