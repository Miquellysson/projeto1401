<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . "/lib/utils.php";

echo "<pre>Testando funções...\n";

// Teste config
print_r(cfg());

// Teste lang
print_r(lang());

// Teste csrf
echo "CSRF: ".csrf_token()."\n";

// Teste pix_payload
echo "PIX: ".pix_payload('chavepix@teste.com', 'Empresa Teste', 'CUIABA', 10)."\n";

echo "\n✅ Se chegou até aqui, utils.php está OK.\n";
