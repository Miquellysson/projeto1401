<?php
// admin_layout.php — Layout unificado do painel (sem dependências de build/NPM)
// Como usar em QUALQUER página do admin (ex: products.php):
//   require __DIR__.'/admin_layout.php';
//   admin_header('Produtos');
//   ... seu conteúdo HTML/PHP ...
//   admin_footer();

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function admin_header(string $title = 'Painel') {
  // Descobre usuário logado (se existir)
  $userName = $_SESSION['admin_name'] ?? ($_SESSION['user_name'] ?? 'Administrador');
  $storeName = function_exists('setting_get') ? (setting_get('store_name', 'Get Power Research')) : 'Get Power Research';

  echo '<!doctype html><html lang="pt-br"><head>';
  echo '  <meta charset="utf-8">';
  echo '  <meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '  <title>'.htmlspecialchars($title).' • Admin</title>';
  echo '  <meta name="theme-color" content="#b91c1c">';
  echo '  <link rel="preconnect" href="https://fonts.googleapis.com">';
  echo '  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
  echo '  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">';
  echo '  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">';
  echo '  <link href="assets/admin.css" rel="stylesheet">';
  echo '  <script defer src="assets/admin.js"></script>';
  echo '</head>';
  echo '<body class="is-light">';
  echo '  <aside class="aside">';
  echo '    <div class="brand">';
  echo '      <div class="logo"><i class="fa-solid fa-capsules"></i></div>';
  echo '      <div class="brand-text">'.htmlspecialchars($storeName).'</div>';
  echo '    </div>';
  echo '    <nav class="menu">';
  echo '      <a class="item" href="dashboard.php"><i class="fa-solid fa-gauge-high"></i><span>Dashboard</span></a>';
  echo '      <a class="item" href="orders.php"><i class="fa-solid fa-receipt"></i><span>Pedidos</span></a>';
  echo '      <a class="item" href="products.php"><i class="fa-solid fa-boxes-stacked"></i><span>Produtos</span></a>';
  echo '      <a class="item" href="categories.php"><i class="fa-solid fa-tags"></i><span>Categorias</span></a>';
  echo '      <a class="item" href="customers.php"><i class="fa-solid fa-users"></i><span>Clientes</span></a>';
  echo '      <a class="item" href="users.php"><i class="fa-solid fa-user-shield"></i><span>Usuários</span></a>';
  echo '      <a class="item" href="settings.php"><i class="fa-solid fa-gear"></i><span>Configurações</span></a>';
  echo '    </nav>';
  echo '  </aside>';

  echo '  <div class="shell">';
  echo '    <header class="topbar">';
  echo '      <div class="title">'.htmlspecialchars($title).'</div>';
  echo '      <div class="actions">';
  echo '        <button class="btn ghost" id="btnTheme" title="Alternar tema"><i class="fa-solid fa-sun"></i></button>';
  echo '        <a class="btn" href="../index.php" target="_blank"><i class="fa-solid fa-store mr"></i> Visitar Loja</a>';
  echo '        <div class="divider"></div>';
  echo '        <div class="user"><i class="fa-solid fa-circle-user"></i><span>'.htmlspecialchars($userName).'</span></div>';
  echo '      </div>';
  echo '    </header>';
  echo '    <main class="content">';
}

function admin_footer() {
  echo '    </main>';
  echo '    <footer class="foot">';
  echo '      <div>&copy; '.date('Y').' Get Power Research • Painel Administrativo</div>';
  echo '      <div class="muted">v1.0</div>';
  echo '    </footer>';
  echo '  </div>';
  echo '</body></html>';
}
