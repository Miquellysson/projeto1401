<?php
ini_set('display_errors',1); error_reporting(E_ALL);

// Puxa helpers existentes do projeto (ajusta caminho se sua estrutura for diferente)
require __DIR__.'/config.php';
require __DIR__.'/lib/utils.php';

// Nome e logo da loja
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
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Instalar app – Android | <?=htmlspecialchars($storeName)?></title>
  <link rel="manifest" href="/manifest.webmanifest">
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
          <p class="text-white/90">Android · experiência de app, rápida e segura</p>
        </div>
      </div>

      <div class="mt-6 glass rounded-2xl p-5">
        <p class="mb-4">Toque no botão abaixo para instalar. Se o prompt não aparecer,
          abra o menu <b>⋮</b> do navegador e escolha <b>“Instalar app”</b>.</p>
        <button id="btnInstall"
                class="w-full md:w-auto px-5 py-3 rounded-xl bg-white text-brand-700 font-semibold hover:bg-brand-50">
          <i class="fa-solid fa-download mr-2"></i> Instalar agora
        </button>
        <div id="tipNoPrompt" class="hidden mt-3 text-sm text-white/90">
          Dica: no Chrome/Edge para Android, use o menu <b>⋮</b> → <b>Instalar app</b>.
        </div>
      </div>
    </div>
  </section>

  <!-- Conteúdo extra -->
  <section class="max-w-3xl mx-auto px-5 py-8">
    <div class="grid sm:grid-cols-2 gap-4">
      <div class="rounded-2xl border bg-white p-5">
        <div class="font-semibold"><i class="fa-solid fa-bolt mr-2 text-brand-700"></i>Rápido</div>
        <p class="text-sm text-gray-600 mt-1">Acesso direto pela tela inicial, sem abrir o navegador.</p>
      </div>
      <div class="rounded-2xl border bg-white p-5">
        <div class="font-semibold"><i class="fa-solid fa-lock mr-2 text-brand-700"></i>Seguro</div>
        <p class="text-sm text-gray-600 mt-1">PWA oficial da loja, atualizado automaticamente.</p>
      </div>
    </div>
  </section>

  <script>
    // SW (se já existir sw.js)
    if ("serviceWorker" in navigator) {
      window.addEventListener("load", () => {
        try { navigator.serviceWorker.register("sw.js?v=2"); } catch(e){}
      });
    }

    let deferredPrompt = null;
    const btn = document.getElementById('btnInstall');
    const tip = document.getElementById('tipNoPrompt');

    // Habilita botão quando o navegador sinaliza que pode instalar
    window.addEventListener('beforeinstallprompt', (e) => {
      e.preventDefault();
      deferredPrompt = e;
      btn.disabled = false;
      tip.classList.add('hidden');
    });

    btn.addEventListener('click', async () => {
      if (deferredPrompt) {
        deferredPrompt.prompt();
        try { await deferredPrompt.userChoice; } catch(_){}
        deferredPrompt = null;
      } else {
        tip.classList.remove('hidden');
        alert('Se o botão nativo não aparecer: menu ⋮ do navegador → "Instalar app".');
      }
    });
  </script>
</body>
</html>
