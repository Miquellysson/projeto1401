const config = window.__ADMIN_APP__ || {};
const pollInterval = Number(config.pollInterval) || 10000;
const pushConfig = config.push || {};
const hasOneSignal = Boolean(pushConfig.appId);

const els = {
  metrics: {
    pending_orders: document.querySelector('[data-metric="pending_orders"]'),
    paid_today: document.querySelector('[data-metric="paid_today"]'),
    revenue_today: document.querySelector('[data-metric="revenue_today"]'),
    orders_last_hour: document.querySelector('[data-metric="orders_last_hour"]'),
  },
  totals: {
    orders: document.querySelector('[data-total="orders"]'),
    customers: document.querySelector('[data-total="customers"]'),
    products: document.querySelector('[data-total="products"]'),
    categories: document.querySelector('[data-total="categories"]'),
  },
  statusBreakdown: document.getElementById('statusBreakdown'),
  orders: document.getElementById('ordersStream'),
  pendingAlerts: document.getElementById('pendingAlerts'),
  failedAlerts: document.getElementById('failedAlerts'),
  alertsCount: document.getElementById('alertsCount'),
  healthPanel: document.getElementById('healthPanel'),
  syncIndicator: document.getElementById('syncIndicator'),
  syncText: document.querySelector('[data-sync-text]'),
  serverStatus: document.getElementById('serverStatus'),
  lastSync: document.getElementById('lastSync'),
  toastStack: document.getElementById('toastStack'),
  notifyBtn: document.getElementById('notifyBtn'),
  installBanner: document.getElementById('installBanner'),
  installAction: document.getElementById('installAction'),
  installClose: document.getElementById('installClose'),
  installHint: document.getElementById('installHint'),
};

const state = {
  timer: null,
  bootstrapped: false,
  seenEvents: new Set(),
  lastServerTime: null,
  failureToastShown: false,
  deferredInstall: null,
};

const INSTALL_STORAGE_KEY = 'appadmin_install_prompt_dismissed';
const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
const isAndroid = /Android/i.test(navigator.userAgent);

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function formatCurrency(value, currency) {
  const amount = Number(value);
  if (!Number.isFinite(amount)) {
    return '—';
  }
  try {
    return new Intl.NumberFormat('pt-BR', {
      style: 'currency',
      currency: currency || config.currency || 'USD',
    }).format(amount);
  } catch (err) {
    return `${currency || ''} ${amount.toFixed(2)}`;
  }
}

function formatRelative(isoString) {
  const date = new Date(isoString);
  if (Number.isNaN(date.getTime())) {
    return '—';
  }
  const diffMs = Date.now() - date.getTime();
  const diffMinutes = Math.round(diffMs / 60000);
  if (diffMinutes < 1) return 'agora';
  if (diffMinutes < 60) return `${diffMinutes} min atrás`;
  const diffHours = Math.round(diffMinutes / 60);
  if (diffHours < 24) return `${diffHours} h atrás`;
  const diffDays = Math.round(diffHours / 24);
  return `${diffDays} d atrás`;
}

function statusLabel(status) {
  const map = {
    paid: 'Pago',
    pending: 'Pendente',
    canceled: 'Cancelado',
    failed: 'Falha',
    shipped: 'Enviado',
  };
  return map[status] || status?.toUpperCase() || '—';
}

function statusClass(status) {
  const normalized = (status || '').toLowerCase();
  if (normalized === 'paid') return 'paid';
  if (normalized === 'pending') return 'pending';
  if (normalized === 'shipped') return 'shipped';
  if (normalized === 'canceled' || normalized === 'failed') return 'failed';
  return '';
}

function updateMetric(key, value, formatter) {
  const el = els.metrics[key];
  if (!el) return;
  el.textContent = formatter ? formatter(value) : (value ?? '—');
}

function updateTotals(totals) {
  if (!totals) return;
  Object.entries(els.totals).forEach(([key, element]) => {
    if (!element) return;
    element.textContent = (totals[key] ?? '—');
  });
}

function renderStatusBreakdown(list = []) {
  if (!els.statusBreakdown) return;
  if (!list.length) {
    els.statusBreakdown.innerHTML = '<span class="status-chip">Sem dados recentes</span>';
    return;
  }
  els.statusBreakdown.innerHTML = list
    .map(
      (item) =>
        `<span class="status-chip">${escapeHtml(statusLabel(item.status))} · ${escapeHtml(
          item.total
        )}</span>`
    )
    .join('');
}

