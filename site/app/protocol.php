<?php
// MCP prin Streamable HTTP, fără sesiuni: fiecare cerere e un POST JSON-RPC care primește un răspuns JSON.
// Nu ține conexiuni deschise, deci merge pe orice găzduire PHP. Ordinea verificărilor contează:
// metodă → origine → cheie (cu blocare după eșecuri) → mărime → JSON. Nimic nu se parsează înainte de cheie.
declare(strict_types=1);

if (!defined('MINICMS')) { http_response_code(403); exit; }

require __DIR__ . '/unelte.php';

const MCP_VERSIUNI = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];
const MCP_MAX_OCTETI = 8 * 1024 * 1024;

function mcp_trimite(int $cod, ?array $corp): void
{
    http_response_code($cod);
    if ($corp !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_text($corp);
    }
    exit;
}

function mcp_eroare(int $http, $id, int $cod, string $mesaj): void
{
    mcp_trimite($http, ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $cod, 'message' => $mesaj]]);
}

// O cerere venită dintr-un browser (are Origin) e acceptată doar de pe situl însuși — apărare DNS rebinding.
function origine_permisa(string $origine): bool
{
    $site = (string) config('site.url');
    if ($site === '') return false;
    $p = parse_url($site);
    $asteptat = strtolower(($p['scheme'] ?? '') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : ''));
    return strtolower(rtrim($origine, '/')) === $asteptat;
}

function ruleaza_mcp(): void
{
    $start = microtime(true);
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    antete_securitate("default-src 'none'; frame-ancestors 'none'");
    $baza = ['punct' => 'mcp'];

    $metoda_http = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
    if ($metoda_http !== 'POST') {
        header('Allow: POST');
        jurnal_scrie($baza + ['cerere' => 'HTTP ' . substr($metoda_http, 0, 10), 'rezultat' => 'respins', 'detalii' => ['cod' => 405]]);
        mcp_trimite(405, ['eroare' => 'Endpointul MCP primește doar POST (Streamable HTTP, fără sesiuni).']);
    }
    $origine = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origine !== '' && !origine_permisa($origine)) {
        jurnal_scrie($baza + ['cerere' => 'POST', 'rezultat' => 'respins', 'detalii' => ['cod' => 403, 'origine' => substr($origine, 0, 100)]]);
        mcp_eroare(403, null, -32000, 'Origine nepermisă.');
    }
    $acces = verifica_acces('mcp', cheie_din_cerere());
    if ($acces['cod'] !== 200) {
        if ($acces['cod'] === 401) header('WWW-Authenticate: Bearer realm="mini-cms-mcp"');
        mcp_eroare($acces['cod'], null, -32001, $acces['mesaj']);
    }
    $rol = $acces['rol'];
    $baza['cheie'] = $rol;

    $corp = (string) file_get_contents('php://input', false, null, 0, MCP_MAX_OCTETI + 1);
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > MCP_MAX_OCTETI || strlen($corp) > MCP_MAX_OCTETI) {
        jurnal_scrie($baza + ['cerere' => 'POST', 'rezultat' => 'respins', 'detalii' => ['cod' => 413]]);
        mcp_eroare(413, null, -32600, 'Cererea depășește 8 MB.');
    }
    $cerere = json_decode($corp, true);
    if (!is_array($cerere)) {
        jurnal_scrie($baza + ['cerere' => 'POST', 'rezultat' => 'eroare', 'detalii' => ['motiv' => 'JSON invalid']]);
        mcp_eroare(400, null, -32700, 'JSON invalid.');
    }
    if ($cerere === [] || isset($cerere[0])) {   // loturile au fost scoase din protocol în 2025-06-18
        jurnal_scrie($baza + ['cerere' => 'lot', 'rezultat' => 'respins']);
        mcp_eroare(400, null, -32600, 'Loturile de cereri nu sunt acceptate; trimite câte o cerere.');
    }
    $id = $cerere['id'] ?? null;
    $metoda = $cerere['method'] ?? null;
    if (($cerere['jsonrpc'] ?? '') !== '2.0' || !is_string($metoda) || ($id !== null && !is_string($id) && !is_int($id))) {
        mcp_eroare(400, is_string($id) || is_int($id) ? $id : null, -32600, 'Cerere JSON-RPC 2.0 invalidă.');
    }
    if (!array_key_exists('id', $cerere)) {   // notificare (ex. notifications/initialized): nu are răspuns
        mcp_trimite(202, null);
    }
    $params = is_array($cerere['params'] ?? null) ? $cerere['params'] : [];
    $baza['cerere'] = substr($metoda, 0, 40);

    switch ($metoda) {
        case 'initialize':
            $ceruta = (string) ($params['protocolVersion'] ?? '');
            $rezultat = [
                'protocolVersion' => in_array($ceruta, MCP_VERSIUNI, true) ? $ceruta : MCP_VERSIUNI[0],
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'mini-cms-mcp', 'title' => 'Mini CMS — ' . config('site.nume'), 'version' => MINICMS_VERSIUNE],
                'instructions' => 'Administrezi conținutul site-ului ' . config('site.nume') . ' (' . url_site() . '). '
                    . 'Cheia ta are drept de ' . $rol . '. Cheamă întâi despre_site pentru reguli. '
                    . 'Tot ce creezi pleacă drept ciornă; publici doar după aprobarea omului. Fiecare apel e scris în jurnal.',
            ];
            $client = $params['clientInfo'] ?? [];
            jurnal_scrie($baza + ['rezultat' => 'ok', 'detalii' => ['client' => substr((string) ($client['name'] ?? '?') . ' ' . (string) ($client['version'] ?? ''), 0, 80),
                                                                    'protocol' => $rezultat['protocolVersion']]]);
            break;
        case 'ping':
            $rezultat = new stdClass();
            break;
        case 'tools/list':
            $lista = [];
            foreach (unelte() as $nume => $u) {
                if ($u['scriere'] && $rol !== 'scriere') continue;
                $lista[] = ['name' => $nume, 'title' => $u['titlu'], 'description' => $u['descriere'],
                            'inputSchema' => $u['schema'], 'annotations' => ['title' => $u['titlu']] + $u['adnotari']];
            }
            $rezultat = ['tools' => $lista];
            jurnal_scrie($baza + ['rezultat' => 'ok', 'detalii' => ['unelte' => count($lista)]]);
            break;
        case 'tools/call':
            $rezultat = mcp_apel_unealta($params, $rol, $baza, $start, $id);
            break;
        default:
            jurnal_scrie($baza + ['rezultat' => 'respins', 'detalii' => ['motiv' => 'metodă necunoscută']]);
            mcp_eroare(200, $id, -32601, 'Metodă necunoscută: ' . substr($metoda, 0, 40));
            return;
    }
    mcp_trimite(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $rezultat]);
}

