<?php
// lib/PasswordResetMailer.php — envio de e-mail de recuperação de senha

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/password_reset.php';

if (!function_exists('get_base_url')) {
    function get_base_url(): string {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }
}

if (!function_exists('send_password_reset_email')) {
    function send_password_reset_email(string $email, string $token, ?string $name = null): void {
        $cfg = cfg();
        $baseUrl = setting_get('app_base_url', $cfg['app_base_url'] ?? get_base_url());

        $resetLink = rtrim($baseUrl, '/') . '/reset_password.php?token=' . urlencode($token);
        $expiresMinutes = PASSWORD_RESET_EXPIRATION_MIN;

        if ($name === null || trim($name) === '') {
            $name = 'Cliente';
        }

        $htmlTemplatePath = __DIR__ . '/../emails/password_reset.html';
        $textTemplatePath = __DIR__ . '/../emails/password_reset.txt';

        $html = file_exists($htmlTemplatePath)
            ? file_get_contents($htmlTemplatePath)
            : '<p>Olá, {{name}}!</p><p>Para redefinir sua senha clique no link:</p><p><a href="{{reset_link}}">Resetar senha</a></p><p>Este link expira em {{expires_minutes}} minutos.</p>';

        $plain = file_exists($textTemplatePath)
            ? file_get_contents($textTemplatePath)
            : "Olá, {{name}}!\n\nUse o link a seguir para definir uma nova senha: {{reset_link}}\n\nEste link expira em {{expires_minutes}} minutos.";

        $replacements = [
            '{{name}}' => sanitize_html($name),
            '{{reset_link}}' => sanitize_html($resetLink),
            '{{expires_minutes}}' => (string)$expiresMinutes,
        ];

        $htmlBody = strtr($html, $replacements);
        $plainBody = strtr($plain, $replacements);

        $from = $cfg['store']['support_email'] ?? ('no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $boundary = 'bndry' . bin2hex(random_bytes(8));
        $headers = [
            'From: ' . $from,
            'Reply-To: ' . $from,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"'
        ];

        $message = "--{$boundary}\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n{$plainBody}\r\n";
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n\r\n{$htmlBody}\r\n";
        $message .= "--{$boundary}--";

        if (!mail($email, 'Recuperação de senha - ' . ($cfg['store']['name'] ?? 'Get Power Research'), $message, implode("\r\n", $headers))) {
            throw new RuntimeException('Falha ao enviar e-mail de recuperação.');
        }
    }
}
