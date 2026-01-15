/* a2hs-superforce.js
 * Força aparecer um banner de “Instalar” SEM depender do beforeinstallprompt.
 * Usa caminho relativo (não depende de estar na raiz do domínio).
 * Use só para teste; depois volte ao a2hs.js normal.
 */
(function () {
  var APP_NAME = 'Get Power Research';
  var ICON = 'assets/icons/farma-192.png'; // relativo ao arquivo atual

  // Estilos inline para não depender de CSS externo
  var css = [
    '.a2hs-wrap{position:fixed;left:12px;right:12px;bottom:12px;z-index:9999;background:#1f2937;color:#e5e7eb;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.35);display:flex;align-items:center;padding:12px 14px;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",sans-serif}',
    '.a2hs-wrap img{width:44px;height:44px;border-radius:10px;flex:0 0 44px;object-fit:cover;margin-right:12px}',
    '.a2hs-title{font-weight:700;font-size:15px;line-height:1.2;color:#fff}',
    '.a2hs-sub{font-size:12px;opacity:.8;margin-top:2px}',
    '.a2hs-spacer{flex:1}',
    '.a2hs-btn{background:#93c5fd;color:#0b1324;border:0;border-radius:10px;padding:8px 12px;font-weight:700;cursor:pointer}',
    '.a2hs-close{margin-left:10px;background:transparent;border:0;color:#9ca3af;font-size:18px;cursor:pointer}',
    '@media(min-width:640px){.a2hs-wrap{left:auto;right:16px;bottom:16px;width:420px}}'
  ].join('');
  var style = document.createElement('style');
  style.id = 'a2hs-styles';
  style.textContent = css;
  document.head.appendChild(style);

  function showBanner() {
    // Não mostra se já estiver em standalone
    var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    if (standalone) return;

    // Remove instâncias anteriores
    var old = document.querySelector('.a2hs-wrap');
    if (old) old.remove();

    var el = document.createElement('div');
    el.className = 'a2hs-wrap';
    el.innerHTML = ''
      + '<img src="' + ICON + '" alt="' + APP_NAME + '">'
      + '<div>'
      +   '<div class="a2hs-title">Instalar ' + APP_NAME + '</div>'
      +   '<div class="a2hs-sub">' + location.hostname + '</div>'
      + '</div>'
      + '<div class="a2hs-spacer"></div>'
      + '<button class="a2hs-btn" type="button">Instalar</button>'
      + '<button class="a2hs-close" aria-label="Fechar" type="button">✕</button>';

    document.body.appendChild(el);

    el.querySelector('.a2hs-close').addEventListener('click', function () { el.remove(); });
    el.querySelector('.a2hs-btn').addEventListener('click', function () {
      alert('Teste visual: aqui o navegador abriria o prompt de instalação.\nVolte depois ao a2hs.js normal.');
      el.remove();
    });
  }

  // Garante que o <body> exista
  if (document.readyState === 'complete') {
    showBanner();
  } else {
    window.addEventListener('load', showBanner);
  }
})();