function rememberEvent(key) {
  els.syncIndicator?.setAttribute('data-last-event', key);
  if (!key) return;
  state.seenEvents.add(key);
  if (state.seenEvents.size > 200) {
    const first = state.seenEvents.values().next().value;
    if (first) {
      state.seenEvents.delete(first);
    }
  }
}

function emitOrderNotification(order) {
  const label = order.order_code || `#${order.id}`;
  const total = formatCurrency(order.total, order.currency);
  const createdAt = new Date(order.created_at).getTime();
  const updatedAt = new Date(order.updated_at).getTime();
  const isNewOrder =
    Number.isFinite(createdAt) &&
    Number.isFinite(updatedAt) &&
    Math.abs(updatedAt - createdAt) < 90000;
  const title = isNewOrder ? 'Novo pedido' : 'Status atualizado';
  const body = `${label} · ${statusLabel(order.status)} · ${total}`;
  pushToast(`${title}: ${label}`, `Status ${statusLabel(order.status)} · ${total}`);
  if ('Notification' in window && Notification.permission === 'granted') {
    const options = {
      body,
      icon: '/assets/icons/admin-192.png',
      badge: '/assets/icons/admin-192.png',
      data: {
        orderId: order.id,
        url: `/orders.php?action=view&id=${order.id}`,
      },
    };
    navigator.serviceWorker?.ready
      ?.then((registration) => {
        if (registration?.showNotification) {
          registration.showNotification(title, options);
        } else {
          new Notification(title, options);
        }
      })
      .catch(() => new Notification(title, options));
  }
}

function renderOrders(orders = []) {
  if (!els.orders) return;
  if (!Array.isArray(orders) || !orders.length) {
    els.orders.innerHTML =
      '<div class="empty">Nenhum pedido novo por aqui. Você receberá notificações assim que algo acontecer.</div>';
    return;
  }
  const fragments = [];
  orders.forEach((order) => {
    const label = order.order_code || `#${order.id}`;
    const total = formatCurrency(order.total, order.currency);
    const eventKey =
      order.event_key ||
      `${order.id}-${order.updated_at || order.created_at || Date.now()}`;
    const isNewEvent = !state.seenEvents.has(eventKey);
    const safeLabel = escapeHtml(label);
    const safeCustomer = escapeHtml(order.customer_name || 'Cliente não informado');
    const safeTotal = escapeHtml(total);
    const safeStatus = escapeHtml(statusLabel(order.status));
    const safeTime = escapeHtml(formatRelative(order.updated_at || order.created_at));
    fragments.push(`
      <article class="order-card ${isNewEvent && state.bootstrapped ? 'pulse' : ''}">
        <div class="order-main">
          <span class="order-id">${safeLabel}</span>
          <span class="order-customer">${safeCustomer}</span>
        </div>
        <div class="order-meta">
          <span class="money">${safeTotal}</span>
          <span class="status-pill ${statusClass(order.status)}">${safeStatus}</span>
          <span class="time">${safeTime}</span>
        </div>
      </article>
    `);
    if (!state.seenEvents.has(eventKey) && state.bootstrapped) {
      emitOrderNotification(order);
    }
    rememberEvent(eventKey);
  });
  els.orders.innerHTML = fragments.join('');
}

function renderAlerts(alerts = {}) {
  const pending = Array.isArray(alerts.pending_overdue) ? alerts.pending_overdue : [];
  const failed = Array.isArray(alerts.failed_payments) ? alerts.failed_payments : [];
  const total = pending.length + failed.length;
  if (els.alertsCount) {
    els.alertsCount.textContent = `${total} alerta${total === 1 ? '' : 's'}`;
  }
  const pendingHtml = pending.length
    ? pending
        .map(
          (item) => `
            <li class="alert-item">
              <strong>Pedido #${escapeHtml(item.id)}</strong>
              <span>${escapeHtml(formatCurrency(item.total, item.currency))}</span>
              <span class="meta">Desde ${escapeHtml(formatRelative(item.created_at))}</span>
            </li>
          `
        )
        .join('')
    : '<li class="alert-item empty">Sem pendências críticas.</li>';
  const failedHtml = failed.length
    ? failed
        .map(
          (item) => `
            <li class="alert-item">
              <strong>Pedido #${escapeHtml(item.id)}</strong>
              <span>Status de pagamento: ${escapeHtml(statusLabel(item.payment_status))}</span>
              <span class="meta">Atualizado ${escapeHtml(formatRelative(item.updated_at))}</span>
            </li>
          `
        )
        .join('')
    : '<li class="alert-item empty">Sem pagamentos falhos.</li>';
  if (els.pendingAlerts) els.pendingAlerts.innerHTML = pendingHtml;
  if (els.failedAlerts) els.failedAlerts.innerHTML = failedHtml;
}

