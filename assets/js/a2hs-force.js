/* a2hs-force.js — versão de teste: força o banner aparecer SEM esperar beforeinstallprompt */
(function () {
  const CONFIG = {
    appName: 'Get Power Research',
    icon192: '/assets/icons/farma-192.png',
    deferDaysAfterDismiss: 1 // quantos dias esperar após o usuário fechar o banner
  };

  const ls = window.localStorage;
  const DISMISS_KEY = 'a2hs_dismissed_at';
  const now = () => Math.floor(Date.now() / 1000);
  const days = d => d * 24 * 60 * 60;
  const dismissedRecently = () => {
    const t = parseInt(ls.getItem(DISMISS_KEY) || '0', 10);
    return t && (now() - t) < days(CONFIG.deferDaysAfterDismiss);
  };
  const markDismissed = () => ls.setItem(DISMISS_KEY, String(now()));

  const isInStandalone =
    window.matchMedia('(display-mode: standalone)').matches ||
    navigator.standalone === true;

  function injectStyles() {
    if (document.getElementById('a2hs-styles')) return;
    const style = document.createElement('style');
    style.id = 'a2hs-styles';
    style.textContent = `
      .a2hs-wrap{position:fixed;left:12px;right:12px;bottom:12px;z-index:9999;background:#1f2937;color:#e5e7eb;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.35);display:flex;align-items:center;padding:12px 14px;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Helvetica Neue',sans-serif}
      .a2hs-wrap img{width:44px;height:44px;border-radius:10px;flex:0 0 44px;object-fit:cover;margin-right:12px}
      .a2hs-title{font-weight:700;font-size:15px;line-height:1.2;color:#fff}
      .a2hs-sub{font-size:12px;opacity:.8;margin-top:2px}
      .a2hs-spacer{flex:1}
      .a2hs-btn{background:#93c5fd;color:#0b1324;border:0;border-radius:10px;padding:8px 12px;font-weight:700;cursor:pointer}
      .a2hs-close{margin-left:10px;background:transparent;border:0;color:#9ca3af;font-size:18px;cursor:pointer}
      @media(min-width:640px){.a2hs-wrap{left:auto;right:16px;bottom:16px;width:420px}}
    `;
    document.head.appendChild(style);
  }

  function createBanner({ title, subtitle, primaryLabel }) {
    injectStyles();
    const el = document.createElement('div');
    el.className = 'a2hs-wrap';
    el.innerHTML = `
      <img src="${CONFIG.icon192}" alt="${CONFIG.appName}">
      <div>
        <div class="a2hs-title">${title}</div>
        <div class="a2hs-sub">${subtitle}</div>
      </div>
      <div class="a2hs-spacer"></div>
      ${primaryLabel ? `<button class="a2hs-btn" type="button">${primaryLabel}</button>` : ''}
      <button class="a2hs-close" aria-label="Fechar" type="button">✕</button>
    `;
    document.body.appendChild(el);
    el.querySelector('.a2hs-close').addEventListener('click', () => { markDismissed(); el.remove(); });
    if (primaryLabel) {
      el.querySelector('.a2hs-btn').addEventListener('click', () => {
        alert('Aqui seria disparado o prompt real do navegador.\nEsta é só a versão forçada de teste.');
        markDismissed();
        el.remove();
      });
    }
    return el;
  }

  document.addEventListener('DOMContentLoaded', () => {
    if (dismissedRecently() || isInStandalone) return;
    createBanner({
      title: 'Instalar ' + CONFIG.appName,
      subtitle: window.location.hostname,
      primaryLabel: 'Instalar'
    });
  });
})();
