// theme.js — alternância light/dark persistente
(function(){
  const KEY = 'ff_theme';
  const root = document.documentElement;
  const body = document.body;

  function apply(theme){
    root.classList.remove('theme-light','theme-dark');
    body.classList.add('themable');
    root.classList.add(theme);
  }
  function current(){ return localStorage.getItem(KEY) || 'theme-light'; }
  function toggle(){
    const next = current()==='theme-light' ? 'theme-dark' : 'theme-light';
    localStorage.setItem(KEY, next);
    apply(next);
  }

  // Expor global
  window.FFTheme = {apply, toggle, current};

  // Inicializar
  apply(current());
})();
