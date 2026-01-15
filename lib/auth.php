<?php

declare(strict_types=1);

if (!function_exists('auth_attempt_login')) {
    /**
     * Tenta autenticar um usuário administrativo com base no e-mail e senha.
     *
     * @return array{ok:bool,user?:array<string,mixed>,error?:string}
     */
    function auth_attempt_login(PDO $pdo, string $email, string $password): array
    {
        $email = trim($email);
        if ($email === '' || $password === '') {
            return ['ok' => false, 'error' => 'missing_credentials'];
        }
        try {
            $stmt = $pdo->prepare("SELECT id, name, email, pass, role, active FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
        if (!$user) {
            return ['ok' => false, 'error' => 'invalid_credentials'];
        }
        if ((int)($user['active'] ?? 0) !== 1) {
            return ['ok' => false, 'error' => 'inactive_user'];
        }
        if (empty($user['pass']) || !password_verify($password, (string)$user['pass'])) {
            return ['ok' => false, 'error' => 'invalid_credentials'];
        }
        return [
            'ok'   => true,
            'user' => [
                'id'    => (int)($user['id'] ?? 0),
                'name'  => (string)($user['name'] ?? $email),
                'email' => (string)($user['email'] ?? $email),
                'role'  => (string)($user['role'] ?? 'admin'),
            ],
        ];
    }
}
