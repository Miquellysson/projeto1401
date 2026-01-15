const config = window.__SUPER_APP__ || {};
const pollInterval = 12000;

const els = {
  stats: {
    orders: document.querySelector('[data-stat="orders"]'),
    customers: document.querySelector('[data-stat="customers"]'),
    products: document.querySelector('[data-stat="products"]'),
    users: document.querySelector('[data-stat="users"]'),
    revenue_last_24h: document.querySelector('[data-stat="revenue_last_24h"]'),
    commission_total: document.querySelector('[data-stat="commission_total"]'),
    commission_period: document.querySelector('[data-stat="commission_period"]'),
    commission_badge: document.querySelector('[data-stat="commission_badge"]'),
    orders_last_24h: document.querySelector('[data-stat="orders_last_24h"]'),
    active_admins: document.querySelector('[data-stat="active_admins"]'),
    failed_today: document.querySelector('[data-stat="failed_today"]'),
  },
  commission: {
    form: document.getElementById('commissionFilter'),
    start: document.getElementById('commissionStart'),
    end: document.getElementById('commissionEnd'),
    clear: document.querySelector('[data-action="clear-commission"]'),
    quickButtons: document.querySelectorAll('[data-range]'),
  },
  statusBars: document.getElementById('statusBars'),
  pendingAlerts: document.getElementById('superPendingAlerts'),
  failedAlerts: document.getElementById('superFailedAlerts'),
  alertsBadge: document.getElementById('alertsBadge'),
  feed: document.getElementById('controlFeed'),
  healthPanel: document.querySelector('.system-grid'),
  stackInfo: document.getElementById('stackInfo'),
  healthFields: {
    errors_last_hour: document.querySelector('[data-health="errors_last_hour"]'),
    orders_with_error: document.querySelector('[data-health="orders_with_error"]'),
    disk_free_percent: document.querySelector('[data-health="disk_free_percent"]'),
    php_version: document.querySelector('[data-health="php_version"]'),
  },
  labels: {
    orders_total: document.querySelector('[data-label="orders_total"]'),
    customers_total: document.querySelector('[data-label="customers_total"]'),
    products_total: document.querySelector('[data-label="products_total"]'),
    users_total: document.querySelector('[data-label="users_total"]'),
    revenue_last_24h: document.querySelector('[data-label="revenue_last_24h"]'),
    orders_last_24h: document.querySelector('[data-label="orders_last_24h"]'),
    failed_today: document.querySelector('[data-label="failed_today"]'),
  },
  healthLabels: {
    errors_last_hour: document.querySelector('[data-health-label="errors_last_hour"]'),
    orders_with_error: document.querySelector('[data-health-label="orders_with_error"]'),
  },
  syncReport: document.getElementById('syncReport'),
  serverClock: document.getElementById('serverClock'),
  fullscreenToggle: document.getElementById('fullscreenToggle'),
  soundToggle: document.getElementById('soundToggle'),
};

const state = {
  timer: null,
  seenOrderKeys: new Set(),
  feedBootstrapped: false,
  audioCtx: null,
  soundEnabled: false,
  commissionFilter: {
    start: '',
    end: '',
  },
  isEditingDates: false,
  labelDefaults: {
    orders_total: '',
    customers_total: '',
    products_total: '',
    users_total: '',
    revenue_last_24h: '',
    orders_last_24h: '',
    failed_today: '',
    errors_last_hour: '',
    orders_with_error: '',
  },
};

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
  if (!Number.isFinite(amount)) return '—';
  try {
    return new Intl.NumberFormat('pt-BR', {
      style: 'currency',
      currency: currency || config.currency || 'USD',
    }).format(amount);
  } catch (err) {
    return `${currency || ''} ${amount.toFixed(2)}`;
  }
}

function statusLabel(status) {
  const map = {
    paid: 'Pago',
    pending: 'Pendente',
    shipped: 'Enviado',
    canceled: 'Cancelado',
    failed: 'Falha',
  };
  return map[status] || status?.toUpperCase() || '—';
}

