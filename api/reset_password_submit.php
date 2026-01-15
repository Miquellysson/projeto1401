<?php
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../lib/password_reset.php';

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

$token = trim($_POST['token'] ?? '');
$password = (string)($_POST['password'] ?? '');
$confirm = (string)($_POST['confirm_password'] ?? '');

if ($token === '' || $password === '' || $confirm === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Preencha todos os campos obrigatórios.']);
    exit;
}

if ($password !== $confirm) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'As senhas informadas não conferem.']);
    exit;
}

if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $password)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Sua senha deve ter pelo menos 8 caracteres incluindo letras maiúsculas, minúsculas e números.']);
    exit;
}

$request = pr_validate_token($token);
if (!$request) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Token inválido ou expirado. Solicite um novo link.']);
    exit;
}

if (!pr_update_password((int)$request['user_id'], $password)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Falha ao atualizar a senha. Tente novamente.']);
    exit;
}

pr_mark_used((int)$request['id']);

echo json_encode(['ok' => true]);
