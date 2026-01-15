<?php
// lib/db.php — Conexão com banco de dados MySQL (Get Power Research)

require_once __DIR__ . '/../config.php'; // garante as constantes DB_* e opções extras

/**
 * Retorna uma conexão PDO singleton.
 * Lança RuntimeException em falha (para ser capturada por try/catch de quem chamou).
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Monta DSN (suporta porta e charset do config)
    $host    = defined('DB_HOST')    ? DB_HOST    : 'localhost';
    $name    = defined('DB_NAME')    ? DB_NAME    : '';
    $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
    $port    = defined('DB_PORT')    ? DB_PORT    : null; // opcional
    $socket  = defined('DB_SOCKET')  ? DB_SOCKET  : null; // opcional (p/ hospedagens que usam socket)

    if (!empty($socket)) {
        $dsn = "mysql:unix_socket={$socket};dbname={$name};charset={$charset}";
    } else {
        $dsn = "mysql:host={$host}" . ($port ? ";port={$port}" : "") . ";dbname={$name};charset={$charset}";
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}",
        // Se quiser conexão persistente (nem sempre recomendado em shared hosting):
        // PDO::ATTR_PERSISTENT => true,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        // Ajustes opcionais conforme config.php (se definidos)
        if (defined('DB_SQL_MODE') && DB_SQL_MODE) {
            $pdo->exec("SET SESSION sql_mode = " . $pdo->quote(DB_SQL_MODE));
        }
        if (defined('DB_TIME_ZONE') && DB_TIME_ZONE) {
            // Ex.: define('DB_TIME_ZONE', '-03:00'); ou 'America/Maceio' se o MySQL tiver time_zone tables
            $tz = DB_TIME_ZONE;
            // detecta formato [+/-]HH:MM vs nome de TZ
            if (preg_match('/^[\+\-]\d{2}:\d{2}$/', $tz)) {
                $pdo->exec("SET time_zone = " . $pdo->quote($tz));
            } else {
                // pode falhar se servidor não tiver time zone names carregados — sem problema
                try { $pdo->exec("SET time_zone = " . $pdo->quote($tz)); } catch (Throwable $__) {}
            }
        }

        return $pdo;
    } catch (PDOException $e) {
        // Não usar die(); deixe o controlador exibir mensagem amigável/logar
        throw new RuntimeException('Falha na conexão com o banco de dados: ' . $e->getMessage(), 0, $e);
    }
}

/**
 * Testa a conexão executando um SELECT 1.
 */
function test_db_connection(): bool {
    try {
        $pdo = db();
        $pdo->query('SELECT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Verifica se uma tabela existe no schema atual.
 */
function table_exists(string $table_name): bool {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table_name]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