function updateStats(control) {
  if (!control) return;
  const totals = control.totals || {};
  if (els.stats.orders) els.stats.orders.textContent = totals.orders ?? '—';
  if (els.stats.customers) els.stats.customers.textContent = totals.customers ?? '—';
  if (els.stats.products) els.stats.products.textContent = totals.products ?? '—';
  if (els.stats.users) els.stats.users.textContent = totals.users ?? '—';
  if (els.stats.revenue_last_24h) {
    els.stats.revenue_last_24h.textContent = formatCurrency(
      control.revenue_last_24h ?? 0,
      config.currency
    );
  }
  if (els.stats.commission_total) {
    els.stats.commission_total.textContent = formatCurrency(
      control.commission_total ?? 0,
      config.currency
    );
  }
  updateCommissionPeriodLabel(control.commission_period);
  updatePeriodLabels(control.commission_period);
  syncCommissionInputs(control.commission_period);
  if (els.stats.orders_last_24h) {
    els.stats.orders_last_24h.textContent = control.orders_last_24h ?? '—';
  }
  if (els.stats.active_admins) {
    els.stats.active_admins.textContent = control.active_admins ?? '—';
  }
  if (els.stats.failed_today) {
    els.stats.failed_today.textContent = control.failed_today ?? '—';
  }
}

function cacheLabelDefaults() {
  if (els.labels.orders_total) state.labelDefaults.orders_total = els.labels.orders_total.textContent;
  if (els.labels.customers_total) state.labelDefaults.customers_total = els.labels.customers_total.textContent;
  if (els.labels.products_total) state.labelDefaults.products_total = els.labels.products_total.textContent;
  if (els.labels.users_total) state.labelDefaults.users_total = els.labels.users_total.textContent;
  if (els.labels.revenue_last_24h) state.labelDefaults.revenue_last_24h = els.labels.revenue_last_24h.textContent;
  if (els.labels.orders_last_24h) state.labelDefaults.orders_last_24h = els.labels.orders_last_24h.textContent;
  if (els.labels.failed_today) state.labelDefaults.failed_today = els.labels.failed_today.textContent;
  if (els.healthLabels.errors_last_hour) state.labelDefaults.errors_last_hour = els.healthLabels.errors_last_hour.textContent;
  if (els.healthLabels.orders_with_error) state.labelDefaults.orders_with_error = els.healthLabels.orders_with_error.textContent;
}

function updatePeriodLabels(period) {
  const start = period?.start || state.commissionFilter.start || '';
  const end = period?.end || state.commissionFilter.end || '';
  const isActive = Boolean(start || end);
  if (!isActive) {
    if (els.labels.orders_total) els.labels.orders_total.textContent = state.labelDefaults.orders_total || 'Pedidos';
    if (els.labels.customers_total) {
      els.labels.customers_total.textContent = state.labelDefaults.customers_total || 'Clientes';
    }
    if (els.labels.products_total) {
      els.labels.products_total.textContent = state.labelDefaults.products_total || 'Produtos';
    }
    if (els.labels.users_total) {
      els.labels.users_total.textContent = state.labelDefaults.users_total || 'Usuários';
    }
    if (els.labels.revenue_last_24h) els.labels.revenue_last_24h.textContent = state.labelDefaults.revenue_last_24h || 'Receita (24h)';
    if (els.labels.orders_last_24h) els.labels.orders_last_24h.textContent = state.labelDefaults.orders_last_24h || 'Pedidos (24h)';
    if (els.labels.failed_today) els.labels.failed_today.textContent = state.labelDefaults.failed_today || 'Falhas hoje';
    if (els.healthLabels.errors_last_hour) {
      els.healthLabels.errors_last_hour.textContent = state.labelDefaults.errors_last_hour || 'Erros (última hora)';
    }
    if (els.healthLabels.orders_with_error) {
      els.healthLabels.orders_with_error.textContent = state.labelDefaults.orders_with_error || 'Pedidos com erro';
    }
    return;
  }
  if (els.labels.orders_total) els.labels.orders_total.textContent = 'Pedidos (período)';
  if (els.labels.customers_total) els.labels.customers_total.textContent = 'Clientes (período)';
  if (els.labels.products_total) els.labels.products_total.textContent = 'Produtos (período)';
  if (els.labels.users_total) els.labels.users_total.textContent = 'Usuários (período)';
  if (els.labels.revenue_last_24h) els.labels.revenue_last_24h.textContent = 'Receita (período)';
  if (els.labels.orders_last_24h) els.labels.orders_last_24h.textContent = 'Pedidos (período)';
  if (els.labels.failed_today) els.labels.failed_today.textContent = 'Falhas no período';
  if (els.healthLabels.errors_last_hour) els.healthLabels.errors_last_hour.textContent = 'Erros no período';
  if (els.healthLabels.orders_with_error) els.healthLabels.orders_with_error.textContent = 'Pedidos com erro (período)';
}

