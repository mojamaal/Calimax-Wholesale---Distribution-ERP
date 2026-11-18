/* Calimax ERP — shared order-line editor (sales orders & purchase orders) and serial scanner */
(function () {
  'use strict';
  const U = window.CalimaxUI, esc = U.esc, money = U.money;
  const APP = window.APP;
  const fmt = (n) => (Number(n) || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
  const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

  /**
   * LineEditor — mode 'sale' | 'purchase'.
   * cfg: { mode, body, search, results, jsonInput, totalsEl, getCustomer(), getWarehouse(), onChange(totals), items }
   * Each line: { product_id, code, name, tracking, base_unit, packs:[{name,factor}], pack_name, pack_factor, pack_qty,
   *              unit_price (per BASE unit), list_price, break_pct, discount_pct, tax_rate, stock, min_order_qty, source, cost }
   */
  function LineEditor(cfg) {
    const mode = cfg.mode, body = cfg.body;
    let items = cfg.items || [];

    function lineCalc(it) {
      const qtyBase = (+it.pack_qty || 0) * (+it.pack_factor || 1);
      const gross = qtyBase * (+it.unit_price || 0);
      const net = Math.round(gross * (1 - (+it.discount_pct || 0) / 100) * 100) / 100;
      const tax = Math.round(net * (+it.tax_rate || 0)) / 100;
      return { qtyBase, gross, net, tax, total: net + tax };
    }
    function render() {
      let sub = 0, disc = 0, tax = 0;
      body.innerHTML = items.map((it, i) => {
        const c = lineCalc(it); sub += c.net; disc += c.gross - c.net; tax += c.tax;
        const packOpts = it.packs.map((p) => '<option value="' + p.factor + '" data-name="' + esc(p.name) + '"' + (+p.factor === +it.pack_factor ? ' selected' : '') + '>' + esc(p.name) + (p.factor > 1 ? ' (' + p.factor + ')' : '') + '</option>').join('');
        const perPack = (+it.unit_price || 0) * (+it.pack_factor || 1);
        let warn = '';
        if (mode === 'sale') {
          if (it.min_order_qty && c.qtyBase < it.min_order_qty) warn = '<div class="text-error text-sm">Min. order ' + fmt(it.min_order_qty) + ' ' + esc(it.base_unit) + '</div>';
          else if (it.stock != null && c.qtyBase > it.stock) warn = '<div class="text-warning text-sm">Only ' + fmt(Math.max(it.stock, 0)) + ' available — partial fulfilment</div>';
        }
        const stockCell = mode === 'sale'
          ? '<td class="num ' + (it.stock <= 0 ? 'stock-out' : (c.qtyBase > it.stock ? 'stock-low' : 'stock-ok')) + '">' + fmt(it.stock) + '</td>'
          : '<td class="num text-secondary">' + fmt(it.stock) + '</td>';
        return '<tr data-i="' + i + '"><td><div class="strong">' + esc(it.name) + '</div><div class="cell-sub mono">' + esc(it.code) + ' ' + (it.tracking !== 'none' ? '<span class="tag ' + it.tracking + '">' + it.tracking + '</span>' : '') + '</div>' + warn + '</td>'
          + stockCell
          + '<td style="width:150px"><select class="form-control" data-f="pack">' + packOpts + '</select></td>'
          + '<td style="width:110px"><input class="form-control num-input" type="number" min="0" step="any" value="' + (it.pack_qty || '') + '" data-f="qty"></td>'
          + '<td style="width:150px"><input class="form-control num-input" type="number" min="0" step="0.01" value="' + (Math.round(perPack * 100) / 100) + '" data-f="price" ' + (mode === 'sale' && !cfg.canEditPrice ? 'readonly title="Only a sales manager can change prices"' : '') + '>'
          + (mode === 'sale' ? '<div class="price-src ' + esc(it.source || '') + '">' + (it.source === 'customer' ? 'Negotiated price' : it.source === 'category' ? 'Category price' : 'List price') + '</div>' : '') + '</td>'
          + (mode === 'sale' ? '<td style="width:90px"><input class="form-control num-input" type="number" min="' + (it.break_pct || 0) + '" max="100" step="0.01" value="' + (+it.discount_pct || 0) + '" data-f="disc">' + (it.break_pct > 0 ? '<div class="price-src customer">Bulk ' + it.break_pct + '%</div>' : '') + '</td>' : '')
          + '<td class="num strong">' + money(mode === 'sale' ? c.total : c.net) + '</td>'
          + '<td class="actions"><button type="button" class="btn btn-secondary btn-sm btn-icon" data-rm="' + i + '" aria-label="Remove">&times;</button></td></tr>';
      }).join('');
      const totals = { subtotal: sub, discount: disc, tax: tax, total: sub + tax, lines: items.length, units: items.reduce((s, i) => s + lineCalc(i).qtyBase, 0) };
      if (cfg.totalsEl) {
        cfg.totalsEl.innerHTML = (mode === 'sale' && disc > 0 ? '<div class="summary-row"><span>Discounts</span><span>− ' + money(disc) + '</span></div>' : '')
          + '<div class="summary-row"><span>Subtotal</span><span>' + money(sub) + '</span></div><div class="summary-row"><span>Tax</span><span>' + money(tax) + '</span></div><div class="summary-row total"><span>Total</span><span>' + money(sub + tax) + '</span></div>';
      }
      if (cfg.emptyEl) cfg.emptyEl.hidden = items.length > 0;
      if (cfg.jsonInput) cfg.jsonInput.value = JSON.stringify(items.map((it) => ({ product_id: it.product_id, pack_name: it.pack_name, pack_factor: it.pack_factor, pack_qty: it.pack_qty, [mode === 'purchase' ? 'unit_cost' : 'unit_price']: (+it.unit_price || 0) * (+it.pack_factor || 1), discount_pct: it.discount_pct || 0 })));
      if (cfg.onChange) cfg.onChange(totals, items);
      U.refreshIcons();
    }
    const reprice = debounce(() => {
      if (mode !== 'sale') return;
      const cust = cfg.getCustomer ? cfg.getCustomer() : '';
      // Break pricing depends on the total base quantity per product.
      const perProduct = {};
      items.forEach((it) => { perProduct[it.product_id] = (perProduct[it.product_id] || 0) + lineCalc(it).qtyBase; });
      Object.keys(perProduct).forEach((pid) => {
        fetch(APP.url + 'api/price.php?product_id=' + pid + '&customer_id=' + encodeURIComponent(cust) + '&qty=' + perProduct[pid]).then((r) => r.json()).then((p) => {
          let changed = false;
          items.filter((it) => it.product_id == pid).forEach((it) => {
            if (!it.manualPrice) { it.unit_price = p.price; changed = true; }
            it.list_price = p.price; it.source = p.source; it.break_pct = p.break_pct; it.tax_rate = p.tax_rate;
            if (!it.manualDisc || it.discount_pct < p.break_pct) it.discount_pct = Math.max(p.break_pct, it.manualDisc ? it.discount_pct : 0);
          });
          render();
        });
      });
    }, 250);

    body.addEventListener('change', (e) => {
      const tr = e.target.closest('tr'); if (!tr) return;
      const it = items[+tr.dataset.i], f = e.target.dataset.f;
      if (f === 'pack') { const o = e.target.selectedOptions[0]; it.pack_factor = +e.target.value; it.pack_name = o.dataset.name; }
      if (f === 'qty') it.pack_qty = parseFloat(e.target.value) || 0;
      if (f === 'price') { it.unit_price = (parseFloat(e.target.value) || 0) / (it.pack_factor || 1); it.manualPrice = true; }
      if (f === 'disc') { it.discount_pct = parseFloat(e.target.value) || 0; it.manualDisc = true; }
      render(); if (f === 'qty' || f === 'pack') reprice();
    });
    body.addEventListener('click', (e) => { const b = e.target.closest('[data-rm]'); if (b) { items.splice(+b.dataset.rm, 1); render(); reprice(); } });

    function add(p) {
      const wh = cfg.getWarehouse ? cfg.getWarehouse() : '';
      const existing = items.find((i) => i.product_id == p.id);
      if (existing) { existing.pack_qty = (+existing.pack_qty || 0) + 1; render(); reprice(); return; }
      const defPack = p.packs.length > 1 ? p.packs[1] : p.packs[0];
      items.push({ product_id: +p.id, code: p.code, name: p.name, tracking: p.tracking, base_unit: p.base_unit, packs: p.packs, pack_name: defPack.name, pack_factor: +defPack.factor, pack_qty: 1,
        unit_price: mode === 'sale' ? +p.price : +p.cost, list_price: +p.price, break_pct: 0, discount_pct: 0, tax_rate: +p.tax_rate, stock: +(p.stock || 0), min_order_qty: +(p.min_order_qty || 0), source: p.source, cost: +p.cost });
      render(); reprice();
    }
    // Product search
    let timer = null, seq = 0;
    cfg.search.addEventListener('input', () => {
      clearTimeout(timer);
      const q = cfg.search.value.trim();
      if (!q) { cfg.results.hidden = true; return; }
      timer = setTimeout(() => {
        const my = ++seq;
        const url = APP.url + 'api/product_search.php?q=' + encodeURIComponent(q) + '&mode=' + mode + '&customer_id=' + encodeURIComponent(cfg.getCustomer ? cfg.getCustomer() : '') + '&warehouse_id=' + encodeURIComponent(cfg.getWarehouse ? cfg.getWarehouse() : '');
        fetch(url).then((r) => r.json()).then((list) => {
          if (my !== seq) return;
          cfg.results.innerHTML = list.length ? '' : '<div class="search-empty">No matching products.</div>';
          list.forEach((p) => {
            const row = document.createElement('div'); row.className = 'search-item';
            row.innerHTML = '<span class="si-main"><div class="si-title">' + esc(p.name) + '</div><div class="si-sub">' + esc(p.code) + (p.tracking !== 'none' ? ' · ' + p.tracking + ' tracked' : '') + '</div></span><span class="si-meta">' + (mode === 'sale' ? fmt(p.stock) + ' available' : fmt(p.stock) + ' on hand') + '</span>';
            row.addEventListener('click', () => { add(p); cfg.results.hidden = true; cfg.search.value = ''; cfg.search.focus(); });
            cfg.results.appendChild(row);
          });
          cfg.results.hidden = false;
        });
      }, 180);
    });
    cfg.search.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); const f = cfg.results.querySelector('.search-item'); if (f) f.click(); } });
    document.addEventListener('click', (e) => { if (e.target !== cfg.search && !cfg.results.contains(e.target)) cfg.results.hidden = true; });
    render();
    return { render, reprice, items: () => items, set: (arr) => { items = arr; render(); } };
  }

  /**
   * SerialScanner — enter/scan serial numbers into a list.
   * el: container with [data-sn-input], [data-sn-list], [data-sn-count], hidden textarea [data-sn-value] (one per line)
   * opts.expected: required count (receiving) ; opts.validate(serial) -> Promise<{ok, message}> (picking)
   */
  function SerialScanner(el, opts) {
    opts = opts || {};
    const input = el.querySelector('[data-sn-input]'), list = el.querySelector('[data-sn-list]'), count = el.querySelector('[data-sn-count]'), hidden = el.querySelector('[data-sn-value]');
    let serials = (hidden.value || '').split(/\r?\n/).map((s) => s.trim()).filter(Boolean);
    function draw() {
      const seen = {};
      list.innerHTML = serials.map((s, i) => { const dup = seen[s.toUpperCase()]; seen[s.toUpperCase()] = 1; return '<span class="sn-chip' + (dup ? ' dup' : '') + '">' + esc(s) + '<button type="button" data-rm="' + i + '" aria-label="Remove">&times;</button></span>'; }).join('');
      hidden.value = serials.join('\n');
      if (count) { count.textContent = serials.length + (opts.expected != null ? ' / ' + opts.expected : '') + ' scanned'; count.className = 'text-sm fw-700 ' + (opts.expected != null ? (serials.length === opts.expected ? 'text-success' : 'text-warning') : ''); }
      if (opts.onChange) opts.onChange(serials);
    }
    function addMany(text) {
      text.split(/[\s,;]+/).map((s) => s.trim()).filter(Boolean).forEach((s) => {
        if (serials.some((x) => x.toUpperCase() === s.toUpperCase())) { U.toast('Serial ' + s + ' is already in the list.', 'error'); return; }
        if (opts.validate) { opts.validate(s).then((r) => { if (r.ok) { serials.push(r.serial || s); draw(); } else U.toast(r.message, 'error'); }); }
        else serials.push(s);
      });
      draw();
    }
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); if (input.value.trim()) { addMany(input.value); input.value = ''; } } });
    input.addEventListener('paste', () => setTimeout(() => { if (/[\s,;]/.test(input.value.trim())) { addMany(input.value); input.value = ''; } }, 0));
    const addBtn = el.querySelector('[data-sn-add]'); if (addBtn) addBtn.addEventListener('click', () => { if (input.value.trim()) { addMany(input.value); input.value = ''; input.focus(); } });
    const range = el.querySelector('[data-sn-range]'); if (range) range.addEventListener('click', () => {
      const prefix = prompt('Serial prefix (e.g. DELL-SN-)', ''); if (prefix === null) return;
      const from = parseInt(prompt('First number', '1'), 10), to = parseInt(prompt('Last number', String(opts.expected || from)), 10);
      if (isNaN(from) || isNaN(to) || to < from || to - from > 2000) { U.toast('Enter a valid range.', 'error'); return; }
      const pad = parseInt(prompt('Digits (zero padding, e.g. 4 → 0001)', '4'), 10) || 0;
      const out = []; for (let i = from; i <= to; i++) out.push(prefix + String(i).padStart(pad, '0'));
      addMany(out.join(' '));
    });
    list.addEventListener('click', (e) => { const b = e.target.closest('[data-rm]'); if (b) { serials.splice(+b.dataset.rm, 1); draw(); } });
    draw();
    return { get: () => serials, set: (a) => { serials = a; draw(); }, setExpected: (n) => { opts.expected = n; draw(); } };
  }

  window.CalimaxLines = { LineEditor, SerialScanner };
})();
