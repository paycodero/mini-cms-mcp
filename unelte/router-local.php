<?php
// Router pentru serverul PHP încorporat (php -S), folosit la testele locale.
// Imită regulile din site/.htaccess, ca testele să verifice aceleași interdicții pe care Apache le aplică
// pe găzduire. Verificarea finală rămâne o cerere HTTP pe serverul real, după livrare.
$rad = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/\\');
$cale = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));

if (preg_match('#^/(app|sabloane|date)(/|$)#', $cale)
    || preg_match('#(^|/)\.(?!well-known/)#', $cale)
    || preg_match('#\.(zip|tar|gz|tgz|7z|rar|sql|bak|old|orig|swp)$#i', $cale)
    || preg_match('#^/media/.+\.(php[0-9]?|phtml|phar|pht|shtml|cgi|pl|py|sh)$#i', $cale)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "403 Forbidden\n";
    return true;
}
if (preg_match('#^/mcp/?$#', $cale)) {
    require $rad . '/mcp.php';
    return true;
}
if ($cale !== '/' && is_file($rad . $cale)) return false;   // fișier real: îl servește serverul (sau îl rulează, dacă e .php)
require $rad . '/index.php';
return true;
