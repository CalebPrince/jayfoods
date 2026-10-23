/* Kafui admin screens: inbox, bookings, test chat, action log, settings.
   Chat text is always set with textContent, never innerHTML (CTL-FE-001). */
(function () {
  'use strict';

  let h; // helpers from admin.js
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };
  const call = (path, method, body) => h.apiFetch(path, method ? { method, body: body == null ? undefined : JSON.stringify(body) } : {});
  const sub = () => { const p = location.hash.replace(/^#\//, '').split('/'); return { page: p[1] || 'inbox', id: p[2] ? +p[2] : 0 }; };
  let pollTimer = null;

  async function render(helpers) {
    h = helpers;
    clearInterval(pollTimer);
    const { page, id } = sub();
    const { data: ov } = await call('/admin/agent/overview');
    const view = h.view;
    view.innerHTML = '';
    view.appendChild(header(ov, page));
    const body = el('div', 'kf-body');
    view.appendChild(body);
    if (page === 'c' && id) return renderChat(body, id);
    if (page === 'bookings') return renderBookings(body);
    if (page === 'test') return renderTest(body);
    if (page === 'log') return renderLog(body);
    if (page === 'settings') return renderSettings(body);
    return renderInbox(body);
  }

  function header(ov, page) {
    const wrap = el('div', 'kf-head');
    const status = el('div', 'kf-status ' + (ov.enabled ? 'on' : 'off'));
    status.appendChild(el('span', 'kf-dot'));
    status.appendChild(el('b', null, ov.enabled ? 'Kafui is answering' : 'Kafui is off'));
    status.appendChild(el('span', 'sub', ov.enabled ? ' on WhatsApp and the website chat' : ' (messages are still saved here for staff)'));
    const toggle = h.button(ov.enabled ? 'Turn Kafui off' : 'Turn Kafui on', ov.enabled ? 'btn-danger btn-sm' : 'btn-primary btn-sm', () => {
      if (ov.enabled) {
        h.openModal('Turn Kafui off?', '<p>Kafui will stop answering on WhatsApp and the website chat straight away. Customers get one short message that the team will reply. Messages keep arriving in the inbox.</p>', [
          h.button('Cancel', 'btn-ghost', h.closeModal),
          h.button('Turn off', 'btn-danger', async () => { await call('/admin/agent/enabled', 'PUT', { enabled: false }); h.closeModal(); h.router(); }),
        ]);
      } else {
        call('/admin/agent/enabled', 'PUT', { enabled: true }).then(() => h.router()).catch((e) => alertBox(wrap, e.message));
      }
    });
    status.appendChild(toggle);
    wrap.appendChild(status);

    if (ov.missing && ov.missing.length) {
      const m = el('div', 'kf-missing');
      m.appendChild(el('b', null, 'Before Kafui can go live: '));
      m.appendChild(el('span', null, ov.missing.join('; ') + '.'));
      wrap.appendChild(m);
    }
    if (!ov.owner_numbers) wrap.appendChild(el('div', 'kf-missing soft', 'No owner number yet. Add it in Settings so Kafui recognises the owner on WhatsApp.'));

    const c = ov.counters || {};
    const stats = el('div', 'kf-stats');
    [['Need a person', c.needs_attention], ['Unread', c.unread], ['Kafui orders today', c.kafui_orders_today], ['Handoffs (24h)', c.handoffs_24h],
     ['Replaced replies (24h)', c.reply_replaced_24h], ['Owner requests (24h)', c.owner_tools_24h], ['AI failures (24h)', c.provider_failed_24h], ['Rejected webhooks (24h)', c.signature_failed_24h]]
      .forEach(([label, n]) => { const s = el('div', 'kf-stat' + (n > 0 && /Need|failures|Rejected|Replaced/.test(label) ? ' warn' : '')); s.appendChild(el('div', 'num', String(n || 0))); s.appendChild(el('div', 'lbl', label)); stats.appendChild(s); });
    wrap.appendChild(stats);

    const tabs = el('nav', 'kf-tabs');
    [['inbox', 'Inbox'], ['bookings', 'Bookings'], ['test', 'Try Kafui'], ['log', 'Action log'], ['settings', 'Settings']].forEach(([key, label]) => {
      const a = el('a', page === key || (key === 'inbox' && page === 'c') ? 'active' : '', label);
      a.href = '#/kafui/' + key;
      tabs.appendChild(a);
    });
    wrap.appendChild(tabs);
    return wrap;
  }

  function alertBox(parent, msg, ok) { const b = el('div', ok ? 'form-ok' : 'form-error', msg); parent.prepend(b); setTimeout(() => b.remove(), 8000); }

  // ---------------------------------------------------------------- inbox
  async function renderInbox(body) {
    const filter = new URLSearchParams(location.hash.split('?')[1] || '').get('f') || '';
    const { data } = await call('/admin/agent/conversations' + (filter ? '?filter=' + encodeURIComponent(filter) : ''));
    const panel = el('div', 'panel');
    const head = el('div', 'panel-head');
    head.appendChild(el('h2', null, data.length + ' chats'));
    const sel = el('select', 'kf-filter'); sel.id = 'kf-filter';
    [['', 'All chats'], ['attention', 'Need a person'], ['whatsapp', 'WhatsApp'], ['web', 'Website chat']].forEach(([v, t]) => { const o = el('option', null, t); o.value = v; o.selected = v === filter; sel.appendChild(o); });
    sel.addEventListener('change', () => { location.hash = '#/kafui/inbox' + (sel.value ? '?f=' + sel.value : ''); });
    head.appendChild(sel);
    panel.appendChild(head);
    if (!data.length) {
      panel.appendChild(el('div', 'empty', 'No chats yet. When customers message the JayFoods WhatsApp number or the website chat, they appear here.'));
    }
    const list = el('div', 'kf-list');
    data.forEach((c) => {
      const row = el('a', 'kf-row' + (c.needs_attention ? ' attention' : ''));
      row.href = '#/kafui/c/' + c.id;
      const top = el('div', 'kf-row-top');
      top.appendChild(el('b', null, c.name || (c.phone ? '+' + c.phone : 'Website visitor')));
      top.appendChild(el('span', 'pill ' + (c.channel === 'whatsapp' ? 'type-bulk' : 'type-single'), c.channel === 'whatsapp' ? 'WhatsApp' : 'Website'));
      top.appendChild(el('span', 'pill ' + (c.mode === 'staff' ? 'off' : 'on'), c.mode === 'staff' ? 'Staff answering' : 'Kafui answering'));
      if (c.needs_attention) top.appendChild(el('span', 'pill kf-need', 'Needs a person'));
      if (c.unread) top.appendChild(el('span', 'badge kf-unread', String(c.unread)));
      top.appendChild(el('span', 'sub kf-time', h.fmtDateText(c.last_message_at)));
      row.appendChild(top);
      row.appendChild(el('div', 'kf-preview', c.last_body || ''));
      list.appendChild(row);
    });
    panel.appendChild(list);
    body.appendChild(panel);
  }

  // ---------------------------------------------------------------- one chat
  async function renderChat(body, id) {
    const { data: c } = await call('/admin/agent/conversations/' + id);
    const grid = el('div', 'kf-chat-grid');
    const panel = el('div', 'panel kf-chat');
    const head = el('div', 'panel-head');
    const title = el('div');
    title.appendChild(el('h2', null, (c.name || 'Customer') + (c.phone ? ' (+' + c.phone + ')' : '')));
    title.appendChild(el('div', 'sub', (c.channel === 'whatsapp' ? 'WhatsApp' : 'Website chat') + (c.is_owner ? ', owner number' : '') + ', ' + (c.mode === 'staff' ? 'staff answering, Kafui is quiet' : 'Kafui answering')));
    head.appendChild(title);
    const actions = el('div', 'kf-actions');
    if (c.mode === 'staff') actions.appendChild(h.button('Hand back to Kafui', 'btn-ghost btn-sm', async () => { await call('/admin/agent/conversations/' + id + '/hand-back', 'POST', {}); h.router(); }));
    else actions.appendChild(h.button('Take over', 'btn-ghost btn-sm', async () => { await call('/admin/agent/conversations/' + id + '/take-over', 'POST', {}); h.router(); }));
    const back = el('a', 'btn btn-ghost btn-sm', 'All chats'); back.href = '#/kafui/inbox';
    actions.appendChild(back);
    head.appendChild(actions);
    panel.appendChild(head);

    const thread = el('div', 'kf-thread'); thread.setAttribute('role', 'log'); thread.setAttribute('aria-live', 'polite');
    c.messages.forEach((m) => {
      const who = m.sender === 'customer' ? (c.name || 'Customer') : m.sender === 'kafui' ? 'Kafui' : m.sender === 'staff' ? (m.staff_name || 'Staff') : 'Note';
      const b = el('div', 'kf-msg ' + m.sender);
      b.appendChild(el('div', 'kf-who', who + ', ' + h.fmtDateText(m.created_at)));
      b.appendChild(el('div', 'kf-text', m.body));
      thread.appendChild(b);
    });
    panel.appendChild(thread);

    const form = el('form', 'kf-reply');
    const ta = el('textarea'); ta.id = 'kf-reply-text'; ta.rows = 3; ta.maxLength = 2000; ta.setAttribute('aria-label', 'Reply to the customer');
    ta.placeholder = c.window_open ? 'Write a reply. Sending it means staff are answering this chat.' : 'WhatsApp\'s 24-hour reply window has closed. Call the customer instead.';
    ta.disabled = !c.window_open;
    const send = el('button', 'btn btn-primary', 'Send reply'); send.type = 'submit'; send.disabled = !c.window_open;
    form.appendChild(ta); form.appendChild(send);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!ta.value.trim()) return;
      send.disabled = true; send.textContent = 'Sending...';
      try { await call('/admin/agent/conversations/' + id + '/reply', 'POST', { text: ta.value }); h.router(); }
      catch (err) { alertBox(form, err.message); send.disabled = false; send.textContent = 'Send reply'; }
    });
    ta.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) form.requestSubmit(); });
    panel.appendChild(form);
    grid.appendChild(panel);

    const side = el('div', 'panel kf-side');
    side.appendChild(el('div', 'panel-head')).appendChild(el('h2', null, 'From this chat'));
    const sb = el('div', 'kf-side-body');
    sb.appendChild(el('h3', null, 'Orders'));
    if (!c.orders.length) sb.appendChild(el('p', 'sub', 'None yet.'));
    c.orders.forEach((o) => { const a = el('a', 'kf-side-row', o.reference + ', ' + h.cedis(o.total_pesewas) + ', ' + o.status + ', ' + o.payment_status); a.href = '#/orders'; sb.appendChild(a); });
    sb.appendChild(el('h3', null, 'Bookings'));
    if (!c.bookings.length) sb.appendChild(el('p', 'sub', 'None yet.'));
    c.bookings.forEach((b) => { const a = el('a', 'kf-side-row', b.reference + ', ' + b.kind + (b.event_date ? ', ' + b.event_date : '') + ', ' + b.status); a.href = '#/kafui/bookings'; sb.appendChild(a); });
    sb.appendChild(el('h3', null, 'What Kafui did'));
    if (!c.actions.length) sb.appendChild(el('p', 'sub', 'No tool use yet.'));
    c.actions.forEach((a) => sb.appendChild(el('div', 'kf-side-row ' + a.status, a.tool.replace(/_/g, ' ') + ': ' + a.status + (a.detail ? ' (' + a.detail + ')' : ''))));
    side.appendChild(sb);
    grid.appendChild(side);
    body.appendChild(grid);
    thread.scrollTop = thread.scrollHeight;
    pollTimer = setInterval(async () => {
      if (!location.hash.startsWith('#/kafui/c/' + id)) return clearInterval(pollTimer);
      if (document.activeElement === ta && ta.value) return;
      try { const { data: fresh } = await call('/admin/agent/conversations/' + id); if (fresh.messages.length !== c.messages.length) h.router(); } catch (_) {}
    }, 8000);
  }

  // ---------------------------------------------------------------- bookings
  async function renderBookings(body) {
    const { data } = await call('/admin/bookings');
    const panel = el('div', 'panel');
    const head = el('div', 'panel-head'); head.appendChild(el('h2', null, data.length + ' bookings and call-back requests')); panel.appendChild(head);
    if (!data.length) panel.appendChild(el('div', 'empty', 'Event bookings and call-back requests Kafui takes appear here for you to confirm.'));
    const list = el('div', 'kf-list');
    data.forEach((b) => {
      const row = el('div', 'kf-row');
      const top = el('div', 'kf-row-top');
      top.appendChild(el('b', null, b.reference + ', ' + b.customer_name));
      top.appendChild(el('span', 'pill type-bulk', b.kind === 'callback' ? 'Call back' : 'Event'));
      top.appendChild(el('span', 'pill ' + (b.status === 'confirmed' || b.status === 'done' ? 'on' : 'off'), b.status));
      top.appendChild(el('span', 'sub kf-time', h.fmtDateText(b.created_at)));
      row.appendChild(top);
      const facts = [b.customer_phone, b.event_date && ('Date ' + b.event_date + ' ' + b.event_time), b.venue && ('Venue ' + b.venue), b.guests > 0 && (b.guests + ' guests'), b.juices && ('Juices: ' + b.juices), b.notes && ('Notes: ' + b.notes), b.staff_notes && ('Staff: ' + b.staff_notes)].filter(Boolean);
      row.appendChild(el('div', 'kf-preview', facts.join('. ')));
      const act = el('div', 'kf-actions');
      [['confirmed', 'Confirm'], ['declined', 'Decline'], ['done', 'Mark done']].forEach(([s, label]) => {
        if (b.status === s) return;
        act.appendChild(h.button(label, 'btn-ghost btn-sm', async () => { await call('/admin/bookings/' + b.id, 'PATCH', { status: s, staff_notes: b.staff_notes }); h.router(); }));
      });
      act.appendChild(h.button('Staff note', 'btn-ghost btn-sm', () => {
        h.openModal('Note on ' + b.reference, '<div class="field"><label for="kf-bnote">Only staff see this</label><textarea id="kf-bnote" rows="4" maxlength="2000"></textarea></div>', [
          h.button('Cancel', 'btn-ghost', h.closeModal),
          h.button('Save note', 'btn-primary', async () => { await call('/admin/bookings/' + b.id, 'PATCH', { status: b.status, staff_notes: document.getElementById('kf-bnote').value }); h.closeModal(); h.router(); }),
        ]);
        document.getElementById('kf-bnote').value = b.staff_notes || '';
      }));
      row.appendChild(act);
      list.appendChild(row);
    });
    panel.appendChild(list);
    body.appendChild(panel);
  }

  // ---------------------------------------------------------------- test chat
  function renderTest(body) {
    const panel = el('div', 'panel kf-chat');
    const head = el('div', 'panel-head');
    head.appendChild(el('h2', null, 'Try Kafui'));
    const role = el('select', 'kf-filter'); role.id = 'kf-test-role'; role.setAttribute('aria-label', 'Chat as');
    [['customer', 'Chat as a customer'], ['owner', 'Chat as the owner']].forEach(([v, t]) => { const o = el('option', null, t); o.value = v; role.appendChild(o); });
    head.appendChild(role);
    panel.appendChild(head);
    panel.appendChild(el('p', 'kf-note', 'This uses the real AI providers and the real price list. Orders and bookings made here are not saved and nothing is sent on WhatsApp. Works while Kafui is off, so you can test before going live.'));
    const thread = el('div', 'kf-thread'); thread.setAttribute('role', 'log'); thread.setAttribute('aria-live', 'polite');
    panel.appendChild(thread);
    const form = el('form', 'kf-reply');
    const input = el('textarea'); input.id = 'kf-test-text'; input.rows = 2; input.maxLength = 1000; input.placeholder = 'For example: I need 40 bottles of orange juice for an office party on Friday'; input.setAttribute('aria-label', 'Message to Kafui');
    const send = el('button', 'btn btn-primary', 'Send'); send.type = 'submit';
    const reset = h.button('Start over', 'btn-ghost', async () => { await call('/admin/agent/test', 'POST', { reset: true, role: role.value }); thread.innerHTML = ''; });
    form.appendChild(input); form.appendChild(send); form.appendChild(reset);
    const add = (cls, who, text) => { const m = el('div', 'kf-msg ' + cls); m.appendChild(el('div', 'kf-who', who)); m.appendChild(el('div', 'kf-text', text)); thread.appendChild(m); thread.scrollTop = thread.scrollHeight; };
    role.addEventListener('change', () => { thread.innerHTML = ''; });
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const text = input.value.trim(); if (!text) return;
      add('customer', role.value === 'owner' ? 'You (as owner)' : 'You (as customer)', text);
      input.value = ''; send.disabled = true; send.textContent = 'Kafui is typing...';
      try {
        const { data } = await call('/admin/agent/test', 'POST', { text, role: role.value });
        if (data.tools && data.tools.length) add('system', 'Tools used', data.tools.map((t) => t.tool.replace(/_/g, ' ') + ': ' + t.status + (t.detail ? ' (' + t.detail + ')' : '')).join('\n'));
        add('kafui', 'Kafui', data.reply == null ? '(no reply: the chat was handed to staff)' : data.reply);
      } catch (err) { add('system', 'Error', err.message); }
      finally { send.disabled = false; send.textContent = 'Send'; input.focus(); }
    });
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); } });
    panel.appendChild(form);
    body.appendChild(panel);
  }

  // ---------------------------------------------------------------- action log
  async function renderLog(body) {
    const { data } = await call('/admin/agent/actions');
    const panel = el('div', 'panel');
    const head = el('div', 'panel-head'); head.appendChild(el('h2', null, 'Everything Kafui did (last 300 tool uses)')); panel.appendChild(head);
    if (!data.length) { panel.appendChild(el('div', 'empty', 'Nothing yet.')); body.appendChild(panel); return; }
    const wrap = el('div', 'panel-body table-scroll');
    const table = el('table', 'data');
    const thead = el('thead'); const tr = el('tr');
    ['When', 'Chat', 'Role', 'Tool', 'Result', 'Detail'].forEach((t) => tr.appendChild(el('th', null, t)));
    thead.appendChild(tr); table.appendChild(thead);
    const tb = el('tbody');
    data.forEach((a) => {
      const r = el('tr');
      r.appendChild(el('td', 'sub', h.fmtDateText(a.created_at)));
      const chat = el('td'); if (a.conversation_id && a.channel !== 'test') { const l = el('a', null, (a.contact_name || 'chat') + ' (' + a.channel + ')'); l.href = '#/kafui/c/' + a.conversation_id; chat.appendChild(l); } else chat.textContent = a.channel === 'test' ? 'Test chat' : '';
      r.appendChild(chat);
      r.appendChild(el('td', null, a.role));
      r.appendChild(el('td', null, a.tool.replace(/_/g, ' ')));
      r.appendChild(el('td')).appendChild(el('span', 'pill ' + (a.status === 'ok' ? 'on' : 'off'), a.status));
      r.appendChild(el('td', 'sub', a.detail));
      tb.appendChild(r);
    });
    table.appendChild(tb); wrap.appendChild(table); panel.appendChild(wrap); body.appendChild(panel);
  }

  // ---------------------------------------------------------------- settings
  async function renderSettings(body) {
    const { data } = await call('/admin/agent/settings');
    const s = data.settings, has = data.has;
    const webhook = (s.site_url || location.origin) + data.webhook_path;
    const saved = (k) => has[k] ? 'Saved. Leave blank to keep it.' : 'Not set';
    const field = (name, label, value, hint, type) => `<div class="field" data-f="${h.esc(name)}"><label for="kf-${h.esc(name)}">${h.esc(label)}</label><input id="kf-${h.esc(name)}" name="${h.esc(name)}" type="${type || 'text'}" value="${h.esc(value || '')}" autocomplete="off">${hint ? `<div class="hint">${hint}</div>` : ''}</div>`;
    const secret = (name, label, hint) => `<div class="field" data-f="${h.esc(name)}"><label for="kf-${h.esc(name)}">${h.esc(label)}</label><input id="kf-${h.esc(name)}" name="secret:${h.esc(name)}" type="password" autocomplete="new-password" placeholder="${h.esc(saved(name))}">${hint ? `<div class="hint">${hint}</div>` : ''}</div>`;
    const providerRows = Object.entries(data.providers).map(([key, label]) => `
      <div class="kf-provider">
        <label class="checkline"><input type="checkbox" name="${key}_enabled" ${s[key + '_enabled'] === '1' ? 'checked' : ''}> <b>${h.esc(label)}</b></label>
        ${field(key + '_model', 'Main model', s[key + '_model'], '')}
        ${field(key + '_fallback_model', 'Backup model', s[key + '_fallback_model'], 'Optional. Tried if the main model fails, before the next provider.')}
        ${secret(key + '_key', 'API key', '')}
      </div>`).join('');
    const owners = (data.owner_numbers.length ? data.owner_numbers : [{ number: '', label: '' }]).concat([{ number: '', label: '' }]).slice(0, 3);

    const panel = el('div', 'panel');
    panel.innerHTML = `
      <div class="panel-head"><h2>Kafui settings</h2></div>
      <form id="kf-settings" class="panel-body kf-settings">
        <div class="form-error" id="kf-err" style="display:none"></div>
        <div class="form-ok" id="kf-ok" style="display:none">Settings saved.</div>
        <h3>1. The live site</h3>
        ${field('site_url', 'Live site address', s.site_url, 'The public https address, for example https://jayfoods.princecaleb.dev. WhatsApp fetches invoice PDFs from it, and Twilio signs messages with it, so it must match exactly.', 'url')}
        <h3>2. WhatsApp</h3>
        <div class="field" data-f="whatsapp_provider"><label for="kf-whatsapp_provider">Connected through</label>
          <select id="kf-whatsapp_provider" name="whatsapp_provider" class="kf-filter">
            <option value="twilio" ${s.whatsapp_provider !== 'meta' ? 'selected' : ''}>Twilio (WhatsApp sender in the Twilio Console)</option>
            <option value="meta" ${s.whatsapp_provider === 'meta' ? 'selected' : ''}>Meta Cloud API directly</option>
          </select><div class="hint">Only the chosen one receives messages.</div></div>
        <div id="kf-twilio-box">
          <div class="form-2col">
            ${field('twilio_account_sid', 'Account SID', s.twilio_account_sid, 'Twilio Console, Account info. Starts with AC.')}
            ${field('twilio_whatsapp_number', 'WhatsApp sender number', s.twilio_whatsapp_number, 'The JayFoods number with country code, for example 233246328461.')}
          </div>
          ${secret('twilio_auth_token', 'Auth token', 'Twilio Console, Account info. It also checks that every incoming message really comes from Twilio.')}
          <div class="field"><label>Webhook URL for Twilio</label><div class="kf-copy"><code id="kf-twilio-webhook"></code></div><div class="hint">In the Twilio Console, open Messaging, Senders, WhatsApp senders, the JayFoods number. Paste this as the webhook URL for incoming messages, method POST. It must match the Live site address above exactly.</div></div>
        </div>
        <div id="kf-meta-box">
        <div class="form-2col">
          ${field('wa_phone_number_id', 'Phone number ID', s.wa_phone_number_id, 'WhatsApp Manager or your Meta app, WhatsApp, API setup. Digits only.')}
          ${field('wa_graph_version', 'Graph API version', s.wa_graph_version, 'As shown in your Meta app, for example v23.0.')}
        </div>
        ${secret('wa_access_token', 'Permanent access token (system user)', 'Business settings, System users. Give it whatsapp_business_messaging on this WhatsApp account only.')}
        ${secret('wa_app_secret', 'App secret', 'Your Meta app, App settings, Basic. Used to check every webhook really comes from Meta.')}
        <div class="field"><label>Webhook URL for Meta</label><div class="kf-copy"><code id="kf-webhook"></code></div><div class="hint">Paste this into your Meta app, WhatsApp, Configuration, Callback URL. Subscribe to the "messages" field.</div></div>
        <div class="field"><label>Verify token for Meta</label><div class="kf-copy"><code id="kf-verify">${has.wa_verify_token ? 'Saved. Generate a new one only if you need to reconnect.' : 'Not set'}</code> <button type="button" class="btn btn-ghost btn-sm" id="kf-gen">Generate new token</button></div><div class="hint">Shown once. Paste it into Meta next to the Callback URL, then press Verify and save there.</div></div>
        </div>
        <h3>3. AI providers</h3>
        <p class="sub">Kafui tries Anthropic, then OpenAI, then OpenRouter, then Groq. If a provider fails or is too slow, its backup model is tried, then the next provider. If every one fails, the customer is told a person will reply and the chat goes to staff. Turn on at least two for a real fallback, with keys and model names from each provider's own site, and set a monthly spend limit at each.</p>
        <div class="kf-providers">${providerRows}</div>
        <h3>4. Owner and staff</h3>
        <p class="sub">The owner's WhatsApp number unlocks sales summaries, order and booking lists and invoices, on WhatsApp only. Use the full number with country code.</p>
        <div id="kf-owners">${owners.map((o, i) => `<div class="form-2col kf-owner"><div class="field"><label for="kf-on-${i}">Owner number ${i + 1}</label><input id="kf-on-${i}" data-owner="number" value="${h.esc(o.number)}" placeholder="233246328461" inputmode="numeric"></div><div class="field"><label for="kf-ol-${i}">Name</label><input id="kf-ol-${i}" data-owner="label" value="${h.esc(o.label)}" placeholder="Owner"></div></div>`).join('')}</div>
        <div class="form-2col">
          ${field('owner_email', 'Owner email', s.owner_email, 'Invoices and owner-request notices go here. Blank uses ' + h.esc(data.smtp_notification_email || 'the SMTP notification email') + '.', 'email')}
          ${field('alert_email', 'Alert email', s.alert_email, 'Warnings (rejected webhooks, AI failures, wrong prices). Blank uses the SMTP notification email.', 'email')}
        </div>
        <h3>5. Orders</h3>
        <label class="checkline"><input type="checkbox" name="pickup_enabled" ${s.pickup_enabled === '1' ? 'checked' : ''}> Customers can pick up orders</label>
        ${field('pickup_details', 'Pickup place and times', s.pickup_details, 'Shown to customers when pickup is on. Placeholder until JayFoods confirms.')}
        <div class="field" data-f="payment_note"><label for="kf-payment_note">How chat orders are paid</label><textarea id="kf-payment_note" name="payment_note" rows="2" maxlength="300">${h.esc(s.payment_note)}</textarea><div class="hint">Kafui says this after every order.</div></div>
        <button class="btn btn-primary" type="submit">Save Kafui settings</button>
      </form>`;
    body.appendChild(panel);
    panel.querySelector('#kf-webhook').textContent = webhook;
    panel.querySelector('#kf-twilio-webhook').textContent = (s.site_url || location.origin) + data.twilio_webhook_path;
    const prov = panel.querySelector('#kf-whatsapp_provider');
    const showProvider = () => { panel.querySelector('#kf-twilio-box').hidden = prov.value !== 'twilio'; panel.querySelector('#kf-meta-box').hidden = prov.value !== 'meta'; };
    prov.addEventListener('change', showProvider); showProvider();

    panel.querySelector('#kf-gen').addEventListener('click', async () => {
      const { data: t } = await call('/admin/agent/verify-token', 'POST', {});
      panel.querySelector('#kf-verify').textContent = t.verify_token;
    });
    panel.querySelector('#kf-settings').addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = e.target, err = panel.querySelector('#kf-err'), ok = panel.querySelector('#kf-ok');
      err.style.display = 'none'; ok.style.display = 'none';
      f.querySelectorAll('.field').forEach((x) => x.classList.remove('err'));
      const settings = {}, secrets = {};
      f.querySelectorAll('input[name], textarea[name], select[name]').forEach((i) => {
        if (i.name.startsWith('secret:')) { if (i.value) secrets[i.name.slice(7)] = i.value; }
        else settings[i.name] = i.type === 'checkbox' ? (i.checked ? '1' : '0') : i.value;
      });
      const owner_numbers = [...f.querySelectorAll('.kf-owner')].map((r) => ({ number: r.querySelector('[data-owner="number"]').value, label: r.querySelector('[data-owner="label"]').value }));
      try {
        await call('/admin/agent/settings', 'PUT', { settings, secrets, owner_numbers });
        ok.style.display = 'block';
        setTimeout(() => h.router(), 900);
      } catch (ex) {
        err.textContent = ex.message; err.style.display = 'block';
        Object.keys(ex.fields || {}).forEach((k) => { const box = f.querySelector(`[data-f="${k}"]`) || (k === 'owner_numbers' && f.querySelector('.kf-owner .field')); if (box) box.classList.add('err'); });
        err.scrollIntoView({ block: 'center' });
      }
    });
  }

  window.KafuiAdmin = { render };
})();
