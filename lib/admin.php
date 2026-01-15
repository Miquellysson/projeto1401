<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require __DIR__.'/bootstrap.php';
require __DIR__.'/config.php';
require __DIR__.'/lib/db.php';
require __DIR__.'/lib/utils.php';
require __DIR__.'/lib/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ===== Helpers seguros (guards) ===== */
if (!function_exists('sanitize_html')) { function sanitize_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('cfg')) { function cfg(){ return []; } }
if (!function_exists('setting_get')) { function setting_get($k,$d=null){ return $d; } }
if (!function_exists('setting_set')) { function setting_set($k,$v){ return true; } }
if (!function_exists('validate_email')) { function validate_email($e){ return filter_var($e, FILTER_VALIDATE_EMAIL); } }
if (!function_exists('csrf_token')) { function csrf_token(){ if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; } }
if (!function_exists('csrf_check')) { function csrf_check($t){ return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$t); } }

function find_logo_path(){
  $opt = setting_get('store_logo_url');
  if ($opt && file_exists(__DIR__.'/'.$opt)) return $opt;
  foreach (['storage/logo/logo.png','storage/logo/logo.jpg','storage/logo/logo.jpeg','storage/logo/logo.webp','assets/logo.png'] as $c) {
    if (file_exists(__DIR__.'/'.$c)) return $c;
  }
  return null;
}
function is_admin(){ return !empty($_SESSION['admin_id']); }
function require_admin(){ if (!is_admin()) { header('Location: admin.php?route=login'); exit; } }

/* ===== Router ===== */
$route = $_GET['route'] ?? (is_admin() ? 'dashboard' : 'login');
$allowed = ['login','logout','dashboard','settings','api_ping'];
if (!in_array($route, $allowed, true)) $route = is_admin() ? 'dashboard' : 'login';