function mcp_apel_unealta(array $params, string $rol, array $baza, float $start, $id): array
{
    $nume = (string) ($params['name'] ?? '');
    $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
    $unelte = unelte();
    $jurnal = $baza + ['unealta' => substr($nume, 0, 40), 'tinta' => tinta_apel($args, $nume)];

    if (!isset($unelte[$nume])) {
        jurnal_scrie($jurnal + ['rezultat' => 'respins', 'detalii' => ['motiv' => 'comandă necunoscută']]);
        mcp_eroare(200, $id, -32602, 'Comandă necunoscută: ' . substr($nume, 0, 40));
    }
    $u = $unelte[$nume];
    if ($u['scriere'] && $rol !== 'scriere') {
        jurnal_scrie($jurnal + ['rezultat' => 'refuzat', 'detalii' => ['motiv' => 'cheie de citire']]);
        return mcp_rezultat_eroare('Refuzat: cheia folosită are doar drept de citire.');
    }
    try {
        $rez = ($u['fn'])($args, $rol);
        $detalii = [];
        foreach (['operatie', 'versiune_anterioara', 'curatari'] as $k) if (!empty($rez[$k])) $detalii[$k] = $rez[$k];
        $intrare = $jurnal + ['rezultat' => 'ok'];
        if ($detalii) $intrare['detalii'] = $detalii;
        if (!empty($rez['amprenta'])) $intrare['amprenta'] = $rez['amprenta'];
        $intrare['ms'] = (int) round((microtime(true) - $start) * 1000);
        jurnal_scrie($intrare);
        return ['content' => [['type' => 'text', 'text' => json_text($rez, true)]], 'structuredContent' => (object) $rez, 'isError' => false];
    } catch (EroareCms $e) {
        jurnal_scrie($jurnal + ['rezultat' => 'eroare', 'detalii' => ['mesaj' => substr($e->getMessage(), 0, 200)]]);
        return mcp_rezultat_eroare($e->getMessage());
    } catch (Throwable $e) {
        jurnal_scrie($jurnal + ['rezultat' => 'eroare', 'detalii' => ['intern' => get_class($e) . ': ' . substr($e->getMessage(), 0, 200)]]);
        return mcp_rezultat_eroare('Eroare internă pe server. Detaliile sunt în jurnal.');
    }
}

function mcp_rezultat_eroare(string $mesaj): array
{
    return ['content' => [['type' => 'text', 'text' => $mesaj]], 'isError' => true];
}
