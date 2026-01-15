<?php
// lib/password_reset.php - helpers para fluxo de recuperação de senha

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

if (!defined('PASSWORD_RESET_EXPIRATION_MIN')) {
    define('PASSWORD_RESET_EXPIRATION_MIN', 30); // expiração padrão: 30 minutos
}
if (!defined('PASSWORD_RESET_RATE_LIMIT')) {
    define('PASSWORD_RESET_RATE_LIMIT', 3); // máximo 3 tentativas por hora
}

if (!function_exists('pr_generate_token')) {
    function pr_generate_token(): string {
        return bin2hex(random_bytes(32));
    }
}

if (!function_exists('pr_hash_token')) {
    function pr_hash_token(string $token): string {
        return hash('sha256', $token);
    }
}

if (!function_exists('pr_rate_limit_check')) {
    function pr_rate_limit_check(string $email, string $ip): bool {
        try {
            $pdo = db();
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM password_resets\n                 WHERE (user_id = (SELECT id FROM users WHERE email = ? LIMIT 1)\n                        OR ip_request = ?)\n                   AND created_at >= (NOW() - INTERVAL 1 HOUR)"
            );
            $stmt->execute([$email, $ip]);
            return ((int)$stmt->fetchColumn()) < PASSWORD_RESET_RATE_LIMIT;
        } catch (Throwable $e) {
            error_log('Rate limit check failed: ' . $e->getMessage());
            return true;
        }
    }
}

if (!function_exists('pr_create_request')) {
    function pr_create_request(string $email, string $ip, string $userAgent): ?array {
        try {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = ? AND active = 1 LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                return null;
            }

            $token = pr_generate_token();
            $hash  = pr_hash_token($token);
            $expiresAt = (new DateTimeImmutable())
                ->modify('+' . PASSWORD_RESET_EXPIRATION_MIN . ' minutes')
                ->format('Y-m-d H:i:s');

            $insert = $pdo->prepare(
                "INSERT INTO password_resets (user_id, token_hash, expires_at, ip_request, user_agent)\n                 VALUES (?, ?, ?, ?, ?)"
            );
            $insert->execute([
                $user['id'],
                $hash,
                $expiresAt,
                $ip,
                mb_substr($userAgent, 0, 255)
            ]);

            return [
                'token' => $token,
                'user'  => $user,
                'expires_at' => $expiresAt,
            ];
        } catch (Throwable $e) {
            error_log('Create password reset request failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('pr_validate_token')) {
    function pr_validate_token(string $token): ?array {
        try {
            $pdo = db();
            $hash = pr_hash_token($token);
            $stmt = $pdo->prepare(
                "SELECT pr.*, u.email, u.name\n                 FROM password_resets pr\n                 INNER JOIN users u ON u.id = pr.user_id\n                 WHERE pr.token_hash = ?\n                   AND pr.used_at IS NULL\n                   AND pr.expires_at >= NOW()\n                 LIMIT 1"
            );
            $stmt->execute([$hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('Validate password reset token failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('pr_mark_used')) {
    function pr_mark_used(int $resetId): void {
        try {
            $pdo = db();
            $upd = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?");
            $upd->execute([$resetId]);
        } catch (Throwable $e) {
            error_log('Mark password reset token as used failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('pr_update_password')) {
    function pr_update_password(int $userId, string $newPassword): bool {
        try {
            $pdo = db();
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE users SET pass = ? WHERE id = ?");
            return $upd->execute([$hash, $userId]);
        } catch (Throwable $e) {
            error_log('Update password failed: ' . $e->getMessage());
            return false;
        }
    }
}
