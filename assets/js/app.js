/* Calimax ERP — shared front-end behaviour */
(function () {
  'use strict';

  const APP = window.APP || { url: '/', currency: 'Rs.' };
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const money = (n) => APP.currency + ' ' + (Number(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const refreshIcons = () => { if (window.lucide) window.lucide.createIcons(); };

  function toast(message, type) {
    let stack = document.querySelector('.flash-stack');
    if (!stack) { stack = document.createElement('div'); stack.className = 'flash-stack'; document.body.appendChild(stack); }
    const el = document.createElement('div');
    el.className = 'alert alert-' + (type || 'success');
    el.innerHTML = '<span>' + esc(message) + '</span><button class="alert-close" type="button" aria-label="Dismiss">&times;</button>';
    stack.appendChild(el);
    setTimeout(() => dismiss(el), type === 'error' ? 6000 : 3500);
  }
  function dismiss(el) { el.style.transition = 'opacity .3s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }

  document.addEventListener('DOMContentLoaded', () => {
    refreshIcons();
    document.querySelectorAll('[data-autodismiss]').forEach((el) => setTimeout(() => dismiss(el), el.classList.contains('alert-error') ? 8000 : 4500));
  });

  // ---- Modals, dropdowns, nav drawer, confirm --------------------------
  function closeAllDropdowns(except) {
    document.querySelectorAll('[data-dropdown] .dropdown-panel').forEach((p) => { if (p !== except) p.hidden = true; });
  }
  document.addEventListener('click', (e) => {
    const t = e.target;
    const opener = t.closest('[data-modal-open]');
    if (opener) {
      const modal = document.querySelector(opener.getAttribute('data-modal-open'));
      if (modal) { modal.hidden = false; const f = modal.querySelector('input:not([type=hidden]),select,textarea'); if (f) setTimeout(() => f.focus(), 30); }
    }
    const closer = t.closest('[data-modal-close]');
    if (closer) { const m = closer.closest('.modal-overlay'); if (m) m.hidden = true; }
    if (t.classList && t.classList.contains('modal-overlay') && !t.hasAttribute('data-static')) t.hidden = true;

    const ddToggle = t.closest('[data-dropdown-toggle]');
    if (ddToggle) {
      const panel = ddToggle.parentElement.querySelector('.dropdown-panel');
      closeAllDropdowns(panel);
      panel.hidden = !panel.hidden;
    } else if (!t.closest('.dropdown-panel')) {
      closeAllDropdowns();
    }

    if (t.closest('[data-nav-toggle]')) document.body.classList.toggle('nav-open');
    if (t.closest('[data-nav-close]')) document.body.classList.remove('nav-open');
    if (t.closest('.alert-close')) dismiss(t.closest('.alert'));
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-overlay:not([hidden])').forEach((m) => { if (!m.hasAttribute('data-static')) m.hidden = true; });
      closeAllDropdowns();
      document.body.classList.remove('nav-open');
    }
  });
  // <form data-confirm="Are you sure?">
  document.addEventListener('submit', (e) => {
    const msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
    const btn = e.target.querySelector('button[type=submit]:not([data-no-busy])');
    if (btn && !e.defaultPrevented) { setTimeout(() => { btn.disabled = true; }, 0); }
  });

  // ---- Global search ----------------------------------------------------
  const gInput = document.getElementById('globalSearchInput');
  const gBox = document.getElementById('globalSearchResults');
  if (gInput && gBox) {
    let timer = null, items = [], focus = -1, seq = 0;
    const groupIcons = { products: 'package', customers: 'contact', orders: 'clipboard-list', invoices: 'receipt', suppliers: 'building-2', purchases: 'file-text', trace: 'barcode' };
    const groupLabels = { products: 'Products', customers: 'Customers', orders: 'Sales orders', invoices: 'Invoices', suppliers: 'Suppliers', purchases: 'Purchase orders', trace: 'Serial & batch numbers' };
    function render(data) {
      items = []; focus = -1;
      let html = '';
      Object.keys(groupLabels).forEach((g) => {
        if (!data[g] || !data[g].length) return;
        html += '<div class="search-group">' + groupLabels[g] + '</div>';
        data[g].forEach((r) => {
          items.push(r.url);
          html += '<a class="search-item" href="' + esc(r.url) + '" data-idx="' + (items.length - 1) + '"><span class="si-icon"><i data-lucide="' + groupIcons[g] + '" style="width:16px;height:16px"></i></span>'
            + '<span class="si-main"><div class="si-title">' + esc(r.title) + '</div>' + (r.sub ? '<div class="si-sub">' + esc(r.sub) + '</div>' : '') + '</span>'
            + (r.meta ? '<span class="si-meta">' + esc(r.meta) + '</span>' : '') + '</a>';
        });
      });
      gBox.innerHTML = html || '<div class="search-empty">No matches for “' + esc(gInput.value) + '”.</div>';
      gBox.hidden = false;
      refreshIcons();
    }
    gInput.addEventListener('input', () => {
      clearTimeout(timer);
      const q = gInput.value.trim();
      if (q.length < 2) { gBox.hidden = true; return; }
      timer = setTimeout(() => {
        const my = ++seq;
        fetch(APP.url + 'api/global_search.php?q=' + encodeURIComponent(q)).then((r) => r.json()).then((d) => { if (my === seq) render(d); }).catch(() => {});
      }, 200);
    });
    gInput.addEventListener('keydown', (e) => {
      const els = gBox.querySelectorAll('.search-item');
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!els.length) return;
        focus = (focus + (e.key === 'ArrowDown' ? 1 : -1) + els.length) % els.length;
        els.forEach((el, i) => el.classList.toggle('focused', i === focus));
        els[focus].scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Enter') {
        if (focus >= 0 && items[focus]) { e.preventDefault(); window.location = items[focus]; }
        else if (els.length === 1) { e.preventDefault(); window.location = items[0]; }
      } else if (e.key === 'Escape') { gBox.hidden = true; gInput.blur(); }
    });
    document.addEventListener('click', (e) => { if (!e.target.closest('#globalSearch')) gBox.hidden = true; });
    document.addEventListener('keydown', (e) => {
      if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName) && !document.body.classList.contains('pos-page')) {
        e.preventDefault(); gInput.focus();
      }
    });
  }

  // ---- Column chart (single series, SVG) ---------------------------------
  // <div class="chart-wrap" data-chart='{"labels":[..],"values":[..],"tips":[..]}'></div>
  function niceMax(v) {
    if (v <= 0) return 1;
    const p = Math.pow(10, Math.floor(Math.log10(v)));
    const n = v / p;
    return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10) * p;
  }
  function compact(v) {
    if (v >= 1e6) return (v / 1e6).toFixed(v % 1e6 ? 1 : 0) + 'M';
    if (v >= 1e4) return Math.round(v / 1e3) + 'K';
    return Math.round(v).toLocaleString();
  }
  function drawChart(wrap) {
    let cfg; try { cfg = JSON.parse(wrap.getAttribute('data-chart')); } catch (e) { return; }
    const vals = cfg.values || [], labels = cfg.labels || [], tips = cfg.tips || [];
    const W = wrap.clientWidth || 600, H = 240, padL = 44, padB = 26, padT = 10;
    const max = niceMax(Math.max(...vals, 0));
    const plotW = W - padL - 4, plotH = H - padB - padT;
    const n = vals.length || 1, band = plotW / n, bw = Math.max(2, Math.min(24, band * 0.62));
    let s = '<svg class="chart-svg" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + esc(cfg.title || 'Chart') + '">';
    for (let i = 0; i <= 4; i++) {
      const y = padT + plotH - (plotH * i / 4);
      s += '<line class="grid-line" x1="' + padL + '" x2="' + (W - 4) + '" y1="' + y + '" y2="' + y + '"/>';
      s += '<text class="axis-label" x="' + (padL - 8) + '" y="' + (y + 4) + '" text-anchor="end">' + compact(max * i / 4) + '</text>';
    }
    const every = Math.ceil(n / Math.max(1, Math.floor(plotW / 56)));
    vals.forEach((v, i) => {
      const x = padL + band * i + (band - bw) / 2;
      const h = Math.max(v > 0 ? 2 : 0, plotH * v / max);
      const y = padT + plotH - h, r = Math.min(4, bw / 2, h);
      const path = h > 0 ? 'M' + x + ',' + (padT + plotH) + 'V' + (y + r) + 'Q' + x + ',' + y + ' ' + (x + r) + ',' + y + 'H' + (x + bw - r) + 'Q' + (x + bw) + ',' + y + ' ' + (x + bw) + ',' + (y + r) + 'V' + (padT + plotH) + 'Z' : '';
      s += '<rect class="bar-hit" data-i="' + i + '" x="' + (padL + band * i) + '" y="' + padT + '" width="' + band + '" height="' + plotH + '"/>';
      if (path) s += '<path class="bar" d="' + path + '"/>';
      if (i % every === 0) s += '<text class="axis-label" x="' + (x + bw / 2) + '" y="' + (H - 8) + '" text-anchor="middle">' + esc(labels[i]) + '</text>';
    });
    s += '</svg><div class="chart-tip" hidden></div>';
    wrap.innerHTML = s;
    const tip = wrap.querySelector('.chart-tip');
    wrap.querySelectorAll('.bar-hit').forEach((hit) => {
      hit.addEventListener('mouseenter', () => {
        const i = +hit.getAttribute('data-i');
        const rect = hit.getBoundingClientRect(), wr = wrap.getBoundingClientRect();
        const h = Math.max(0, plotH * vals[i] / max);
        tip.innerHTML = '<div>' + esc(tips[i] || labels[i]) + '</div><div class="ct-v">' + esc(cfg.format === 'count' ? vals[i] : money(vals[i])) + '</div>' + (cfg.sub && cfg.sub[i] ? '<div>' + esc(cfg.sub[i]) + '</div>' : '');
        tip.style.left = (rect.left - wr.left + rect.width / 2) + 'px';
        tip.style.top = ((padT + plotH - h) * (wr.height / H)) + 'px';
        tip.hidden = false;
      });
      hit.addEventListener('mouseleave', () => { tip.hidden = true; });
    });
  }
  function drawAllCharts() { document.querySelectorAll('[data-chart]').forEach(drawChart); }
  document.addEventListener('DOMContentLoaded', drawAllCharts);
  let rt; window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(drawAllCharts, 150); });

  window.CalimaxUI = { toast, esc, money, refreshIcons, drawAllCharts };
})();
