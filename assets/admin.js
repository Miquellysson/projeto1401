// assets/admin.js — pequenos helpers do painel
function ensureToastContainer() {
  let container = document.querySelector('.toast-container');
  if (!container) {
    container = document.createElement('div');
    container.className = 'toast-container';
    document.body.appendChild(container);
  }
  return container;
}

function showToast(message, type = 'success') {
  if (!message) return;
  const container = ensureToastContainer();
  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  toast.textContent = message;
  container.appendChild(toast);
  setTimeout(() => {
    toast.remove();
  }, 2400);
}

document.addEventListener('click', (e)=>{
  const btn = e.target.closest('[data-copy]');
  if (!btn) return;
  const text = btn.getAttribute('data-copy') || '';
  navigator.clipboard.writeText(text).then(()=>{
    btn.classList.add('copied');
    showToast('Copiado!', 'success');
    setTimeout(()=>btn.classList.remove('copied'), 1200);
  }).catch(()=>{
    showToast('Nao foi possivel copiar.', 'error');
  });
});

document.addEventListener('change', (e) => {
  const select = e.target.closest('.order-status-form .status-select');
  if (!select) return;
  const form = select.closest('.order-status-form');
  const btn = form ? form.querySelector('.update-btn') : null;
  if (!form || !btn) return;
  const current = form.getAttribute('data-current-status') || '';
  const changed = select.value !== current;
  btn.disabled = !changed;
});

document.addEventListener('submit', (e) => {
  const form = e.target.closest('.order-status-form');
  if (!form) return;
  const btn = form.querySelector('.update-btn');
  if (!btn) return;
  btn.disabled = true;
  btn.dataset.originalText = btn.textContent;
  btn.textContent = 'Atualizando...';
  btn.classList.add('is-loading');
  showToast('Status atualizado.', 'success');
});
