// assets/pwa.js - PWA + Theme + A2HS button
(function(){
  // Theme persistence
  const root = document.documentElement;
  const saved = localStorage.getItem('ff-theme');
  if(saved){ root.setAttribute('data-theme', saved); }
  document.addEventListener('click', e=>{
    const t = e.target.closest('[data-action="toggle-theme"]');
    if(!t) return;
    const next = (root.getAttribute('data-theme')==='dark')?'light':'dark';
    root.setAttribute('data-theme', next);
    localStorage.setItem('ff-theme', next);
  });

  // PWA register
  if('serviceWorker' in navigator){
    navigator.serviceWorker.register('./sw.js').catch(()=>{});
  }

  // A2HS
  let deferredPrompt = null;
  window.addEventListener('beforeinstallprompt', (e)=>{
    e.preventDefault();
    deferredPrompt = e;
    const btn = document.querySelector('[data-action="install-app"]');
    if(btn){ btn.style.display = 'inline-flex'; }
  });
  document.addEventListener('click', async (e)=>{
    const btn = e.target.closest('[data-action="install-app"]');
    if(!btn || !deferredPrompt) return;
    btn.disabled = true;
    deferredPrompt.prompt();
    try { await deferredPrompt.userChoice; } finally {
      deferredPrompt = null; btn.disabled = false; btn.style.display='none';
    }
  });
})();