function formatDateLabel(value) {
  if (!value) return '';
  const parts = value.split('-');
  if (parts.length !== 3) return value;
  return `${parts[2]}/${parts[1]}/${parts[0]}`;
}

function updateCommissionPeriodLabel(period) {
  if (!els.stats.commission_period) return;
  const start = period?.start || state.commissionFilter.start || '';
  const end = period?.end || state.commissionFilter.end || '';
  if (els.stats.commission_badge) {
    els.stats.commission_badge.textContent = buildBadgeLabel(start, end);
  }
  if (!start && !end) {
    els.stats.commission_period.textContent = 'Período: todo o histórico';
    return;
  }
  if (start && end) {
    els.stats.commission_period.textContent = `Período: ${formatDateLabel(start)} — ${formatDateLabel(end)}`;
    return;
  }
  if (start) {
    els.stats.commission_period.textContent = `Período: desde ${formatDateLabel(start)}`;
    return;
  }
  els.stats.commission_period.textContent = `Período: até ${formatDateLabel(end)}`;
}

function buildBadgeLabel(start, end) {
  if (!start && !end) return 'Todo período';
  if (start && end) return `${formatDateLabel(start)} → ${formatDateLabel(end)}`;
  if (start) return `Desde ${formatDateLabel(start)}`;
  return `Até ${formatDateLabel(end)}`;
}

function syncCommissionInputs(period) {
  if (!els.commission.start || !els.commission.end || !period) return;
  if (state.isEditingDates) return;
  if (period.start !== undefined && els.commission.start.value !== (period.start || '')) {
    els.commission.start.value = period.start || '';
  }
  if (period.end !== undefined && els.commission.end.value !== (period.end || '')) {
    els.commission.end.value = period.end || '';
  }
  state.commissionFilter.start = period.start || '';
  state.commissionFilter.end = period.end || '';
}

function setCommissionFilterFromInputs() {
  state.commissionFilter.start = els.commission.start?.value || '';
  state.commissionFilter.end = els.commission.end?.value || '';
}

function buildApiUrl() {
  const url = new URL(config.apiUrl, window.location.origin);
  if (state.commissionFilter.start) {
    url.searchParams.set('commission_start', state.commissionFilter.start);
  }
  if (state.commissionFilter.end) {
    url.searchParams.set('commission_end', state.commissionFilter.end);
  }
  return url.toString();
}

function renderStatusBars(list = []) {
  if (!els.statusBars) return;
  if (!list.length) {
    els.statusBars.innerHTML = '<p>Sem dados suficientes.</p>';
    return;
  }
  const total = list.reduce((sum, item) => sum + (item.total || 0), 0) || 1;
  els.statusBars.innerHTML = list
    .map((item) => {
      const percent = Math.round(((item.total || 0) / total) * 100);
      return `
        <div class="status-row">
          <span>${escapeHtml(statusLabel(item.status))}</span>
          <div class="status-bar"><div style="width:${percent}%;"></div></div>
          <span>${escapeHtml(item.total)}</span>
        </div>
      `;
    })
    .join('');
}

function renderAlerts(alerts = {}) {
  const pending = Array.isArray(alerts.pending_overdue) ? alerts.pending_overdue : [];
  const failed = Array.isArray(alerts.failed_payments) ? alerts.failed_payments : [];
  const total = pending.length + failed.length;
  if (els.alertsBadge) {
    els.alertsBadge.textContent = `${total} alerta${total === 1 ? '' : 's'}`;
  }
  const pendingHtml = pending.length
    ? pending
        .map(
          (item) => `
            <li>
              <strong>#${escapeHtml(item.id)} • ${escapeHtml(
                formatCurrency(item.total, item.currency)
              )}</strong>
              <span class="meta">Atrasado há ${escapeHtml(formatRelative(item.created_at))}</span>
            </li>
          `
        )
        .join('')
    : '<li class="empty">Sem pendências.</li>';
  const failedHtml = failed.length
    ? failed
        .map(
          (item) => `
            <li>
              <strong>#${escapeHtml(item.id)} • ${escapeHtml(statusLabel(item.payment_status))}</strong>
              <span class="meta">Atualizado ${escapeHtml(formatRelative(item.updated_at))}</span>
            </li>
          `
        )
        .join('')
    : '<li class="empty">Nenhum pagamento falho.</li>';
  if (els.pendingAlerts) els.pendingAlerts.innerHTML = pendingHtml;
  if (els.failedAlerts) els.failedAlerts.innerHTML = failedHtml;
}