/* ===== Layout ===== */
function admin_header($title='Admin - Get Power Research', $withLayout=true){
  $logo = find_logo_path();
  $cfg  = function_exists('cfg') ? cfg() : [];
  $storeName = setting_get('store_name', $cfg['store']['name'] ?? 'Get Power Research');
  $currentScript = basename($_SERVER['SCRIPT_NAME']);
  $route = $_GET['route'] ?? '';

  // Flag para o footer
  $GLOBALS['_ADMIN_WITH_LAYOUT'] = $withLayout;

  echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8">';
  echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<link rel="manifest" href="/manifest-admin.webmanifest">';
  echo '<meta name="theme-color" content="#2060C8">';
  echo '<script src="https://cdn.tailwindcss.com"></script>';
  echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">';
  echo '<title>'.sanitize_html($title).'</title>';
  echo '<style>
    :root{--bg:#f4f7ff;--fg:#0f172a;--muted:#64748b;--line:#dbe4ff;--brand:#2060C8;--brand-700:#16469a;--accent:#4f88ff}
    html[data-theme=dark]{--bg:#101522;--fg:#E5E7EB;--muted:#9ca3af;--line:#1f2a44}
    body{background:var(--bg);color:var(--fg);margin:0;min-height:100vh;padding-bottom:env(safe-area-inset-bottom,0px)}
    .topbar{background:rgba(244,247,255,.9);backdrop-filter:saturate(180%) blur(10px);border-bottom:1px solid rgba(32,96,200,.1)}
    html[data-theme=dark] .topbar{background:rgba(16,21,34,.88)}
    .brand-chip{display:flex;align-items:center;gap:.6rem}
    .brand-chip .logo{width:42px;height:42px;border-radius:.9rem;display:grid;place-items:center;background:linear-gradient(135deg,var(--brand),var(--accent));color:#fff}
    .topbar-inner{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
    .topbar-actions{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;justify-content:flex-end}
    .layout{display:grid;grid-template-columns:260px 1fr;gap:1rem}
    @media (max-width:1024px){.layout{grid-template-columns:1fr} .sidebar{position:sticky;top:64px}}
    .card{background:#fff;border:1px solid var(--line);border-radius:1rem;box-shadow:0 25px 50px -35px rgba(15,23,42,.35)}
    html[data-theme=dark] .card{background:#131a2d}
    .btn{display:inline-flex;align-items:center;gap:.5rem;font-weight:600;border-radius:.9rem;transition:all .2s}
    .btn-primary{background:var(--brand);color:#fff;padding:.6rem 1rem;box-shadow:0 18px 30px -22px rgba(32,96,200,.9)}
    .btn-primary:hover{background:var(--brand-700)}
    .btn-ghost{border:1px solid rgba(32,96,200,.18);padding:.5rem .9rem;background:#fff;color:var(--brand-700)}
    .btn-ghost:hover{background:rgba(32,96,200,.08)}
    .nav a{display:flex;align-items:center;gap:.6rem;padding:.6rem .8rem;border-radius:.6rem}
    .nav a:hover{background:rgba(32,96,200,.08)}
    .nav a.active{background:linear-gradient(135deg,rgba(32,96,200,.18),rgba(79,136,255,.18));border:1px solid rgba(32,96,200,.28);color:var(--brand-700)}
    .link-muted{color:var(--muted)}
    /* HERO */
    .hero{border-radius:1rem; overflow:hidden}
    .hero-bg{background:linear-gradient(135deg, rgba(32,96,200,1), rgba(79,136,255,1));}
    .hero .glass{background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.25)}
    html[data-theme=dark] .hero .glass{background:rgba(19,26,45,.45); border-color:rgba(79,136,255,.25)}
    .hero-actions{flex-wrap:wrap;gap:.5rem}
    .hero-actions .glass{min-width:180px}
    .auth-card{max-width:420px;width:100%}
    @media (max-width:768px){
      .topbar-actions .btn span{display:none}
      .topbar-actions{width:100%;justify-content:flex-end}
    }
    @media (max-width:640px){
      .topbar-inner{padding-left:.75rem;padding-right:.75rem}
      .topbar-actions .btn{padding:.5rem .75rem}
      .layout{gap:.75rem}
      .hero-actions{flex-direction:column;width:100%}
      .hero-actions .glass{width:100%;justify-content:center}
      .brand-chip{width:100%}
      .auth-card{padding:1.25rem}
    }
  </style>';
  echo '<script>
  (function(){
    var key="ff-theme";
    function apply(t){document.documentElement.setAttribute("data-theme",t);}
    apply(localStorage.getItem(key)||"light");
    document.addEventListener("click",e=>{var b=e.target.closest("[data-action=toggle-theme]"); if(!b) return; var t=(localStorage.getItem(key)||"light")==="light"?"dark":"light"; localStorage.setItem(key,t); apply(t);});
    if("serviceWorker" in navigator){ window.addEventListener("load",()=>navigator.serviceWorker.register("sw.js").catch(()=>{})); }
    var deferred=null, btn=null;
    window.addEventListener("beforeinstallprompt",e=>{e.preventDefault();deferred=e;btn=document.querySelector("[data-action=install-app]"); btn&&btn.classList.remove("hidden");});
    document.addEventListener("click",async e=>{var b=e.target.closest("[data-action=install-app]"); if(!b||!deferred) return; deferred.prompt(); try{await deferred.userChoice;}catch(_){ } deferred=null; b.classList.add("hidden");});
  })();
  </script>';
  echo '</head><body>';

  // Topbar
  echo '<div class="topbar sticky top-0 z-40 border-b border-[var(--line)]">';
  echo '  <div class="topbar-inner max-w-7xl mx-auto px-4 py-3">';
  echo '    <div class="brand-chip">';
  echo '      <div class="logo"><i class="fa-solid fa-capsules"></i></div>';
  echo '      <div><div class="font-bold leading-tight">'.sanitize_html($storeName).'</div><div class="text-xs link-muted">Painel Administrativo</div></div>';
  echo '    </div>';
  echo '    <div class="topbar-actions flex items-center gap-2">';
  echo '      <button class="btn btn-ghost hidden" data-action="install-app" title="Adicionar à tela inicial"><i class="fa-solid fa-mobile-screen-button"></i><span class="hidden sm:inline">Instalar</span></button>';
  echo '      <button class="btn btn-ghost" data-action="toggle-theme" title="Alternar tema"><i class="fa-solid fa-circle-half-stroke"></i></button>';
  echo '      <a class="btn btn-ghost" href="index.php" target="_blank"><i class="fa-solid fa-up-right-from-square"></i><span class="hidden sm:inline">Loja</span></a>';
  echo '      <a class="btn btn-ghost" href="admin.php?route=logout"><i class="fa-solid fa-right-from-bracket"></i><span class="hidden sm:inline">Sair</span></a>';
  echo '    </div>';
  echo '  </div>';
  echo '</div>';

  // Abre layout completo (sidebar+main) ou só container simples
  if ($withLayout){
    echo '<div class="max-w-7xl mx-auto p-4 layout">';
    echo '  <aside class="sidebar"><nav class="card p-3 nav">';
    $active = function($targets) use($currentScript,$route){
      foreach ((array)$targets as $t) {
        if ($t==='settings' && $route==='settings') return 'active';
        if (strcasecmp($currentScript,$t)===0) return 'active';
      }
      return '';
    };
    echo '    <a class="'.$active(['dashboard.php']).'" href="dashboard.php"><i class="fa-solid fa-gauge-high"></i><span>Dashboard</span></a>';
    echo '    <a class="'.$active(['orders.php']).'" href="orders.php"><i class="fa-solid fa-receipt"></i><span>Pedidos</span></a>';
    echo '    <a class="'.$active(['financial.php']).'" href="financial.php"><i class="fa-solid fa-chart-line"></i><span>Financeiro</span></a>';
    if (is_super_admin()) {
      echo '    <a class="'.$active(['import_wordpress_web.php']).'" href="import_wordpress_web.php"><i class="fa-solid fa-cloud-arrow-up"></i><span>Importar pedidos</span></a>';
    }
    echo '    <a class="'.$active(['products.php']).'" href="products.php"><i class="fa-solid fa-pills"></i><span>Produtos</span></a>';
    echo '    <a class="'.$active(['categories.php']).'" href="categories.php"><i class="fa-solid fa-tags"></i><span>Categorias</span></a>';
    echo '    <a class="'.$active(['customers.php']).'" href="customers.php"><i class="fa-solid fa-users"></i><span>Clientes</span></a>';
    echo '    <a class="'.$active(['users.php']).'" href="users.php"><i class="fa-solid fa-user-shield"></i><span>Usuários</span></a>';
    echo '    <a class="'.$active(['settings']).'" href="settings.php?tab=general"><i class="fa-solid fa-gear"></i><span>Configurações</span></a>';
    echo '  </nav></aside>';
    echo '  <main>';
  } else {
    echo '<div class="max-w-7xl mx-auto p-4">';
  }
}

/* HERO reutilizável no admin (estilo do index) */
function admin_hero($title, $subtitle='Gerencie sua loja com rapidez', $showQuickActions=true) {
  echo '<section class="hero mb-6">';
  echo '  <div class="hero-bg p-1">';
  echo '    <div class="rounded-2xl bg-white/10 text-white px-5 py-6">';
  echo '      <div class="flex items-start justify-between gap-4 flex-wrap">';
  echo '        <div>';
  echo '          <h1 class="text-2xl md:text-3xl font-bold">'.sanitize_html($title).'</h1>';
  echo '          <p class="text-white/90 mt-1">'.sanitize_html($subtitle).'</p>';
  echo '        </div>';
  echo '        <div class="hero-actions flex items-center gap-2">';
  echo '          <a href="orders.php" class="glass px-4 py-2 rounded-xl inline-flex items-center gap-2"><i class="fa-solid fa-receipt"></i> Pedidos</a>';
  echo '          <a href="products.php" class="glass px-4 py-2 rounded-xl inline-flex items-center gap-2"><i class="fa-solid fa-pills"></i> Produtos</a>';
  echo '          <a href="settings.php?tab=builder" class="glass px-4 py-2 rounded-xl inline-flex items-center gap-2"><i class="fa-solid fa-paintbrush"></i> Editor da Home</a>';
  echo '          <a href="settings.php?tab=payments" class="glass px-4 py-2 rounded-xl inline-flex items-center gap-2"><i class="fa-solid fa-credit-card"></i> Pagamentos</a>';
  echo '          <a href="settings.php?tab=general" class="glass px-4 py-2 rounded-xl inline-flex items-center gap-2"><i class="fa-solid fa-gear"></i> Configurações</a>';
  echo '          <a href="index.php" target="_blank" class="glass px-4 py-2 rounded-xl inline-flex items-center gap-2"><i class="fa-solid fa-store"></i> Ver loja</a>';
  echo '        </div>';
  echo '      </div>';
  if ($showQuickActions) {
    echo '    <div class="mt-4 grid sm:grid-cols-2 lg:grid-cols-5 gap-3">';
    echo '      <a href="orders.php" class="glass rounded-xl p-4 block">';
    echo '        <div class="text-sm opacity-90">Hoje</div>';
    echo '        <div class="flex items-center justify-between mt-1">';
    echo '          <div class="text-lg font-semibold">Pedidos</div>';
    echo '          <i class="fa-solid fa-arrow-right-long"></i>';
    echo '        </div>';
    echo '      </a>';
    echo '      <a href="products.php" class="glass rounded-xl p-4 block">';
    echo '        <div class="text-sm opacity-90">Catálogo</div>';
    echo '        <div class="flex items-center justify-between mt-1">';
    echo '          <div class="text-lg font-semibold">Produtos</div>';
    echo '          <i class="fa-solid fa-arrow-right-long"></i>';
    echo '        </div>';
    echo '      </a>';
    echo '      <a href="categories.php" class="glass rounded-xl p-4 block">';
    echo '        <div class="text-sm opacity-90">Organização</div>';
    echo '        <div class="flex items-center justify-between mt-1">';
    echo '          <div class="text-lg font-semibold">Categorias</div>';
    echo '          <i class="fa-solid fa-arrow-right-long"></i>';
    echo '        </div>';
    echo '      </a>';
    echo '      <a href="customers.php" class="glass rounded-xl p-4 block">';
    echo '        <div class="text-sm opacity-90">Base</div>';
    echo '        <div class="flex items-center justify-between mt-1">';
    echo '          <div class="text-lg font-semibold">Clientes</div>';
    echo '          <i class="fa-solid fa-arrow-right-long"></i>';
    echo '        </div>';
    echo '      </a>';
    echo '      <a href="settings.php?tab=payments" class="glass rounded-xl p-4 block">';
    echo '        <div class="text-sm opacity-90">Cobranças</div>';
    echo '        <div class="flex items-center justify-between mt-1">';
    echo '          <div class="text-lg font-semibold">Pagamentos</div>';
    echo '          <i class="fa-solid fa-arrow-right-long"></i>';
    echo '        </div>';
    echo '      </a>';
    echo '    </div>';
  }
  echo '    </div>';
  echo '  </div>';
  echo '</section>';
}

function admin_footer(){
  $withLayout = $GLOBALS['_ADMIN_WITH_LAYOUT'] ?? true;
  if ($withLayout){
    echo '  </main></div>';
  } else {
    echo '</div>';
  }
  echo '</body></html>';
}

/* ===== Rotas ===== */
try{

  /* ===== Login ===== */
  if ($route==='login') {
    if (is_admin()) { header('Location: dashboard.php'); exit; }

    $err = '';
    if ($_SERVER['REQUEST_METHOD']==='POST') {
      if (!csrf_check($_POST['csrf'] ?? '')) die('CSRF');
      $email = trim($_POST['email'] ?? '');
      $pass  = (string)($_POST['password'] ?? '');
      $pdo = null;
      try {
        $pdo = db();
      } catch (Throwable $e) {
        $err = 'Falha ao conectar ao banco. Tente novamente em instantes.';
      }
      $loginAttempt = null;
      if ($pdo) {
        $loginAttempt = auth_attempt_login($pdo, $email, $pass);
        if ($loginAttempt['ok']) {
          session_regenerate_id(true);
          set_admin_session([
            'id'    => (int)$loginAttempt['user']['id'],
            'email' => $loginAttempt['user']['email'],
            'name'  => $loginAttempt['user']['name'],
            'role'  => $loginAttempt['user']['role'],
          ]);
          header('Location: dashboard.php'); exit;
        }
      }
      if ((!$loginAttempt || !$loginAttempt['ok']) && defined('ADMIN_EMAIL') && defined('ADMIN_PASS_HASH') && ADMIN_EMAIL !== '' && ADMIN_PASS_HASH !== '' && $email === ADMIN_EMAIL && password_verify($pass, ADMIN_PASS_HASH) && $pdo) {
        $userRow = null;
        try {
          $st = $pdo->prepare("SELECT id, name, email, pass, role, active FROM users WHERE email=? LIMIT 1");
          $st->execute([$email]);
          $userRow = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
          $userRow = null;
        }
        if (!$userRow) {
          try {
            $ins = $pdo->prepare("INSERT IGNORE INTO users(name,email,pass,role,active,created_at) VALUES(?,?,?,?,1,NOW())");
            $ins->execute([$email, $email, ADMIN_PASS_HASH, 'super_admin']);
            $st = $pdo->prepare("SELECT id, name, email, pass, role, active FROM users WHERE email=? LIMIT 1");
            $st->execute([$email]);
            $userRow = $st->fetch(PDO::FETCH_ASSOC);
          } catch (Throwable $e) {
            $userRow = null;
          }
        }
        if ($userRow && ($userRow['role'] ?? '') !== 'super_admin') {
          try {
            $upRole = $pdo->prepare("UPDATE users SET role='super_admin' WHERE id=?");
            $upRole->execute([(int)$userRow['id']]);
            $userRow['role'] = 'super_admin';
          } catch (Throwable $e) {}
        }
        if ($userRow) {
          $role = $userRow['role'] ?? 'super_admin';
          $name = $userRow['name'] ?? $email;
          $id   = isset($userRow['id']) ? (int)$userRow['id'] : 1;
          session_regenerate_id(true);
          set_admin_session([
            'id'    => $id,
            'email' => $email,
            'name'  => $name,
            'role'  => $role ?: 'super_admin',
          ]);
          header('Location: dashboard.php'); exit;
        }
      }
      if (!$err) {
        $errorCode = $loginAttempt['error'] ?? 'invalid_credentials';
        $messages = [
          'missing_credentials' => 'Informe e-mail e senha.',
          'inactive_user' => 'Usuário inativo. Contate o administrador.',
          'db_error' => 'Não foi possível consultar os usuários. Tente mais tarde.',
          'invalid_credentials' => 'Credenciais inválidas.',
        ];
        $err = $messages[$errorCode] ?? 'Não foi possível autenticar.';
      }
    }

    // Cabeçalho sem layout (sem sidebar), com hero
    admin_header('Login - Admin', false);

    // HERO
    admin_hero('Bem-vindo ao Painel', 'Acesse para gerenciar pedidos, produtos e configurações', false);

    // Formulário central
    echo '<div class="grid place-items-center pb-10">';
    echo '  <form method="post" class="card auth-card p-6 w-full max-w-md">';
    echo '    <h2 class="text-xl font-semibold mb-1">Acessar painel</h2>';
    echo '    <p class="text-sm link-muted mb-4">Use suas credenciais administrativas.</p>';
    if (!empty($err)) {
      echo '  <div class="mb-3 p-3 rounded bg-red-50 text-red-700 border border-red-200 text-sm"><i class="fa-solid fa-circle-exclamation mr-2"></i>'.sanitize_html($err).'</div>';
    }
    echo '    <input type="hidden" name="csrf" value="'.csrf_token().'">';
    echo '    <label class="block text-sm mb-1">E-mail</label>';
    echo '    <input class="w-full border rounded px-3 py-2 mb-3" type="email" name="email" required>';
    echo '    <label class="block text-sm mb-1">Senha</label>';
    echo '    <input class="w-full border rounded px-3 py-2 mb-4" type="password" name="password" required>';
    echo '    <button class="btn btn-primary w-full" type="submit"><i class="fa-solid fa-right-to-bracket mr-2"></i>Entrar</button>';
    echo '    <div class="mt-3 flex items-center justify-between text-sm">';
    echo '      <a class="text-brand-600 hover:underline" href="forgot_password.php"><i class="fa-solid fa-key mr-1"></i>Esqueci minha senha</a>';
    echo '      <a class="btn btn-ghost" href="index.php" target="_blank"><i class="fa-solid fa-store mr-1"></i>Ver loja</a>';
    echo '    </div>';
    echo '  </form>';
    echo '</div>';

    admin_footer();
    exit;
  }

  /* ===== Logout ===== */
  if ($route==='logout') {
    $_SESSION=[]; if (ini_get('session.use_cookies')){
      $p=session_get_cookie_params();
      setcookie(session_name(),' ',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);
    }
    session_destroy(); header('Location: admin.php?route=login'); exit;
  }

  /* ===== Dashboard ===== */
  if ($route==='dashboard') { require_admin(); header('Location: dashboard.php'); exit; }

  /* ===== Settings (com HERO no topo) ===== */
  if ($route==='settings') {
    require_admin();
    header('Location: settings.php?tab=general');
    exit;
  }

  /* ===== Ping ===== */
  if ($route==='api_ping') {
    header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>true,'ts'=>time()]); exit;
  }

  header('Location: dashboard.php'); exit;

} catch (Throwable $e) {
  admin_header('Erro');
  admin_hero('Erro no Sistema', 'Algo inesperado aconteceu', false);
  echo '<div class="card p-5 border border-red-200 bg-red-50 text-red-700">';
  echo '  <div class="font-semibold mb-2">Detalhes</div>';
  echo '  <pre class="text-sm whitespace-pre-wrap">'.sanitize_html($e->getMessage()).'</pre>';
  echo '</div>';
  admin_footer();
}
