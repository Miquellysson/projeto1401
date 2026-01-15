<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SMS Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= rtrim($baseUrl, '/') ?>/css/app.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="<?= $baseUrl ?>?route=dashboard">SMS Manager</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbars" aria-controls="navbars" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbars">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php if (!empty($_SESSION['user_id'])): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>?route=dashboard">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>?route=contacts">Contatos</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>?route=campaigns/new">Nova Campanha</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>?route=campaigns">Histórico</a></li>
                <?php endif; ?>
            </ul>
            <span class="navbar-text text-white">
                <?php if (!empty($_SESSION['user_email'])): ?>
                    <?= htmlspecialchars($_SESSION['user_email']) ?> |
                    <a class="text-decoration-none text-warning" href="<?= $baseUrl ?>?route=logout">Sair</a>
                <?php endif; ?>
            </span>
        </div>
    </div>
</nav>
<div class="container page-shell">
