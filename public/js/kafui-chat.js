/* Kafui website chat: the same assistant as the JayFoods WhatsApp number.
   Chat text is always set with textContent (CTL-FE-001). The session id is
   kept in sessionStorage only; message text is never stored in the browser. */
(function () {
  'use strict';
  if (window.__kafuiChat) return;
  window.__kafuiChat = true;

  const css = `
  .kf-launch{position:fixed;right:max(18px,env(safe-area-inset-right));bottom:max(18px,env(safe-area-inset-bottom));z-index:9998;display:flex;align-items:center;gap:10px;
    background:#f5871f;color:#fff;border:0;border-radius:999px;padding:12px 18px 12px 14px;font:700 15px/1 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
    box-shadow:0 12px 30px rgba(18,53,31,.25);cursor:pointer;min-height:48px}
  .kf-launch:hover{background:#d96f0c}.kf-launch:focus-visible,.kf-panel :focus-visible{outline:3px solid #f5871f;outline-offset:2px}
  .kf-launch svg{width:24px;height:24px;flex:none}
  .kf-panel{position:fixed;right:max(18px,env(safe-area-inset-right));bottom:max(84px,calc(env(safe-area-inset-bottom) + 84px));z-index:9999;width:min(380px,calc(100vw - 36px));
    height:min(560px,calc(100vh - 120px));display:flex;flex-direction:column;background:#fff8ec;border-radius:18px;overflow:hidden;
    box-shadow:0 18px 40px rgba(18,53,31,.22);padding:0;margin:0;box-sizing:border-box;font:15px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#16241b}
  .kf-panel[hidden]{display:none}.kf-panel *{box-sizing:border-box}
  .kf-top{background:#12351f;color:#fff;padding:14px 16px;display:flex;align-items:center;gap:10px}
  .kf-top .kf-av{width:36px;height:36px;border-radius:50%;background:#f5871f;display:grid;place-items:center;font-weight:800}
  .kf-top b{display:block;font-size:16px}.kf-top small{opacity:.8;font-size:12.5px}
  .kf-top .kf-x{margin-left:auto;background:transparent;border:0;color:#fff;font-size:26px;line-height:1;cursor:pointer;width:40px;height:40px;border-radius:10px}
  .kf-wa{display:block;background:#eafaef;color:#12351f;font-size:13px;padding:7px 16px;text-decoration:none;border-bottom:1px solid #e6ede8}
  .kf-wa:hover{text-decoration:underline}
  .kf-log{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:8px}
  .kf-b{max-width:85%;padding:9px 13px;border-radius:18px;white-space:pre-wrap;overflow-wrap:anywhere}
  .kf-b.kafui{align-self:flex-start;background:#fff;border:1px solid #e6ede8;border-bottom-left-radius:6px}
  .kf-b.staff{align-self:flex-start;background:#fff;border:1px solid #f5871f;border-bottom-left-radius:6px}
  .kf-b.customer{align-self:flex-end;background:#1f7a3d;color:#fff;border-bottom-right-radius:6px}
  .kf-b .kf-from{display:block;font-size:11.5px;font-weight:700;color:#5f7166;margin-bottom:2px}
  .kf-b.err{align-self:center;background:transparent;color:#a3261b;font-size:13px;text-align:center}
  .kf-typing{align-self:flex-start;color:#5f7166;font-size:13px;padding:4px 6px}
  .kf-form{display:flex;gap:8px;padding:10px;border-top:1px solid #e6ede8;background:#fff}
  .kf-form textarea{flex:1;min-width:0;resize:none;border:1px solid #e6ede8;border-radius:12px;padding:10px 12px;font:inherit;max-height:110px}
  .kf-form button{background:#1f7a3d;color:#fff;border:0;border-radius:12px;padding:0 16px;font-weight:700;min-height:44px;cursor:pointer}
  .kf-form button:disabled{opacity:.6;cursor:default}
  .kf-note{font-size:11.5px;color:#5f7166;padding:0 12px 8px;background:#fff}.kf-note a{color:#1f7a3d}.kf-powered{margin-top:4px;text-align:center;font-size:11px;color:#8a978f}
  @media (max-width:480px){.kf-panel{right:0;bottom:0;width:100vw;height:100%;border-radius:0}.kf-launch span{display:none}.kf-launch{padding:12px}}
  @media (prefers-reduced-motion:no-preference){.kf-panel{animation:kfIn .2s ease-out}@keyframes kfIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}}`;
  const style = document.createElement('style'); style.textContent = css; document.head.appendChild(style);

  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };
  const store = { get(k) { try { return sessionStorage.getItem(k); } catch (_) { return null; } }, set(k, v) { try { sessionStorage.setItem(k, v); } catch (_) {} }, del(k) { try { sessionStorage.removeItem(k); } catch (_) {} } };

  const launch = el('button', 'kf-launch'); launch.type = 'button'; launch.setAttribute('aria-label', 'Chat with Kafui'); launch.setAttribute('aria-expanded', 'false');
  launch.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/><path d="M8.5 12h.01M12 12h.01M15.5 12h.01"/></svg>';
  launch.appendChild(el('span', null, 'Chat with Kafui'));

  const panel = el('div', 'kf-panel'); panel.hidden = true; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', 'Chat with Kafui, the Jay foods assistant');
  const top = el('div', 'kf-top');
  top.appendChild(el('div', 'kf-av', 'K'));
  const who = el('div'); who.appendChild(el('b', null, 'Kafui')); who.appendChild(el('small', null, "Jay foods automatic assistant")); top.appendChild(who);
  const close = el('button', 'kf-x', '×'); close.type = 'button'; close.setAttribute('aria-label', 'Close chat'); top.appendChild(close);
  const wa = el('a', 'kf-wa', 'Prefer WhatsApp? Chat with us there'); wa.target = '_blank'; wa.rel = 'noopener'; wa.href = 'https://wa.me/233246328461';
  const log = el('div', 'kf-log'); log.setAttribute('role', 'log'); log.setAttribute('aria-live', 'polite');
  const form = el('form', 'kf-form');
  const input = el('textarea'); input.id = 'kafui-input'; input.rows = 1; input.maxLength = 1000; input.placeholder = 'Type your message'; input.setAttribute('aria-label', 'Message to Kafui');
  const send = el('button', null, 'Send'); send.type = 'submit';
  form.appendChild(input); form.appendChild(send);
  const note = el('div', 'kf-note', 'Kafui is automatic and can make mistakes. Orders are confirmed by our team. ');
  const privacy = el('a', null, 'How we use your chat'); privacy.href = '/privacy.html#chat'; note.appendChild(privacy);
  const powered = el('div', 'kf-powered', 'Powered by '); const pc = el('a', null, 'princecaleb.dev'); pc.href = 'https://www.princecaleb.dev'; pc.target = '_blank'; pc.rel = 'noopener'; powered.appendChild(pc); note.appendChild(powered);
  panel.append(top, wa, log, form, note);
  document.body.append(panel, launch);

  let session = store.get('kafui.session');
  let lastId = +(store.get('kafui.last') || 0);
  let poll = null, mode = 'kafui', started = false;

  function bubble(from, text) {
    const b = el('div', 'kf-b ' + from);
    if (from === 'staff') b.appendChild(el('span', 'kf-from', 'Jay foods team'));
    b.appendChild(document.createTextNode(text));
    log.appendChild(b); log.scrollTop = log.scrollHeight;
  }
  function error(text) { const b = el('div', 'kf-b err', text); log.appendChild(b); log.scrollTop = log.scrollHeight; }

  async function api(path, body) {
    const res = await fetch('/api/v1/chat/' + path, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {});
    let data = null; try { data = await res.json(); } catch (_) {}
    if (!res.ok) { const e = new Error((data && data.error) || 'Something went wrong. Please try again.'); e.status = res.status; e.data = data; throw e; }
    return data.data;
  }

  async function start() {
    if (started) return; started = true;
    if (session) {
      try { const d = await api('history?session=' + encodeURIComponent(session) + '&after=0'); d.messages.forEach((m) => { bubble(m.from, m.text); lastId = Math.max(lastId, m.id); }); mode = d.mode; if (!d.messages.length) bubble('kafui', greeting); watch(); return; }
      catch (e) { session = null; store.del('kafui.session'); }
    }
    try {
      const d = await api('session', {});
      session = d.session; store.set('kafui.session', session);
      if (d.whatsapp) wa.href = 'https://wa.me/' + d.whatsapp;
      bubble('kafui', d.greeting);
    } catch (e) {
      if (e.data && e.data.whatsapp) wa.href = 'https://wa.me/' + e.data.whatsapp;
      error(e.status === 503 ? 'Kafui is resting right now. Please chat with us on WhatsApp.' : e.message);
      input.disabled = true; send.disabled = true;
    }
  }
  const greeting = "Hi, I'm Kafui, the Jay foods automatic assistant. How can I help?";

  function watch() {
    clearInterval(poll);
    if (mode !== 'staff' || panel.hidden) return;
    poll = setInterval(async () => {
      if (panel.hidden || !session) return clearInterval(poll);
      try { const d = await api('history?session=' + encodeURIComponent(session) + '&after=' + lastId); d.messages.forEach((m) => { if (m.from !== 'customer') bubble(m.from, m.text); lastId = Math.max(lastId, m.id); }); store.set('kafui.last', String(lastId)); mode = d.mode; if (mode !== 'staff') clearInterval(poll); } catch (_) {}
    }, 6000);
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const text = input.value.trim();
    if (!text || !session) return;
    bubble('customer', text);
    input.value = ''; send.disabled = true;
    const typing = el('div', 'kf-typing', 'Kafui is typing...'); log.appendChild(typing); log.scrollTop = log.scrollHeight;
    try {
      const d = await api('message', { session, text });
      d.messages.forEach((m) => { if (m.from !== 'customer') bubble(m.from, m.text); lastId = Math.max(lastId, m.id); });
      store.set('kafui.last', String(lastId));
      mode = d.mode; watch();
    } catch (err) {
      if (err.status === 401) { session = null; store.del('kafui.session'); started = false; error('Your chat expired. Starting a new one.'); start(); }
      else { error(err.message); input.value = text; }
    } finally { typing.remove(); send.disabled = false; input.focus(); }
  });
  input.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); } });

  function open() { panel.hidden = false; launch.setAttribute('aria-expanded', 'true'); start().then(watch); setTimeout(() => input.focus(), 50); }
  function shut() { panel.hidden = true; launch.setAttribute('aria-expanded', 'false'); clearInterval(poll); launch.focus(); }
  launch.addEventListener('click', () => (panel.hidden ? open() : shut()));
  close.addEventListener('click', shut);
  panel.addEventListener('keydown', (e) => { if (e.key === 'Escape') shut(); });
})();
