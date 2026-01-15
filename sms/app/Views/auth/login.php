<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - SMS Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= rtrim($baseUrl, '/') ?>/css/app.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="auth-wrapper">
    <div class="auth-card card shadow-sm">
        <div class="card-body p-4">
            <h4 class="mb-3 text-center">Login</h4>
            <p class="text-muted text-center small mb-4">Acesse a área administrativa de qualquer dispositivo.</p>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post" action="<?= $baseUrl ?>?route=login" class="d-grid gap-3">
                <div>
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div>
                    <label class="form-label">Senha</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Entrar</button>
            </form>
        </div>
    </div>
    <p class="text-center mt-3 text-muted small">Use o usuário seed do SQL para acessar.</p>
</div>
</body>
</html>
