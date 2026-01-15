<?php

declare(strict_types=1);

require __DIR__.'/../bootstrap.php';
require __DIR__.'/../config.php';
require __DIR__.'/../lib/db.php';
require __DIR__.'/../lib/utils.php';

if (!function_exists('appadmin_str_starts_with')) {
    function appadmin_str_starts_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('appadmin_logo_url')) {
    function appadmin_logo_url(): string
    {
        $candidates = [];
        $settingLogo = setting_get('store_logo_url');
        if (is_string($settingLogo) && $settingLogo !== '') {
            $candidates[] = $settingLogo;
        }
        $candidates = array_merge($candidates, [
            'storage/logo/logo.png',
            'storage/logo/logo.jpg',
            'storage/logo/logo.jpeg',
            'storage/logo/logo.webp',
            'assets/logo.png',
            'assets/pwa/icon-192.png',
        ]);
        foreach ($candidates as $candidate) {
            $path = appadmin_str_starts_with($candidate, 'http')
                ? $candidate
                : '/'.ltrim($candidate, '/');
            if (appadmin_str_starts_with($path, 'http')) {
                return $path;
            }
            if (file_exists(__DIR__.'/..'.$path)) {
                return $path;
            }
        }
        return '/assets/pwa/icon-192.png';
    }
}

if (!function_exists('appadmin_require_login')) {
    function appadmin_require_login(): void
    {
        if (empty($_SESSION['admin_id'])) {
            header('Location: /admin.php?route=login&redirect=appadmin');
            exit;
        }
    }
}

appadmin_require_login();

$storeConfig  = cfg();
$storeName    = setting_get('store_name', $storeConfig['store']['name'] ?? 'Get Power Research');
$adminName    = $_SESSION['admin_name'] ?? ($_SESSION['user_name'] ?? 'Administrador');
$isSuperAdmin = function_exists('is_super_admin') ? is_super_admin() : false;
$currency     = strtoupper($storeConfig['store']['currency'] ?? 'USD');
$initials     = strtoupper(function_exists('mb_substr') ? mb_substr($adminName, 0, 2, 'UTF-8') : substr($adminName, 0, 2));
$pushConfig   = $storeConfig['push']['onesignal'] ?? [];
$pushAppId    = $pushConfig['app_id'] ?? '';
$pushSafariId = $pushConfig['safari_web_id'] ?? '';
$hasPush      = !empty($pushAppId);
$logoUrl      = appadmin_logo_url();
$quickLinks   = [
    ['href' => '/orders.php',        'label' => 'Pedidos',     'icon' => 'fa-receipt'],
    ['href' => '/products.php',      'label' => 'Produtos',    'icon' => 'fa-box-open'],
    ['href' => '/settings.php',      'label' => 'Configurações','icon' => 'fa-gear'],
    ['href' => '/settings.php?tab=payments', 'label' => 'Pagamentos', 'icon' => 'fa-credit-card'],
];
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title>App Admin • <?php echo sanitize_html($storeName); ?></title>
    <meta name="theme-color" content="#050816">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <link rel="manifest" href="/manifest-admin.webmanifest">
    <link rel="apple-touch-icon" href="<?php echo sanitize_html($logoUrl); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-Mo6Y0rum1+qsSVqS+8CA0nVddOZXS6jttuPAHyBs+K6TfGsDz3jAC5vVsQt1zArhcXd1LSeX776BFqe7aX0x6Q==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="/assets/appadmin.css">
