<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/../lib/auth.php';

final class LoginTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT UNIQUE, pass TEXT, role TEXT, active INTEGER)');
        $stmt = $this->pdo->prepare('INSERT INTO users (name,email,pass,role,active) VALUES (?,?,?,?,?)');
        $stmt->execute(['Admin', 'admin@example.com', password_hash('secret123', PASSWORD_BCRYPT), 'admin', 1]);
        $stmt->execute(['Inactive', 'inactive@example.com', password_hash('secret123', PASSWORD_BCRYPT), 'admin', 0]);
    }

    public function testLoginWithValidCredentials(): void
    {
        $result = auth_attempt_login($this->pdo, 'admin@example.com', 'secret123');
        $this->assertTrue($result['ok'], 'Login deveria ser aprovado.');
        $this->assertEquals('Admin', $result['user']['name']);
    }

    public function testLoginWithInvalidPassword(): void
    {
        $result = auth_attempt_login($this->pdo, 'admin@example.com', 'wrong');
        $this->assertTrue(!$result['ok'], 'Senha incorreta não pode autenticar.');
        $this->assertEquals('invalid_credentials', $result['error']);
    }

    public function testInactiveUserIsBlocked(): void
    {
        $result = auth_attempt_login($this->pdo, 'inactive@example.com', 'secret123');
        $this->assertTrue(!$result['ok']);
        $this->assertEquals('inactive_user', $result['error']);
    }
}
