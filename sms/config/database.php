<?php
// PDO connection factory using configuration loaded from config.php

$config = require __DIR__ . '/config.php';

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db']['host'], $config['db']['name']);

try {
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    // Friendly message for production, detailed for logs
    die('Erro ao conectar ao banco de dados. Verifique as configurações.');
}

return $pdo;