</head>
<body>
    <div class="install-banner" id="installBanner" role="dialog" aria-live="polite">
        <div>
            <strong>Instale o App Admin</strong>
            <p>Adicione o painel à tela inicial para abrir como app nativo.</p>
            <small id="installHint">No Android, toque em “Instalar” e confirme o atalho.</small>
        </div>
        <div class="banner-actions">
            <button class="pill primary" id="installAction" type="button">Instalar agora</button>
            <button class="pill ghost" id="installClose" type="button">Depois</button>
        </div>
    </div>
    <div class="app-shell">
        <header class="topbar">
            <div class="brand">
                <div class="logo-chip">
                    <img src="<?php echo sanitize_html($logoUrl); ?>" alt="Logo">
                </div>
                <div>
                    <div class="brand-title"><?php echo sanitize_html($storeName); ?></div>
                    <div class="brand-sub">Admin mobile</div>
                </div>
            </div>
            <div class="top-actions">
                <button id="notifyBtn" class="pill ghost" type="button">Ativar notificações</button>
                <div class="user-chip">
                    <span class="avatar"><?php echo sanitize_html($initials); ?></span>
                    <div>
                        <div class="user-name"><?php echo sanitize_html($adminName); ?></div>
                        <div class="user-role"><?php echo $isSuperAdmin ? 'Super admin' : 'Admin'; ?></div>
                    </div>
                </div>
                <div id="syncIndicator" class="sync-pill" data-state="idle">
                    <span class="dot"></span>
                    <span data-sync-text>Sincronizando…</span>
                </div>
            </div>
        </header>

        <main class="dash-grid">
            <section class="panel hero-panel card">
                <div class="hero-content">
                    <p class="eyebrow">PWA exclusivo da loja</p>
                    <h1>Comande pedidos e alertas em qualquer lugar</h1>
                    <p class="lede">Acompanhe pedidos em tempo real, receba alertas e execute ações rápidas pelo seu dispositivo.</p>
                    <div class="hero-actions">
                        <a class="btn primary" href="/orders.php"><i class="fa-solid fa-bolt"></i> Acessar pedidos</a>
                        <a class="btn ghost" href="/settings.php"><i class="fa-solid fa-sliders"></i> Ajustar loja</a>
                    </div>
                    <div class="quick-actions">
                        <?php foreach ($quickLinks as $link): ?>
                            <a href="<?php echo sanitize_html($link['href']); ?>" class="quick-chip">
                                <i class="fa-solid <?php echo sanitize_html($link['icon']); ?>"></i>
                                <?php echo sanitize_html($link['label']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="hero-meta">
                    <div>
                        <span class="label">Último sync</span>
                        <strong id="lastSync">—</strong>
                    </div>
                    <div>
                        <span class="label">Status do servidor</span>
                        <strong id="serverStatus">Monitorando…</strong>
                    </div>
                    <div class="status-chips" id="statusBreakdown"></div>
                </div>
            </section>

            <section class="panel card kpis">
                <div class="kpi-card">
                    <p>Pedidos pendentes</p>
                    <strong data-metric="pending_orders">—</strong>
                    <span>Atualização automática</span>
                </div>
                <div class="kpi-card">
                    <p>Pagos hoje</p>
                    <strong data-metric="paid_today">—</strong>
                    <span>Últimas 24h</span>
                </div>
                <div class="kpi-card">
                    <p>Receita do dia</p>
                    <strong data-metric="revenue_today">—</strong>
                    <span>Liquidação confirmada</span>
                </div>
                <div class="kpi-card">
                    <p>Pedidos / hora</p>
                    <strong data-metric="orders_last_hour">—</strong>
                    <span>Fluxo recente</span>
                </div>
            </section>

            <section class="panel card totals-panel">
                <div class="mini-kpis">
                    <article class="mini-kpi">
                        <span>Pedidos</span>
                        <strong data-total="orders">—</strong>
                        <small>Total registrados</small>
                    </article>
                    <article class="mini-kpi">
                        <span>Clientes</span>
                        <strong data-total="customers">—</strong>
                        <small>Base cadastrada</small>
                    </article>
                    <article class="mini-kpi">
                        <span>Produtos ativos</span>
                        <strong data-total="products">—</strong>
                        <small>No catálogo</small>
                    </article>
                    <article class="mini-kpi">
                        <span>Categorias</span>
                        <strong data-total="categories">—</strong>
                        <small>Vitrines publicadas</small>
                    </article>
                </div>
            </section>

            <section class="panel card orders-panel">
                <div class="panel-header">
                    <div>
                        <h2>Pedidos em tempo real</h2>
                        <p>Monitoramento contínuo e alertas instantâneos</p>
                    </div>
                    <a class="chip-link" href="/orders.php"><i class="fa-solid fa-arrow-up-right-from-square"></i> Ver tudo</a>
                </div>
                <div id="ordersStream" class="orders-stream" aria-live="polite"></div>
            </section>

            <section class="panel card alerts-panel">
                <div class="panel-header">
                    <div>
                        <h2>Alertas críticos</h2>
                        <p>Pedidos que exigem atenção imediata</p>
                    </div>
                    <span class="chip chip-warning" id="alertsCount">0 alertas</span>
                </div>
                <div class="alerts-grid">
                    <div>
                        <h3>Pendentes fora do SLA</h3>
                        <ul id="pendingAlerts" class="alert-list"></ul>
                    </div>
                    <div>
                        <h3>Pagamentos com falha</h3>
                        <ul id="failedAlerts" class="alert-list"></ul>
                    </div>
                </div>
            </section>

            <?php if ($isSuperAdmin): ?>
            <section class="panel card health-panel" id="healthPanel">
                <div class="panel-header">
                    <div>
                        <h2>Saúde do sistema</h2>
                        <p>Visão rápida de riscos e infraestrutura</p>
                    </div>
                    <a class="chip-link" href="/superadmin.php"><i class="fa-solid fa-display"></i> Abrir painel master</a>
                </div>
                <div class="health-grid">
                    <div class="health-card">
                        <span>Erros na última hora</span>
                        <strong data-health="errors_last_hour">—</strong>
                    </div>
                    <div class="health-card">
                        <span>Pedidos com erro</span>
                        <strong data-health="orders_with_error">—</strong>
                    </div>
                    <div class="health-card">
                        <span>Disco livre</span>
                        <strong data-health="disk_free_percent">—</strong>
                    </div>
                    <div class="health-card">
                        <span>Versão do PHP</span>
                        <strong data-health="php_version"><?php echo PHP_VERSION; ?></strong>
                    </div>
                </div>
            </section>
            <?php endif; ?>
        </main>

        <footer class="app-footer">
            <span>Get Power Research • App Admin</span>
            <span>Logado como <?php echo sanitize_html($adminName); ?></span>
        </footer>
    </div>

    <div id="toastStack" class="toast-stack" aria-live="polite" aria-atomic="true"></div>

    <?php if ($hasPush): ?>
    <script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
    <script>
        window.OneSignalDeferred = window.OneSignalDeferred || [];
        OneSignalDeferred.push(async function(OneSignal) {
            await OneSignal.init(<?php
                $osConfig = ['appId' => $pushAppId];
                if (!empty($pushSafariId)) {
                    $osConfig['safariWebId'] = $pushSafariId;
                }
                echo json_encode($osConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            ?>);
        });
    </script>
    <?php endif; ?>
    <script>
        window.__ADMIN_APP__ = <?php
            echo json_encode(
                [
                    'apiUrl'       => '/api/admin_app_feed.php',
                    'pollInterval' => 10000,
                    'currency'     => $currency,
                    'isSuperAdmin' => $isSuperAdmin,
                    'adminName'    => $adminName,
                    'push'         => [
                        'appId'       => $pushAppId,
                        'safariWebId' => $pushSafariId,
                    ],
                ],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        ?>;
    </script>
    <script type="module" src="/assets/appadmin.js" defer></script>
</body>
</html>
