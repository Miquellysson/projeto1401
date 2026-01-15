<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Debug Ativo ✅</h2>";

require __DIR__.'/lib/db.php';

try {
    $pdo = db();
    echo "<p>Conexão com banco: OK ✅</p>";
} catch (Throwable $e) {
    echo "<p>Erro ao conectar: ".$e->getMessage()."</p>";
}

if (file_exists(__DIR__.'/config.php')) {
    echo "<p>Arquivo config.php encontrado ✅</p>";
    $cfg = require __DIR__.'/config.php';
    echo "<pre>";
    var_dump($cfg);
    echo "</pre>";
} else {
    echo "<p>Arquivo config.php NÃO encontrado ❌</p>";
}

echo "<p>Fim dos testes.</p>";
