/* Calimax Retail ERP — POS terminal */
(function () {
  'use strict';

  const CFG = window.POS || {};
  const APP = window.APP;
  const { esc, toast, money, refreshIcons } = window.CalimaxUI;
  const $ = (s) => document.querySelector(s);
  const $$ = (s) => document.querySelectorAll(s);
  const r2 = (n) => Math.round((Number(n) || 0) * 100) / 100;
  const ico = (n, s) => '<i data-lucide="' + n + '" style="width:' + (s || 14) + 'px;height:' + (s || 14) + 'px"></i>';
  const methodLabels = { cash: 'Cash', card: 'Card', bank_transfer: 'Bank Transfer', mobile_wallet: 'Mobile Payment', store_credit: 'Credit (On Account)' };

  const state = {
    cart: [], billDiscount: 0, customer: null, payments: [], resumeSaleId: null, invoiceNumber: null,
    exchange: null, selectedMethod: null, category: '', lastSaleId: null, override: false, busy: false,
  };

  function post(url, payload) {
    return fetch(APP.url + url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
      .then((r) => r.json().catch(() => ({ error: 'Unexpected server response.' })));
  }

  // ------------------------------------------------------------------
  // Product browsing & scanning
  // ------------------------------------------------------------------
  let searchSeq = 0;
  function fetchProducts(q) {
    const my = ++searchSeq;
    const url = 'api/variant_search.php?q=' + encodeURIComponent(q || '') + (state.category ? '&category_id=' + state.category : '');
    fetch(APP.url + url).then((r) => r.json()).then((list) => { if (my === searchSeq) renderGrid(list); }).catch(() => {});
  }
  let lastGrid = [];
  function renderGrid(list) {
    lastGrid = list;
    const grid = $('#productGrid');
    if (!list.length) { grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1;"><div class="title">No products found</div><div class="hint">Try another name, SKU or barcode.</div></div>'; return; }
    grid.innerHTML = list.map((v, i) => {
      const label = v.product_name + (v.variant_label ? ' — ' + v.variant_label : '');
      const stock = Number(v.stock_qty);
      const cls = stock <= 0 ? 'out' : (stock <= Number(v.reorder_level) ? 'low' : '');
      const thumb = v.image ? '<img src="' + APP.url + 'uploads/products/' + encodeURIComponent(v.image) + '" alt="" loading="lazy">' : esc((v.product_name || '?').substring(0, 2).toUpperCase());
      return '<button type="button" class="pos-product-card" data-i="' + i + '"' + (stock <= 0 ? ' disabled' : '') + ' title="' + esc(label) + '">'
        + '<div class="pos-product-thumb">' + thumb + '<span class="stock-dot ' + cls + '"></span></div>'
        + '<div class="pname">' + esc(label) + '</div>'
        + '<div class="pmeta">' + (stock > 0 ? stock + ' ' + esc(v.unit || 'pcs') + ' in stock' : 'Out of stock') + '</div>'
        + '<div class="pfooter"><span class="pprice">' + money(v.price) + '</span></div></button>';
    }).join('');
  }
  $('#productGrid').addEventListener('click', (e) => {
    const card = e.target.closest('.pos-product-card');
    if (card && !card.disabled) addToCart(lastGrid[+card.getAttribute('data-i')]);
  });
  $('#catChips').addEventListener('click', (e) => {
    const chip = e.target.closest('.chip');
    if (!chip) return;
    $$('#catChips .chip').forEach((c) => c.classList.toggle('active', c === chip));
    state.category = chip.getAttribute('data-cat');
    fetchProducts(scanInput.value.trim());
  });

  const scanInput = $('#scanInput');
  let searchTimer = null;
  scanInput.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => fetchProducts(scanInput.value.trim()), 220); });
  scanInput.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const val = scanInput.value.trim();
    if (!val) return;
    fetch(APP.url + 'api/variant_search.php?barcode=' + encodeURIComponent(val)).then((r) => r.json()).then((v) => {
      if (v) { addToCart(v); scanInput.value = ''; fetchProducts(''); }
      else if (lastGrid.length === 1) { addToCart(lastGrid[0]); scanInput.value = ''; fetchProducts(''); }
      else { toast('No product matches “' + val + '”. Pick it from the list.', 'error'); fetchProducts(val); }
    });
  });

  // ------------------------------------------------------------------
  // Cart
  // ------------------------------------------------------------------
  function addToCart(v, qty) {
    qty = qty || 1;
    const id = Number(v.id);
    const existing = state.cart.find((i) => i.variant_id === id);
    if (existing) {
      if (existing.qty + qty > existing.stock_qty) { toast('Only ' + existing.stock_qty + ' in stock.', 'error'); return; }
      existing.qty += qty;
      renderCart(id);
      return;
    }
    if (Number(v.stock_qty) <= 0) { toast('Out of stock.', 'error'); return; }
    state.cart.unshift({
      variant_id: id, product_name: v.product_name, variant_label: v.variant_label, sku: v.sku,
      price: Number(v.price), tax_rate: Number(v.tax_rate) || 0, qty: Math.min(qty, Number(v.stock_qty)),
      stock_qty: Number(v.stock_qty), disc: Number(v.discount_amount) || 0,
    });
    renderCart(id);
  }

  function renderCart(flashId) {
    const wrap = $('#cartItems');
    if (!state.cart.length) {
      wrap.innerHTML = '<div class="cart-empty"><div class="icon-tile">' + ico('shopping-basket', 22) + '</div><div class="title">No items yet</div><div class="hint">Scan a barcode or tap a product to start the sale.</div></div>';
    } else {
      const t = computeTotals();
      wrap.innerHTML = state.cart.map((it, idx) => {
        const lt = t.lines[idx];
        return '<div class="cart-line' + (it.variant_id === flashId ? ' flash' : '') + '">'
          + '<div class="info"><div class="n" title="' + esc(it.product_name) + '">' + esc(it.product_name) + (it.variant_label ? ' — ' + esc(it.variant_label) : '') + '</div>'
          + '<div class="m">' + money(it.price) + ' each' + (it.disc > 0 ? ' · <span class="disc">−' + money(it.disc) + '</span>' : '') + '</div></div>'
          + '<div class="qty-controls"><button type="button" class="qty-btn" data-dec="' + idx + '" aria-label="Decrease">−</button>'
          + '<input class="qty-input" type="number" min="1" max="' + it.stock_qty + '" value="' + it.qty + '" data-qty="' + idx + '" aria-label="Quantity">'
          + '<button type="button" class="qty-btn" data-inc="' + idx + '" aria-label="Increase">+</button></div>'
          + '<div class="line-actions"><button type="button" class="line-btn" data-disc="' + idx + '" title="Line discount">' + ico('tag') + '</button>'
          + '<button type="button" class="line-btn remove" data-remove="' + idx + '" title="Remove">' + ico('x') + '</button></div>'
          + '<div class="line-total">' + money(lt.line_total) + '</div></div>';
      }).join('');
    }
    refreshIcons();
    $('#listItemHeading').textContent = 'Items (' + state.cart.reduce((s, i) => s + i.qty, 0) + ')';
    updateSummary();
  }

  $('#cartItems').addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.inc !== undefined) { const it = state.cart[b.dataset.inc]; if (it.qty < it.stock_qty) it.qty++; else toast('Only ' + it.stock_qty + ' in stock.', 'error'); renderCart(); }
    else if (b.dataset.dec !== undefined) { const it = state.cart[b.dataset.dec]; if (it.qty > 1) { it.qty--; it.disc = Math.min(it.disc, it.qty * it.price); renderCart(); } }
    else if (b.dataset.remove !== undefined) { state.cart.splice(+b.dataset.remove, 1); renderCart(); }
    else if (b.dataset.disc !== undefined) { openDiscount(+b.dataset.disc); }
  });
  $('#cartItems').addEventListener('change', (e) => {
    if (e.target.dataset.qty === undefined) return;
    const it = state.cart[e.target.dataset.qty];
    let q = parseInt(e.target.value, 10) || 1;
    if (q > it.stock_qty) { toast('Only ' + it.stock_qty + ' in stock.', 'error'); q = it.stock_qty; }
    it.qty = Math.max(1, q);
    it.disc = Math.min(it.disc, it.qty * it.price);
    renderCart();
  });
  $('#clearCartBtn').addEventListener('click', () => { if (state.cart.length && confirm('Clear the current sale?')) resetSale(); });

  // ------------------------------------------------------------------
  // Totals — mirrors api/pos_checkout.php exactly
  // ------------------------------------------------------------------
  function computeTotals() {
    let subtotal = 0, lineDisc = 0;
    const base = state.cart.map((i) => {
      const gross = r2(i.price * i.qty), d = Math.min(r2(i.disc), gross);
      subtotal += gross; lineDisc += d;
      return { gross, d, rate: i.tax_rate };
    });
    const netBefore = subtotal - lineDisc;
    const bill = Math.min(r2(state.billDiscount), netBefore);
    let allocated = 0, tax = 0, total = 0;
    const lines = base.map((l, idx) => {
      const b = l.gross - l.d;
      const alloc = idx === base.length - 1 ? r2(bill - allocated) : (netBefore > 0 ? r2(bill * b / netBefore) : 0);
      allocated += alloc;
      const net = Math.max(0, b - alloc);
      let lt, tx;
      if (CFG.pricesIncludeTax) { tx = l.rate > 0 ? r2(net - net / (1 + l.rate / 100)) : 0; lt = r2(net); }
      else { tx = r2(net * l.rate / 100); lt = r2(net + tx); }
      tax += tx; total += lt;
      return { line_total: lt, tax: tx };
    });
    total = r2(total);
    const exchange = state.exchange ? Math.min(state.exchange.amount, total) : 0;
    return { subtotal: r2(subtotal), discount: r2(lineDisc + bill), tax: r2(tax), total, lines, exchange, due: r2(total - exchange) };
  }
  function paid() { return r2(state.payments.reduce((s, p) => s + p.amount, 0)); }
  function remaining() { return Math.max(0, r2(computeTotals().due - paid())); }

  function updateSummary() {
    const t = computeTotals();
    $('#sumSubtotal').textContent = money(t.subtotal);
    $('#sumDiscount').textContent = t.discount > 0 ? '−' + money(t.discount) : money(0);
    $('#sumTax').textContent = money(t.tax);
    $('#taxLabel').textContent = CFG.pricesIncludeTax ? 'Tax (included)' : 'Tax';
    $('#sumTotal').textContent = money(t.due);
    $('#exchangeRow').hidden = !state.exchange;
    if (state.exchange) { $('#sumExchange').textContent = '−' + money(t.exchange); $('#exchangeRef').textContent = state.exchange.return_number; }
    const rem = remaining();
    $('#remainingAmount').textContent = money(rem);
    $('#payTotal').textContent = money(t.due);
    $('#dueBanner').classList.toggle('settled', rem <= 0.009 && state.payments.length > 0);
    $('#dueLabel').textContent = rem <= 0.009 && state.payments.length ? 'Fully paid' : 'Amount due';
    const hasHeld = state.resumeSaleId ? 'Resumed ' + state.invoiceNumber : 'New sale';
    $('#saleTag').textContent = hasHeld;
  }

  // ------------------------------------------------------------------
  // Discounts (line or bill)
  // ------------------------------------------------------------------
  let discTarget = null; // index or 'bill'
  function openDiscount(target) {
    discTarget = target;
    const t = computeTotals();
    let base, current;
    if (target === 'bill') {
      base = t.subtotal - state.cart.reduce((s, i) => s + Math.min(i.disc, i.price * i.qty), 0);
      current = state.billDiscount;
      $('#discountTitle').textContent = 'Discount on whole bill';
    } else {
      const it = state.cart[target];
      base = it.price * it.qty; current = it.disc;
      $('#discountTitle').textContent = 'Discount: ' + it.product_name;
    }
    $('#discountBase').textContent = 'Applies to ' + money(base);
    $('#discountModal').dataset.base = base;
    document.querySelector('input[name=discType][value=amount]').checked = true;
    $('#discountValue').value = current > 0 ? current.toFixed(2) : '';
    $('#discountModal').hidden = false;
    setTimeout(() => $('#discountValue').focus(), 30);
  }
  $('#billDiscountBtn').addEventListener('click', () => { if (!state.cart.length) return toast('Add items first.', 'error'); openDiscount('bill'); });
  function applyDiscount(remove) {
    const base = Number($('#discountModal').dataset.base) || 0;
    let v = remove ? 0 : (parseFloat($('#discountValue').value) || 0);
    if (document.querySelector('input[name=discType]:checked').value === 'percent') v = base * Math.min(100, v) / 100;
    v = Math.max(0, Math.min(r2(v), base));
    if (discTarget === 'bill') state.billDiscount = v; else if (state.cart[discTarget]) state.cart[discTarget].disc = v;
    $('#discountModal').hidden = true;
    renderCart();
    const t = computeTotals();
    if (!CFG.canOverride && t.subtotal > 0 && t.discount / t.subtotal * 100 > CFG.maxDiscountPct) toast('This discount exceeds your limit — a manager will need to approve it at payment.', 'warning');
  }
  $('#discountApply').addEventListener('click', () => applyDiscount(false));
  $('#discountRemove').addEventListener('click', () => applyDiscount(true));
  $('#discountValue').addEventListener('keydown', (e) => { if (e.key === 'Enter') applyDiscount(false); });

  // ------------------------------------------------------------------
  // Customer
  // ------------------------------------------------------------------
  const customerSearch = $('#customerSearch');
  const customerResults = $('#customerResults');
  let custTimer = null;
  customerSearch.addEventListener('input', () => {
    clearTimeout(custTimer);
    const q = customerSearch.value.trim();
    if (q.length < 2) { customerResults.hidden = true; return; }
    custTimer = setTimeout(() => {
      fetch(APP.url + 'api/customer_search.php?q=' + encodeURIComponent(q)).then((r) => r.json()).then((list) => {
        let html = list.map((c, i) => '<div class="search-item" data-ci="' + i + '"><span class="si-main"><div class="si-title">' + esc(c.name) + '</div><div class="si-sub">' + esc(c.phone || 'No phone') + '</div></span>'
          + (Number(c.credit_balance) > 0 ? '<span class="si-meta">Owes ' + money(c.credit_balance) + '</span>' : '') + '</div>').join('');
        if (CFG.canCreateCustomer) {
          const looksPhone = /^[0-9+\s-]{7,}$/.test(q);
          html += '<div class="search-item" data-new="1"><span class="si-icon">' + ico('user-plus', 16) + '</span><span class="si-main"><div class="si-title" style="color:var(--orange-dark);">Register new customer</div><div class="si-sub">' + (looksPhone ? 'Phone ' + esc(q) : 'Name “' + esc(q) + '”') + '</div></span></div>';
        }
        customerResults.innerHTML = html || '<div class="search-empty">No customers found.</div>';
        customerResults._list = list;
        customerResults.hidden = false;
        refreshIcons();
      });
    }, 220);
  });
  customerResults.addEventListener('click', (e) => {
    const row = e.target.closest('.search-item');
    if (!row) return;
    if (row.dataset.new) {
      const q = customerSearch.value.trim();
      const looksPhone = /^[0-9+\s-]{7,}$/.test(q);
      const name = looksPhone ? prompt('Customer name for ' + q + ':') : q;
      if (!name) return;
      let phone = looksPhone ? q : (prompt('Phone number for ' + name + ' (optional):') || '');
      const fd = new FormData();
      fd.append('csrf_token', APP.csrf); fd.append('name', name); fd.append('phone', phone);
      fetch(APP.url + 'api/customer_quick_add.php', { method: 'POST', body: fd }).then((r) => r.json()).then((c) => {
        if (c.id) { selectCustomer(c); toast('Customer registered.'); } else toast(c.error || 'Could not add customer.', 'error');
      });
      return;
    }
    selectCustomer(customerResults._list[+row.dataset.ci]);
  });
  document.addEventListener('click', (e) => { if (!e.target.closest('#customerSearch') && !e.target.closest('#customerResults')) customerResults.hidden = true; });

  function selectCustomer(c) {
    state.customer = c;
    $('#customerPicker').hidden = true;
    const box = $('#selectedCustomer');
    box.innerHTML = '<span>' + ico('user-round-check', 15) + ' <strong>' + esc(c.name) + '</strong> <span class="text-muted">' + esc(c.phone || '') + '</span>'
      + (Number(c.credit_balance) > 0 ? ' · <span class="text-warning">owes ' + money(c.credit_balance) + '</span>' : '') + '</span>'
      + '<button type="button" class="line-btn" id="clearCustomerBtn" title="Remove customer" style="border:0;background:none;cursor:pointer;">' + ico('x') + '</button>';
    box.hidden = false;
    customerResults.hidden = true;
    customerSearch.value = '';
    refreshIcons();
  }
  function clearCustomer() {
    state.customer = null;
    $('#customerPicker').hidden = false;
    $('#selectedCustomer').hidden = true;
    state.payments = state.payments.filter((p) => p.method !== 'store_credit');
    renderPaymentLines();
  }
  $('#selectedCustomer').addEventListener('click', (e) => { if (e.target.closest('#clearCustomerBtn')) clearCustomer(); });

  // ------------------------------------------------------------------
  // Held sales
  // ------------------------------------------------------------------
  let heldList = [];
  function refreshHeld() {
    fetch(APP.url + 'api/pos_held_sales.php').then((r) => r.json()).then((list) => { heldList = list; $('#heldCount').textContent = list.length; });
  }
  $('#heldSalesBtn').addEventListener('click', () => {
    const el = $('#heldList');
    el.innerHTML = heldList.length ? heldList.map((s) => '<div class="list-rows"><div>'
      + '<div><div class="fw-700">' + esc(s.invoice_number) + ' · ' + esc(s.customer) + '</div><div class="text-muted text-sm">' + s.item_count + ' item(s) · held by ' + esc(s.held_by) + ' · ' + esc(s.created_at) + (s.notes ? ' · ' + esc(s.notes) : '') + '</div></div>'
      + '<div class="flex items-center gap-2"><span class="fw-700">' + money(s.total_amount) + '</span><button class="btn btn-primary btn-sm" data-resume="' + s.id + '">Resume</button><button class="btn btn-secondary btn-sm" data-discard="' + s.id + '" title="Discard">' + ico('trash-2') + '</button></div></div></div>').join('')
      : '<div class="empty-state"><div class="title">No held sales</div><div class="hint">Use Hold (F8) to park a sale and serve the next customer.</div></div>';
    $('#heldModal').hidden = false;
    refreshIcons();
  });
  $('#heldList').addEventListener('click', (e) => {
    const r = e.target.closest('[data-resume]'), d = e.target.closest('[data-discard]');
    if (r) resumeHeld(r.dataset.resume);
    if (d && confirm('Discard this held sale? The items return to the shelf.')) {
      const fd = new FormData(); fd.append('csrf_token', APP.csrf); fd.append('id', d.dataset.discard);
      fetch(APP.url + 'api/pos_held_sales.php', { method: 'POST', body: fd }).then(() => { refreshHeld(); $('#heldModal').hidden = true; toast('Held sale discarded.'); });
    }
  });
  function resumeHeld(id) {
    if (state.cart.length && !confirm('Replace the current sale with this held sale?')) return;
    fetch(APP.url + 'api/pos_resume.php?id=' + id).then((r) => r.json()).then((data) => {
      if (data.error) { toast(data.error, 'error'); return; }
      resetSale(true);
      state.resumeSaleId = data.sale_id;
      state.invoiceNumber = data.invoice_number;
      state.cart = data.items.map((it) => ({
        variant_id: Number(it.variant_id), product_name: it.product_name, variant_label: it.variant_label, sku: it.sku,
        price: Number(it.price), tax_rate: Number(it.tax_rate) || 0, qty: Number(it.qty), stock_qty: Number(it.stock_qty), disc: 0,
      }));
      if (data.customer) selectCustomer(data.customer);
      $('#heldModal').hidden = true;
      renderCart();
      const short = state.cart.filter((i) => i.qty > i.stock_qty);
      if (short.length) toast('Stock changed since this sale was held — check quantities for ' + short.map((i) => i.product_name).join(', '), 'warning');
    });
  }

  function holdSale() {
    if (!state.cart.length) { toast('The cart is empty.', 'error'); return; }
    if (state.exchange) { toast('Remove the exchange credit before holding.', 'error'); return; }
    const note = CFG.canCheckout ? '' : (prompt('Note for the cashier (optional), e.g. customer name:') || '');
    post('api/pos_checkout.php', buildPayload(true, note)).then((res) => {
      if (res.error) { toast(res.error, 'error'); return; }
      toast((CFG.canCheckout ? 'Sale held as ' : 'Sent to cashier as ') + res.invoice_number + '.');
      resetSale(); refreshHeld();
    });
  }
  $('#holdBtn').addEventListener('click', holdSale);
  if ($('#sendToCashierBtn')) $('#sendToCashierBtn').addEventListener('click', holdSale);

  // ------------------------------------------------------------------
  // Exchange credit
  // ------------------------------------------------------------------
  function applyExchange(ref) {
    fetch(APP.url + 'api/exchange_lookup.php?ref=' + encodeURIComponent(ref)).then((r) => r.json()).then((x) => {
      if (x.error) { toast(x.error, 'error'); return; }
      state.exchange = { id: x.id, return_number: x.return_number, amount: x.amount };
      if (x.customer && !state.customer) selectCustomer(x.customer);
      $('#exchangeModal').hidden = true;
      toast('Exchange credit of ' + money(x.amount) + ' applied. Add the replacement items.');
      updateSummary();
    });
  }
  if ($('#exchangeBtn')) $('#exchangeBtn').addEventListener('click', () => { $('#exchangeModal').hidden = false; setTimeout(() => $('#exchangeRefInput').focus(), 30); });
  if ($('#exchangeApply')) $('#exchangeApply').addEventListener('click', () => applyExchange($('#exchangeRefInput').value.trim()));
  if ($('#exchangeRefInput')) $('#exchangeRefInput').addEventListener('keydown', (e) => { if (e.key === 'Enter') applyExchange(e.target.value.trim()); });
  $('#removeExchange').addEventListener('click', () => { state.exchange = null; updateSummary(); });

  // ------------------------------------------------------------------
  // Payment modal
  // ------------------------------------------------------------------
  let activeTarget = null;
  document.addEventListener('focusin', (e) => { if (e.target.classList && e.target.classList.contains('numpad-target')) { activeTarget = e.target; updateNumpad(); } });
  function updateNumpad() { $('#numpadDisplay').textContent = activeTarget ? (activeTarget.value || '0') : '0'; }
  document.querySelector('.numpad').addEventListener('click', (e) => {
    const key = e.target.getAttribute('data-key');
    if (!key) return;
    if (!activeTarget) activeTarget = state.selectedMethod === 'cash' ? $('#tenderedInput') : $('#paymentAmountInput');
    let val = activeTarget.value || '';
    if (key === 'clear') val = '';
    else if (key === 'back') val = val.slice(0, -1);
    else if (key === '.') { if (!val.includes('.')) val += (val ? '.' : '0.'); }
    else val += key;
    activeTarget.value = val;
    activeTarget.dispatchEvent(new Event('input', { bubbles: true }));
    updateNumpad();
  });
  ['#paymentAmountInput', '#tenderedInput'].forEach((s) => $(s).addEventListener('input', () => { updateNumpad(); updateChange(); }));

  function selectMethod(m) {
    state.selectedMethod = m;
    $$('.pay-chip').forEach((c) => c.classList.toggle('active', c.dataset.method === m));
    const rem = remaining();
    $('#tenderedGroup').hidden = m !== 'cash';
    $('#quickCash').hidden = m !== 'cash';
    $('#referenceGroup').hidden = !['card', 'bank_transfer', 'mobile_wallet'].includes(m);
    $('#paymentAmountInput').value = rem > 0 ? rem.toFixed(2) : '';
    $('#tenderedInput').value = '';
    $('#referenceInput').value = '';
    const info = $('#creditInfo');
    info.hidden = m !== 'store_credit';
    if (m === 'store_credit') {
      if (!state.customer) info.innerHTML = 'Attach a registered customer to sell on credit.';
      else {
        const c = state.customer;
        info.innerHTML = '<strong>' + esc(c.name) + '</strong> currently owes ' + money(c.credit_balance) + (c.credit_limit !== null && c.credit_limit !== undefined && c.credit_limit !== '' ? ' · limit ' + money(c.credit_limit) : ' · no credit limit') + (Number(c.allow_credit) === 0 ? '<br><strong>Credit is disabled for this customer.</strong>' : '');
      }
    }
    renderQuickCash();
    updateChange();
    activeTarget = m === 'cash' ? $('#tenderedInput') : $('#paymentAmountInput');
    setTimeout(() => activeTarget.focus(), 20);
  }
  $$('.pay-chip').forEach((c) => c.addEventListener('click', () => selectMethod(c.dataset.method)));

  function renderQuickCash() {
    const rem = remaining();
    if (rem <= 0) { $('#quickCash').innerHTML = ''; return; }
    const opts = [rem];
    [100, 500, 1000, 5000].forEach((step) => { const v = Math.ceil(rem / step) * step; if (v > rem && !opts.includes(v)) opts.push(v); });
    $('#quickCash').innerHTML = opts.slice(0, 5).map((v, i) => '<button type="button" class="btn btn-secondary btn-sm" data-cash="' + v + '">' + (i === 0 ? 'Exact' : money(v)) + '</button>').join('');
  }
  $('#quickCash').addEventListener('click', (e) => {
    const b = e.target.closest('[data-cash]');
    if (!b) return;
    $('#tenderedInput').value = Number(b.dataset.cash).toFixed(2);
    updateChange(); updateNumpad();
  });

  function updateChange() {
    const el = $('#changeDisplay');
    if (state.selectedMethod !== 'cash') { el.textContent = ''; return; }
    const amount = parseFloat($('#paymentAmountInput').value) || 0;
    const tendered = parseFloat($('#tenderedInput').value) || 0;
    if (!tendered) { el.textContent = ''; return; }
    if (tendered < amount) { el.innerHTML = '<span class="text-warning">Cash received is less than the amount — only ' + money(tendered) + ' will be applied.</span>'; return; }
    el.innerHTML = 'Change to give: <span style="font-size:18px;" class="text-success">' + money(tendered - amount) + '</span>';
  }

  /** Adds the payment currently in the form. Returns false if nothing valid. */
  function commitCurrentPayment(silent) {
    const m = state.selectedMethod;
    if (!m) { if (!silent) toast('Choose a payment method.', 'error'); return false; }
    let amount = r2(parseFloat($('#paymentAmountInput').value) || 0);
    const rem = remaining();
    const tendered = m === 'cash' ? (parseFloat($('#tenderedInput').value) || null) : null;
    if (m === 'cash' && tendered !== null && tendered < amount) amount = r2(tendered);
    if (amount <= 0) { if (!silent) toast('Enter an amount.', 'error'); return false; }
    if (amount > rem + 0.001) { if (!silent) toast('That is more than the amount due. For cash, enter the extra in “Cash received” to calculate change.', 'error'); return false; }
    if (m === 'store_credit') {
      if (!state.customer) { toast('Attach a registered customer to sell on credit.', 'error'); return false; }
      if (Number(state.customer.allow_credit) === 0) { toast('Credit is disabled for this customer.', 'error'); return false; }
    }
    state.payments.push({ method: m, amount, tendered_amount: tendered, reference_no: $('#referenceInput').value.trim() || null });
    renderPaymentLines();
    updateSummary();
    const left = remaining();
    if (left > 0) selectMethod(m === 'store_credit' ? 'cash' : m);
    else { $('#paymentAmountInput').value = ''; $('#tenderedInput').value = ''; $('#changeDisplay').textContent = ''; renderQuickCash(); }
    return true;
  }
  $('#addPaymentBtn').addEventListener('click', () => commitCurrentPayment(false));

  function renderPaymentLines() {
    $('#paymentLines').innerHTML = state.payments.map((p, i) => '<div class="pay-line"><span><strong>' + esc(methodLabels[p.method] || p.method) + '</strong>'
      + (p.tendered_amount ? ' <span class="text-muted">· received ' + money(p.tendered_amount) + ', change ' + money(Math.max(0, p.tendered_amount - p.amount)) + '</span>' : '')
      + (p.reference_no ? ' <span class="text-muted">· ' + esc(p.reference_no) + '</span>' : '') + '</span>'
      + '<span class="flex items-center gap-2"><strong>' + money(p.amount) + '</strong><button type="button" class="line-btn" data-rm-pay="' + i + '" style="border:0;background:none;cursor:pointer;color:var(--error);" aria-label="Remove payment">&times;</button></span></div>').join('');
  }
  $('#paymentLines').addEventListener('click', (e) => {
    const b = e.target.closest('[data-rm-pay]');
    if (b) { state.payments.splice(+b.dataset.rmPay, 1); renderPaymentLines(); updateSummary(); selectMethod(state.selectedMethod || 'cash'); }
  });

  function openPayment() {
    if (!CFG.canCheckout) return;
    if (!state.cart.length) { toast('The cart is empty.', 'error'); return; }
    $('#overrideBox').hidden = !state.override;
    $('#paymentModal').hidden = false;
    updateSummary();
    renderPaymentLines();
    selectMethod(state.selectedMethod && state.selectedMethod !== 'store_credit' ? state.selectedMethod : 'cash');
  }
  if ($('#checkoutBtn')) $('#checkoutBtn').addEventListener('click', openPayment);

  function buildPayload(hold, note) {
    const t = computeTotals();
    const billAllowed = Math.min(state.billDiscount, t.subtotal);
    return {
      csrf_token: APP.csrf,
      customer_id: state.customer ? state.customer.id : null,
      items: state.cart.map((i) => ({ variant_id: i.variant_id, qty: i.qty, discount_amount: r2(Math.min(i.disc, i.price * i.qty)) })),
      discount_amount: r2(billAllowed),
      payments: hold ? [] : state.payments,
      hold: !!hold,
      notes: note || null,
      resume_sale_id: state.resumeSaleId,
      exchange_return_id: state.exchange ? state.exchange.id : null,
      override_username: state.override ? $('#ovUser').value : null,
      override_password: state.override ? $('#ovPass').value : null,
    };
  }

  $('#completeBtn').addEventListener('click', () => {
    if (state.busy) return;
    if (remaining() > 0.009) commitCurrentPayment(true);
    if (remaining() > 0.009) { toast('Still ' + money(remaining()) + ' to collect.', 'error'); return; }
    state.busy = true;
    $('#completeBtn').disabled = true;
    post('api/pos_checkout.php', buildPayload(false)).then((res) => {
      state.busy = false;
      $('#completeBtn').disabled = false;
      if (res.error) {
        if (res.needs_override) {
          state.override = true;
          $('#overrideBox').hidden = false;
          $('#overrideMsg').textContent = res.error;
          $('#ovUser').focus();
        } else toast(res.error, 'error');
        return;
      }
      state.lastSaleId = res.sale_id;
      $('#paymentModal').hidden = true;
      $('#doneInvoice').textContent = res.invoice_number + ' · ' + money(res.total);
      let changeHtml = '';
      if (res.change_due > 0) changeHtml += '<div class="label">Change to give</div><div style="font-size:34px;font-weight:800;" class="text-success">' + money(res.change_due) + '</div>';
      if (res.exchange_cash_back > 0) changeHtml += '<div class="label mt-3">Exchange balance to refund in cash</div><div style="font-size:26px;font-weight:800;">' + money(res.exchange_cash_back) + '</div>';
      $('#doneChange').innerHTML = changeHtml;
      $('#doneModal').hidden = false;
      setTimeout(() => $('#doneNew').focus(), 50);
      refreshHeld();
    }).catch(() => { state.busy = false; $('#completeBtn').disabled = false; toast('Network error — the sale was not saved. Try again.', 'error'); });
  });
  $('#donePrint').addEventListener('click', () => { if (state.lastSaleId) window.open(APP.url + 'pos/receipt.php?id=' + state.lastSaleId, '_blank'); });
  $('#doneNew').addEventListener('click', () => { $('#doneModal').hidden = true; resetSale(); fetchProducts(''); scanInput.focus(); });

  function resetSale(keepModals) {
    Object.assign(state, { cart: [], billDiscount: 0, payments: [], resumeSaleId: null, invoiceNumber: null, exchange: null, selectedMethod: null, override: false });
    $('#ovUser').value = ''; $('#ovPass').value = '';
    $('#overrideBox').hidden = true;
    clearCustomer();
    renderPaymentLines();
    renderCart();
    if (!keepModals) $('#paymentModal').hidden = true;
  }

  // ------------------------------------------------------------------
  // Keyboard shortcuts
  // ------------------------------------------------------------------
  document.addEventListener('keydown', (e) => {
    if (e.key === 'F2') { e.preventDefault(); scanInput.focus(); scanInput.select(); }
    else if (e.key === 'F4') { e.preventDefault(); if (!state.customer) customerSearch.focus(); }
    else if (e.key === 'F8') { e.preventDefault(); holdSale(); }
    else if (e.key === 'F9') { e.preventDefault(); if ($('#paymentModal').hidden) openPayment(); else $('#completeBtn').click(); }
    else if (e.key === 'Enter' && !$('#doneModal').hidden) { e.preventDefault(); $('#doneNew').click(); }
    else if (e.key === 'Escape' && !$('#paymentModal').hidden) { $('#paymentModal').hidden = true; }
  });

  // ------------------------------------------------------------------
  // Init
  // ------------------------------------------------------------------
  fetchProducts('');
  renderCart();
  refreshHeld();
  if (CFG.exchangeRef) applyExchange(CFG.exchangeRef);
  if (CFG.resumeId) resumeHeld(CFG.resumeId);
})();