function renderHealth(health) {
  if (!els.healthPanel || !health) return;
  ['errors_last_hour', 'orders_with_error', 'disk_free_percent', 'php_version'].forEach(
    (key) => {
      const target = els.healthPanel.querySelector(`[data-health="${key}"]`);
      if (!target) return;
      const value =
        key === 'disk_free_percent' && Number.isFinite(Number(health[key]))
          ? `${Number(health[key]).toFixed(1)}%`
          : health[key] ?? '—';
      target.textContent = value;
    }
  );
  els.healthPanel.style.display = 'block';
}

function pushToast(title, body) {
  if (!els.toastStack) return;
  const toast = document.createElement('div');
  toast.className = 'toast';
  const titleEl = document.createElement('strong');
  titleEl.textContent = title;
  const bodyEl = document.createElement('span');
  bodyEl.textContent = body;
  toast.append(titleEl, bodyEl);
  els.toastStack.appendChild(toast);
  setTimeout(() => toast.remove(), 6000);
}

function updateSyncIndicator(state, text) {
  if (!els.syncIndicator) return;
  els.syncIndicator.dataset.state = state;
  if (els.syncText && text) {
    els.syncText.textContent = text;
  }
}

function setServerStatus(text) {
  if (els.serverStatus) {
    els.serverStatus.textContent = text;
  }
}

function setLastSync(isoString) {
  if (!els.lastSync || !isoString) return;
  const date = new Date(isoString);
  if (Number.isNaN(date.getTime())) return;
  els.lastSync.textContent = date.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
}

async function fetchFeed() {
  updateSyncIndicator('warning', 'Sincronizando…');
  try {
    const res = await fetch(config.apiUrl, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
    });
    if (!res.ok) {
      throw new Error(`HTTP ${res.status}`);
    }
    const payload = await res.json();
    if (!payload.ok) {
      throw new Error(payload.error || 'Resposta inválida');
    }
    const { summary, orders, alerts, health, server_time: serverTime } = payload;
    updateMetric('pending_orders', summary?.pending_orders ?? 0);
    updateMetric('paid_today', summary?.paid_today ?? 0);
    updateMetric('revenue_today', summary?.revenue_today ?? 0, (value) =>
      formatCurrency(value, config.currency)
    );
    updateMetric('orders_last_hour', summary?.orders_last_hour ?? 0);
    updateTotals(summary?.totals || null);
    renderStatusBreakdown(summary?.status_breakdown || []);
    renderOrders(orders || []);
    renderAlerts(alerts || {});
    if (health) {
      renderHealth(health);
    }
    if (!state.bootstrapped) {
      state.bootstrapped = true;
    }
    setLastSync(serverTime || summary?.last_sync_iso);
    setServerStatus('Online');
    updateSyncIndicator('ok', 'Atualizado agora');
    state.failureToastShown = false;
  } catch (error) {
    updateSyncIndicator('error', 'Sem conexão');
    setServerStatus('Offline');
    if (!state.failureToastShown) {
      pushToast('Falha ao sincronizar', error.message || 'Verifique sua conexão');
      state.failureToastShown = true;
    }
  } finally {
    scheduleNext();
  }
}

function scheduleNext() {
  if (state.timer) {
    clearTimeout(state.timer);
  }
  state.timer = window.setTimeout(fetchFeed, pollInterval);
}

function handleVisibility() {
  if (document.visibilityState === 'visible') {
    fetchFeed();
  }
}

