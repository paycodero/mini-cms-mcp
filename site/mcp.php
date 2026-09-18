<?php
// Punctul de intrare pentru AI (MCP). Adresa de dat clientului: https://site/mcp
declare(strict_types=1);
define('MINICMS', true);
require __DIR__ . '/app/nucleu.php';
require __DIR__ . '/app/protocol.php';

ruleaza_mcp();
