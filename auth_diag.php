<?php
require __DIR__ . '/config.php';

$inputEmail = 'admin@farmafacil.com';
$inputPass  = 'arkaleads2025!';

header('Content-Type: text/plain; charset=utf-8');

echo "ADMIN_EMAIL em config.php: ";
var_dump(defined('ADMIN_EMAIL') ? ADMIN_EMAIL : '(NÃO DEFINIDO)');

echo "\nConfere e-mail? ";
var_dump(defined('ADMIN_EMAIL') && ADMIN_EMAIL === $inputEmail);

echo "\nTestando password_verify('arkaleads2025!'): ";
var_dump(defined('ADMIN_PASS_HASH') ? password_verify($inputPass, ADMIN_PASS_HASH) : false);

echo "\nHash atual (ADMIN_PASS_HASH): ";
var_dump(defined('ADMIN_PASS_HASH') ? ADMIN_PASS_HASH : '(NÃO DEFINIDO)');

echo "\nSe password_verify der false, gere um novo hash (exemplo):\n";
echo password_hash($inputPass, PASSWORD_DEFAULT), "\n";