function renderFeed(recentOrders = []) {
  if (!els.feed) return;
  if (!recentOrders.length) {
    els.feed.innerHTML = '<div class="activity-item">Sem eventos recentes.</div>';
    state.seenOrderKeys.clear();
    state.feedBootstrapped = true;
    return;
  }
  const pieces = [];
  const maxRemember = 100;
  recentOrders.forEach((order) => {
    const eventKey = `${order.id}-${order.updated_at || order.created_at || ''}`;
    const isNew = !state.seenOrderKeys.has(eventKey);
    pieces.push(
      `
        <div class="activity-item ${isNew && state.feedBootstrapped ? 'highlight' : ''}">
          <strong>${escapeHtml(order.order_code || '#' + order.id)} • ${escapeHtml(
        formatCurrency(order.total, order.currency)
      )}</strong>
          <div>${escapeHtml(order.customer_name || 'Cliente não informado')}</div>
          <div class="meta">${escapeHtml(statusLabel(order.status))} • ${escapeHtml(
        formatRelative(order.updated_at || order.created_at)
      )}</div>
        </div>
      `
    );
    if (isNew && state.feedBootstrapped) {
      playOrderChime();
    }
    state.seenOrderKeys.add(eventKey);
  });
  while (state.seenOrderKeys.size > maxRemember) {
    const first = state.seenOrderKeys.values().next().value;
    if (!first) break;
    state.seenOrderKeys.delete(first);
  }
  els.feed.innerHTML = pieces.join('');
  state.feedBootstrapped = true;
}

function renderHealth(health) {
  if (!health) return;
  Object.entries(els.healthFields).forEach(([key, el]) => {
    if (!el) return;
    let value = health[key];
    if (key === 'disk_free_percent' && Number.isFinite(Number(value))) {
      value = `${Number(value).toFixed(1)}%`;
    }
    el.textContent = value ?? '—';
  });
}

function renderStackInfo(stack = {}) {
  if (!els.stackInfo) return;
  const segments = [];
  Object.entries(stack).forEach(([key, value]) => {
    if (value) {
      segments.push(`<span>${escapeHtml(key)}: ${escapeHtml(value)}</span>`);
    }
  });
  els.stackInfo.innerHTML = segments.length ? segments.join('') : '';
}

function playOrderChime() {
  if (!state.soundEnabled) return;
  try {
    if (!state.audioCtx) {
      state.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
    const ctx = state.audioCtx;
    if (ctx.state === 'suspended') {
      ctx.resume?.();
    }
    const oscillator = ctx.createOscillator();
    const gainNode = ctx.createGain();
    oscillator.type = 'sine';
    oscillator.frequency.value = 660;
    gainNode.gain.value = 0.08;
    oscillator.connect(gainNode);
    gainNode.connect(ctx.destination);
    oscillator.start();
    oscillator.stop(ctx.currentTime + 0.25);
  } catch (_) {}
}

function formatRelative(isoString) {
  const date = new Date(isoString);
  if (Number.isNaN(date.getTime())) return '—';
  const diff = Date.now() - date.getTime();
  const minutes = Math.round(diff / 60000);
  if (minutes < 1) return 'agora';
  if (minutes < 60) return `${minutes} min atrás`;
  const hours = Math.round(minutes / 60);
  if (hours < 24) return `${hours} h atrás`;
  const days = Math.round(hours / 24);
  return `${days} d atrás`;
}

function setServerClock(iso) {
  if (!els.serverClock || !iso) return;
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return;
  els.serverClock.textContent = date.toLocaleTimeString('pt-BR', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  });
}

function setSyncState(state, message) {
  if (!els.syncReport) return;
  els.syncReport.textContent = message;
  els.syncReport.dataset.state = state;
}

