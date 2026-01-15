<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/config.php';
require __DIR__.'/lib/db.php';
require __DIR__.'/lib/utils.php';
require __DIR__.'/lib/admin_app.php';

if (!function_exists('require_super_admin')) {
    http_response_code(403);
    exit('Super admin guard ausente.');
}

if (empty($_SESSION['admin_id'])) {
    header('Location: /admin.php?route=login&redirect=superadmin');
    exit;
}

require_super_admin();

$superEmail = 'mike@mmlins.com.br';
$superPass  = 'mkcd61la';
$authKey    = 'superadmin_gate';
$loginError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['superadmin_auth'])) {
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = trim((string)($_POST['password'] ?? ''));
    if (hash_equals($superEmail, $email) && hash_equals($superPass, $pass)) {
        $_SESSION[$authKey] = true;
        header('Location: /superadmin.php');
        exit;
    } else {
        $loginError = 'Credenciais inválidas.';
    }
}

if (empty($_SESSION[$authKey])) {
    ?>
    <!doctype html>
    <html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Command Center • Login</title>
        <style>
            body{margin:0;min-height:100vh;background:#020617;font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#f8fafc;display:flex;align-items:center;justify-content:center;padding:20px}
            .card{background:#0f172a;border:1px solid rgba(148,163,184,.2);border-radius:16px;padding:24px;max-width:360px;width:100%;box-shadow:0 25px 60px rgba(2,6,23,.6)}
            label{display:block;font-size:13px;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;color:#94a3b8}
            input{width:100%;padding:10px 12px;border-radius:10px;border:1px solid rgba(148,163,184,.3);background:#020617;color:#f8fafc;margin-bottom:14px}
            button{width:100%;padding:12px;border-radius:10px;border:0;background:linear-gradient(135deg,#0ea5e9,#6366f1);color:#fff;font-weight:600;cursor:pointer}
            .error{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.4);padding:10px;border-radius:10px;font-size:13px;margin-bottom:12px;color:#fecaca}
        </style>
    </head>
    <body>
        <form class="card" method="post">
            <h2 style="margin-top:0;margin-bottom:16px;">Acesso restrito</h2>
            <?php if ($loginError): ?>
            <div class="error"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <input type="hidden" name="superadmin_auth" value="1">
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" required>
            <label for="password">Senha</label>
            <input id="password" name="password" type="password" required>
            <button type="submit">Entrar</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

$storeName = setting_get('store_name', cfg()['store']['name'] ?? 'Get Power Research');
$adminName = $_SESSION['admin_name'] ?? 'Super Admin';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title>Command Center • <?php echo sanitize_html($storeName); ?></title>
    <meta name="theme-color" content="#000007">
    <link rel="stylesheet" href="/assets/superadmin.css?v=1.2">
</head>
<body class="control-room">
    <div class="grid-overlay"></div>
    <div class="scanline"></div>

    <main class="hud">
        <header class="hud-header">
            <div>
                <p class="eyebrow">Super admin master</p>
                <h1>Command Center — <?php echo sanitize_html($storeName); ?></h1>
                <p class="lede">Monitoramento total: pedidos, usuários, alertas, erros e infraestrutura em tempo real.</p>
            </div>
            <div class="hud-actions">
                <div class="operator">Logado como <?php echo sanitize_html($adminName); ?></div>
                <button id="soundToggle" class="btn ghost">Som: desligado</button>
                <button id="fullscreenToggle" class="btn ghost">Modo tela cheia</button>
                <a class="btn primary" href="/appadmin/" target="_self">Abrir App Admin</a>
            </div>
        </header>

        <section class="stats-grid">
            <article class="stat-card">
                <span data-label="orders_total">Pedidos</span>
                <strong data-stat="orders">—</strong>
                <small>Total registrado</small>
            </article>
            <article class="stat-card">
                <span data-label="customers_total">Clientes</span>
                <strong data-stat="customers">—</strong>
                <small>Base ativa</small>
            </article>
            <article class="stat-card">
                <span data-label="products_total">Produtos</span>
                <strong data-stat="products">—</strong>
                <small>Disponíveis</small>
            </article>
            <article class="stat-card">
                <span data-label="users_total">Usuários</span>
                <strong data-stat="users">—</strong>
                <small>Contas internas</small>
            </article>
            <article class="stat-card">
                <span data-label="revenue_last_24h">Receita (24h)</span>
                <strong data-stat="revenue_last_24h">—</strong>
                <small>Pagamentos confirmados</small>
            </article>
            <article class="stat-card">
                <span data-label="orders_last_24h">Pedidos (24h)</span>
                <strong data-stat="orders_last_24h">—</strong>
                <small>Fluxo nas últimas 24h</small>
            </article>
            <article class="stat-card">
                <span>Admins ativos</span>
                <strong data-stat="active_admins">—</strong>
                <small>Equipe com acesso</small>
            </article>
            <article class="stat-card danger">
                <span data-label="failed_today">Falhas hoje</span>
                <strong data-stat="failed_today">—</strong>
                <small>Pagamentos/erros</small>
            </article>
        </section>

        <section class="panel commission-panel">
            <div class="panel-header">
                <div>
                    <h2>Comissão & Período</h2>
                    <p>Defina o intervalo e acompanhe a comissão filtrada.</p>
                </div>
                <div class="period-badge" data-stat="commission_badge">Todo período</div>
            </div>
            <div class="commission-body">
                <div class="commission-metric">
                    <div class="metric-label">Comissão total</div>
                    <div class="metric-value" data-stat="commission_total">—</div>
                    <div class="metric-meta">$10 por pedido pago/enviado</div>
                    <div class="metric-meta" data-stat="commission_period">Período: todo o histórico</div>
                </div>
                <form id="commissionFilter" class="commission-filter">
                    <div class="field">
                        <label for="commissionStart">Início</label>
                        <input id="commissionStart" name="commission_start" type="date" autocomplete="off">
                    </div>
                    <div class="field">
                        <label for="commissionEnd">Fim</label>
                        <input id="commissionEnd" name="commission_end" type="date" autocomplete="off">
                    </div>
                    <div class="actions">
                        <button class="btn ghost small" type="submit">Aplicar</button>
                        <button class="btn ghost small" type="button" data-action="clear-commission">Limpar</button>
                    </div>
                    <div class="quick-range">
                        <span>Atalhos</span>
                        <button class="chip-btn" type="button" data-range="today">Hoje</button>
                        <button class="chip-btn" type="button" data-range="7d">7 dias</button>
                        <button class="chip-btn" type="button" data-range="30d">30 dias</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="grid-panels">
            <article class="panel status-board">
                <div class="panel-header">
                    <div>
                        <h2>Status dos pedidos</h2>
                        <p>Distribuição e volume por status</p>
                    </div>
                    <span class="chip" id="serverClock">--:--</span>
                </div>
                <div id="statusBars" class="status-bars"></div>
            </article>

            <article class="panel alerts">
                <div class="panel-header">
                    <div>
                        <h2>Alertas e incidentes</h2>
                        <p>Pedidos fora do SLA e pagamentos com erro</p>
                    </div>
                    <span class="chip warning" id="alertsBadge">0 alertas</span>
                </div>
                <div class="alerts-columns">
                    <div>
                        <h3>Pendentes críticos</h3>
                        <ul id="superPendingAlerts" class="alert-feed"></ul>
                    </div>
                    <div>
                        <h3>Pagamentos falhos/chargeback</h3>
                        <ul id="superFailedAlerts" class="alert-feed"></ul>
                    </div>
                </div>
            </article>

            <article class="panel activity">
                <div class="panel-header">
                    <div>
                        <h2>Feed em tempo real</h2>
                        <p>Novos pedidos e atualizações</p>
                    </div>
                </div>
                <div id="controlFeed" class="activity-feed" aria-live="polite"></div>
            </article>

            <article class="panel system">
                <div class="panel-header">
                    <div>
                        <h2>Saúde da plataforma</h2>
                        <p>Infraestrutura, recursos e logs</p>
                    </div>
                </div>
                <div class="system-grid">
                    <div class="system-card">
                        <span data-health-label="errors_last_hour">Erros (última hora)</span>
                        <strong data-health="errors_last_hour">—</strong>
                    </div>
                    <div class="system-card">
                        <span data-health-label="orders_with_error">Pedidos com erro</span>
                        <strong data-health="orders_with_error">—</strong>
                    </div>
                    <div class="system-card">
                        <span>Disco livre</span>
                        <strong data-health="disk_free_percent">—</strong>
                    </div>
                    <div class="system-card">
                        <span>Versão PHP</span>
                        <strong data-health="php_version">—</strong>
                    </div>
                </div>
                <div class="stack-info" id="stackInfo"></div>
            </article>
        </section>
    </main>

    <div class="command-footer">
        <span>© <?php echo date('Y'); ?> — Centro de Operações</span>
        <span id="syncReport">Sincronizando…</span>
    </div>

    <script>
        window.__SUPER_APP__ = <?php
            echo json_encode(
                [
                    'apiUrl'   => '/api/super_admin_feed.php',
                    'currency' => strtoupper(cfg()['store']['currency'] ?? 'USD'),
                ],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        ?>;
    </script>
    <script type="module" src="/assets/superadmin.js?v=1.2" defer></script>
</body>
</html>
