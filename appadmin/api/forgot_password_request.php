<?php
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../lib/password_reset.php';
require __DIR__ . '/../lib/PasswordResetMailer.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método inválido']);
    exit;
}

if (!csrf_check($_POST['csrf'] ?? '')) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'CSRF inválido']);
    exit;
}

$email = sanitize_string($_POST['email'] ?? '', 190);
if (!validate_email($email)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Informe um e-mail válido']);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!pr_rate_limit_check($email, $ip)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Limite de tentativas excedido. Tente novamente mais tarde.']);
    exit;
}

$request = pr_create_request($email, $ip, $_SERVER['HTTP_USER_AGENT'] ?? '');
if (!$request) {
    // Resposta genérica para evitar enumeração
    echo json_encode(['ok' => true]);
    exit;
}

try {
    send_password_reset_email($email, $request['token'], $request['user']['name'] ?? null);
} catch (Throwable $e) {
    error_log('Erro ao enviar e-mail de recuperação: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erro ao enviar e-mail. Tente novamente.']);
    exit;
}

echo json_encode(['ok' => true]);