async function fetchFeed() {
  setSyncState('loading', 'Sincronizando…');
  try {
    const res = await fetch(buildApiUrl(), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const payload = await res.json();
    if (!payload.ok) throw new Error(payload.error || 'Resposta inválida');
    updateStats(payload.control);
    renderStatusBars(payload.control?.orders_by_status || []);
    renderAlerts(payload.alerts || {});
    renderFeed(payload.control?.recent_orders || []);
    renderHealth(payload.health || {});
    renderStackInfo(payload.control?.stack || {});
    setServerClock(payload.server_time);
    setSyncState('ok', 'Atualizado às ' + new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }));
  } catch (error) {
    console.error(error);
    setSyncState('error', 'Falha ao sincronizar');
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

function setupFullscreen() {
  if (!els.fullscreenToggle) return;
  const update = () => {
    const isFull = document.fullscreenElement != null;
    els.fullscreenToggle.textContent = isFull ? 'Sair do modo tela cheia' : 'Modo tela cheia';
  };
  els.fullscreenToggle.addEventListener('click', () => {
    if (document.fullscreenElement) {
      document.exitFullscreen?.();
    } else {
      document.documentElement.requestFullscreen?.();
    }
  });
  document.addEventListener('fullscreenchange', update);
  update();
}

function initCommissionFilter() {
  if (!els.commission.form) return;
  const params = new URLSearchParams(window.location.search);
  const start = params.get('commission_start') || '';
  const end = params.get('commission_end') || '';
  if (els.commission.start && start) els.commission.start.value = start;
  if (els.commission.end && end) els.commission.end.value = end;
  state.commissionFilter.start = start;
  state.commissionFilter.end = end;
  updateCommissionPeriodLabel({ start, end });

  els.commission.form.addEventListener('submit', (event) => {
    event.preventDefault();
    setCommissionFilterFromInputs();
    fetchFeed();
  });

  if (els.commission.clear) {
    els.commission.clear.addEventListener('click', () => {
      if (els.commission.start) els.commission.start.value = '';
      if (els.commission.end) els.commission.end.value = '';
      state.commissionFilter.start = '';
      state.commissionFilter.end = '';
      fetchFeed();
    });
  }

  const onFocus = () => {
    state.isEditingDates = true;
  };
  const onBlur = () => {
    state.isEditingDates = false;
  };
  els.commission.start?.addEventListener('focus', onFocus);
  els.commission.end?.addEventListener('focus', onFocus);
  els.commission.start?.addEventListener('blur', onBlur);
  els.commission.end?.addEventListener('blur', onBlur);

  els.commission.quickButtons?.forEach((btn) => {
    btn.addEventListener('click', () => {
      const today = new Date();
      const endDate = today.toISOString().slice(0, 10);
      let startDate = endDate;
      const range = btn.getAttribute('data-range');
      if (range === '7d') {
        const d = new Date(today);
        d.setDate(d.getDate() - 6);
        startDate = d.toISOString().slice(0, 10);
      } else if (range === '30d') {
        const d = new Date(today);
        d.setDate(d.getDate() - 29);
        startDate = d.toISOString().slice(0, 10);
      }
      if (els.commission.start) els.commission.start.value = startDate;
      if (els.commission.end) els.commission.end.value = endDate;
      setCommissionFilterFromInputs();
      fetchFeed();
    });
  });
}

function updateSoundLabel() {
  if (!els.soundToggle) return;
  els.soundToggle.textContent = state.soundEnabled ? 'Som: ligado' : 'Som: desligado';
}

function enableSound() {
  state.soundEnabled = true;
  localStorage.setItem('superadmin_sound', '1');
  if (!state.audioCtx) {
    state.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
  }
  if (state.audioCtx.state === 'suspended') {
    state.audioCtx.resume?.();
  }
  updateSoundLabel();
}

function disableSound() {
  state.soundEnabled = false;
  localStorage.setItem('superadmin_sound', '0');
  updateSoundLabel();
}

function initSoundToggle() {
  if (!els.soundToggle) return;
  state.soundEnabled = localStorage.getItem('superadmin_sound') === '1';
  updateSoundLabel();
  els.soundToggle.addEventListener('click', () => {
    if (state.soundEnabled) {
      disableSound();
    } else {
      enableSound();
    }
  });
}

document.addEventListener('visibilitychange', handleVisibility);
cacheLabelDefaults();
initCommissionFilter();
initSoundToggle();
setupFullscreen();
fetchFeed();
