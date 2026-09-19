<?php
// Punctul de intrare al site-ului public. Toate adresele care nu sunt fișiere reale ajung aici (.htaccess).
declare(strict_types=1);
define('MINICMS', true);
require __DIR__ . '/app/nucleu.php';
require __DIR__ . '/app/site.php';

$cale = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));
if (preg_match('#^/(\.well-known/(oauth-|openid-configuration)|oauth/)#', $cale)) ruleaza_oauth(rtrim($cale, '/'));   // conectorul claude.ai
else ruleaza_site();
