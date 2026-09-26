<?php
// Puntea stdio: pornește o copie demo, goală, a site-ului pe calculatorul local și o expune prin stdio
// (JSON-RPC, un mesaj pe rând), pentru clienții MCP care pornesc serverul ca proces: inspectoare, cataloage
// (Glama îl construiește din Dockerfile și cere tools/list), Claude Desktop pentru o probă.
// Site-ul real rămâne cel de pe găzduire, la https://site/mcp. Aici nu se atinge nimic de pe server.
//
//   php unelte/stdio.php                  site demo temporar, șters la ieșire
//   php unelte/stdio.php --date=<dosar>   datele demo se păstrează în <dosar> între rulări
//
// Nimic în afară de răspunsurile JSON-RPC nu se scrie pe stdout: serverul PHP încorporat scrie în jurnalul lui.
declare(strict_types=1);

$REPO = dirname(__DIR__);
$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opt[$m[1]] = $m[2] ?? true;
}
function eroare_start(string $t): void { fwrite(STDERR, "mini-cms-mcp stdio: $t\n"); exit(1); }

function copiaza(string $sursa, string $dest, array $exclus, string $rel = ''): void
{
    @mkdir($dest, 0755, true);
    foreach (scandir($sursa) as $f) {
        if ($f === '.' || $f === '..' || in_array(ltrim("$rel/$f", '/'), $exclus, true)) continue;
        if (is_dir("$sursa/$f")) copiaza("$sursa/$f", "$dest/$f", $exclus, "$rel/$f");
        else copy("$sursa/$f", "$dest/$f");
    }
}
function sterge(string $d): void
{
    if (!is_dir($d)) return;
    foreach (scandir($d) as $f) {
        if ($f === '.' || $f === '..') continue;
        is_dir("$d/$f") ? sterge("$d/$f") : @unlink("$d/$f");
    }
    @rmdir($d);
}

// --- site-ul demo ---------------------------------------------------------------------------------
$pastrat = is_string($opt['date'] ?? null) && $opt['date'] !== '';
$tmp = $pastrat ? rtrim($opt['date'], '/\\') : sys_get_temp_dir() . '/mini-cms-mcp-stdio-' . bin2hex(random_bytes(6));
copiaza("$REPO/site", "$tmp/site", ['app/config.php', 'date', 'media']);   // la --date: codul se reîmprospătează, datele rămân

$port = 0;
for ($i = 0; $i < 30 && !$port; $i++) {
    $p = random_int(20100, 20999);
    $s = @fsockopen('127.0.0.1', $p, $e1, $e2, 0.2);
    if ($s) fclose($s); else $port = $p;
}
if (!$port) eroare_start('nu am găsit un port liber.');
$url = "http://127.0.0.1:$port";

// O cheie de scriere nouă la fiecare pornire, ținută doar în memorie: site-ul demo ascultă doar pe 127.0.0.1.
$cheie = 'mcms_s_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$cheie_citire = 'mcms_c_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');   // cerută de config, nefolosită
$config = ['site' => ['nume' => 'mini-cms-mcp demo', 'descriere' => 'Site demo local, pornit prin puntea stdio.', 'url' => $url,
                      'limba' => 'ro', 'autor' => 'mini-cms-mcp'],
           'chei' => ['citire' => hash('sha256', $cheie_citire), 'scriere' => hash('sha256', $cheie)]];
file_put_contents("$tmp/site/app/config.php", "<?php\nif (!defined('MINICMS')) { http_response_code(403); exit; }\nreturn "
    . var_export($config, true) . ";\n");

$jurnal = "$tmp/server.log";
$server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', "127.0.0.1:$port", '-t', "$tmp/site", "$REPO/unelte/router-local.php"],
    [0 => ['pipe', 'r'], 1 => ['file', $jurnal, 'a'], 2 => ['file', $jurnal, 'a']], $pipes, "$tmp/site");
if (!is_resource($server)) eroare_start('serverul PHP încorporat nu a pornit.');
register_shutdown_function(function () use ($server, $tmp, $pastrat) {
    $st = @proc_get_status($server);
    if ($st && $st['running']) proc_terminate($server);
    if (!$pastrat) { usleep(200000); sterge($tmp); }
});
$gata = false;
for ($i = 0; $i < 100 && !$gata; $i++) {
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
    if ($s) { fclose($s); $gata = true; } else usleep(100000);
}
if (!$gata) eroare_start("serverul PHP încorporat nu răspunde (jurnal: $jurnal).");

// --- puntea: un rând de pe stdin = o cerere POST /mcp; răspunsul JSON = un rând pe stdout ------------
while (($rand = fgets(STDIN)) !== false) {
    $rand = trim($rand);
    if ($rand === '') continue;
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'ignore_errors' => true, 'timeout' => 120, 'content' => $rand,
        'header' => "Content-Type: application/json\r\nAccept: application/json, text/event-stream\r\nAuthorization: Bearer $cheie\r\n",
    ]]);
    $corp = @file_get_contents("$url/mcp", false, $ctx);
    if ($corp === false || trim($corp) === '') {   // notificările (fără id) primesc 202 fără corp: nu se răspunde nimic
        $cerere = json_decode($rand, true);
        if (is_array($cerere) && array_key_exists('id', $cerere)) {
            echo json_encode(['jsonrpc' => '2.0', 'id' => $cerere['id'], 'error' => ['code' => -32603, 'message' => 'site-ul demo nu a răspuns']]), "\n";
            fflush(STDOUT);
        }
        continue;
    }
    echo str_replace(["\r", "\n"], '', $corp), "\n";
    fflush(STDOUT);
}
