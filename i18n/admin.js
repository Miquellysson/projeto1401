// assets/admin.js — pequenos helpers do painel
document.addEventListener('click', (e)=>{
  const btn = e.target.closest('[data-copy]');
  if (!btn) return;
  const text = btn.getAttribute('data-copy') || '';
  navigator.clipboard.writeText(text).then(()=>{
    btn.classList.add('copied');
    setTimeout(()=>btn.classList.remove('copied'), 1200);
  }).catch(()=>{});
});
