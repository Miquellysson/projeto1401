<?php
// admin_layout.php — layout unificado (claro/escuro), sem dependências além de Tailwind CDN + nossos assets
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!function_exists('sanitize_html')) { function sanitize_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('setting_get')) { function setting_get($k,$d=null){ return $d; } }
if (!function_exists('cfg')) { function cfg(){ return []; } }

function admin_header($title='Admin'){
  $store = setting_get('store_name', (cfg()['store']['name'] ?? 'Get Power Research'));
  $themeColor = setting_get('theme_color', '#2060C8');
  $defaultPalette = [
      'DEFAULT' => '#2060C8',
      '50' => '#EEF4FF',
      '100' => '#DCE7FF',
      '200' => '#B9D0FF',
      '300' => '#96B8FF',
      '400' => '#6D9CFF',
      '500' => '#4883F0',
      '600' => '#2060C8',
      '700' => '#1C54B0',
      '800' => '#17448E',
      '900' => '#10326A',
  ];
  $brandPaletteGenerated = function_exists('generate_brand_palette') ? generate_brand_palette($themeColor) : [];
  $normalizedPalette = [];
  if (is_array($brandPaletteGenerated)) {
    foreach ($brandPaletteGenerated as $k => $v) {
      $normalizedPalette[(string)$k] = $v;
    }
  }
  $brandPalette = array_replace($defaultPalette, $normalizedPalette);
  $accentColor = function_exists('adjust_color_brightness') ? adjust_color_brightness($themeColor, 0.35) : '#4F88FF';
  $tailwindBrandJson = json_encode($brandPalette, JSON_UNESCAPED_SLASHES);
  $accentPaletteJson = json_encode(['400' => $accentColor], JSON_UNESCAPED_SLASHES);
  $adminThemeColor = $brandPalette['600'] ?? $brandPalette['DEFAULT'];
  echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8">';
  echo '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">';
  echo '<script src="https://cdn.tailwindcss.com"></script>';
  echo "<script>tailwind.config={theme:{extend:{colors:{brand:$tailwindBrandJson,accent:$accentPaletteJson}}}};</script>";
  echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">';
  $css = function_exists('asset_url') ? asset_url('assets/admin.css') : 'assets/admin.css';
  echo '<link rel="stylesheet" href="'.$css.'">';
  echo '<title>'.sanitize_html($title).' — Admin</title>';
  echo '<meta name="theme-color" content="'.sanitize_html($adminThemeColor).'">';
  echo '<style>:root{--brand:'.$brandPalette['DEFAULT'].';--brand-50:'.$brandPalette['50'].';--brand-600:'.$brandPalette['600'].';--brand-700:'.$brandPalette['700'].';--accent:'.$accentColor.';}</style>';
  echo '</head><body>';
  // Topbar
  echo '<header class="admin-top"><div class="wrap">';
  echo '<div class="brand"><div class="logo"><i class="fa-solid fa-capsules"></i></div><div><div class="name">'.sanitize_html($store).'</div><div class="sub">Painel Administrativo</div></div></div>';
  echo '<div class="actions">';
  echo '<a class="btn btn-ghost" href="admin.php?route=settings"><i class="fa-solid fa-gear"></i><span>Config</span></a>';
  echo '<a class="btn btn-ghost" href="index.php" target="_blank"><i class="fa-solid fa-store"></i><span>Loja</span></a>';
  echo '<a class="btn btn-ghost" href="admin.php?route=logout"><i class="fa-solid fa-right-from-bracket"></i><span>Sair</span></a>';
  echo '</div></div></header>';
  // Layout: sidebar + main
  echo '<div class="admin-grid"><aside class="admin-side"><nav>';
  $cur = basename($_SERVER['SCRIPT_NAME']);
  echo '<a class="'.($cur==='dashboard.php'?'active':'').'" href="'.cache_bust_url('dashboard.php').'"><i class="fa-solid fa-gauge-high"></i>Dashboard</a>';
  echo '<a class="'.($cur==='orders.php'?'active':'').'" href="'.cache_bust_url('orders.php').'"><i class="fa-solid fa-receipt"></i>Pedidos</a>';
  echo '<a class="'.($cur==='financial.php'?'active':'').'" href="'.cache_bust_url('financial.php').'"><i class="fa-solid fa-chart-line"></i>Financeiro</a>';
  echo '<a class="'.($cur==='products.php'?'active':'').'" href="'.cache_bust_url('products.php').'"><i class="fa-solid fa-pills"></i>Produtos</a>';
  echo '<a class="'.($cur==='categories.php'?'active':'').'" href="'.cache_bust_url('categories.php').'"><i class="fa-solid fa-tags"></i>Categorias</a>';
  echo '<a class="'.($cur==='customers.php'?'active':'').'" href="'.cache_bust_url('customers.php').'"><i class="fa-solid fa-users"></i>Clientes</a>';
  echo '<a class="'.($cur==='users.php'?'active':'').'" href="'.cache_bust_url('users.php').'"><i class="fa-solid fa-user-shield"></i>Usuários</a>';
  echo '<a class="'.($cur==='settings.php'?'active':'').'" href="settings.php?tab=general"><i class="fa-solid fa-sliders"></i>Configurações</a>';
  if (function_exists('is_super_admin') && is_super_admin()) {
    echo '<a class="'.($cur==='import_wordpress_web.php'?'active':'').'" href="'.cache_bust_url('import_wordpress_web.php').'"><i class="fa-solid fa-cloud-arrow-up"></i>Importar pedidos</a>';
    echo '<a class="'.($cur==='backup.php'?'active':'').'" href="'.cache_bust_url('backup.php').'"><i class="fa-solid fa-cloud-arrow-down"></i>Backups</a>';
  }
  echo '</nav></aside><main class="admin-main">';
}

function admin_footer(){
  echo '</main></div>';
  $js = function_exists('asset_url') ? asset_url('assets/admin.js') : 'assets/admin.js';
  echo '<script src="'.$js.'"></script>';
  echo '</body></html>';
}
