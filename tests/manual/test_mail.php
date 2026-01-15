<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__.'/lib/utils.php';

$status = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = trim($_POST['to'] ?? '');
    $subject = trim($_POST['subject'] ?? 'Teste de envio');
    $message = trim($_POST['message'] ?? 'Mensagem de teste via PHP mail().');

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $error = 'Informe um e-mail válido no campo "Para".';
    } else {
        $html = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
        $sent = send_email($to, $subject, '<p>'.$html.'</p>');
        $status = $sent ? 'Enviado com sucesso.' : 'mail() retornou false – verifique os logs ou o servidor.';
    }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Teste de envio de e-mail</title>
    <style>
        body { font-family: Arial, sans-serif; background:#f3f4f6; padding:40px; }
        .card { max-width:500px; margin:0 auto; background:#fff; border-radius:12px; padding:24px; box-shadow:0 10px 25px rgba(15,23,42,.12); }
        label { font-size:14px; font-weight:600; display:block; margin-bottom:6px; }
        input, textarea { width:100%; padding:10px; border:1px solid #cbd5f5; border-radius:8px; margin-bottom:14px; font-size:14px; }
        button { background:#2563eb; color:#fff; border:none; padding:10px 16px; border-radius:8px; cursor:pointer; font-weight:600; }
        .alert { padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:14px; }
        .alert-success { background:#ecfdf5; color:#065f46; border:1px solid #34d399; }
        .alert-error { background:#fef2f2; color:#b91c1c; border:1px solid #f87171; }
    </style>
</head>
<body>
<div class="card">
    <h1 style="margin-top:0;">Teste rápido de envio</h1>
    <p>Preencha os campos abaixo e envie para verificar se o servidor está aceitando <code>mail()</code>.</p>
    <?php if ($status): ?>
        <div class="alert <?= $status === 'Enviado com sucesso.' ? 'alert-success' : 'alert-error'; ?>">
            <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>
    <form method="post">
        <label>Para</label>
        <input type="email" name="to" value="<?= htmlspecialchars($_POST['to'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="destinatario@exemplo.com" required>

        <label>Assunto</label>
        <input type="text" name="subject" value="<?= htmlspecialchars($_POST['subject'] ?? 'Teste de envio', ENT_QUOTES, 'UTF-8'); ?>">

        <label>Mensagem</label>
        <textarea name="message" rows="4"><?= htmlspecialchars($_POST['message'] ?? 'Mensagem de teste via PHP mail().', ENT_QUOTES, 'UTF-8'); ?></textarea>

        <button type="submit">Enviar</button>
    </form>
</div>
</body>
</html>