function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) return;
  navigator.serviceWorker
    .getRegistration()
    .then((registration) => {
      if (!registration) {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
      }
    })
    .catch(() => {});
}

function initNotifications() {
  if (!els.notifyBtn) return;
  if (hasOneSignal) {
    const waitForSDK = (cb) => {
      if (typeof window.OneSignal === 'undefined') {
        setTimeout(() => waitForSDK(cb), 300);
        return;
      }
      cb(window.OneSignal);
    };

    const updateState = () => {
      waitForSDK((OneSignal) => {
        if (typeof OneSignal.isPushNotificationsEnabled === 'function') {
          OneSignal.isPushNotificationsEnabled()
            .then((enabled) => {
              if (enabled) {
                els.notifyBtn.textContent = 'Notificações ativas';
                els.notifyBtn.disabled = true;
                els.notifyBtn.classList.add('active');
              } else {
                els.notifyBtn.textContent = 'Ativar notificações';
                els.notifyBtn.disabled = false;
                els.notifyBtn.classList.remove('active');
              }
            })
            .catch(() => {
              els.notifyBtn.textContent = 'Ativar notificações';
              els.notifyBtn.disabled = false;
            });
        }
      });
    };

    els.notifyBtn.addEventListener('click', () => {
      waitForSDK((OneSignal) => {
        if (OneSignal.Slidedown && OneSignal.Slidedown.promptPush) {
          OneSignal.Slidedown.promptPush();
        } else if (OneSignal.showSlidedownPrompt) {
          OneSignal.showSlidedownPrompt();
        } else if (OneSignal.registerForPushNotifications) {
          OneSignal.registerForPushNotifications();
        }
      });
    });

    updateState();
    return;
  }

  if (!('Notification' in window)) {
    els.notifyBtn.textContent = 'Sem suporte';
    els.notifyBtn.disabled = true;
    return;
  }
  const syncButtonState = () => {
    if (Notification.permission === 'granted') {
      els.notifyBtn.textContent = 'Notificações ativas';
      els.notifyBtn.classList.add('active');
      els.notifyBtn.disabled = true;
    } else if (Notification.permission === 'denied') {
      els.notifyBtn.textContent = 'Permissão bloqueada';
      els.notifyBtn.disabled = true;
    } else {
      els.notifyBtn.textContent = 'Ativar notificações';
    }
  };
  els.notifyBtn.addEventListener('click', async () => {
    if (!('Notification' in window)) return;
    try {
      await Notification.requestPermission();
    } finally {
      syncButtonState();
    }
  });
  syncButtonState();
}

function showInstallBanner(force = false) {
  if (!els.installBanner) return;
  if (!force && localStorage.getItem(INSTALL_STORAGE_KEY) === '1') return;
  els.installBanner.classList.add('show');
}

function hideInstallBanner(permanent = false) {
  if (!els.installBanner) return;
  els.installBanner.classList.remove('show');
  if (permanent) {
    localStorage.setItem(INSTALL_STORAGE_KEY, '1');
  }
}

function initInstallBanner() {
  if (!els.installBanner) return;
  if (els.installHint) {
    els.installHint.textContent = isIOS
      ? 'No Safari, toque em Compartilhar → Adicionar à Tela de Início.'
      : 'No Chrome, abra o menu ⋮ e escolha “Adicionar à tela inicial”.';
  }
  if (els.installClose) {
    els.installClose.addEventListener('click', () => hideInstallBanner(true));
  }
  if (els.installAction) {
    els.installAction.addEventListener('click', async () => {
      if (state.deferredInstall) {
        state.deferredInstall.prompt();
        try {
          await state.deferredInstall.userChoice;
        } catch (_) {}
        state.deferredInstall = null;
        hideInstallBanner(true);
      } else if (isIOS) {
        alert('No Safari: toque em Compartilhar → Adicionar à Tela de Início.');
      } else {
        alert('Abra o menu do navegador e selecione “Adicionar à tela inicial”.');
      }
    });
  }
  setTimeout(() => showInstallBanner(false), 1200);
}

window.addEventListener('beforeinstallprompt', (event) => {
  event.preventDefault();
  state.deferredInstall = event;
  showInstallBanner(true);
});

document.addEventListener('visibilitychange', handleVisibility);
initNotifications();
initInstallBanner();
registerServiceWorker();
fetchFeed();
