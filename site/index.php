<?php
// Punctul de intrare al site-ului public. Toate adresele care nu sunt fișiere reale ajung aici (.htaccess).
declare(strict_types=1);
define('MINICMS', true);
require __DIR__ . '/app/nucleu.php';
require __DIR__ . '/app/site.php';

ruleaza_site();
