/* Jayfoods Control Center — vanilla SPA (hash router). */
(function () {
  'use strict';

  // ---------------------------------------------------------------------------
  // API helper
  // ---------------------------------------------------------------------------
  async function apiFetch(path, options = {}) {
    const isForm = options.body instanceof FormData;
    const res = await fetch('/api/v1' + path, {
      credentials: 'same-origin',
      headers: { ...(isForm ? {} : { 'Content-Type': 'application/json' }), ...(options.headers || {}) },
      ...options,
    });
    if (res.status === 401) {
      location.replace('login.html');
      throw new Error('Unauthorized');
    }
    let data = null;
    try { data = await res.json(); } catch (_) {}
    if (!res.ok) {
      const err = new Error((data && data.error) || `Request failed (${res.status})`);
      err.fields = (data && data.fields) || {};
      throw err;
    }
    return data;
  }
  const api = {
    me:            () => apiFetch('/auth/me'),
    logout:        () => apiFetch('/auth/logout', { method: 'POST' }),
    stats:         () => apiFetch('/admin/stats'),
    products:      () => apiFetch('/admin/products'),
    createProduct: (p) => apiFetch('/admin/products', { method: 'POST', body: p }),
    updateProduct: (id, p) => apiFetch('/admin/products/' + id, { method: 'POST', body: p }),
    deleteProduct: (id) => apiFetch('/admin/products/' + id, { method: 'DELETE' }),
    toggleProductBulk: (id, enabled) => apiFetch('/admin/products/' + id + '/bulk', { method: 'PATCH', body: JSON.stringify({ bulk_available: enabled }) }),
    orders:        () => apiFetch('/admin/orders'),
    order:         (id) => apiFetch('/admin/orders/' + id),
    setOrderStatus:(id, status) => apiFetch('/admin/orders/' + id, { method: 'PATCH', body: JSON.stringify({ status }) }),
    setOrderNotes: (id, admin_notes) => apiFetch('/admin/orders/' + id + '/notes', {method:'PATCH',body:JSON.stringify({admin_notes})}),
    customers:     () => apiFetch('/admin/customers'),
    messages:      () => apiFetch('/admin/messages'),
    setMessageRead:(id, is_read) => apiFetch('/admin/messages/' + id, { method: 'PATCH', body: JSON.stringify({ is_read }) }),
    deleteMessage: (id) => apiFetch('/admin/messages/' + id, { method: 'DELETE' }),
    changePassword:(body) => apiFetch('/admin/account/password', { method: 'POST', body: JSON.stringify(body) }),
    updateProfile: (body) => apiFetch('/admin/account/profile', { method: 'PUT', body: JSON.stringify(body) }),
    smtpSettings:  () => apiFetch('/admin/settings/smtp'),
    updateSmtp:    (body) => apiFetch('/admin/settings/smtp', { method: 'PUT', body: JSON.stringify(body) }),
    testSmtp:      () => apiFetch('/admin/settings/smtp/test', { method: 'POST' }),
    paystackSettings: () => apiFetch('/admin/settings/paystack'),
    updatePaystack: (body) => apiFetch('/admin/settings/paystack', { method: 'PUT', body: JSON.stringify(body) }),
    content:       () => apiFetch('/admin/content'),
    updateContent: (body) => apiFetch('/admin/content', { method: 'PUT', body: JSON.stringify(body) }),
    deliveryZones: () => apiFetch('/admin/delivery-zones'),
    updateDeliveryZones: (zones) => apiFetch('/admin/delivery-zones', { method:'PUT', body:JSON.stringify({zones}) }),
    promoCodes: () => apiFetch('/admin/promo-codes'),
    savePromo: body => apiFetch('/admin/promo-codes', {method:'POST',body:JSON.stringify(body)}),
    deletePromo: id => apiFetch('/admin/promo-codes/'+id, {method:'DELETE'}),
  };

  // ---------------------------------------------------------------------------
  // Utilities
  // ---------------------------------------------------------------------------
  const $ = (sel) => document.querySelector(sel);
  const view = $('#view');
  const cedis = (p) => 'GH₵' + (p / 100).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const toPesewas = (v) => Math.round(parseFloat(v || 0) * 100);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
  const fmtDate = (s) => { const d = new Date((s || '').replace(' ', 'T') + 'Z'); return isNaN(d) ? esc(s) : d.toLocaleString('en-GB', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' }); };
  const statusPill = (s) => `<span class="pill st-${esc(s)}">${esc(s)}</span>`;
  const typePill = (t) => `<span class="pill type-${esc(t)}">${esc(t)}</span>`;
  const paymentPill = (s) => `<span class="pill ${s === 'paid' ? 'on' : 'off'}">${s === 'paid' ? 'Paid' : 'Unpaid'}</span>`;

  // ---------------------------------------------------------------------------
  // Modal
  // ---------------------------------------------------------------------------
  const overlay = $('#modal-overlay');
  function openModal(title, bodyHtml, footEls) {
    $('#modal-title').textContent = title;
    $('#modal-body').innerHTML = bodyHtml;
    const foot = $('#modal-foot');
    foot.innerHTML = '';
    (footEls || []).forEach((el) => foot.appendChild(el));
    overlay.classList.add('open');
  }
  function closeModal() { overlay.classList.remove('open'); }
  function button(label, cls, onClick) {
    const b = document.createElement('button');
    b.className = 'btn ' + cls;
    b.textContent = label;
    b.addEventListener('click', onClick);
    return b;
  }
  $('#modal-close').addEventListener('click', closeModal);
  overlay.addEventListener('click', (e) => { if (e.target === overlay) closeModal(); });

  // ---------------------------------------------------------------------------
  // Router
  // ---------------------------------------------------------------------------
  const routes = {
    dashboard: { title: 'Dashboard', render: renderDashboard },
    products:  { title: 'Products',  render: renderProducts },
    orders:    { title: 'Orders',    render: renderOrders },
    customers: { title: 'Customers', render: renderCustomers },
    backups:   { title: 'Backups', render: renderBackups },
    messages:  { title: 'Messages',  render: renderMessages },
    content:   { title: 'Site content', render: renderContent },
    delivery:  { title: 'Delivery zones', render: renderDeliveryZones },
    promos:    { title: 'Promo codes', render: renderPromos },
    settings:  { title: 'Settings',  render: renderSettings },
  };

  function currentRoute() {
    const key = (location.hash.replace(/^#\//, '') || 'dashboard').split('/')[0];
    return routes[key] ? key : 'dashboard';
  }

  async function router() {
    const key = currentRoute();
    const route = routes[key];
    $('#page-title').textContent = route.title;
    document.querySelectorAll('#nav a').forEach((a) => a.classList.toggle('active', a.dataset.route === key));
    $('#sidebar').classList.remove('open');
    view.innerHTML = '<div class="loading">Loading…</div>';
    try {
      await route.render();
    } catch (err) {
      if (err.message !== 'Unauthorized') {
        view.innerHTML = `<div class="empty">Something went wrong: ${esc(err.message)}</div>`;
      }
    }
    refreshBadge();
  }
  window.addEventListener('hashchange', router);

  // ---------------------------------------------------------------------------
  // Dashboard
  // ---------------------------------------------------------------------------
  async function renderDashboard() {
    const { data } = await api.stats();
    const cards = [
      { ic: '🧾', num: data.orders_total, lbl: 'Total orders' },
      { ic: '⏳', num: data.orders_pending, lbl: 'Pending orders' },
      { ic: '✓', num: data.orders_paid, lbl: 'Paid orders' },
      { ic: '💰', num: cedis(data.revenue_pesewas), lbl: 'Revenue' },
      { ic: '🧃', num: `${data.products_active}/${data.products_total}`, lbl: 'Active products' },
      { ic: '✉️', num: data.messages_unread, lbl: 'Unread messages' },
      { ic: '⚠', num: data.low_stock_sizes, lbl: 'Low-stock sizes' },
      { ic: '⌛', num: data.reserved_orders, lbl: 'Stock reservations' },
    ];
    cards.push(
      { ic: '☀', num: cedis(data.sales_today_pesewas), lbl: "Today's paid sales" },
      { ic: '7', num: cedis(data.sales_7_days_pesewas), lbl: 'Paid sales · 7 days' },
      { ic: '30', num: cedis(data.sales_30_days_pesewas), lbl: 'Paid sales · 30 days' },
      { ic: 'Ø', num: cedis(data.average_order_pesewas), lbl: 'Average paid order' },
    );
    const recent = data.recent_orders.length
      ? data.recent_orders.map((o) => `
          <tr>
            <td class="prod-name">${esc(o.reference)}</td>
            <td>${esc(o.customer_name)}</td>
            <td>${typePill(o.order_type)}</td>
            <td>${cedis(o.total_pesewas)}</td>
            <td>${paymentPill(o.payment_status)}</td>
            <td>${statusPill(o.status)}</td>
            <td class="sub">${fmtDate(o.created_at)}</td>
          </tr>`).join('')
      : '<tr><td colspan="7" class="empty">No orders yet.</td></tr>';

    const topProducts = data.top_products.length ? data.top_products.map((p,i)=>`<tr><td>${i+1}</td><td class="prod-name">${esc(p.name)}</td><td>${p.units}</td><td>${cedis(p.sales_pesewas)}</td></tr>`).join('') : '<tr><td colspan="4" class="empty">Paid sales will appear here.</td></tr>';
    view.innerHTML = `
      <div class="stat-grid">
        ${cards.map((c) => `<div class="stat"><div class="ic">${c.ic}</div><div class="num">${esc(String(c.num))}</div><div class="lbl">${c.lbl}</div></div>`).join('')}
      </div>
      <div class="panel analytics-panel">
        <div class="panel-head"><h2>Best-selling products</h2><span class="sub">Paid, non-cancelled orders</span></div>
        <div class="panel-body table-scroll"><table class="data"><thead><tr><th>#</th><th>Product</th><th>Units sold</th><th>Sales</th></tr></thead><tbody>${topProducts}</tbody></table></div>
      </div>
      <div class="panel">
        <div class="panel-head"><h2>Recent orders</h2><a class="btn btn-ghost btn-sm" href="#/orders">View all</a></div>
        <div class="panel-body table-scroll">
          <table class="data">
            <thead><tr><th>Reference</th><th>Customer</th><th>Type</th><th>Total</th><th>Payment</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>${recent}</tbody>
          </table>
        </div>
      </div>`;
  }

  // ---------------------------------------------------------------------------
  // Products (CRUD)
  // ---------------------------------------------------------------------------
  let productCache = [];
  async function renderProducts() {
    const { data } = await api.products();
    productCache = data;
    const rows = data.length ? data.map((p) => `
      <tr data-product-row data-active="${p.is_active ? 1 : 0}" data-stock="${Math.min(...(p.sizes || []).map(s=>s.stock_quantity))}">
        <td>
          <div class="prod-cell">
            ${p.image_url ? `<img class="pthumb" src="${esc(p.image_url)}" alt="" />` : '<span class="pthumb ph">🧃</span>'}
            <div>
              <div class="prod-name">${esc(p.name)}</div>
              <div class="sub">${esc(p.flavour)} · ${esc(p.sku)}</div>
            </div>
          </div>
        </td>
        <td>${(p.sizes || []).map(s => esc(s.label)).join('<br>')}</td>
        <td>${(p.sizes || []).map(s => cedis(s.unit_price_pesewas)).join('<br>')}</td>
        <td><label class="checkline"><input type="checkbox" data-bulk-toggle="${p.id}" ${p.bulk_available ? 'checked' : ''} /> ${p.bulk_available && p.bulk_price_pesewas != null ? `${cedis(p.bulk_price_pesewas)} @ ${p.bulk_min_quantity}+` : 'Off'}</label></td>
        <td>${(p.sizes || []).map(s => `<span class="pill ${s.stock_quantity === 0 ? 'off' : s.stock_quantity <= 10 ? 'pending' : 'on'}">${esc(s.label)}: ${s.stock_quantity}</span>`).join('<br>')}</td>
        <td><span class="pill ${p.is_active ? 'on' : 'off'}">${p.is_active ? 'Active' : 'Hidden'}</span></td>
        <td>
          <div class="row-actions">
            <button class="btn btn-ghost btn-sm" data-edit="${p.id}">Edit</button>
            <button class="btn btn-danger btn-sm" data-del="${p.id}">Delete</button>
          </div>
        </td>
      </tr>`).join('') : '<tr><td colspan="7" class="empty">No products yet. Add your first one.</td></tr>';

    view.innerHTML = `
      <div class="panel">
        <div class="panel-head">
          <h2>${data.length} product${data.length === 1 ? '' : 's'}</h2>
          <button class="btn btn-primary btn-sm" id="add-product">+ Add product</button>
        </div>
        <div class="product-tools"><input id="product-search" type="search" placeholder="Search product, flavour or SKU"><select id="product-filter"><option value="all">All products</option><option value="low">Low stock (10 or fewer)</option><option value="out">Out of stock</option><option value="active">Active products</option><option value="hidden">Hidden products</option></select></div>
        <div class="panel-body table-scroll">
          <table class="data">
            <thead><tr><th>Product</th><th>Unit</th><th>Price</th><th>Bulk</th><th>Stock</th><th>Status</th><th></th></tr></thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </div>`;

    $('#add-product').addEventListener('click', () => openProductModal(null));
    const filterProducts=()=>{const q=$('#product-search').value.trim().toLowerCase(),filter=$('#product-filter').value;view.querySelectorAll('[data-product-row]').forEach((row,i)=>{const p=data[i],stock=+row.dataset.stock,matchText=!q||[p.name,p.flavour,p.sku].some(v=>String(v||'').toLowerCase().includes(q)),matchFilter=filter==='all'||filter==='low'&&stock<=10||filter==='out'&&stock===0||filter==='active'&&row.dataset.active==='1'||filter==='hidden'&&row.dataset.active==='0';row.hidden=!(matchText&&matchFilter)})};
    $('#product-search').addEventListener('input',filterProducts);$('#product-filter').addEventListener('change',filterProducts);
    view.querySelectorAll('[data-edit]').forEach((b) =>
      b.addEventListener('click', () => openProductModal(productCache.find((p) => p.id === +b.dataset.edit))));
    view.querySelectorAll('[data-del]').forEach((b) =>
      b.addEventListener('click', () => deleteProduct(productCache.find((p) => p.id === +b.dataset.del))));
    view.querySelectorAll('[data-bulk-toggle]').forEach((input) => input.addEventListener('change', async () => {
      input.disabled = true;
      try { await api.toggleProductBulk(+input.dataset.bulkToggle, input.checked); router(); }
      catch (err) { input.checked = !input.checked; alert(err.message); input.disabled = false; }
    }));
  }

  function openProductModal(product) {
    const p = product || {};
    const isEdit = !!product;
    const sizes = p.sizes && p.sizes.length ? p.sizes : [{ label:'500ml bottle', unit_price_pesewas:0, stock_quantity:0, bulk_min_quantity:0, bulk_price_pesewas:null }];
    openModal(isEdit ? 'Edit product' : 'Add product', `
      <div class="form-error" id="pm-error" style="display:none"></div>
      <form id="product-form">
        <div class="form-2col">
          <div class="field full"><label>Name *</label><input name="name" value="${esc(p.name || '')}" /></div>
          <div class="field"><label>Flavour</label><input name="flavour" value="${esc(p.flavour || '')}" /></div>
          <div class="field"><label>SKU *</label><input name="sku" value="${esc(p.sku || '')}" placeholder="JF-XXXX-500" /></div>
          <div class="field full"><label>Description</label><textarea name="description" rows="2">${esc(p.description || '')}</textarea></div>
          <div class="field full"><label>Product image</label><input name="image" type="file" accept="image/jpeg,image/png,image/webp,image/gif" /><input name="existing_image_url" type="hidden" value="${esc(p.image_url || '')}" /><div class="hint">JPG, PNG, WebP or GIF, up to 5 MB.${p.image_url ? ' Leave empty to keep the current image.' : ''}</div>${p.image_url ? `<img class="upload-preview" src="${esc(p.image_url)}" alt="Current product image" />` : ''}</div>
          <div class="field full"><label>Bottle sizes *</label><div id="size-rows" class="size-rows">${sizes.map(sizeRowHtml).join('')}</div><button class="btn btn-ghost btn-sm" type="button" id="add-size">+ Add bottle size</button></div>
          <div class="field" style="align-self:end"><label class="checkline"><input type="checkbox" name="is_active" ${p.is_active === false ? '' : 'checked'} /> Active (visible on site)</label></div>
          <div class="field full"><label class="checkline"><input type="checkbox" name="bulk_available" id="pm-bulk" ${p.bulk_available ? 'checked' : ''} /> Offer a bulk price</label></div>
        </div>
      </form>
    `, [
      button('Cancel', 'btn-ghost', closeModal),
      button(isEdit ? 'Save changes' : 'Create product', 'btn-primary', () => submitProduct(product)),
    ]);
    $('#add-size').addEventListener('click', () => $('#size-rows').insertAdjacentHTML('beforeend', sizeRowHtml({})));
    $('#size-rows').addEventListener('click', e => { const b=e.target.closest('[data-remove-size]'); if(b && document.querySelectorAll('.size-row').length>1)b.closest('.size-row').remove(); });
  }

  function sizeRowHtml(s) {
    return `<div class="size-row" data-size-id="${s.id || 0}"><input data-size="label" placeholder="Size (500ml)" value="${esc(s.label || '')}"><input data-size="price" type="number" step="0.01" min="0" placeholder="Price GH₵" value="${s.unit_price_pesewas ? (s.unit_price_pesewas/100).toFixed(2) : ''}"><input data-size="stock" type="number" min="0" placeholder="Stock" value="${s.stock_quantity || 0}"><input data-size="bulk_min" type="number" min="0" placeholder="Bulk min" value="${s.bulk_min_quantity || 0}"><input data-size="bulk_price" type="number" step="0.01" min="0" placeholder="Bulk GH₵" value="${s.bulk_price_pesewas ? (s.bulk_price_pesewas/100).toFixed(2) : ''}"><button type="button" class="btn btn-danger btn-sm" data-remove-size>×</button></div>`;
  }

  async function submitProduct(existing) {
    const form = $('#product-form');
    const f = new FormData(form);
    const sizes = [...form.querySelectorAll('.size-row')].map(row => ({ id:+row.dataset.sizeId, label:row.querySelector('[data-size="label"]').value.trim(), unit_price_pesewas:toPesewas(row.querySelector('[data-size="price"]').value), stock_quantity:parseInt(row.querySelector('[data-size="stock"]').value,10)||0, bulk_min_quantity:parseInt(row.querySelector('[data-size="bulk_min"]').value,10)||0, bulk_price_pesewas:row.querySelector('[data-size="bulk_price"]').value?toPesewas(row.querySelector('[data-size="bulk_price"]').value):null }));
    f.set('sizes', JSON.stringify(sizes));
    f.set('unit_price_pesewas', toPesewas(f.get('unit_price')));
    f.set('stock_quantity', parseInt(f.get('stock_quantity'), 10) || 0);
    f.set('is_active', f.get('is_active') === 'on' ? '1' : '0');
    f.set('bulk_available', f.get('bulk_available') === 'on' ? '1' : '0');
    f.set('bulk_min_quantity', parseInt(f.get('bulk_min_quantity'), 10) || 0);
    f.set('bulk_price_pesewas', f.get('bulk_price') ? toPesewas(f.get('bulk_price')) : '');
    const errBox = $('#pm-error');
    errBox.style.display = 'none';
    form.querySelectorAll('.field').forEach((el) => el.classList.remove('err'));
    try {
      if (existing) await api.updateProduct(existing.id, f);
      else await api.createProduct(f);
      closeModal();
      router();
    } catch (err) {
      errBox.textContent = err.message;
      errBox.style.display = 'block';
      Object.keys(err.fields || {}).forEach((k) => {
        const input = form.querySelector(`[name="${k.replace('_pesewas', '').replace('unit_price', 'unit_price').replace('bulk_price', 'bulk_price')}"]`);
        if (input) input.closest('.field').classList.add('err');
      });
    }
  }

  function deleteProduct(p) {
    if (!p) return;
    openModal('Delete product', `<p>Delete <b>${esc(p.name)}</b> permanently? This cannot be undone.</p>`, [
      button('Cancel', 'btn-ghost', closeModal),
      button('Delete', 'btn-danger', async () => { await api.deleteProduct(p.id); closeModal(); router(); }),
    ]);
  }

  // ---------------------------------------------------------------------------
  // Orders
  // ---------------------------------------------------------------------------
  const STATUSES = ['pending', 'confirmed', 'processing', 'delivered', 'cancelled'];
  async function renderOrders() {
    const { data } = await api.orders();
    const draw = () => {
      const query = ($('#order-search')?.value || '').trim().toLowerCase();
      const status = $('#order-status')?.value || '';
      const payment = $('#order-payment')?.value || '';
      const filtered = data.filter(o => (!query || [o.reference,o.customer_name,o.customer_phone,o.region].some(v => String(v || '').toLowerCase().includes(query))) && (!status || o.status === status) && (!payment || o.payment_status === payment));
      const rows = filtered.length ? filtered.map((o) => `
      <tr class="clickable" data-order="${o.id}">
        <td class="prod-name">${esc(o.reference)}</td>
        <td>${esc(o.customer_name)}<div class="sub">${esc(o.customer_phone)}</div></td>
        <td>${typePill(o.order_type)}</td>
        <td>${o.unit_count} <span class="sub">unit(s)</span></td>
        <td>${cedis(o.total_pesewas)}</td>
        <td>${paymentPill(o.payment_status)}</td>
        <td>${statusPill(o.status)}</td>
        <td class="sub">${fmtDate(o.created_at)}</td>
      </tr>`).join('') : '<tr><td colspan="8" class="empty">No orders match these filters.</td></tr>';

      $('#orders-body').innerHTML = rows;
      $('#orders-count').textContent = `${filtered.length} of ${data.length} orders`;
      view.querySelectorAll('[data-order]').forEach((tr) => tr.addEventListener('click', () => openOrder(+tr.dataset.order)));
      $('#export-orders').onclick = () => exportOrdersCsv(filtered);
    };

    view.innerHTML = `
      <div class="panel">
        <div class="panel-head"><h2 id="orders-count">${data.length} orders</h2><button class="btn btn-ghost btn-sm" id="export-orders">Export CSV</button></div>
        <div class="order-tools">
          <input id="order-search" type="search" placeholder="Search reference, customer, phone or area">
          <select id="order-status"><option value="">All order statuses</option>${STATUSES.map(s=>`<option value="${s}">${s}</option>`).join('')}</select>
          <select id="order-payment"><option value="">All payments</option><option value="paid">Paid</option><option value="unpaid">Unpaid</option></select>
        </div>
        <div class="panel-body table-scroll">
          <table class="data">
            <thead><tr><th>Reference</th><th>Customer</th><th>Type</th><th>Qty</th><th>Total</th><th>Payment</th><th>Status</th><th>Date</th></tr></thead>
            <tbody id="orders-body"></tbody>
          </table>
        </div>
      </div>`;

    ['order-search','order-status','order-payment'].forEach(id => $('#'+id).addEventListener(id==='order-search'?'input':'change', draw));
    draw();
  }

  function exportOrdersCsv(orders) {
    if (!orders.length) return alert('There are no matching orders to export.');
    const safe = value => { let s=String(value??''); if(/^[=+\-@]/.test(s))s="'"+s; return '"'+s.replace(/"/g,'""')+'"'; };
    const headers=['Reference','Customer','Phone','Region','Type','Units','Subtotal (GHS)','Delivery (GHS)','Total (GHS)','Payment','Status','Paystack reference','Date'];
    const rows=orders.map(o=>[o.reference,o.customer_name,o.customer_phone,o.region,o.order_type,o.unit_count,(o.subtotal_pesewas/100).toFixed(2),(o.delivery_fee_pesewas/100).toFixed(2),(o.total_pesewas/100).toFixed(2),o.payment_status,o.status,o.payment_reference,o.created_at]);
    const blob=new Blob(['\uFEFF'+[headers,...rows].map(row=>row.map(safe).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'});
    const link=document.createElement('a');link.href=URL.createObjectURL(blob);link.download='jayfoods-orders-'+new Date().toISOString().slice(0,10)+'.csv';link.click();URL.revokeObjectURL(link.href);
  }

  async function openOrder(id) {
    const { data: o } = await api.order(id);
    const items = o.items.map((i) => `
      <div class="detail-row">
        <span>${i.quantity} × ${esc(i.product_name)}${i.is_bulk ? ' <span class="pill type-bulk">bulk</span>' : ''}</span>
        <span>${cedis(i.line_total_pesewas)}</span>
      </div>`).join('');
    const options = STATUSES.map((s) => `<option value="${s}" ${s === o.status ? 'selected' : ''}>${s}</option>`).join('');
    openModal('Order ' + o.reference, `
      <div class="form-ok" id="om-ok" style="display:none">Status updated.</div>
      <div class="detail-row"><span>Customer</span><span>${esc(o.customer_name)}</span></div>
      <div class="detail-row"><span>Phone</span><span>${esc(o.customer_phone)}</span></div>
      ${o.customer_email ? `<div class="detail-row"><span>Email</span><span>${esc(o.customer_email)}</span></div>` : ''}
      <div class="detail-row"><span>Deliver to</span><span style="text-align:right">${esc(o.delivery_address)}, ${esc(o.region)}</span></div>
      ${o.notes ? `<div class="detail-row"><span>Notes</span><span style="text-align:right">${esc(o.notes)}</span></div>` : ''}
      <div class="detail-row"><span>Placed</span><span>${fmtDate(o.created_at)}</span></div>
      <div class="detail-row"><span>Payment</span><span>${paymentPill(o.payment_status)}</span></div>
      <div class="detail-row"><span>Inventory</span><span>${esc(o.stock_state === 'reserved' ? 'Reserved until ' + fmtDate(o.reservation_expires_at) : o.stock_state)}</span></div>
      ${o.payment_reference ? `<div class="detail-row"><span>Paystack reference</span><span style="text-align:right;font-family:monospace">${esc(o.payment_reference)}</span></div>` : ''}
      <div style="margin:16px 0 6px;font-weight:700;color:var(--green-dk)">Items</div>
      ${items}
      <div class="detail-row"><span>Products subtotal</span><span>${cedis(o.subtotal_pesewas)}</span></div>
      ${o.discount_pesewas>0?`<div class="detail-row"><span>Discount (${esc(o.promo_code)})</span><span>−${cedis(o.discount_pesewas)}</span></div>`:''}
      <div class="detail-row"><span>Delivery fee</span><span>${cedis(o.delivery_fee_pesewas)}</span></div>
      <div class="detail-row" style="font-weight:800;border-bottom:0"><span>Grand total</span><span>${cedis(o.total_pesewas)}</span></div>
      <div class="field" style="margin-top:16px"><label>Update status</label><select id="om-status">${options}</select></div>
      <div class="field"><label>Private staff notes</label><textarea id="om-admin-notes" rows="4" maxlength="5000" placeholder="Delivery follow-up, payment checks or internal instructions">${esc(o.admin_notes||'')}</textarea><div class="hint">Only administrators can see these notes.</div></div>
      <div style="margin:16px 0 6px;font-weight:700;color:var(--green-dk)">Status history</div>${(o.history||[]).map(h=>`<div class="detail-row"><span>${statusPill(h.status)} ${esc(h.note||'')}</span><span class="sub">${fmtDate(h.created_at)}</span></div>`).join('')}
    `, [
      button('Close', 'btn-ghost', closeModal),
      button('Print invoice', 'btn-ghost', () => printInvoice(o)),
      button('Save staff notes', 'btn-ghost', async()=>{await api.setOrderNotes(o.id,$('#om-admin-notes').value);const ok=$('#om-ok');ok.textContent='Staff notes saved.';ok.style.display='block'}),
      button('Save status', 'btn-primary', async () => {
        await api.setOrderStatus(o.id, $('#om-status').value);
        $('#om-ok').style.display = 'block';
      }),
    ]);
  }

  function printInvoice(o){const win=window.open('','_blank','width=820,height=900');if(!win)return alert('Allow pop-ups to print the invoice.');const itemRows=o.items.map(i=>`<tr><td>${esc(i.product_name)}</td><td>${i.quantity}</td><td>${cedis(i.unit_price_pesewas)}</td><td>${cedis(i.line_total_pesewas)}</td></tr>`).join('');win.document.write(`<!doctype html><html><head><title>Invoice ${esc(o.reference)}</title><style>body{font:14px Arial,sans-serif;color:#17251c;max-width:760px;margin:35px auto;padding:0 20px}header{display:flex;justify-content:space-between;align-items:start;border-bottom:3px solid #1f7a3d;padding-bottom:18px}img{height:58px}h1{margin:0;color:#155c2d}h2{font-size:16px;margin-top:28px}.meta{display:grid;grid-template-columns:1fr 1fr;gap:8px 30px;margin-top:22px}.meta div{padding:6px 0;border-bottom:1px solid #ddd}table{width:100%;border-collapse:collapse;margin-top:12px}th,td{text-align:left;padding:10px;border-bottom:1px solid #ddd}th{background:#edf7f0}.totals{width:320px;margin:20px 0 0 auto}.line{display:flex;justify-content:space-between;padding:7px}.grand{font-size:18px;font-weight:bold;border-top:2px solid #1f7a3d}.paid{text-transform:uppercase;font-weight:bold;color:#1f7a3d}footer{margin-top:50px;border-top:1px solid #ddd;padding-top:12px;color:#66776d;text-align:center}@media print{body{margin:0}.no-print{display:none}}</style></head><body><header><img src="${location.origin}/img/logo-color.png"><div><h1>Order invoice</h1><div>${esc(o.reference)}</div></div></header><div class="meta"><div><b>Customer</b><br>${esc(o.customer_name)}</div><div><b>Phone</b><br>${esc(o.customer_phone)}</div><div><b>Delivery</b><br>${esc(o.delivery_address)}, ${esc(o.region)}</div><div><b>Order date</b><br>${fmtDate(o.created_at)}</div><div><b>Order status</b><br>${esc(o.status)}</div><div><b>Payment</b><br><span class="paid">${esc(o.payment_status)}</span></div></div><h2>Items</h2><table><thead><tr><th>Product</th><th>Qty</th><th>Unit price</th><th>Total</th></tr></thead><tbody>${itemRows}</tbody></table><div class="totals"><div class="line"><span>Subtotal</span><span>${cedis(o.subtotal_pesewas)}</span></div>${o.discount_pesewas>0?`<div class="line"><span>Discount (${esc(o.promo_code)})</span><span>−${cedis(o.discount_pesewas)}</span></div>`:''}<div class="line"><span>Delivery</span><span>${cedis(o.delivery_fee_pesewas)}</span></div><div class="line grand"><span>Grand total</span><span>${cedis(o.total_pesewas)}</span></div></div>${o.notes?`<h2>Order notes</h2><p>${esc(o.notes)}</p>`:''}<footer>Jay fooDs Ghana · Accra, Ghana · ${esc(o.reference)}</footer><script>window.onload=()=>window.print()<\/script></body></html>`);win.document.close()}

  // ---------------------------------------------------------------------------
  async function renderCustomers(){const {data}=await api.customers();const draw=()=>{const q=$('#customer-search').value.trim().toLowerCase(),filtered=data.filter(c=>!q||[c.customer_name,c.customer_phone,c.customer_email,c.region].some(v=>String(v||'').toLowerCase().includes(q)));$('#customer-count').textContent=`${filtered.length} customer${filtered.length===1?'':'s'}`;$('#customer-body').innerHTML=filtered.length?filtered.map(c=>{const digits=String(c.customer_phone).replace(/\D/g,'').replace(/^0/,'233');return `<tr><td><div class="prod-name">${esc(c.customer_name)}</div><div class="sub">${esc(c.customer_email||'No email')}</div></td><td>${esc(c.customer_phone)}</td><td>${esc(c.region)}</td><td>${c.order_count}</td><td>${cedis(c.lifetime_value_pesewas)}</td><td class="sub">${fmtDate(c.last_order_at)}</td><td><div class="row-actions"><a class="btn btn-ghost btn-sm" href="tel:${esc(c.customer_phone)}">Call</a><a class="btn btn-primary btn-sm" target="_blank" rel="noopener" href="https://wa.me/${digits}">WhatsApp</a></div></td></tr>`}).join(''):'<tr><td colspan="7" class="empty">No customers match your search.</td></tr>'};view.innerHTML=`<div class="panel"><div class="panel-head"><h2 id="customer-count">${data.length} customers</h2></div><div class="product-tools"><input id="customer-search" type="search" placeholder="Search name, phone, email or area"></div><div class="panel-body table-scroll"><table class="data"><thead><tr><th>Customer</th><th>Phone</th><th>Area</th><th>Orders</th><th>Paid value</th><th>Last order</th><th></th></tr></thead><tbody id="customer-body"></tbody></table></div></div>`;$('#customer-search').addEventListener('input',draw);draw()}

  function renderBackups(){view.innerHTML=`<div class="panel"><div class="panel-head"><h2>Database backup</h2></div><div class="panel-body" style="padding:24px"><div class="form-ok" id="backup-ok" style="display:none">Backup download started.</div><p style="margin:0 0 12px">Download a complete copy of orders, customers, products, settings and website content.</p><p class="sub" style="margin-bottom:20px">Store backups securely. The database contains customer details and encrypted service credentials. Create a backup before migrations or major changes.</p><button class="btn btn-primary" id="download-backup">Download database backup</button></div></div>`;$('#download-backup').addEventListener('click',async()=>{const b=$('#download-backup');b.disabled=true;b.textContent='Preparing backup…';try{const res=await fetch('/api/v1/admin/backups/database',{credentials:'same-origin'});if(res.status===401){location.replace('login.html');return}if(!res.ok)throw new Error('Could not create the backup.');const blob=await res.blob(),disposition=res.headers.get('Content-Disposition')||'',match=disposition.match(/filename="?([^";]+)"?/),a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=match?match[1]:'jayfoods-backup.sqlite';a.click();setTimeout(()=>URL.revokeObjectURL(a.href),1000);$('#backup-ok').style.display='block'}catch(err){alert(err.message)}finally{b.disabled=false;b.textContent='Download database backup'}})}

  // Messages
  // ---------------------------------------------------------------------------
  async function renderMessages() {
    const { data } = await api.messages();
    if (!data.length) {
      view.innerHTML = '<div class="panel"><div class="empty">No messages yet.</div></div>';
      return;
    }
    const items = data.map((m) => `
      <div class="msg-item ${m.is_read ? 'read' : 'unread'}">
        <div class="dot"></div>
        <div style="min-width:0">
          <div class="who">${esc(m.name)}</div>
          <div class="meta">${esc(m.phone)}${m.email ? ' · ' + esc(m.email) : ''} · ${fmtDate(m.created_at)}</div>
          <div class="text">${esc(m.message)}</div>
        </div>
        <div class="acts">
          <button class="btn btn-ghost btn-sm" data-read="${m.id}" data-val="${m.is_read ? 0 : 1}">${m.is_read ? 'Mark unread' : 'Mark read'}</button>
          <button class="btn btn-danger btn-sm" data-delmsg="${m.id}">Delete</button>
        </div>
      </div>`).join('');
    view.innerHTML = `<div class="panel"><div class="panel-head"><h2>${data.length} message${data.length === 1 ? '' : 's'}</h2></div><div class="panel-body">${items}</div></div>`;

    view.querySelectorAll('[data-read]').forEach((b) =>
      b.addEventListener('click', async () => { await api.setMessageRead(+b.dataset.read, +b.dataset.val === 1); router(); }));
    view.querySelectorAll('[data-delmsg]').forEach((b) =>
      b.addEventListener('click', async () => { await api.deleteMessage(+b.dataset.delmsg); router(); }));
  }

  // ---------------------------------------------------------------------------
  // Frontend content
  // ---------------------------------------------------------------------------
  async function renderContent() {
    const { data } = await api.content();
    const labels = { announcement:'Header announcement', hero:'Hero', features:'Why choose us', menu:'Product menu', ordering:'How it works', bulk:'Bulk orders', about:'About', reviews:'Reviews', faq:'FAQ', contact:'Contact details', footer:'Footer & social links' };
    const groups = Object.entries(data.groups).map(([group, fields]) => `
      <div class="panel content-group">
        <div class="panel-head"><h2>${esc(labels[group] || group)}</h2></div>
        <div class="panel-body" style="padding:20px">
          ${Object.entries(fields).map(([key, meta]) => `<div class="field">${meta[2] === 'checkbox' ? `<input type="hidden" name="${esc(key)}" value="0"><label class="checkline"><input name="${esc(key)}" type="checkbox" value="1" ${String(data.values[key])==='1'?'checked':''}> ${esc(meta[0])}</label>` : `<label>${esc(meta[0])}</label>${meta[2] === 'textarea' ? `<textarea name="${esc(key)}" rows="3">${esc(data.values[key] || '')}</textarea>` : `<input name="${esc(key)}" type="${meta[2] === 'url' ? 'url' : 'text'}" value="${esc(data.values[key] || '')}" />`}`}</div>`).join('')}
        </div>
      </div>`).join('');
    view.innerHTML = `<div class="form-error" id="content-error" style="display:none"></div><div class="form-ok" id="content-ok" style="display:none">Website content updated.</div><form id="content-form"><div class="content-grid">${groups}</div><div class="content-save"><button class="btn btn-primary" type="submit">Save all website content</button></div></form>`;
    $('#content-form').addEventListener('submit', async e => {
      e.preventDefault(); const f=new FormData(e.target), payload={}; for(const [key,value] of f.entries())payload[key]=value;
      const error=$('#content-error'),ok=$('#content-ok');error.style.display='none';ok.style.display='none';
      try{await api.updateContent(payload);ok.style.display='block';window.scrollTo({top:0,behavior:'smooth'});}catch(err){error.textContent=err.message;error.style.display='block';}
    });
  }

  async function renderDeliveryZones() {
    const {data}=await api.deliveryZones();
    const row=z=>`<div class="delivery-row" data-zone-id="${z.id||0}"><input data-zone="name" placeholder="Zone name" value="${esc(z.name||'')}"><input data-zone="fee" type="number" min="0" step="0.01" placeholder="Fee GH₵" value="${z.fee_pesewas!=null?(z.fee_pesewas/100).toFixed(2):''}"><label class="checkline"><input data-zone="active" type="checkbox" ${z.is_active!==false?'checked':''}> Active</label><button type="button" class="btn btn-danger btn-sm" data-remove-zone>×</button></div>`;
    view.innerHTML=`<div class="panel"><div class="panel-head"><h2>Delivery zones and fees</h2><button class="btn btn-ghost btn-sm" id="add-zone">+ Add zone</button></div><div class="panel-body" style="padding:20px"><div class="form-error" id="zone-error" style="display:none"></div><div class="form-ok" id="zone-ok" style="display:none">Delivery zones updated.</div><p class="sub" style="margin-bottom:16px">Customers only see active zones. Fees are added to the Paystack amount and saved with each order.</p><form id="zones-form"><div class="delivery-rows" id="delivery-rows">${data.map(row).join('')}</div><button class="btn btn-primary" type="submit">Save delivery zones</button></form></div></div>`;
    $('#add-zone').addEventListener('click',()=>$('#delivery-rows').insertAdjacentHTML('beforeend',row({is_active:true})));
    $('#delivery-rows').addEventListener('click',e=>{const b=e.target.closest('[data-remove-zone]');if(b)b.closest('.delivery-row').remove()});
    $('#zones-form').addEventListener('submit',async e=>{e.preventDefault();const zones=[...document.querySelectorAll('.delivery-row')].map(r=>({id:+r.dataset.zoneId,name:r.querySelector('[data-zone="name"]').value.trim(),fee_pesewas:toPesewas(r.querySelector('[data-zone="fee"]').value),is_active:r.querySelector('[data-zone="active"]').checked}));const error=$('#zone-error'),ok=$('#zone-ok');error.style.display='none';ok.style.display='none';try{await api.updateDeliveryZones(zones);await renderDeliveryZones();$('#zone-ok').style.display='block'}catch(err){error.textContent=err.message;error.style.display='block'}});
  }

  // ---------------------------------------------------------------------------
  async function renderPromos(){const {data}=await api.promoCodes();const rows=data.length?data.map(p=>`<tr><td class="prod-name">${esc(p.code)}</td><td>${p.discount_type==='percent'?p.discount_value+'%':cedis(p.discount_value)}</td><td>${cedis(p.minimum_pesewas)}</td><td>${p.used_count}${p.usage_limit>0?' / '+p.usage_limit:' / unlimited'}</td><td>${p.is_active==1?'Active':'Off'}</td><td><button class="btn btn-danger btn-sm" data-delete-promo="${p.id}">Delete</button></td></tr>`).join(''):'<tr><td colspan="6" class="empty">No promotional codes yet.</td></tr>';view.innerHTML=`<div class="panel"><div class="panel-head"><h2>Promotional codes</h2></div><div class="panel-body" style="padding:20px"><div class="form-error" id="promo-error" style="display:none"></div><form id="promo-form" class="form-2col"><div class="field"><label>Code</label><input name="code" required placeholder="WELCOME10"></div><div class="field"><label>Type</label><select name="type"><option value="percent">Percentage</option><option value="fixed">Fixed amount</option></select></div><div class="field"><label>Value</label><input name="value" type="number" min="1" required></div><div class="field"><label>Minimum order (GH₵)</label><input name="minimum" type="number" min="0" step="0.01" value="0"></div><div class="field"><label>Usage limit (0 = unlimited)</label><input name="limit" type="number" min="0" value="0"></div><div class="field"><label class="checkline"><input name="active" type="checkbox" checked> Active</label></div><button class="btn btn-primary">Create code</button></form></div><div class="panel-body table-scroll"><table class="data"><thead><tr><th>Code</th><th>Discount</th><th>Minimum</th><th>Used</th><th>Status</th><th></th></tr></thead><tbody>${rows}</tbody></table></div></div>`;$('#promo-form').addEventListener('submit',async e=>{e.preventDefault();const f=new FormData(e.target),type=f.get('type'),value=+f.get('value');try{await api.savePromo({code:f.get('code'),discount_type:type,discount_value:type==='fixed'?toPesewas(value):value,minimum_pesewas:toPesewas(f.get('minimum')),usage_limit:+f.get('limit'),is_active:f.get('active')==='on'});renderPromos()}catch(err){const b=$('#promo-error');b.textContent=err.message;b.style.display='block'}});view.querySelectorAll('[data-delete-promo]').forEach(b=>b.addEventListener('click',async()=>{if(confirm('Delete this code?')){await api.deletePromo(+b.dataset.deletePromo);renderPromos()}}));}

  // Settings
  // ---------------------------------------------------------------------------
  async function renderSettings() {
    const [{ data: smtp }, { data: paystack }] = await Promise.all([api.smtpSettings(), api.paystackSettings()]);
    view.innerHTML = `
      <div class="settings-grid">
        <div class="panel">
          <div class="panel-head"><h2>Account</h2></div>
          <div class="panel-body" style="padding:20px">
            <div class="form-error" id="profile-error" style="display:none"></div>
            <div class="form-ok" id="profile-ok" style="display:none">Account details updated.</div>
            <form id="profile-form">
              <div class="field"><label>Name</label><input name="name" value="${esc(currentAdmin.name)}" autocomplete="name" /></div>
              <div class="field"><label>Email</label><input type="email" name="email" value="${esc(currentAdmin.email)}" autocomplete="email" /></div>
              <button class="btn btn-primary" type="submit">Save account details</button>
            </form>
          </div>
        </div>
        <div class="panel">
          <div class="panel-head"><h2>Change password</h2></div>
          <div class="panel-body" style="padding:20px">
            <div class="form-error" id="pw-error" style="display:none"></div>
            <div class="form-ok" id="pw-ok" style="display:none">Password updated.</div>
            <form id="pw-form">
              <div class="field"><label>Current password</label><input type="password" name="current" /></div>
              <div class="field"><label>New password</label><input type="password" name="next" /><div class="hint">At least 8 characters.</div></div>
              <button class="btn btn-primary" type="submit">Update password</button>
            </form>
          </div>
        </div>
        <div class="panel settings-wide">
          <div class="panel-head"><h2>Gmail SMTP</h2></div>
          <div class="panel-body" style="padding:20px">
            <div class="form-error" id="smtp-error" style="display:none"></div>
            <div class="form-ok" id="smtp-ok" style="display:none">SMTP settings saved.</div>
            <form id="smtp-form">
              <div class="form-2col">
                <div class="field"><label>SMTP host</label><input name="host" value="${esc(smtp.host)}" /></div>
                <div class="field"><label>Port</label><input name="port" type="number" min="1" max="65535" value="${smtp.port}" /></div>
                <div class="field"><label>Security</label><select name="encryption"><option value="tls" ${smtp.encryption === 'tls' ? 'selected' : ''}>TLS</option><option value="ssl" ${smtp.encryption === 'ssl' ? 'selected' : ''}>SSL</option></select></div>
                <div class="field"><label>Sender name</label><input name="sender_name" value="${esc(smtp.sender_name)}" /></div>
                <div class="field"><label>Gmail address</label><input name="username" type="email" value="${esc(smtp.username)}" autocomplete="username" placeholder="youraccount@gmail.com" /></div>
                <div class="field"><label>Notification email</label><input name="notification_email" type="email" value="${esc(smtp.notification_email)}" placeholder="orders@example.com" /></div>
                <div class="field full"><label>Google App Password</label><input name="password" type="password" autocomplete="new-password" placeholder="${smtp.has_password ? 'Saved — leave blank to keep it' : '16-character App Password'}" /><div class="hint">Use a Google App Password, not your regular Google password. Spaces are removed automatically.</div></div>
              </div>
              <button class="btn btn-primary" type="submit">Save SMTP settings</button>
              <button class="btn btn-ghost" type="button" id="smtp-test">Send test email</button>
            </form>
          </div>
        </div>
        <div class="panel settings-wide">
          <div class="panel-head"><h2>Paystack payments</h2></div>
          <div class="panel-body" style="padding:20px">
            <div class="form-error" id="paystack-error" style="display:none"></div>
            <div class="form-ok" id="paystack-ok" style="display:none">Paystack settings saved.</div>
            <form id="paystack-form"><div class="form-2col">
              <div class="field full"><label>Public key</label><input name="public_key" value="${esc(paystack.public_key)}" placeholder="pk_test_..." /></div>
              <div class="field full"><label>Secret key</label><input name="secret_key" type="password" autocomplete="new-password" placeholder="${paystack.has_secret_key ? 'Saved — leave blank to keep it' : 'sk_test_...'}" /><div class="hint">Encrypted at rest and never returned to the browser.</div></div>
              <div class="field full"><label>Webhook URL</label><input name="webhook_url" type="url" value="${esc(paystack.webhook_url)}" placeholder="https://yourdomain.com/api/v1/payments/webhook" /><div class="hint">Add this exact URL in your Paystack dashboard.</div></div>
            </div><button class="btn btn-primary" type="submit">Save Paystack settings</button></form>
          </div>
        </div>
      </div>`;
    $('#profile-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = new FormData(e.target);
      const errBox = $('#profile-error'), okBox = $('#profile-ok');
      errBox.style.display = 'none'; okBox.style.display = 'none';
      e.target.querySelectorAll('.field').forEach((el) => el.classList.remove('err'));
      try {
        const { data } = await api.updateProfile({
          name: f.get('name').trim(),
          email: f.get('email').trim(),
        });
        currentAdmin = data;
        $('#admin-name').textContent = data.name;
        $('#admin-email').textContent = data.email;
        okBox.style.display = 'block';
      } catch (err) {
        errBox.textContent = err.message;
        errBox.style.display = 'block';
        Object.keys(err.fields || {}).forEach((key) => {
          const input = e.target.querySelector(`[name="${key}"]`);
          if (input) input.closest('.field').classList.add('err');
        });
      }
    });
    $('#pw-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = new FormData(e.target);
      const errBox = $('#pw-error'), okBox = $('#pw-ok');
      errBox.style.display = 'none'; okBox.style.display = 'none';
      try {
        await api.changePassword({ current_password: f.get('current'), new_password: f.get('next') });
        okBox.style.display = 'block';
        e.target.reset();
      } catch (err) {
        errBox.textContent = err.message;
        errBox.style.display = 'block';
      }
    });
    $('#smtp-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = new FormData(e.target);
      const errBox = $('#smtp-error'), okBox = $('#smtp-ok');
      errBox.style.display = 'none'; okBox.style.display = 'none';
      e.target.querySelectorAll('.field').forEach((el) => el.classList.remove('err'));
      try {
        await api.updateSmtp({
          host: f.get('host').trim(), port: parseInt(f.get('port'), 10),
          encryption: f.get('encryption'), sender_name: f.get('sender_name').trim(),
          username: f.get('username').trim(), notification_email: f.get('notification_email').trim(),
          password: f.get('password'),
        });
        e.target.elements.password.value = '';
        e.target.elements.password.placeholder = 'Saved — leave blank to keep it';
        okBox.style.display = 'block';
      } catch (err) {
        errBox.textContent = err.message;
        errBox.style.display = 'block';
        Object.keys(err.fields || {}).forEach((key) => {
          const input = e.target.querySelector(`[name="${key}"]`);
          if (input) input.closest('.field').classList.add('err');
        });
      }
    });
    $('#smtp-test').addEventListener('click', async (e) => {
      const button = e.currentTarget;
      const errBox = $('#smtp-error'), okBox = $('#smtp-ok');
      errBox.style.display = 'none'; okBox.style.display = 'none';
      button.disabled = true; button.textContent = 'Sending…';
      try {
        const { data } = await api.testSmtp();
        okBox.textContent = `Test email sent to ${data.recipient}.`;
        okBox.style.display = 'block';
      } catch (err) {
        errBox.textContent = err.message;
        errBox.style.display = 'block';
      } finally {
        button.disabled = false; button.textContent = 'Send test email';
      }
    });
    $('#paystack-form').addEventListener('submit', async (e) => {
      e.preventDefault(); const f = new FormData(e.target), errBox=$('#paystack-error'), okBox=$('#paystack-ok');
      errBox.style.display='none'; okBox.style.display='none'; e.target.querySelectorAll('.field').forEach(x=>x.classList.remove('err'));
      try { await api.updatePaystack({public_key:f.get('public_key').trim(),secret_key:f.get('secret_key'),webhook_url:f.get('webhook_url').trim()}); e.target.elements.secret_key.value=''; e.target.elements.secret_key.placeholder='Saved — leave blank to keep it'; okBox.style.display='block'; }
      catch(err){ errBox.textContent=err.message; errBox.style.display='block'; Object.keys(err.fields||{}).forEach(k=>{const i=e.target.querySelector(`[name="${k}"]`);if(i)i.closest('.field').classList.add('err');}); }
    });
  }

  // ---------------------------------------------------------------------------
  // Badge + chrome
  // ---------------------------------------------------------------------------
  async function refreshBadge() {
    try {
      const { data } = await api.stats();
      const badge = $('#nav-msg-badge');
      if (data.messages_unread > 0) { badge.textContent = data.messages_unread; badge.style.display = 'inline-block'; }
      else badge.style.display = 'none';
    } catch (_) {}
  }

  $('#logout').addEventListener('click', async () => { try { await api.logout(); } catch (_) {} location.replace('login.html'); });
  $('#hamburger').addEventListener('click', () => $('#sidebar').classList.toggle('open'));

  // ---------------------------------------------------------------------------
  // Boot: guard, then route
  // ---------------------------------------------------------------------------
  let currentAdmin = null;
  (async function boot() {
    try {
      const { data } = await api.me();
      currentAdmin = data;
      $('#admin-name').textContent = data.name;
      $('#admin-email').textContent = data.email;
      if (!location.hash) location.hash = '#/dashboard';
      router();
    } catch (err) {
      // apiFetch already redirects on 401.
    }
  })();
})();
