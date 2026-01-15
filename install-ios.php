<?php
ini_set('display_errors',1); error_reporting(E_ALL);

require __DIR__.'/config.php';
require __DIR__.'/lib/utils.php';

$cfg = function_exists('cfg') ? cfg() : [];
$storeName = setting_get('store_name', $cfg['store']['name'] ?? 'Get Power Research');

function store_logo_path() {
  $opt = setting_get('store_logo_url');
  if ($opt && file_exists(__DIR__.'/'.$opt)) return $opt;
  foreach (['storage/logo/logo.png','storage/logo/logo.jpg','storage/logo/logo.jpeg','storage/logo/logo.webp','assets/logo.png'] as $c) {
    if (file_exists(__DIR__.'/'.$c)) return $c;
  }
  return null;
}
$logo = store_logo_path() ?: 'assets/logo.png';
$logoUrl = versioned_public_url($logo);

// Raiz que você quer salvar no atalho (garanta que seja a home da loja)
$TARGET_ROOT = '/'; // ou use 'https://victorfarmafacil.com/' se preferir absoluto
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Instalar app – iOS | <?=htmlspecialchars($storeName)?></title>

  <!-- iOS PWA metas / ícone -->
  <link rel="apple-touch-icon" href="assets/icons/farma-192.png">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="theme-color" content="#B91C1C">

  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = { theme: { extend: { colors: {
      brand: { DEFAULT:"#DC2626", 700:"#B91C1C" }, amber: { 400:"#F59E0B" }
    }}}};
  </script>
  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    .hero-grad{ background:linear-gradient(135deg,#DC2626,#F59E0B); }
    .card{ background:#fff; border:1px solid #e5e7eb; border-radius:1rem }
    .step{display:flex; align-items:flex-start; gap:.75rem}
    .badge{min-width:1.75rem;height:1.75rem;border-radius:.5rem;background:#F59E0B;color:#fff;
           display:grid;place-items:center;font-weight:700}
    .glass{ background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.25) }
  </style>
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">

  <!-- HERO -->
  <section class="hero-grad text-white">
    <div class="max-w-3xl mx-auto px-5 py-10">
      <div class="flex items-center gap-4">
        <img src="<?=htmlspecialchars($logoUrl ?: $logo)?>" alt="logo"
             class="w-14 h-14 rounded-xl object-cover bg-white/90 p-1">
        <div>
          <h1 class="text-2xl md:text-3xl font-bold leading-tight">
            Instalar o app <?=htmlspecialchars($storeName)?>
          </h1>
          <p class="text-white/90">iPhone / iPad · Adicione à Tela de Início (abre a loja)</p>
        </div>
      </div>
      <div class="mt-6 glass rounded-2xl p-5">
        <p class="mb-2">Esta instalação cria um atalho direto para a <b>loja</b>.</p>
        <p class="text-white/90 text-sm">Se você não estiver no Safari, toque no botão abaixo para abrir a loja.</p>
        <a href="<?=htmlspecialchars($TARGET_ROOT)?>"
           class="inline-flex items-center gap-2 mt-3 px-4 py-2 rounded-xl bg-white text-brand-700 font-semibold hover:bg-brand-50">
          <i class="fa-solid fa-store"></i> Abrir a loja
        </a>
      </div>
    </div>
  </section>

  <!-- Passo-a-passo -->
  <section class="max-w-3xl mx-auto px-5 py-8">
    <div id="notSafari" class="hidden card p-4 mb-4">
      <div class="text-sm text-gray-700">
        <i class="fa-solid fa-circle-info text-brand-700 mr-1"></i>
        Abra esta página no <b>Safari</b> para instalar (no iOS a instalação só é permitida pelo Safari).
      </div>
    </div>

    <div class="card p-6">
      <h2 class="text-xl font-semibold mb-4">Como instalar no iOS (salvar a loja)</h2>

      <div class="step mb-4">
        <div class="badge">1</div>
        <p>No <b>Safari</b>, toque em <b>Compartilhar</b>
          <i class="fa-solid fa-share-from-square text-blue-600"></i>.</p>
      </div>

      <div class="step mb-4">
        <div class="badge">2</div>
        <p>Role e selecione <b>Adicionar à Tela de Início</b>.</p>
      </div>

      <div class="step">
        <div class="badge">3</div>
        <p>Confirme em <b>Adicionar</b>. O ícone abrirá <b>direto a loja</b> sempre.</p>
      </div>

      <hr class="my-6">

      <div class="text-sm text-gray-600">
        <i class="fa-solid fa-lock text-brand-700 mr-1"></i>
        PWA oficial da loja. Sem App Store e com atualizações automáticas.
      </div>
    </div>
  </section>

  <script>
    (function(){
      const TARGET_ROOT = <?=json_encode($TARGET_ROOT)?>;

      // Detecta iOS + Safari
      const ua = navigator.userAgent || navigator.vendor || "";
      const isIOS = /iPad|iPhone|iPod/.test(ua);
      const isSafari = /^((?!chrome|android).)*safari/i.test(ua);

      // Se estiver no iOS Safari e NÃO estiver em modo standalone,
      // muda a URL da aba para a raiz da loja.
      // Assim, quando o usuário fizer "Adicionar à Tela de Início",
      // o atalho vai abrir a LOJA e não esta página.
      if (isIOS && isSafari && !window.navigator.standalone) {
        try {
          // Se já não estamos na raiz, troca a URL exibida
          if (location.pathname !== TARGET_ROOT) {
            history.replaceState(null, "", TARGET_ROOT);
          }
        } catch(e){}
      }

      // Mostra aviso se não for Safari no iOS
      if (isIOS && !isSafari) {
        document.getElementById('notSafari')?.classList.remove('hidden');
      }

      // (Opcional) registra SW se existir
      if ("serviceWorker" in navigator) {
        window.addEventListener("load", () => {
          try { navigator.serviceWorker.register("sw.js?v=2"); } catch(e){}
        });
      }
    })();
  </script>
</body>
</html>
