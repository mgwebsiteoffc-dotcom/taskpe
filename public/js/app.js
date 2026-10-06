/* ==========================================================================
   TaskPe — embedded Shopify app SPA (vanilla JS, no build step)
   - Auth: App Bridge v4 session tokens on every XHR (2026 requirement)
   - UI: Polaris-styled, rendered from /api/board payloads
   ========================================================================== */
'use strict';

(() => {
  const cfg = window.__TASKPE__ || {};
  const root = document.getElementById('root');
  const IS_STAFF = !!cfg.staff;   // staff web portal (cookie auth, no App Bridge)

  // One sentence, used by both the boot gate and any toast raised by a later
  // call, so "no Shopify session" never surfaces as a stack-trace-ish string.
  const NO_SESSION_MSG = 'No Shopify session for this page — open TaskPe from your Shopify admin (Apps → TaskPe).';

  // The four sections of the app, in admin-nav order. `icon` keys into ICONS.
  const SECTIONS = [
    { view: 'board',    label: 'Board',    icon: 'board' },
    { view: 'team',     label: 'Team',     icon: 'people' },
    { view: 'settings', label: 'Settings', icon: 'settings' },
    { view: 'plan',     label: 'Plan',     icon: 'premium' },
  ];

  const state = {
    board: null,          // /api/board payload
    view: 'board',        // board | team | settings | plan
    settings: null,       // /api/settings payload (lazy)
    me: Number(localStorage.getItem('taskpe_me') || 0),
    drawerTaskId: null,
    quickAdd: null,      // column id whose "add task" editor should auto-open
    loading: false,
  };

  /* ---------------------------------------------------------------- helpers */

  // Tiny DOM builder — never injects HTML, so user content is XSS-safe.
  function h(tag, attrs, ...children) {
    const node = document.createElement(tag);
    if (attrs) {
      for (const [k, v] of Object.entries(attrs)) {
        if (v === null || v === undefined || v === false) continue;
        if (k === 'class') node.className = v;
        else if (k === 'dataset') Object.assign(node.dataset, v);
        else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.slice(2), v);
        else if (k in node && k !== 'for' && k !== 'list' && typeof v !== 'string') node[k] = v;
        else node.setAttribute(k, v);
      }
    }
    for (const c of children.flat(9)) {
      if (c === null || c === undefined || c === false) continue;
      node.append(c.nodeType ? c : document.createTextNode(String(c)));
    }
    return node;
  }

  function toast(msg, isError = false) {
    if (window.shopify && window.shopify.toast) {
      try { window.shopify.toast.show(msg, { isError }); return; } catch { /* older bridge — fall through */ }
    }
    // Staff portal (no App Bridge): lightweight floating toast.
    const t = h('div', { class: 'fab-toast' + (isError ? ' err' : '') }, msg);
    document.body.append(t);
    setTimeout(() => t.classList.add('show'), 10);
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 2600);
  }

  function fail(message, code, extra) {
    const e = new Error(message);
    e.code = code || message;
    return Object.assign(e, extra || {});
  }

  // Shopify hands embedded apps a session token through App Bridge. Outside
  // the admin iframe (someone pasted the app URL into a new tab, a monitor
  // link, an email…) there is no bridge and therefore no token — that is a
  // *state we can explain*, not a board failure, so detect it up front.
  function appBridge() {
    return window.shopify && typeof window.shopify.idToken === 'function' ? window.shopify : null;
  }

  async function token() {
    if (IS_STAFF) return null;                 // cookie auth, no bridge needed
    const bridge = appBridge();
    if (!bridge) throw fail(NO_SESSION_MSG, 'not_embedded');

    // App Bridge can still be mid-handshake on a cold iframe load; give it a
    // bounded window instead of failing the whole board instantly.
    let timer;
    try {
      const jwt = await Promise.race([
        bridge.idToken(),
        new Promise((_, reject) => { timer = setTimeout(() => reject(fail('bridge_timeout')), 8000); }),
      ]);
      if (typeof jwt !== 'string' || !jwt) throw fail(NO_SESSION_MSG, 'not_embedded');
      return jwt;
    } catch (e) {
      if (e.code === 'not_embedded') throw e;
      throw fail(NO_SESSION_MSG, 'not_embedded', { detail: String(e.message || e) });
    } finally {
      clearTimeout(timer);
    }
  }

  function shopFromQuery() {
    return new URLSearchParams(location.search).get('shop') || '';
  }

  // Same-origin by default. The SPA is served by the same Laravel app that
  // owns /api/*, so a stale or wrong APP_URL in .env must never be allowed to
  // point the XHRs at another host (a classic "board won't load" cause after
  // a domain change or a subdirectory deploy).
  function apiBase() {
    const configured = String(cfg.appUrl || '').replace(/\/+$/, '');
    if (!configured) return '';
    try {
      return new URL(configured, location.href).origin === location.origin ? '' : configured;
    } catch {
      return '';
    }
  }

  function appUrl() { return apiBase(); }

  async function api(path, { method = 'GET', body, retry = true } = {}) {
    const init = {
      method,
      // Accept: JSON → Laravel renders API failures as JSON instead of an
      // HTML error page, which is what makes the real reason readable here.
      headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
      body: body ? JSON.stringify(body) : undefined,
    };

    let url;
    if (IS_STAFF) {
      url = appUrl() + '/staff/api' + path;     // cookie-authed (SameSite=Lax cookie)
    } else {
      url = appUrl() + '/api' + path;
      const jwt = await token();
      init.headers.Authorization = 'Bearer ' + jwt;
      // Apache/LiteSpeed on shared hosting sometimes strips Authorization
      // before PHP sees it; the middleware accepts this twin as a fallback.
      init.headers['X-TaskPe-Auth'] = jwt;
      init.headers['X-TaskPe-Member'] = state.me || '';
    }

    let res;
    try {
      res = await fetch(url, init);
    } catch (e) {
      throw fail('Cannot reach the TaskPe server (' + (e.message || 'network error') + ')',
        'network', { url });
    }

    if (res.status === 401) {
      const body401 = await res.json().catch(() => ({}));
      if (IS_STAFF) { location.assign('/staff'); throw fail('staff_auth', 'staff_auth'); }

      // A token we never had vs. a token the server refused are different
      // fixes — don't restart OAuth in a loop when App Bridge isn't there.
      if ((body401.error === 'missing_session_token' || body401.error === 'invalid_session_token') && retry) {
        await new Promise(r => setTimeout(r, 700));            // let the bridge settle
        return api(path, { method, body, retry: false });      // then try exactly once
      }

      // Not installed → restart OAuth at top level.
      let shop = body401.shop || shopFromQuery();
      if (shop && !api._redirecting) {
        api._redirecting = true;
        open(appUrl() + '/auth/shopify?shop=' + encodeURIComponent(shop), '_top');
      }
      throw fail('reauth', 'reauth', { shop, reason: body401.error || 'unauthorized' });
    }

    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      throw fail(data.message || data.error || 'Request failed (' + res.status + ')',
        data.error || 'http_' + res.status, { status: res.status, url, body: data });
    }
    return data;
  }

  function fmtDate(iso, opts) {
    if (!iso) return '';
    try {
      return new Intl.DateTimeFormat('en-IN', {
        day: 'numeric', month: 'short',
        ...(opts?.time ? { hour: 'numeric', minute: '2-digit', hour12: true } : {}),
        timeZone: state.board?.shop?.timezone || 'Asia/Kolkata',
        ...opts?.intl,
      }).format(new Date(iso));
    } catch { return iso; }
  }

  function openAdmin(url) { open(url, '_top'); }

  function debounce(fn, ms) {
    let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
  }

  function esc(s) { return s === null || s === undefined ? '' : String(s); }

  /* ------------------------------------------------------------------- icons
     Inline SVG instead of emoji. Emoji render differently on every OS, cannot
     be recoloured or sized to the text, and look toy-like at 13px in an admin
     panel. Every path here is stroke-only + currentColor, so an icon inherits
     the colour of the button, pill or link it sits in. 24px Polaris grid. */

  const ICONS = {
    board:      [['path', { d: 'M4 5.4h4.6v13.2H4zM10.4 5.4H15v8.2h-4.6zM16.7 5.4h3.9v11h-3.9z' }]],
    people:     [['circle', { cx: 9.6, cy: 8.6, r: 3.2 }], ['path', { d: 'M4.2 18.8a5.4 5.4 0 0 1 10.8 0' }],
                 ['path', { d: 'M15.6 6.2a3.2 3.2 0 0 1 0 4.9' }], ['path', { d: 'M17.2 18.8a5.5 5.5 0 0 0-1.7-3.9' }]],
    settings:   [['path', { d: 'M4 7.6h8.8M17.4 7.6H20M4 16.4h4.4M12.9 16.4H20' }],
                 ['circle', { cx: 15, cy: 7.6, r: 2.3 }], ['circle', { cx: 10.7, cy: 16.4, r: 2.3 }]],
    premium:    [['path', { d: 'M12 3.9l2.5 5 5.6.9-4.1 3.9 1 5.6-5-2.6-5 2.6 1-5.6-4.1-3.9 5.6-.9z' }]],
    plus:       [['path', { d: 'M12 5.4v13.2M5.4 12h13.2' }]],
    minus:      [['path', { d: 'M5.4 12h13.2' }]],
    edit:       [['path', { d: 'M4.6 19.4h4L20 8a2.1 2.1 0 0 0-3-3L5.6 16.4z' }], ['path', { d: 'M14.9 7.1l3 3' }]],
    delete:     [['path', { d: 'M4.6 7h14.8M9.4 7V4.8h5.2V7M6.7 7l.9 12.3h8.8L17.3 7' }],
                 ['path', { d: 'M10.4 10.6v5.6M13.6 10.6v5.6' }]],
    clock:      [['circle', { cx: 12, cy: 12, r: 7.8 }], ['path', { d: 'M12 7.5V12l3.1 1.9' }]],
    check:      [['path', { d: 'M5 12.7l4.5 4.5L19 6.9' }]],
    'check-circle': [['circle', { cx: 12, cy: 12, r: 8 }], ['path', { d: 'M8.1 12.2l2.7 2.7 5-5.4' }]],
    'alert-circle': [['circle', { cx: 12, cy: 12, r: 8 }], ['path', { d: 'M12 7.9v4.7M12 16.2h.01' }]],
    'info-circle':  [['circle', { cx: 12, cy: 12, r: 8 }], ['path', { d: 'M12 11.2v4.9M12 7.9h.01' }]],
    link:       [['path', { d: 'M10.3 13.7a3.6 3.6 0 0 0 5.1 0l2.4-2.4a3.6 3.6 0 1 0-5.1-5.1l-1.2 1.2' }],
                 ['path', { d: 'M13.7 10.3a3.6 3.6 0 0 0-5.1 0L6.2 12.7a3.6 3.6 0 1 0 5.1 5.1l1.2-1.2' }]],
    text:       [['path', { d: 'M4.6 7.6h14.8M4.6 12h10.6M4.6 16.4h12.8' }]],
    stack:      [['path', { d: 'M12 4.2l8 3.5-8 3.4-8-3.4z' }], ['path', { d: 'M4 12.1l8 3.4 8-3.4M4 16.1l8 3.4 8-3.4' }]],
    bell:       [['path', { d: 'M6.6 16.3V11a5.4 5.4 0 0 1 10.8 0v5.3l1.5 2.1H5.1z' }], ['path', { d: 'M10 20.5a2.2 2.2 0 0 0 4 0' }]],
    'bell-off': [['path', { d: 'M6.6 16.3V11a5.4 5.4 0 0 1 8.3-4.5M17.4 12.6v3.7l1.5 2.1H7.4' }],
                 ['path', { d: 'M4.4 4.4l15.2 15.2' }]],
    close:      [['path', { d: 'M6.6 6.6l10.8 10.8M17.4 6.6L6.6 17.4' }]],
    search:     [['circle', { cx: 11, cy: 11, r: 6 }], ['path', { d: 'M15.4 15.4L19.6 19.6' }]],
    send:       [['path', { d: 'M4.6 12l14.8-7-3.6 14.6-4.5-5.3z' }], ['path', { d: 'M11.3 14.3l11.4-9.3' }]],
    refresh:    [['path', { d: 'M19.4 12a7.4 7.4 0 1 1-2.2-5.3' }], ['path', { d: 'M19.6 4.4v4.3h-4.3' }]],
    reopen:     [['path', { d: 'M4.6 12a7.4 7.4 0 1 0 2.2-5.3' }], ['path', { d: 'M4.4 4.4v4.3h4.3' }]],
    'arrow-left': [['path', { d: 'M19 12H5.6' }], ['path', { d: 'M11.2 5.6L4.8 12l6.4 6.4' }]],
    'chevron-right': [['path', { d: 'M9.6 5.6L16 12l-6.4 6.4' }]],
    'chevron-down':  [['path', { d: 'M5.6 9.4L12 15.8l6.4-6.4' }]],
    globe:      [['circle', { cx: 12, cy: 12, r: 7.8 }],
                 ['path', { d: 'M4.2 12h15.6M12 4.2c2.2 2.2 3.3 5 3.3 7.8s-1.1 5.6-3.3 7.8c-2.2-2.2-3.3-5-3.3-7.8s1.1-5.6 3.3-7.8z' }]],
    chat:       [['path', { d: 'M4.6 12a7.4 7.4 0 1 1 3 5.9l-3.6 1.2 1.2-3.4A7.3 7.3 0 0 1 4.6 12z' }],
                 ['path', { d: 'M8.8 10.8h6.4M8.8 13.8h4.2' }]],
    logout:     [['path', { d: 'M14.4 5.2H6.6a1.4 1.4 0 0 0-1.4 1.4v10.8a1.4 1.4 0 0 0 1.4 1.4h7.8' }],
                 ['path', { d: 'M17.6 8.4L21 12l-3.4 3.6M21 12h-9.4' }]],
    phone:      [['path', { d: 'M5.4 5.4h3.4l1.5 3.8-2 1.4a9.6 9.6 0 0 0 4.7 4.7l1.4-2 3.8 1.5v3.4c0 .8-.7 1.5-1.5 1.4C10 19.2 4.8 14 4.4 7c-.1-.8.6-1.5 1.4-1.6z' }]],
    wallet:     [['path', { d: 'M4.6 7.7a2.2 2.2 0 0 1 2.2-2.2h11v13.1h-11a2.2 2.2 0 0 1-2.2-2.2z' }],
                 ['path', { d: 'M4.6 10.6h13.2M15 14.2h2.8' }]],
    receipt:    [['path', { d: 'M6.4 4.5h11.2v15l-2.8-1.6-2.8 1.6-2.8-1.6-2.8 1.6z' }],
                 ['path', { d: 'M9.2 8.6h5.6M9.2 12.2h5.6' }]],
    map:        [['path', { d: 'M12 20.6s6-5.3 6-9.5a6 6 0 1 0-12 0c0 4.2 6 9.5 6 9.5z' }], ['circle', { cx: 12, cy: 11, r: 2.2 }]],
    truck:      [['path', { d: 'M3.6 7.3h9.2v8.4H3.6zM12.8 10.4h3.5l2.7 2.8v2.5h-6.2z' }],
                 ['circle', { cx: 7, cy: 17.6, r: 1.7 }], ['circle', { cx: 16.5, cy: 17.6, r: 1.7 }]],
    calendar:   [['path', { d: 'M4.8 6.8h14.4v12H4.8z' }], ['path', { d: 'M4.8 10.6h14.4M9 4.8v3.4M15 4.8v3.4' }]],
    'user-plus':[['circle', { cx: 10, cy: 8.4, r: 3.2 }], ['path', { d: 'M4.4 18.8a5.6 5.6 0 0 1 11.2 0' }],
                 ['path', { d: 'M18.6 6.2v5.2M16 8.8h5.2' }]],
    rupee:      [['path', { d: 'M7.4 5.6h9.2M7.4 9.4h9.2M15.4 5.6c0 3.1-2.3 4.3-5.8 4.3H7.4l7.4 8.9' }],
                 ['path', { d: 'M7.4 13.2h4.8' }]],
    box:        [['path', { d: 'M4.6 8.2L12 4.4l7.4 3.8v7.6L12 19.6 4.6 15.8z' }],
                 ['path', { d: 'M4.6 8.2L12 12l7.4-3.8M12 12v7.6' }]],
  };

  // opts: { size, label } — `label` makes it an accessible, focus-revealing
  // element; without a label it is pure decoration and hidden from AT.
  function icon(name, opts = {}) {
    const spec = ICONS[name] || ICONS['info-circle'];
    const NS = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('class', 'icon' + (opts.class ? ' ' + opts.class : ''));
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('width', String(opts.size || 18));
    svg.setAttribute('height', String(opts.size || 18));
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', String(opts.stroke || '1.7'));
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', opts.label ? 'false' : 'true');
    if (opts.label) svg.setAttribute('role', 'img');
    for (const [tag, attrs] of spec) {
      const el = document.createElementNS(NS, tag);
      for (const [k, v] of Object.entries(attrs)) el.setAttribute(k, String(v));
      svg.append(el);
    }
    if (opts.label) {
      const title = document.createElementNS(NS, 'title');
      title.textContent = opts.label;
      svg.append(title);
    }
    return svg;
  }

  // Icon + text, the shape 90% of the callsites need.
  function withIcon(name, label, opts = {}) {
    return [icon(name, opts), label];
  }

  // Icon-only control: the visible glyph is the button, so the name has to
  // live in aria-label (and title, for hover). Never a bare emoji.
  function iconButton(name, { title, onClick, className = 'icon-btn', disabled }) {
    return h('button', {
      class: className, title: title, 'aria-label': title, disabled: !!disabled,
      onclick: onClick,
    }, icon(name, { size: 16 }));
  }

  /* ---------------------------------------------------------------- board */

  async function loadBoard() {
    const data = await api('/board');
    // Shape check, not decoration: a 200 with an HTML body (captive portal,
    // old cache, an opcache-served page) used to land here and die somewhere
    // deep inside render() with a TypeError.
    if (!data || !data.shop || !Array.isArray(data.columns)) {
      throw fail('The server replied to /api/board without a board payload — '
        + 'usually an HTML/error page instead of JSON. Check storage/logs/laravel.log.',
        'bad_payload', { body: data });
    }
    state.board = data;
    document.title = (data.shop.name || '') + ' · TaskPe';
  }

  function render() {
    root.replaceChildren(renderShell());
    if (cfg.billingFlag) { handleBillingFlag(cfg.billingFlag); cfg.billingFlag = null; }
    if (cfg.openTask && state.board) { openTaskDrawer(Number(cfg.openTask)); cfg.openTask = null; }
  }

  function renderShell() {
    const s = state.board;
    if (!s) return h('div', { class: 'boot' }, h('div', { class: 'boot-text' }, 'Loading…'));

    // Staff portal: board-only surface, so no section nav — just who you are
    // and how to get out. Same row shape as the admin nav, minus the tabs.
    if (IS_STAFF) {
      return h('div', null,
        h('div', { class: 'appnav' },
          h('div', { class: 'appnav-title' },
            icon('board', { size: 18 }),
            h('span', null, s.shop.name || 'Your board'),
            h('span', { class: 'appnav-sub' }, 'Staff board')),
          h('span', { class: 'spacer' }),
          h('span', { class: 'staff-chip', title: 'Signed in via staff portal' },
            h('span', { class: 'avatar' }, cfg.staff.initials || ''),
            cfg.staff.name),
          h('button', { class: 'btn plain sm', onclick: staffLogout },
            ...withIcon('logout', 'Log out', { size: 15 })),
          iconButton('info-circle', { title: 'Keyboard shortcuts', onClick: openShortcutsHelp })),
        renderBoard(),
        state.drawerTaskId ? renderTaskDrawer(state.drawerTaskId) : null,
      );
    }

    // Section nav in the shape Shopify's own admin uses: an icon+label tab list
    // with a bottom-border active state, no app-brand bar (the admin chrome
    // already shows the app name) and no emoji anywhere.
    return h('div', null,
      h('div', { class: 'appnav' },
        h('div', { class: 'appnav-tabs', role: 'tablist', 'aria-label': 'TaskPe sections' },
          SECTIONS.map(sec => h('button', {
            class: 'tab' + (state.view === sec.view ? ' is-active' : ''),
            role: 'tab',
            'aria-selected': state.view === sec.view ? 'true' : 'false',
            onclick: () => { state.view = sec.view; render(); },
          }, icon(sec.icon, { size: 18, class: 'tab-icon' }), sec.label))),
        renderMeChips(),
        iconButton('info-circle', { title: 'Keyboard shortcuts (?)', onClick: openShortcutsHelp })),
      renderBanners(),
      state.view === 'board' ? renderBoard() :
      state.view === 'team' ? renderTeam() :
      state.view === 'settings' ? renderSettings() : renderPlan(),
      state.drawerTaskId ? renderTaskDrawer(state.drawerTaskId) : null,
    );
  }

  async function staffLogout() {
    try { await fetch(appUrl() + '/staff/logout', { method: 'POST' }); } catch { /* still navigate */ }
    location.assign('/staff');
  }

  function renderBanners() {
    const s = state.board;
    const wrap = h('div', null);
    if (IS_STAFF) return wrap;   // admin-only nag banners never reach the portal

    if (s.shop.plan === 'free') {
      wrap.append(h('div', { class: 'banner warn' },
        h('div', null,
          h('div', { class: 'b-title' }, 'You are on the Free plan'),
          h('div', { class: 'b-body' }, 'Upgrade to Starter (≈ ₹499/mo) to enable WhatsApp alerts for your team, unlimited tasks and the daily owner digest.')),
        h('button', { class: 'btn primary sm', onclick: () => { state.view = 'plan'; render(); } }, 'View plans')));
      return wrap;
    }

    // Only nag when the merchant has explicitly switched WhatsApp ON but
    // hasn't finished connecting Whatify. When the master switch is off
    // (default), we stay silent — feature is opt-in.
    if (s.whatsapp.plan_allowed && s.whatsapp.master_on && !s.whatsapp.has_key) {
      wrap.append(h('div', { class: 'banner warn' },
        h('div', null,
          h('div', { class: 'b-title' }, 'WhatsApp is ON — connect your Whatify account to finish'),
          h('div', { class: 'b-body' }, 'Paste your Whatify API key in Settings — takes 2 minutes. Task alerts then land directly on your staff\'s WhatsApp.')),
        h('button', { class: 'btn primary sm', onclick: () => { state.view = 'settings'; render(); } }, 'Connect')));
    }

    const anyMember = s.members.some(m => m.role === 'owner' && m.whatsapp_verified);
    if (s.whatsapp.has_key && !anyMember && s.view === 'board') {
      wrap.append(h('div', { class: 'banner' },
        h('div', null,
          h('div', { class: 'b-title' }, 'Verify your own number'),
          h('div', { class: 'b-body' }, 'Add yourself in the Team tab with the Owner role so the morning digest reaches you.')),
        h('button', { class: 'btn sm', onclick: () => { state.view = 'team'; render(); } }, 'Add me')));
    }
    return wrap;
  }

  function renderMeChips() {
    const s = state.board;
    if (IS_STAFF || !s || !s.members.length) return h('div', { class: 'me-chips' });

    return h('div', { class: 'me-chips' },
      h('span', { class: 'lbl' }, 'Working as:'),
      s.members.filter(m => m.active).map(m =>
        h('button', {
          class: 'me-chip' + (state.me === m.id ? ' on' : ''),
          onclick: () => {
            state.me = state.me === m.id ? 0 : m.id;
            localStorage.setItem('taskpe_me', state.me || '');
            render();
          },
          title: m.role + (m.whatsapp_verified ? ' · WhatsApp verified' : ''),
        }, h('span', { class: 'avatar' + (state.me === m.id ? '' : ' gray') }, m.initials), m.name.split(' ')[0])));
  }

  /* ------------------------------------------------------------- kanban UI */

  function renderBoard() {
    const s = state.board;
    const board = h('div', { class: 'board' });

    for (const col of s.columns) board.append(renderColumn(col));
    board.append(h('button', { class: 'add-col-btn', onclick: promptAddColumn }, ...withIcon('plus', 'Add column', { size: 16 })));

    return h('div', null,
      h('div', { class: 'board-tools' },
        h('button', { class: 'btn sm', onclick: openTemplatesModal }, ...withIcon('stack', 'COD / NDR task templates')),
        h('span', { class: 'muted small' }, 'One-click checklists built for Indian D2C: COD confirm, NDR rescue, RTO checks…')),
      board);
  }

  function renderColumn(col) {
    const el = h('div', { class: 'board-col', dataset: { colId: col.id } },
      h('div', { class: 'col-head' },
        h('h3', null, col.name),
        h('span', { class: 'col-count' }, col.tasks.length),
        h('div', { class: 'col-actions' },
          iconButton('edit', { title: 'Rename column', onClick: () => promptRenameColumn(col) }),
          state.board.columns.length > 1
            ? iconButton('delete', { title: 'Delete column', onClick: () => deleteColumn(col) }) : null)),
      h('div', { class: 'col-cards' }, col.tasks.map(t => renderCard(t, col))),
      renderAddCard(col));

    // HTML5 drag & drop targets
    el.addEventListener('dragover', e => {
      if (!dragState.taskId) return;
      e.preventDefault();
      el.classList.add('drag-over');
    });
    el.addEventListener('dragleave', () => el.classList.remove('drag-over'));
    el.addEventListener('drop', e => {
      e.preventDefault();
      el.classList.remove('drag-over');
      if (!dragState.taskId) return;
      const pos = dropPosition(el, e.clientY);
      void moveTask(dragState.taskId, col.id, pos);
    });

    return el;
  }

  function dropPosition(colEl, y) {
    // Index = how many card midpoints are above the cursor.
    const cards = [...colEl.querySelectorAll('.card:not(.dragging)')];
    return cards.filter(c => {
      const r = c.getBoundingClientRect();
      return y > r.top + r.height / 2;
    }).length;
  }

  function renderCard(t, col) {
    const card = h('div', {
      class: 'card' + (t.completed_at ? ' is-done' : '') + (t.priority === 'urgent' ? ' priority-urgent' : ''),
      draggable: true,
      dataset: { taskId: t.id },
      onclick: () => { if (!dragState.moved) openTaskDrawer(t.id); },
    },
      h('div', { class: 'card-title' }, t.title),
      h('div', { class: 'card-meta' },
        h('span', { class: 'pill ' + t.priority }, t.priority),
        t.completed_at ? h('span', { class: 'pill done' }, ...withIcon('check', 'Done', { size: 13 }))
          : t.overdue ? h('span', { class: 'pill overdue' }, icon('clock', { size: 13 }), fmtDate(t.due_at))
          : t.due_at ? h('span', { class: 'pill due' }, fmtDate(t.due_at)) : null),
      t.resource ? h('div', { class: 'res-line' },
        t.resource.image ? h('img', { src: t.resource.image, alt: '' }) : h('span', { class: 'res-ic' }, icon('link', { size: 14 })),
        IS_STAFF
          ? h('span', { class: 'small', title: 'Opens in Shopify admin — ask your manager if you need it' }, t.resource.label + ' ' + (t.resource.title || ''))
          : h('a', { href: t.resource.url || '#', onclick: e => { e.preventDefault(); e.stopPropagation(); if (t.resource.url) openAdmin(t.resource.url); } },
              h('span', null, t.resource.label + ' ' + (t.resource.title || '')))) : null,
      h('div', { class: 'card-foot' },
        (() => {
          const ck = checklistLines(t.description);
          if (ck) return h('span', { class: 'muted small ck-count', title: 'Checklist progress' }, icon('check-circle', { size: 14 }), ck.items.filter(i => i.done).length + '/' + ck.items.length);
          return t.description ? h('span', { class: 'muted small', title: 'Has a description' }, icon('text', { size: 14 })) : null;
        })(),
        t.assignee ? h('span', { class: 'avatar', title: t.assignee.name }, t.assignee.initials)
          : h('span', { class: 'avatar gray', title: 'Unassigned' }, '·')));

    card.addEventListener('dragstart', e => {
      dragState = { taskId: t.id, moved: false };
      card.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
    });
    card.addEventListener('dragend', () => {
      card.classList.remove('dragging');
      setTimeout(() => { dragState = { taskId: null, moved: false }; }, 50);
    });

    return card;
  }

  let dragState = { taskId: null, moved: false };

  async function moveTask(taskId, columnId, position) {
    // Optimistic render
    const cols = state.board.columns;
    let task = null;
    for (const c of cols) {
      const i = c.tasks.findIndex(t => t.id === taskId);
      if (i >= 0) { task = c.tasks.splice(i, 1)[0]; break; }
    }
    if (!task) return;
    const target = cols.find(c => c.id === columnId);
    target.tasks.splice(Math.min(position, target.tasks.length), 0, task);
    task.column_id = columnId;
    dragState.moved = true;
    render();

    try {
      const updated = await api('/tasks/' + taskId + '/move', { method: 'POST', body: { column_id: columnId, position } });
      task.completed_at = updated.completed_at; task.position = updated.position;
      if (updated.completed_at) toast('Task completed');
      render();
    } catch (e) { toast(e.message, true); await refreshBoard(); }
  }

  function renderAddCard(col) {
    const box = h('div', { class: 'add-card' });
    const toggle = h('button', { class: 'add-card-toggle' }, '+ Add task');

    function openEditor() {
      const ta = h('textarea', { placeholder: 'e.g. Refund this customer and send ₹500 gift card…', rows: 2 });
      ta.focus();
      const save = h('button', {
        class: 'btn primary sm',
        onclick: async () => {
          const title = ta.value.trim();
          if (!title) return;
          save.disabled = true;
          try {
            await api('/tasks', { method: 'POST', body: { title, column_id: col.id } });
            await refreshBoard();
            toast('Task added');
          } catch (e) { toast(e.message, e.code === 'plan_limit'); save.disabled = false; }
        },
      }, 'Add');
      const cancel = h('button', { class: 'btn sm', onclick: closeEditor }, 'Cancel');
      ta.addEventListener('keydown', ev => { if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); save.click(); } });

      box.replaceChildren(h('div', null, ta, h('div', { class: 'row' }, save, cancel)));
      setTimeout(() => ta.focus(), 0);
    }
    function closeEditor() { box.replaceChildren(toggle); }

    toggle.addEventListener('click', openEditor);
    // Opened by a key rather than a click (see bindShortcuts): consume the flag
    // so a later re-render does not keep popping the editor back open.
    if (state.quickAdd === col.id) {
      state.quickAdd = null;
      box.append(toggle);
      setTimeout(openEditor, 0);
      return box;
    }
    box.append(toggle);
    return box;
  }

  async function refreshBoard() {
    await loadBoard();
    render();
  }

  function promptAddColumn() {
    openModal('Add column', h('div', null,
      h('div', { class: 'field' }, h('label', null, 'Column name'), h('input', { class: 'input', id: 'col-name', placeholder: 'e.g. Waiting for courier', maxlength: 60 })),
      h('label', { class: 'switch' },
        h('input', { type: 'checkbox', id: 'col-done' }), h('span', { class: 'track' }),
        h('span', null, h('span', { class: 'sw-label' }, 'Tasks dropped here are "Done"')))),
      async () => {
        const name = document.getElementById('col-name').value.trim();
        if (!name) return false;
        await api('/columns', { method: 'POST', body: { name, is_done_stage: document.getElementById('col-done').checked } });
        await refreshBoard();
        return true;
      });
  }

  function promptRenameColumn(col) {
    openModal('Rename column', h('div', null,
      h('div', { class: 'field' }, h('label', null, 'Column name'), h('input', { class: 'input', id: 'col-name', value: col.name, maxlength: 60 }))),
      async () => {
        const name = document.getElementById('col-name').value.trim();
        if (!name) return false;
        await api('/columns/' + col.id, { method: 'PATCH', body: { name } });
        await refreshBoard();
        return true;
      });
  }

  async function deleteColumn(col) {
    if (!confirm(`Delete "${col.name}"? Its ${col.tasks.length} task(s) move to the first remaining column.`)) return;
    await api('/columns/' + col.id, { method: 'DELETE' });
    await refreshBoard();
    toast('Column deleted');
  }

  /* ------------------------------------------------------------ task drawer */

  function findTask(id) {
    for (const c of state.board.columns) {
      const t = c.tasks.find(x => x.id === id);
      if (t) return t;
    }
    return null;
  }

  function openTaskDrawer(id) {
    if (findTask(id)) { state.drawerTaskId = id; render(); }
  }

  function closeDrawer() { state.drawerTaskId = null; render(); }

  function renderTaskDrawer(id) {
    const t = findTask(id);
    if (!t) return null;
    const s = state.board;
    const activeMembers = s.members.filter(m => m.active);

    const overlay = h('div', { class: 'overlay right', onclick: e => { if (e.target === overlay) closeDrawer(); } });

    const assigneeSel = h('select', { class: 'input' },
      h('option', { value: '' }, 'Unassigned'),
      activeMembers.map(m => h('option', { value: m.id, selected: t.assignee?.id === m.id },
                       m.name + (m.whatsapp_verified ? ' · on WhatsApp' : ''))));
    assigneeSel.value = t.assignee?.id || '';

    const colSel = h('select', { class: 'input' },
      s.columns.map(c => h('option', { value: c.id, selected: c.id === t.column_id }, c.name)));

    const prioSel = h('select', { class: 'input' },
      ['low', 'medium', 'high', 'urgent'].map(p => h('option', { value: p, selected: p === t.priority }, p)));

    const dueInput = h('input', {
      class: 'input', type: 'datetime-local',
      value: t.due_at ? toLocalInput(t.due_at) : '',
    });

    const titleInput = h('input', { class: 'input', value: t.title, maxlength: 190 });
    const descInput = h('textarea', { class: 'input', rows: 3, placeholder: 'Add details for your team…' }, t.description || '');

    const activityBox = h('div', null, h('div', { class: 'muted small' }, 'Loading activity…'));
    void api('/tasks/' + id + '/activity').then(rows => {
      activityBox.replaceChildren(...(rows.length ? rows.map(a => h('div', { class: 'activity-item' },
        h('span', { class: 'dot' }),
        h('div', null,
          h('div', null, activityText(a)),
          h('div', { class: 'a-sub' }, (a.actor || 'Store team') + ' · ' + fmtDate(a.created_at, { time: true }))))
      ) : [h('div', { class: 'muted small' }, 'No activity yet.')]));
    }).catch(() => activityBox.replaceChildren(h('div', { class: 'muted small' }, 'Could not load activity.')));

    overlay.append(h('div', { class: 'drawer' },
      h('div', { class: 'modal-head' },
        h('h2', null, 'Task'),
        h('span', { class: 'pill ' + t.priority }, t.priority),
        h('button', { class: 'x', onclick: closeDrawer }, '×')),
      h('div', { class: 'd-body' },
        h('div', { class: 'field' }, h('label', null, 'Title'), titleInput),
        h('div', { class: 'field' }, h('label', null, 'Description'), descInput, renderChecklistBlock(t)),
        h('div', { class: 'field-row' },
          h('div', { class: 'field' }, h('label', null, 'Assignee'), assigneeSel),
          h('div', { class: 'field' }, h('label', null, 'Priority'), prioSel)),
        h('div', { class: 'field-row' },
          h('div', { class: 'field' }, h('label', null, 'Column'), colSel),
          h('div', { class: 'field' }, h('label', null, 'Due'), dueInput)),
        h('div', { class: 'field' },
          h('label', null, 'Linked Shopify object'),
          renderLinkedResource(t)),
        h('div', { class: 'divider' }),
        h('div', { class: 'field' }, h('label', null, 'Activity'), activityBox)),
      h('div', { class: 'd-foot' },
        h('button', {
          class: 'btn ' + (t.completed_at ? '' : 'primary'),
          onclick: async () => {
            await api('/tasks/' + id + '/complete', { method: 'POST' });
            await refreshBoard();
            state.drawerTaskId = id; render();
          },
        }, ...(t.completed_at ? withIcon('reopen', 'Reopen', { size: 16 }) : withIcon('check', 'Mark done', { size: 16 }))),
        (t.assignee && !IS_STAFF) ? h('button', {
          class: 'btn', title: 'Send WhatsApp reminder to assignee',
          onclick: async e => {
            e.target.disabled = true;
            try {
              await api('/tasks/' + id + '/remind', { method: 'POST' });
              toast('WhatsApp reminder queued');
            } catch (err) { toast(err.message, true); }
            e.target.disabled = false;
          },
        }, ...withIcon('bell', 'Nudge', { size: 16 })) : null,
        h('span', { class: 'spacer' }),
        IS_STAFF ? null : h('button', {
          class: 'btn danger',
          onclick: async () => {
            if (!confirm('Delete this task permanently?')) return;
            await api('/tasks/' + id, { method: 'DELETE' });
            closeDrawer();
            await refreshBoard();
          },
        }, 'Delete'),
        h('button', {
          class: 'btn primary',
          onclick: async () => {
            const body = {
              title: titleInput.value.trim(),
              description: descInput.value || null,
              priority: prioSel.value,
              due_at: dueInput.value ? new Date(dueInput.value).toISOString() : null,
              assignee_id: assigneeSel.value ? Number(assigneeSel.value) : null,
              column_id: Number(colSel.value),
              resource_type: t.resource?.type || null,
              resource_id: t.resource?.id || null,
              resource_gid: null,
              resource_title: t.resource?.title || null,
              resource_url: t.resource?.url || null,
            };
            if (!body.title) { toast('Title is required', true); return; }
            try {
              const prevCol = t.column_id;
              await api('/tasks/' + id, { method: 'PATCH', body });
              if (Number(body.column_id) !== prevCol) {
                await api('/tasks/' + id + '/move', { method: 'POST', body: { column_id: Number(body.column_id), position: 999999 } });
              }
              await refreshBoard();
              state.drawerTaskId = id; render();
              toast('Saved');
            } catch (e2) { toast(e2.message, true); }
          },
        }, 'Save changes'))));

    return overlay;
  }

  function activityText(a) {
    const m = a.meta || {};
    switch (a.action) {
      case 'created': return 'Task created in ' + (m.column || 'board');
      case 'updated': return 'Details updated';
      case 'moved': return 'Moved to ' + (m.to || '');
      case 'assigned': return 'Assigned to ' + (m.to || '');
      case 'completed': return 'Marked as done';
      case 'reopened': return 'Reopened';
      default: return a.action;
    }
  }

  function toLocalInput(iso) {
    const d = new Date(iso);
    const pad = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }

  function renderLinkedResource(t) {
    const wrap = h('div', null);
    if (t.resource) {
      wrap.append(h('div', { class: 'res-current' },
        h('span', { class: 'pill link' }, t.resource.label),
        IS_STAFF
          ? h('span', { class: 'small' }, t.resource.title || 'Linked object')
          : h('a', { href: t.resource.url || '#', class: 'small', onclick: e => { e.preventDefault(); if (t.resource.url) openAdmin(t.resource.url); } }, t.resource.title || 'Open'),
        h('span', { class: 'spacer' }),
        h('button', { class: 'btn plain sm', onclick: () => openResourcePicker(t) }, 'Change'),
        h('button', {
          class: 'btn plain sm', title: 'Remove link',
          onclick: async () => {
            await api('/tasks/' + t.id, { method: 'PATCH', body: { resource_type: null } });
            await refreshBoard(); state.drawerTaskId = t.id; render();
          },
        }, ...withIcon('close', 'Remove', { size: 14 }))));
    } else {
      wrap.append(h('button', { class: 'btn sm', onclick: () => openResourcePicker(t) }, ...withIcon('link', 'Link order, product, customer or blog post', { size: 16 })));
    }
    return wrap;
  }

  /* -------------------------------------------------------- resource picker */

  function openResourcePicker(task) {
    const types = [['order', 'Orders'], ['draft_order', 'Draft orders'], ['product', 'Products'], ['customer', 'Customers'], ['article', 'Blog posts']];
    let activeType = 'order';
    let selected = null;

    const searchInput = h('input', { class: 'input', placeholder: 'Search… e.g. #1001 or product name' });
    const results = h('div', null, h('div', { class: 'res-empty' }, 'Type to search'));
    const tabs = h('div', { class: 'res-tabs' });

    const doSearch = debounce(async () => {
      const q = searchInput.value.trim();
      // Smart paste: full Shopify admin URL → resolve directly.
      const paste = q.match(/admin\.shopify\.com\/store\/[^/]+\/(orders|draft_orders|products|customers|articles)\/(\d+)/);
      let params;
      if (paste) {
        activeType = { orders: 'order', draft_orders: 'draft_order', products: 'product', customers: 'customer', articles: 'article' }[paste[1]];
        params = 'type=' + activeType + '&id=' + paste[2];
      } else {
        if (!q) { results.replaceChildren(h('div', { class: 'res-empty' }, 'Type to search')); return; }
        params = 'type=' + activeType + '&q=' + encodeURIComponent(q.replace(/^#/, ''));
      }
      renderTabs();
      results.replaceChildren(h('div', { class: 'res-empty' }, 'Searching…'));
      try {
        const data = await api('/resources/search?' + params);
        results.replaceChildren(...(data.items.length ? data.items.map(item => h('div', {
          class: 'res-item',
          onclick: ev => {
            selected = item;
            [...results.children].forEach(c2 => (c2.style.background = ''));
            ev.currentTarget.style.background = 'var(--info-bg)';
          },
        },
          item.image ? h('img', { src: item.image, alt: '' }) : h('img', { alt: '' }),
          h('div', null,
            h('div', { class: 'ri-title' }, item.title || '(no title)'),
            h('div', { class: 'ri-sub' }, types.find(([k]) => k === item.type)?.[1] + (item.subtitle ? ' · ' + item.subtitle : '')),
          ),
        )) : [h('div', { class: 'res-empty' }, 'No matches found')]));
      } catch (e) {
        results.replaceChildren(h('div', { class: 'res-empty' }, e.message || 'Search failed'));
      }
    }, 350);

    function renderTabs() {
      tabs.replaceChildren(...types.map(([k, label]) => h('button', {
        class: k === activeType ? 'active' : '',
        onclick: () => { activeType = k; selected = null; renderTabs(); doSearch(); },
      }, label)));
    }
    renderTabs();
    searchInput.addEventListener('input', doSearch);

    openModal('Link a Shopify object', h('div', null, tabs, searchInput, h('div', { class: 'mt' }, results)),
      async () => {
        if (!selected) { toast('Pick a result first', true); return false; }
        await api('/tasks/' + task.id, {
          method: 'PATCH',
          body: {
            resource_type: selected.type,
            resource_id: selected.id,
            resource_gid: selected.gid,
            resource_title: selected.title,
            resource_url: selected.url,
          },
        });
        await refreshBoard();
        state.drawerTaskId = task.id; render();
        toast('Linked ' + selected.title);
        return true;
      });
    setTimeout(() => searchInput.focus(), 50);
  }

  /* ------------------------------------------- checklist parsing (templates) */

  const CK_RE = /^- \[( |x|X)\] (.*)$/;

  // "- [ ] step" lines inside a description → clickable checklist metadata.
  function checklistLines(desc) {
    if (!desc) return null;
    const lines = String(desc).split('\n');
    const items = [];
    lines.forEach((line, idx) => {
      const m = line.match(CK_RE);
      if (m) items.push({ idx, done: m[1].toLowerCase() === 'x', text: m[2] });
    });
    return items.length ? { lines, items } : null;
  }

  // Interactive checklist shown in the task drawer (saves on each toggle).
  function renderChecklistBlock(t) {
    const ck = checklistLines(t.description);
    if (!ck) return null;
    const doneCount = ck.items.filter(i => i.done).length;

    return h('div', { class: 'ck-list' },
      h('div', { class: 'ck-progress' }, icon('stack', { size: 15 }), 'Checklist — ' + doneCount + '/' + ck.items.length + ' done'),
      ck.items.map(item => h('button', {
        class: 'ck-item' + (item.done ? ' done' : ''),
        onclick: async () => {
          ck.lines[item.idx] = '- [' + (item.done ? ' ' : 'x') + '] ' + item.text;
          try {
            await api('/tasks/' + t.id, { method: 'PATCH', body: { description: ck.lines.join('\n') } });
            await refreshBoard();
            state.drawerTaskId = t.id; render();
          } catch (e) { toast(e.message, true); }
        },
      },
        h('span', { class: 'ck-box' }, item.done ? icon('check', { size: 11, stroke: '2.6' }) : null),
        h('span', { class: 'ck-text' }, item.text))));
  }

  /* ------------------------------------------- COD / NDR template pack modal */

  function fillTemplateTitle(tpl, orderTitle) {
    // Deliberately identical to TaskTemplates::renderTitle() (PHP format "d M Y"),
    // because POST /api/tasks/bulk treats "same title + same order + still open"
    // as its duplicate test. If the two date formats drift, a task created from
    // the board and one created from the Orders list stop recognising each other
    // and the merchant files the same follow-up twice.
    const d = new Date();
    const today = String(d.getDate()).padStart(2, '0') + ' '
      + d.toLocaleDateString('en-GB', { month: 'short' }) + ' ' + d.getFullYear();
    return (tpl.title || tpl.name).replace('{order}', orderTitle || '').replace('{date}', today).replace(/\s+/g, ' ').trim();
  }

  function humanHours(hours) {
    if (hours % 168 === 0) return (hours / 168) + ' week';
    if (hours % 24 === 0) return (hours / 24) + (hours / 24 > 1 ? ' days' : ' day');
    return hours + ' hours';
  }

  function openTemplatesModal() {
    const s = state.board;
    const templates = Object.entries(s.task_templates || {});
    const body = h('div', null);
    const close = openModalAuto('Task templates — India COD / NDR pack', body);

    function renderGrid() {
      body.replaceChildren(
        h('p', { class: 'muted small', style: 'margin-top:0' }, 'One click builds a task pre-filled with the exact checklist your team should follow. Tap a template:'),
        h('div', { class: 'tmpl-grid' }, templates.map(([key, tpl]) => h('button', {
          class: 'tmpl-card',
          onclick: () => renderDetail(tpl),
        },
          h('span', { class: 'tmpl-icon' }, icon(tpl.icon || 'box', { size: 22 })),
          h('span', { class: 'tmpl-name' }, tpl.name),
          h('span', { class: 'tmpl-tag' }, tpl.tagline || '')))));
    }

    function renderDetail(tpl) {
      let selected = null;
      const needsResource = !!tpl.resource_type;

      const search = h('input', { class: 'input', placeholder: 'Search order… e.g. #1001' });
      const results = h('div', null, h('div', { class: 'res-empty' }, 'Type to search'));
      const createBtn = h('button', { class: 'btn primary', disabled: needsResource }, ...withIcon('plus', 'Create task', { size: 16 }));

      const doSearch = debounce(async () => {
        const q = search.value.trim();
        if (!q) { results.replaceChildren(h('div', { class: 'res-empty' }, 'Type to search')); return; }
        results.replaceChildren(h('div', { class: 'res-empty' }, 'Searching…'));
        try {
          const data = await api('/resources/search?type=' + tpl.resource_type + '&q=' + encodeURIComponent(q.replace(/^#/, '')));
          results.replaceChildren(...(data.items.length ? data.items.map(item => h('div', {
            class: 'res-item',
            onclick: ev => {
              selected = item;
              createBtn.disabled = false;
              [...results.children].forEach(c2 => c2.classList.remove('sel'));
              ev.currentTarget.classList.add('sel');
            },
          },
            item.image ? h('img', { src: item.image, alt: '' }) : h('img', { alt: '' }),
            h('div', null,
              h('div', { class: 'ri-title' }, item.title || '(no title)'),
              h('div', { class: 'ri-sub' }, item.subtitle || '')))) : [h('div', { class: 'res-empty' }, 'No matches found')]));
        } catch (e) { results.replaceChildren(h('div', { class: 'res-empty' }, e.message || 'Search failed')); }
      }, 350);
      search.addEventListener('input', doSearch);

      createBtn.addEventListener('click', async () => {
        createBtn.disabled = true;
        const firstCol = s.columns.find(c => !c.is_done_stage) || s.columns[0];
        const payload = {
          column_id: firstCol.id,
          title: fillTemplateTitle(tpl, selected?.title || ''),
          description: (tpl.checklist || []).map(it => '- [ ] ' + it).join('\n'),
          priority: tpl.priority || 'medium',
          due_at: tpl.due_in_hours ? new Date(Date.now() + tpl.due_in_hours * 3600e3).toISOString() : null,
          resource_type: selected?.type || null,
          resource_id: selected?.id || null,
          resource_gid: selected?.gid || null,
          resource_title: selected?.title || null,
          resource_url: selected?.url || null,
        };
        try {
          const created = await api('/tasks', { method: 'POST', body: payload });
          close();
          await refreshBoard();
          if (created?.id) { state.drawerTaskId = created.id; render(); }
          toast('Task created — checklist ready');
        } catch (e) {
          toast(e.message, true); createBtn.disabled = false;
        }
      });

      body.replaceChildren(
        h('button', { class: 'btn plain sm', onclick: renderGrid }, ...withIcon('arrow-left', 'All templates', { size: 15 })),
        h('div', { class: 'tmpl-detail' },
          h('div', { class: 'tmpl-dhead' },
            h('span', { class: 'tmpl-icon big' }, icon(tpl.icon || 'box', { size: 28 })),
            h('div', null,
              h('h3', null, tpl.name),
              h('div', { class: 'muted small' }, tpl.tagline || ''))),
          h('div', { class: 'tmpl-meta' },
            h('span', { class: 'pill ' + (tpl.priority || 'medium') }, tpl.priority || 'medium'),
            tpl.due_in_hours ? h('span', { class: 'pill due' }, 'due in ' + humanHours(tpl.due_in_hours)) : null,
            needsResource ? h('span', { class: 'pill link' }, 'links an order') : h('span', { class: 'pill done' }, 'recurring chore')),
          h('div', { class: 'ck-preview' }, (tpl.checklist || []).map(it =>
            h('div', { class: 'ck-line' }, h('span', { class: 'ck-box' }), h('span', { class: 'ck-text' }, it)))),
          needsResource ? h('div', { class: 'field' }, h('label', null, ...withIcon('link', 'Link the order', { size: 14 })), search, h('div', { class: 'mt' }, results)) : null,
          h('div', { class: 'row-flex mt' }, createBtn)));
      if (needsResource) setTimeout(() => search.focus(), 50);
    }

    renderGrid();
  }

  /* ------------------------------------------------- first-run onboarding */

  // Shown once after install (per shop). Inline SVG art only — no emojis,
  // no external images, works in any sandboxed/embedded context.
  const TOUR_STEPS = [
    {
      art: `<svg viewBox="0 0 260 150" class="tour-svg" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Kanban board inside Shopify admin">
<rect x="8" y="8" width="244" height="134" rx="10" fill="#f6f6f7" stroke="#dfe3e6"/>
<path d="M8 18 a10 10 0 0 1 10 -10 h224 a10 10 0 0 1 10 10 v12 h-244 z" fill="#ffffff" stroke="#dfe3e6"/>
<circle cx="24" cy="19" r="3.2" fill="#e0e0e0"/><circle cx="35" cy="19" r="3.2" fill="#e0e0e0"/><circle cx="46" cy="19" r="3.2" fill="#e0e0e0"/>
<rect x="72" y="13.5" width="118" height="11" rx="5.5" fill="#eef0f1"/>
<rect x="22" y="40" width="66" height="94" rx="6" fill="#ffffff" stroke="#e3e3e3"/>
<rect x="30" y="48" width="40" height="7" rx="3.5" fill="#8a919a"/>
<rect x="30" y="62" width="50" height="20" rx="4" fill="#e4f5ef" stroke="#b7e5d8"/>
<rect x="30" y="88" width="46" height="20" rx="4" fill="#ffffff" stroke="#dfe3e6"/>
<rect x="30" y="112" width="50" height="16" rx="4" fill="#ffffff" stroke="#dfe3e6"/>
<rect x="97" y="40" width="66" height="94" rx="6" fill="#ffffff" stroke="#e3e3e3"/>
<rect x="105" y="48" width="46" height="7" rx="3.5" fill="#8a919a"/>
<rect x="105" y="62" width="50" height="20" rx="4" fill="#fff4e5" stroke="#ffd79d"/>
<rect x="105" y="88" width="44" height="20" rx="4" fill="#ffffff" stroke="#dfe3e6"/>
<rect x="172" y="40" width="66" height="94" rx="6" fill="#ffffff" stroke="#e3e3e3"/>
<rect x="180" y="48" width="34" height="7" rx="3.5" fill="#8a919a"/>
<rect x="180" y="62" width="50" height="20" rx="4" fill="#e6f4ea" stroke="#b7dfc4"/>
<path d="M189 72 l4 4 l7 -7" stroke="#008060" stroke-width="2.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
<rect x="180" y="88" width="47" height="20" rx="4" fill="#e6f4ea" stroke="#b7dfc4"/>
<path d="M189 98 l4 4 l7 -7" stroke="#008060" stroke-width="2.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
</svg>`,
      title: 'Welcome to TaskPe',
      body: 'A fast task board that lives inside your Shopify admin — no separate site, no new tab.',
      points: [
        'Create tasks in seconds and drag them across your own columns',
        'Assign work, set due dates and priorities, track everything',
        'Your team works right here — the same place your orders live',
      ],
    },
    {
      art: `<svg viewBox="0 0 260 150" class="tour-svg" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Task linked to a Shopify order">
<rect x="18" y="42" width="108" height="66" rx="8" fill="#ffffff" stroke="#dfe3e6"/>
<rect x="28" y="52" width="72" height="8" rx="4" fill="#1a1a1a" opacity="0.85"/>
<rect x="28" y="66" width="48" height="6" rx="3" fill="#8a919a"/>
<rect x="28" y="82" width="40" height="14" rx="7" fill="#e4f5ef" stroke="#b7e5d8"/>
<line x1="126" y1="75" x2="136" y2="75" stroke="#008060" stroke-width="3"/>
<g transform="translate(136 66)">
<rect x="-2" y="6" width="16" height="9" rx="4.5" fill="none" stroke="#008060" stroke-width="3"/>
<rect x="8" y="-1" width="16" height="9" rx="4.5" fill="none" stroke="#008060" stroke-width="3"/>
</g>
<line x1="160" y1="75" x2="170" y2="75" stroke="#008060" stroke-width="3"/>
<rect x="170" y="42" width="74" height="66" rx="8" fill="#f6f6f7" stroke="#dfe3e6"/>
<text x="207" y="66" font-size="11" fill="#8a919a" text-anchor="middle">Order</text>
<text x="207" y="90" font-size="16" font-weight="700" fill="#1a1a1a" text-anchor="middle">#1002</text>
<path d="M60 118 h140" stroke="#dfe3e6" stroke-width="2" stroke-dasharray="2 6" stroke-linecap="round"/>
<text x="130" y="136" font-size="11" fill="#6d7175" text-anchor="middle">orders - draft orders - products - customers - blog posts</text>
</svg>`,
      title: 'Link tasks to the real thing',
      body: 'Every task can point at the exact Shopify object it is about — one click takes you to it.',
      points: [
        'Search by order number, product name or customer — or just paste an admin URL',
        'The card shows a live link back into Shopify admin at all times',
        'The order page also gets a "Create task" button via the app action',
      ],
    },
    {
      art: `<svg viewBox="0 0 260 150" class="tour-svg" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="COD and NDR workflows for Indian stores">
<circle cx="62" cy="75" r="30" fill="#fff8ec" stroke="#ffd79d" stroke-width="2"/>
<text x="62" y="86" text-anchor="middle" font-size="28" font-weight="700" fill="#b98900">₹</text>
<path d="M150 42 l26 10 v22 c0 18 -12 27 -26 33 c-14 -6 -26 -15 -26 -33 v-22 z" fill="#e4f5ef" stroke="#008060" stroke-width="3"/>
<path d="M139 74 l8 8 l15 -15" stroke="#008060" stroke-width="4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
<path d="M212 56 a22 22 0 1 1 -7 16" fill="none" stroke="#8a919a" stroke-width="3" stroke-linecap="round"/>
<path d="M201 64 l5 9 l9 -5" fill="none" stroke="#8a919a" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
<text x="130" y="132" font-size="11" fill="#6d7175" text-anchor="middle">confirm before you ship - rescue what fails - reconcile the cash</text>
</svg>`,
      title: 'Built for COD-first Indian stores',
      body: 'RTO is the silent profit-killer. TaskPe ships the workflows that fight it, ready-made.',
      points: [
        'One-click COD / NDR templates — confirmation, rescue, prepaid conversion, address fixes',
        'Optional automations: auto-task on new COD orders, courier NDR pushes, weekly remittance chore',
        'Everything is off by default — turn on only what you need in Settings',
      ],
    },
    {
      art: `<svg viewBox="0 0 260 150" class="tour-svg" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Team assignment">
<circle cx="50" cy="52" r="19" fill="#008060"/>
<text x="50" y="57" font-size="12" fill="#ffffff" text-anchor="middle" font-weight="700">RK</text>
<circle cx="50" cy="104" r="16" fill="#95c9b4"/>
<text x="50" y="108.5" font-size="11" fill="#0c3b2e" text-anchor="middle" font-weight="700">SA</text>
<path d="M78 52 C 102 52 104 70 126 70" stroke="#dfe3e6" stroke-width="3" fill="none" stroke-linecap="round"/>
<path d="M74 104 C 100 104 102 82 126 78" stroke="#dfe3e6" stroke-width="3" fill="none" stroke-linecap="round"/>
<rect x="126" y="44" width="112" height="62" rx="8" fill="#ffffff" stroke="#dfe3e6"/>
<rect x="136" y="54" width="70" height="8" rx="4" fill="#1a1a1a" opacity="0.85"/>
<rect x="136" y="68" width="46" height="6" rx="3" fill="#8a919a"/>
<circle cx="222" cy="88" r="11" fill="#008060"/>
<text x="222" y="92" font-size="8.5" fill="#ffffff" text-anchor="middle" font-weight="700">RK</text>
<text x="130" y="128" font-size="11" fill="#6d7175">assign - due dates - priorities - activity timeline</text>
</svg>`,
      title: 'Bring your team on board',
      body: 'Add teammates in the Team tab and hand out work with a tap.',
      points: [
        'Staff and owner roles — owners can get a morning board digest',
        'Optional WhatsApp alerts through your own Whatify account (about ₹0.12/message)',
        'Also off by default: skip it entirely and every screen works the same',
      ],
    },
    {
      art: `<svg viewBox="0 0 260 150" class="tour-svg" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Ready to start">
<rect x="30" y="32" width="76" height="96" rx="10" fill="#ffffff" stroke="#dfe3e6"/>
<rect x="40" y="46" width="56" height="24" rx="8" fill="#e4f5ef"/>
<rect x="47" y="53" width="34" height="5" rx="2.5" fill="#5ea48e"/>
<rect x="47" y="62" width="24" height="5" rx="2.5" fill="#8fc7b4"/>
<rect x="40" y="78" width="44" height="20" rx="8" fill="#f6f6f7" stroke="#e3e3e3"/>
<rect x="47" y="85" width="26" height="5" rx="2.5" fill="#8a919a"/>
<rect x="120" y="52" width="44" height="22" rx="11" fill="#dfe3e6"/>
<circle cx="131" cy="63" r="8" fill="#ffffff"/>
<text x="142" y="92" font-size="11" fill="#6d7175" text-anchor="middle">all optional</text>
<circle cx="203" cy="100" r="26" fill="#008060"/>
<path d="M191 100 l9 9 l16 -17" stroke="#ffffff" stroke-width="5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
</svg>`,
      title: 'You are all set',
      body: 'Three quick things and your store is running on TaskPe.',
      points: [
        'Open "COD / NDR task templates" on the board and try one',
        'Add yourself (and your team) in the Team tab',
        'Visit Settings whenever you want automation or WhatsApp — nothing runs without you switching it on',
      ],
    },
  ];

  function openTour() {
    let step = 0;
    const overlay = h('div', { class: 'tour-overlay' });
    const artBox = h('div', { class: 'tour-art' });
    const titleEl = h('h2', null);
    const bodyEl = h('p', { class: 'tour-body' });
    const pointsEl = h('ul', { class: 'tour-points' });
    const dotsEl = h('div', { class: 'tour-dots' });
    const backBtn = h('button', { class: 'btn' }, 'Back');
    const nextBtn = h('button', { class: 'btn primary' }, 'Next');
    const skipBtn = h('button', { class: 'tour-skip' }, 'Skip intro');

    function renderStep() {
      const s = TOUR_STEPS[step];
      artBox.innerHTML = s.art;   // static, app-owned SVG strings
      titleEl.textContent = s.title;
      bodyEl.textContent = s.body;
      pointsEl.replaceChildren(...s.points.map(p => h('li', null, p)));
      dotsEl.replaceChildren(...TOUR_STEPS.map((_, i) => h('span', { class: 't-dot' + (i === step ? ' on' : '') })));
      backBtn.style.visibility = step === 0 ? 'hidden' : 'visible';
      nextBtn.textContent = step === TOUR_STEPS.length - 1 ? 'Get started' : 'Next';
    }

    async function finish() {
      overlay.remove();
      try { await api('/onboarding/complete', { method: 'POST' }); }
      catch { /* board works regardless; tour may reappear next load — harmless */ }
    }

    backBtn.addEventListener('click', () => { if (step > 0) { step--; renderStep(); } });
    nextBtn.addEventListener('click', () => {
      if (step === TOUR_STEPS.length - 1) void finish();
      else { step++; renderStep(); }
    });
    skipBtn.addEventListener('click', () => void finish());

    overlay.append(h('div', { class: 'tour-card' },
      h('div', { class: 'tour-top' },
        h('span', { class: 'tour-badge' }, 'Quick intro'),
        skipBtn),
      artBox, titleEl, bodyEl, pointsEl,
      h('div', { class: 'tour-foot' }, backBtn, dotsEl, nextBtn)));

    document.body.append(overlay);
    renderStep();
  }

  /* -------------------------------------------------------------- team view */

  function renderTeam() {
    const s = state.board;
    const rows = s.members.map(m => h('div', { class: 'mem-row' },
      h('span', { class: 'avatar' + (m.active ? '' : ' gray') }, m.initials),
      h('div', null,
        h('div', { class: 'm-name' }, m.name, ' ', m.role === 'owner' ? h('span', { class: 'pill medium' }, 'OWNER') : null, m.active ? null : h('span', { class: 'pill' }, ' disabled')),
        h('div', { class: 'm-sub' }, '+' + m.phone)),
      h('div', { class: 'm-actions' },
        m.portal_active ? h('span', { class: 'verify-badge yes', title: 'Can use the web portal — no Shopify admin needed' }, icon('globe', { size: 13 }), 'portal') : null,
        m.whatsapp_verified
          ? h('span', { class: 'verify-badge yes' }, icon('check-circle', { size: 13 }), 'WhatsApp verified')
          : h('span', { class: 'verify-badge no' }, 'Not verified'),
        h('button', {
          class: 'btn sm',
          title: 'Staff portal link — this member gets the full board on any phone browser, no Shopify account needed. Regenerating disables the older link.',
          onclick: async () => {
            try {
              const r = await api(`/members/${m.id}/portal-link`, { method: 'POST' });
              try { await navigator.clipboard.writeText(r.url); } catch { prompt('Copy this staff portal link:', r.url); }
              toast('Portal link for ' + m.name + ' copied — send it on WhatsApp/SMS. Any older link is now disabled.');
              await refreshBoard();
            } catch (e) { toast(e.message, true); }
          },
        }, ...withIcon(m.portal_active ? 'refresh' : 'link', 'Portal link', { size: 15 })),
        m.portal_active ? h('button', {
          class: 'btn sm plain',
          title: 'Revoke this member\'s web portal access immediately',
          onclick: async () => {
            if (!confirm(`Revoke ${m.name}'s web portal access?`)) return;
            try { await api(`/members/${m.id}/portal-link`, { method: 'DELETE' }); await refreshBoard(); toast('Portal access revoked'); }
            catch (e) { toast(e.message, true); }
          },
        }, 'Revoke') : null,
        !m.whatsapp_verified ? h('button', { class: 'btn sm', onclick: () => sendOtp(m) }, 'Send code') : null,
        !m.whatsapp_verified ? h('button', { class: 'btn sm primary', onclick: () => promptVerify(m) }, 'Enter code') : null,
        h('button', {
          class: 'btn sm', title: m.active ? 'Deactivate' : 'Activate',
          onclick: async () => { await api('/members/' + m.id, { method: 'PATCH', body: { active: !m.active } }); await refreshBoard(); },
        }, m.active ? 'Disable' : 'Enable'),
        h('button', {
          class: 'btn sm danger',
          onclick: async () => {
            if (!confirm(`Remove ${m.name}? Their tasks become unassigned.`)) return;
            try { await api('/members/' + m.id, { method: 'DELETE' }); await refreshBoard(); }
            catch (e) { toast(e.message, true); }
          },
        }, 'Remove'))));

    return h('div', { class: 'page' },
      h('div', { class: 'two-col' },
        h('div', null,
          h('div', { class: 'panel' },
            h('div', { class: 'p-head' }, h('h2', null, 'Team members'),
              h('span', { class: 'sub' }, `${s.members.filter(m => m.active).length} active`)),
            h('div', { class: 'p-body flush' },
              s.members.length ? rows : h('div', { class: 'empty-state' }, icon('people', { size: 34, class: 'big' }), 'Add your first team member — your VA, packer, or yourself.')))),
        h('div', null,
          h('div', { class: 'panel' },
            h('div', { class: 'p-head' }, h('h2', null, 'Add member')),
            h('div', { class: 'p-body' },
              h('div', { class: 'field' }, h('label', null, 'Name'), h('input', { class: 'input', id: 'nm-name', placeholder: 'e.g. Sarah' })),
              h('div', { class: 'field' }, h('label', null, 'WhatsApp number'), h('input', { class: 'input', id: 'nm-phone', placeholder: '98765 43210' }),
                h('div', { class: 'help' }, 'Indian numbers: plain 10 digits is fine — we add +91.')),
              h('div', { class: 'field' }, h('label', null, 'Role'), h('select', { class: 'input', id: 'nm-role' },
                h('option', { value: 'staff' }, 'Staff — sees own tasks'),
                h('option', { value: 'owner' }, 'Owner — gets the daily digest'))),
              h('button', {
                class: 'btn primary',
                onclick: async e => {
                  const btn = e.target;
                  const name = document.getElementById('nm-name').value.trim();
                  const phone = document.getElementById('nm-phone').value.trim();
                  if (!name || !phone) { toast('Name and phone are required', true); return; }
                  btn.disabled = true;
                  try {
                    const r = await api('/members', { method: 'POST', body: { name, phone, role: document.getElementById('nm-role').value } });
                    toast(r.message);
                    await refreshBoard();
                    const added = state.board.members.find(x => x.id === r.id);
                    if (r.otp_queued && added) promptVerify(added);
                  } catch (err) { toast(err.message, true); }
                  btn.disabled = false;
                },
              }, 'Add member'))),
          h('div', { class: 'panel' },
            h('div', { class: 'p-head' }, h('h2', null, 'No Shopify login for staff? Use the web portal')),
            h('div', { class: 'p-body small muted' },
              h('p', null, 'Shopify Basic gives you only ONE staff seat — your packer or VA usually can\'t open Shopify admin at all.'),
              h('p', { class: 'mt' }, 'Tap Portal link next to a member and send them the link: they get this SAME board (tasks, COD templates, checklists) in any phone browser. No Shopify account, no app install — just "Add to Home Screen".'),
              h('p', { class: 'mt' }, 'Staff can create, move and complete tasks — they can\'t delete tasks or touch settings, billing or the team list. Revoke a link anytime. Works on every plan, including Free.'))),
          h('div', { class: 'panel' },
            h('div', { class: 'p-head' }, h('h2', null, 'How WhatsApp verification works')),
            h('div', { class: 'p-body small muted' },
              h('p', null, '1. Add a member → we WhatsApp them a 6-digit code (from YOUR Whatify number).'),
              h('p', { class: 'mt' }, '2. They share the code → enter it here → verified'),
              h('p', { class: 'mt' }, '3. From then on, assigned tasks and reminders land on their WhatsApp instantly.'),
              h('p', { class: 'mt' }, 'Tip: ask staff to reply "hi" to your WhatsApp number once — it keeps instant messages flowing.'))))));
  }

  async function sendOtp(m) {
    try {
      const r = await api(`/members/${m.id}/send-otp`, { method: 'POST' });
      toast(r.message);
    } catch (e) { toast(e.message, true); }
  }

  function promptVerify(m) {
    openModal(`Verify ${m.name}`, h('div', null,
      h('p', { class: 'muted small mb' }, `Enter the 6-digit code sent to +${m.phone} on WhatsApp.`),
      h('input', { class: 'input', id: 'otp-code', inputmode: 'numeric', maxlength: 6, placeholder: '• • • • • •', style: 'text-align:center;font-size:22px;letter-spacing:8px' })),
      async () => {
        const code = document.getElementById('otp-code').value.trim();
        if (code.length !== 6) { toast('Enter the 6-digit code', true); return false; }
        try {
          const r = await api(`/members/${m.id}/verify`, { method: 'POST', body: { code } });
          toast(r.message);
          await refreshBoard();
          return true;
        } catch (e) { toast(e.message, true); return false; }
      });
  }

  /* ---------------------------------------------------------- settings view */

  async function renderSettingsAsync() {
    if (!state.settings) {
      state.settings = 'loading';
      try { state.settings = await api('/settings'); } catch (e) { state.settings = { error: e.message }; }
      render();
    }
  }

  function renderSettings() {
    if (!state.settings || state.settings === 'loading') {
      void renderSettingsAsync();
      return h('div', { class: 'page' }, h('div', { class: 'boot' }, h('div', { class: 'boot-text' }, 'Loading settings…')));
    }
    const st = state.settings;
    if (st.error) return h('div', { class: 'page' }, h('div', { class: 'banner crit' }, 'Failed to load settings: ' + st.error));

    const s = st.settings;
    const wf = st.whatify;
    const waOn = !!s.notify.whatsapp_on;
    const planAllowsWA = !!st.plan?.cfg?.whatsapp;

    // Master enable/disable card — always visible at the top of Settings.
    const masterPanel = h('div', { class: 'panel' },
      h('div', { class: 'p-head' },
        h('h2', null, 'WhatsApp alerts'),
        waOn ? h('span', { class: 'pill done' }, 'ENABLED') : h('span', { class: 'pill' }, 'DISABLED')),
      h('div', { class: 'p-body' },
        h('label', { class: 'switch' },
          (() => { const i = h('input', { type: 'checkbox', id: 'ms-wa-on' }); i.checked = waOn; return i; })(),
          h('span', { class: 'track' }),
          h('span', null,
            h('span', { class: 'sw-label' }, 'Enable WhatsApp notifications for your team'),
            h('span', { class: 'sw-sub' }, planAllowsWA
              ? 'Assignment pings, nudges and the owner digest — sent via YOUR Whatify account (about ₹0.12 per message).'
              : 'Available on Starter plan and above — switch to the Plan tab to upgrade.'))),
        h('div', { class: 'row-flex mt' },
          h('button', {
            class: 'btn primary sm',
            onclick: async e => {
              const checkbox = document.getElementById('ms-wa-on');
              e.target.disabled = true;
              try {
                await saveSettings();
                const nowOn = checkbox.checked;
                state.settings = null;
                await loadBoard();
                toast('WhatsApp alerts ' + (nowOn ? 'enabled' : 'disabled'));
                render();
              } catch (err) { toast(err.message, true); e.target.disabled = false; }
            },
          }, waOn ? 'Save' : 'Enable WhatsApp'),
          !planAllowsWA ? h('button', { class: 'btn plain sm', onclick: () => { state.view = 'plan'; render(); } }, 'View plans →') : null)));

    // COD / NDR automation hub — independent of WhatsApp, always visible
    // (every sub-feature OFF by default; merchants enable what they need).
    const auto = s.automation || {};
    const codPanel = (() => {
      const sw = (id, label, sub) => h('label', { class: 'switch', style: 'margin-bottom:10px' },
        (() => { const i = h('input', { type: 'checkbox', id }); i.checked = !!auto[id === 'cod-auto' ? 'cod_auto' : id === 'ndr-auto' ? 'ndr_auto' : 'weekly_remittance']; return i; })(),
        h('span', { class: 'track' }),
        h('span', null,
          h('span', { class: 'sw-label' }, label),
          h('span', { class: 'sw-sub' }, sub)));

      const onCount = ['cod_auto', 'ndr_auto', 'weekly_remittance'].filter(k => auto[k]).length;

      return h('div', { class: 'panel' },
        h('div', { class: 'p-head' },
          h('h2', null, 'COD / NDR automation'),
          onCount ? h('span', { class: 'pill done' }, onCount + ' of 3 ON') : h('span', { class: 'pill' }, 'ALL OFF')),
        h('div', { class: 'p-body' },
          sw('cod-auto',
            'Auto-create a confirmation task for every new COD order',
            'RTO saver: every Cash-on-Delivery order instantly lands on the board with the COD checklist, due in 24h. (Registers the orders/create webhook — no reinstall needed.)'),
          sw('ndr-auto',
            'Turn courier NDR pushes into urgent rescue tasks',
            'Paste the intake URL below into Shiprocket / Delhivery / XpressBees webhook settings. Each NDR event becomes an urgent 12-hour rescue task.'),
          auto.ndr_intake_url ? h('div', { class: 'field' },
            h('label', null, 'Your NDR intake URL (secret — do not share)'),
            h('div', { class: 'row-flex' },
              h('input', { class: 'input', readonly: true, value: auto.ndr_intake_url, onclick: e => e.target.select() }),
              h('button', {
                class: 'btn sm',
                onclick: e => {
                  navigator.clipboard.writeText(auto.ndr_intake_url).then(() => toast('Copied — paste in your courier panel'));
                },
              }, 'Copy'))) : null,
          sw('weekly-remit',
            'Weekly COD remittance chore, every week automatically',
            'Creates the "COD remittance check" task every week until you complete it — chase the courier before unclaimed cash piles up.'),
          h('div', { class: 'row-flex mt' },
            h('button', {
              class: 'btn primary sm',
              onclick: async e => {
                e.target.disabled = true;
                try {
                  await saveSettings();
                  const nowOn = ['cod-auto', 'ndr-auto', 'weekly-remit'].filter(id => document.getElementById(id)?.checked);
                  state.settings = null;
                  toast('Automation saved' + (nowOn.length ? ' — ' + nowOn.length + ' feature(s) active' : ''));
                  render();
                } catch (err) { toast(err.message, true); }
                e.target.disabled = false;
              },
            }, 'Save'))));
    })();

    // What TaskPe puts *inside* the Shopify admin, and how a merchant turns it
    // on. Both the bulk action and the block need a step from them (Shopify does
    // not let an app place a block), so the instructions live next to the
    // automations they belong to instead of only in extensions/README.md.
    const adminPanel = h('div', { class: 'panel' },
      h('div', { class: 'p-head' },
        h('h2', null, 'In your Shopify admin'),
        h('span', { class: 'sub' }, 'File follow-ups without leaving the order')),
      h('div', { class: 'p-body small' },
        h('div', { class: 'how-row' }, icon('plus', { size: 16 }),
          h('div', null, h('b', null, 'One order'),
            h('p', { class: 'mt' }, 'Open it → ', h('b', null, 'More actions'), ' → ', h('b', null, 'Create task'), '. The order is pre-linked, so the task never loses its context.'))),
        h('div', { class: 'how-row' }, icon('stack', { size: 16 }),
          h('div', null, h('b', null, 'Many orders at once'),
            h('p', { class: 'mt' }, 'Tick them on the Orders list → ', h('b', null, 'Create TaskPe tasks'), ' → choose the template and who does them. Orders that already have that task open are skipped, so pressing it twice is safe.'))),
        h('div', { class: 'how-row' }, icon('check-circle', { size: 16 }),
          h('div', null, h('b', null, 'The order-page card'),
            h('p', { class: 'mt' }, 'On an order page, choose ', h('b', null, 'Add custom app block'), ' → TaskPe. Then you can tick checklist steps and file a COD/NDR task while the order is open. Only you can pin a block — Shopify does not let apps place it.'))),
        h('div', { class: 'how-row' }, icon('edit', { size: 16 }),
          h('div', null, h('b', null, 'Entries missing from the admin menus?'),
            h('p', { class: 'mt' }, 'They are Shopify extensions: set ', h('code', null, 'APP_URL'), ' in ', h('code', null, 'extensions/shared/api.js'), ', then run ', h('code', null, 'shopify app deploy'), ' from your own computer (', h('code', null, 'extensions/README.md'), ').'))),
        h('p', { class: 'mt small muted' }, 'On the board: n adds a task · t opens templates · c completes the open task · 1–4 switch sections · ? lists them.')));

    // DISABLED state: show only the master card + explanation. Zero WhatsApp
    // UI otherwise — feature stays invisible until the merchant turns it on.
    if (!waOn) {
      return h('div', { class: 'page' },
        h('div', { class: 'two-col' },
          h('div', null,
            masterPanel,
            h('div', { class: 'panel' },
              h('div', { class: 'p-body small muted' },
                h('p', null, '— WhatsApp alerts are turned off. Your board, team and task linking work exactly the same — nothing is ever sent to WhatsApp.'),
                h('p', { class: 'mt' }, 'Turn the switch on when you want: instant task pings to staff, due reminders, and the owner\'s morning digest.')))),
          h('div', null,
            codPanel,
            adminPanel,
            h('div', { class: 'panel' },
              h('div', { class: 'p-head' }, h('h2', null, 'Setup guide')),
              h('div', { class: 'p-body small muted' },
                h('p', null, '1. Turn the switch ON above.'),
                h('p', { class: 'mt' }, '2. Create an account at ', h('b', null, 'whatify.in'), ' and connect your WhatsApp Business number.'),
                h('p', { class: 'mt' }, '3. Generate an API key there and paste it here.'),
                h('p', { class: 'mt' }, '4. Add yourself as an Owner in the Team tab and verify your number.'),
                h('p', { class: 'mt' }, '5. (Recommended) Create message templates in Whatify so alerts also work outside the 24-hour window.'),
                h('p', { class: 'mt' }, h('button', { class: 'btn plain sm', onclick: openTour }, 'Replay the intro tour')))))));
    }

    // ENABLED state — full wiring below.
    return h('div', { class: 'page' },
      h('div', { class: 'two-col' },
        h('div', null,
          masterPanel,
          // ---- Whatify connection
          h('div', { class: 'panel' },
            h('div', { class: 'p-head' },
              h('h2', null, 'Whatify (WhatsApp) connection'),
              wf.connected ? h('span', { class: 'pill done' }, 'Connected') : h('span', { class: 'pill overdue' }, s.has_api_key ? 'Key problem' : 'Not connected')),
            h('div', { class: 'p-body' },
              h('div', { class: 'field' },
                h('label', null, 'Whatify API key'),
                h('input', { class: 'input', id: 'wf-key', type: 'password', placeholder: s.has_api_key ? s.api_key_hint + ' (saved — paste to replace)' : 'wfy_xxxxxxxxxxxx' }),
                h('div', { class: 'help' }, 'Get it from whatify.in dashboard → Developers/API keys. '),
                ),
              wf.accounts?.length ? h('div', { class: 'field' },
                h('label', null, 'Send from number'),
                (() => {
                  const sel = h('select', { class: 'input', id: 'wf-account' },
                    wf.accounts.map(a => h('option', { value: a.id, selected: a.id === (s.whatsapp_account_id || wf.default_account_id) },
                      `${a.display_name || 'Number'} (+${a.phone_number})` + (a.quality_rating === 'GREEN' ? ' · quality: healthy' : ''))));
                  return sel;
                })()) : null,
              wf.wallet && wf.connected ? h('p', { class: 'small muted mb' }, `Whatify wallet: ₹${Number(wf.wallet.balance ?? 0).toFixed(2)} balance`) : null,
              wf.error ? h('p', { class: 'small mb', style: 'color:var(--critical)' }, wf.error) : null,
              h('div', { class: 'row-flex' },
                h('button', {
                  class: 'btn primary',
                  onclick: async e => {
                    const btn = e.target; btn.disabled = true;
                    try {
                      await saveSettings({});
                      state.settings = null;
                      toast('Saved & verified');
                      await loadBoard(); render();
                    } catch (err) { toast(err.message, true); }
                    btn.disabled = false;
                  },
                }, 'Save connection'),
                s.has_api_key ? h('button', {
                  class: 'btn',
                  onclick: async () => {
                    try { state.settings = await api('/settings'); render(); toast('Refreshed'); } catch (e) { toast(e.message, true); }
                  },
                }, ...withIcon('refresh', 'Test / refresh', { size: 15 })) : null))),

          // ---- templates
          wf.connected ? h('div', { class: 'panel' },
            h('div', { class: 'p-head' }, h('h2', null, 'Message templates'),
              h('span', { class: 'sub' }, 'Map each event to an approved template from your Whatify account')),
            h('div', { class: 'p-body' },
              ['otp', 'task_assigned', 'task_reminder', 'digest'].map(key => h('div', { class: 'field' },
                h('label', null, { otp: 'OTP verification', task_assigned: 'Task assigned', task_reminder: 'Reminder / nudge', digest: 'Daily digest' }[key]),
                (() => {
                  const sel = h('select', { class: 'input', id: 'tpl-' + key },
                    h('option', { value: '' }, '— plain text fallback (24h window only) —'),
                    (wf.templates || []).map(t => h('option', { value: t.name, selected: (st.settings.templates || {})[key] === t.name }, `${t.name} (${t.category || '?'})`)));
                  sel.value = (st.settings.templates || {})[key] || '';
                  return sel;
                })(),
                h('div', { class: 'help' }, { otp: 'Needed for staff verification.', task_assigned: 'Instant alert on assignment.', task_reminder: 'Manual + due reminders.', digest: 'Owner morning summary.' }[key]))),
              h('button', {
                class: 'btn primary',
                onclick: async e => {
                  e.target.disabled = true;
                  try { await saveSettings({}); state.settings = null; toast('Templates saved'); render(); }
                  catch (err) { toast(err.message, true); }
                  e.target.disabled = false;
                },
              }, 'Save templates'))
          ) : null,

          // ---- notifications & digest
          h('div', { class: 'panel' },
            h('div', { class: 'p-head' }, h('h2', null, 'Notifications & digest')),
            h('div', { class: 'p-body' },
              h('label', { class: 'switch' },
                (() => { const i = h('input', { type: 'checkbox', id: 'nt-assign' }); i.checked = s.notify.on_assign; return i; })(),
                h('span', { class: 'track' }),
                h('span', null, h('span', { class: 'sw-label' }, 'Ping when a task is assigned'))),
              h('label', { class: 'switch' },
                (() => { const i = h('input', { type: 'checkbox', id: 'dg-on' }); i.checked = s.digest.enabled; return i; })(),
                h('span', { class: 'track' }),
                h('span', null, h('span', { class: 'sw-label' }, "Owner's daily digest"), h('span', { class: 'sw-sub' }, 'The Bird\'s-Eye-View, every morning on WhatsApp'))),
              h('div', { class: 'field-row' },
                h('div', { class: 'field' },
                  h('label', null, 'Digest time (' + (state.board.shop.timezone) + ')'),
                  h('input', { class: 'input', id: 'dg-time', type: 'time', value: s.digest.time }))),
              h('div', { class: 'row-flex' },
                h('button', {
                  class: 'btn primary',
                  onclick: async e => {
                    e.target.disabled = true;
                    try { await saveSettings({}); state.settings = null; toast('Saved'); render(); }
                    catch (err) { toast(err.message, true); }
                    e.target.disabled = false;
                  },
                }, 'Save notification settings'),
                h('button', {
                  class: 'btn',
                  onclick: async () => {
                    try {
                      const r = await api('/digest/send', { method: 'POST' });
                      toast(r.message, !r.ok);
                    } catch (err) { toast(err.message, true); }
                  },
                }, ...withIcon('send', 'Send digest now', { size: 16 })),
                h('button', {
                  class: 'btn plain',
                  onclick: async () => {
                    try {
                      const r = await api('/digest/preview');
                      openModal('Digest preview', h('pre', { style: 'white-space:pre-wrap;font:inherit' }, r.summary), null);
                    } catch (err) { toast(err.message, true); }
                  },
                }, 'Preview')))),

          // ---- logs
          h('div', { class: 'panel' },
            h('div', { class: 'p-head' }, h('h2', null, 'WhatsApp delivery log'), h('span', { class: 'sub' }, 'last 30 attempts')),
            h('div', { class: 'p-body flush' },
              st.logs.length ? h('table', { class: 'log-table' },
                h('thead', null, h('tr', null, h('th', null, 'When'), h('th', null, 'Kind'), h('th', null, 'To'), h('th', null, 'Status'), h('th', null, 'Detail'))),
                h('tbody', null, st.logs.map(l => h('tr', null,
                  h('td', null, fmtDate(l.created_at, { time: true })),
                  h('td', null, l.kind),
                  h('td', null, '+' + l.phone),
                  h('td', null, h('span', { class: 'log-status ' + l.status }, l.status), h('span', { class: 'muted small' }, ' · ' + l.channel)),
                  h('td', { class: 'log-err' }, l.error || '—')))))
                : h('div', { class: 'empty-state' }, 'No messages yet.')))),

        // ---- right rail: setup guide
        h('div', null,
          codPanel,
          adminPanel,
          h('div', { class: 'panel' },
            h('div', { class: 'p-head' }, h('h2', null, 'Setup guide')),
            h('div', { class: 'p-body small muted' },
              h('p', null, '1. Create an account at ', h('b', null, 'whatify.in'), ' and connect your WhatsApp Business number.'),
              h('p', { class: 'mt' }, '2. Generate an API key and paste it on this page.'),
              h('p', { class: 'mt' }, '3. Add yourself as an Owner in the Team tab and verify your number.'),
              h('p', { class: 'mt' }, '4. (Recommended) Create the 4 templates below in your Whatify dashboard so messages work even outside the 24-hour window.'),
              h('p', { class: 'mt' }, 'Cost: task alerts are "utility" messages — about ₹0.12 each on your Whatify wallet.'),
              h('p', { class: 'mt' }, h('button', { class: 'btn plain sm', onclick: openTour }, 'Replay the intro tour')))),
          h('div', { class: 'panel' },
            h('div', { class: 'p-head' }, h('h2', null, 'Copy-paste templates')),
            h('div', { class: 'p-body' },
              (st.suggested_templates || []).map(t => h('div', { class: 'tpl-card' },
                h('div', { class: 't-key' }, t.name, ' ', h('span', { class: 'pill link' }, t.category)),
                h('pre', null, t.body),
                h('div', { class: 't-note' }, t.note),
                h('button', {
                  class: 'btn sm mt',
                  onclick: e => {
                    navigator.clipboard.writeText('Name: ' + t.name + '\nCategory: ' + t.category + '\nBody:\n' + t.body)
                      .then(() => toast('Copied — paste in Whatify dashboard'));
                  },
                }, 'Copy'))))))));
  }

  // Partial-safe settings save: only sections whose inputs are actually on
  // screen are included — hidden panels never overwrite saved values.
  async function saveSettings(overrides = {}) {
    const body = {};

    const key = document.getElementById('wf-key')?.value.trim();
    if (key) body.whatify_api_key = key;

    const acct = document.getElementById('wf-account');
    if (acct) body.whatsapp_account_id = Number(acct.value) || null;

    const templates = {};
    for (const k of ['otp', 'task_assigned', 'task_reminder', 'digest']) {
      const el = document.getElementById('tpl-' + k);
      if (el) templates[k] = el.value || '';
    }
    if (Object.keys(templates).length) body.templates = templates;

    const notify = {};
    const ms = document.getElementById('ms-wa-on');   // master WhatsApp switch
    if (ms) notify.whatsapp_on = ms.checked;
    const na = document.getElementById('nt-assign');
    if (na) notify.on_assign = na.checked;
    if (Object.keys(notify).length) body.notify = notify;

    const digest = {};
    const dg = document.getElementById('dg-on');
    if (dg) digest.enabled = dg.checked;
    const dt = document.getElementById('dg-time');
    if (dt?.value) digest.time = dt.value;
    if (Object.keys(digest).length) body.digest = digest;

    const automation = {};
    const ca = document.getElementById('cod-auto');   // COD auto-task switch
    if (ca) automation.cod_auto = ca.checked;
    const nd = document.getElementById('ndr-auto');   // NDR watcher switch
    if (nd) automation.ndr_auto = nd.checked;
    const wr = document.getElementById('weekly-remit'); // weekly remittance chore
    if (wr) automation.weekly_remittance = wr.checked;
    if (Object.keys(automation).length) body.automation = automation;

    await api('/settings', { method: 'PUT', body: { ...body, ...overrides } });
  }

  /* -------------------------------------------------------------- plan view */

  // Price displayed in the merchant's billing currency: exact entry when the
  // plan defines it (INR for Indian stores), USD fallback otherwise — same
  // logic as BillingService::resolvePrice on the backend.
  function planPrice(key) {
    const cur = state.board.shop?.currency || 'USD';
    const prices = state.board.plans?.[key]?.prices || {};
    const code = prices[cur] !== undefined ? cur : 'USD';
    return { amount: Number(prices[code] ?? 0), code };
  }

  function fmtMoney(amount, code) {
    try {
      return new Intl.NumberFormat(window.navigator.language || 'en', {
        style: 'currency',
        currency: code,
        maximumFractionDigits: code === 'INR' ? 0 : 2,
      }).format(amount);
    } catch { return code + ' ' + amount; }
  }

  function renderPlan() {
    const s = state.board;
    const cur = s.shop.currency || 'USD';
    const features = {
      free: ['2 team members', '50 open tasks', 'Board + activity timeline', 'In-app only (no WhatsApp)'],
      starter: ['5 team members', 'Unlimited tasks', 'WhatsApp alerts to staff', 'Daily owner digest', '7-day free trial'],
      growth: ['Everything in Starter', 'Unlimited team members', 'Priority support', '7-day free trial'],
    };

    return h('div', { class: 'page' },
      h('div', { class: 'plans' },
        Object.entries(s.plans || {}).map(([key, cfg]) => {
          const p = planPrice(key);
          const paid = p.amount > 0;

          const cta = (() => {
            if (s.shop.plan === key) {
              return paid ? h('button', {
                class: 'btn danger',
                onclick: async () => {
                  if (!confirm('Cancel the paid plan and go back to Free?')) return;
                  try { await api('/billing/cancel', { method: 'POST' }); await refreshBoard(); toast('Plan cancelled'); }
                  catch (e) { toast(e.message, true); }
                },
              }, 'Cancel plan') : null;
            }
            if (!paid) return null;
            return h('button', {
              class: 'btn primary',
              onclick: async e => {
                e.target.disabled = true;
                try {
                  const r = await api('/billing/subscribe', { method: 'POST', body: { plan: key } });
                  open(r.confirmation_url, '_top');   // Shopify-hosted approve page
                } catch (err) { toast(err.message, true); e.target.disabled = false; }
              },
            }, 'Choose ' + cfg.name);
          })();

          return h('div', { class: 'plan-card' + (s.shop.plan === key ? ' current' : '') },
            s.shop.plan === key ? h('span', { class: 'cur-badge' }, 'CURRENT') : null,
            h('h3', null, cfg.name),
            h('div', { class: 'price' },
              paid ? fmtMoney(p.amount, p.code) : 'Free',
              paid ? h('span', null, ' /month (' + p.code + ')') : null),
            h('ul', null, (features[key] || []).map(f => h('li', null, f))),
            cta);
        })),
      h('p', { class: 'muted small mt' },
        cur === 'INR'
          ? 'Prices are shown and charged in Indian Rupees (₹) — your store\'s billing currency. Shopify bills your card/RuPay/UPI directly; no USD conversion and no forex fees on this subscription.'
          : 'Prices are shown in your store\'s billing currency (' + cur + '). Indian stores see plans directly in ₹ (INR). All charges run through Shopify Billing — nothing is charged outside Shopify.'));
  }

  function handleBillingFlag(flag) {
    if (flag === 'active') { toast('Plan activated — WhatsApp features unlocked.'); void api('/billing/sync', { method: 'POST' }).then(refreshBoard).catch(() => {}); }
    else if (flag === 'declined') toast('Plan not approved — still on Free settings.', true);
    else if (flag === 'error') toast('Could not confirm the charge — hit "Sync" on Plan tab.', true);
  }

  /* ----------------------------------------------------------------- modal */

  /* ------------------------------------------------------ keyboard shortcuts */

  // The board and the Orders list are the same two pages all day, so every
  // shortcut here is a click somebody used to make. Single keys, and inert
  // while a field has focus — same etiquette the admin itself follows.
  const SHORTCUTS = [
    ['n', 'Add a task to the first open column'],
    ['t', 'Open the COD / NDR template pack'],
    ['c', 'Complete (or reopen) the task open in the drawer'],
    ['1 – 4', 'Board · Team · Settings · Plan'],
    ['?', 'This list'],
    ['Esc', 'Close the open dialog'],
  ];

  function isTyping(el) {
    if (!el || !el.tagName) return false;
    const tag = String(el.tagName).toUpperCase();
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable === true;
  }

  // Dialogs append themselves to <body>, while the drawer is rendered inside
  // the board tree — so "a dialog is open" means "an overlay on body", and the
  // drawer never blocks its own `c` key.
  function openOverlays() {
    return (document.body.children || []).filter(el => String(el.className || '').split(' ').includes('overlay'));
  }

  function openShortcutsHelp() {
    openModalAuto('Keyboard shortcuts', h('div', { class: 'shortcuts' },
      h('p', { class: 'muted small', style: 'margin-top:0' }, 'They work on every view — just not while you are typing in a field.'),
      SHORTCUTS.map(([key, label]) => h('div', { class: 'sc-row' }, h('kbd', null, key), h('span', null, label)))));
  }

  function firstOpenColumn() {
    const cols = state.board?.columns || [];
    return cols.find(c => !c.is_done_stage) || cols[0] || null;
  }

  function startQuickAdd() {
    const col = firstOpenColumn();
    if (!col) return;
    state.quickAdd = col.id;
    state.drawerTaskId = null;
    render();
  }

  async function completeOpenTask() {
    const id = state.drawerTaskId;
    if (!id) return;
    const wasDone = !!findTask(id)?.completed_at;
    try {
      await api('/tasks/' + id + '/complete', { method: 'POST' });
      await refreshBoard();
      state.drawerTaskId = id;          // stay open — ticking off a list is the point
      render();
      toast(wasDone ? 'Task reopened' : 'Task completed');
    } catch (e) { toast(e.message, true); }
  }

  function bindShortcuts() {
    document.addEventListener('keydown', ev => {
      if (ev.defaultPrevented || ev.metaKey || ev.ctrlKey || ev.altKey) return;

      if (ev.key === 'Escape') {
        const overlays = openOverlays();
        if (overlays.length) { ev.preventDefault(); overlays[overlays.length - 1].remove(); return; }
        if (state.drawerTaskId) { ev.preventDefault(); closeDrawer(); }
        return;
      }

      if (isTyping(ev.target) || isTyping(document.activeElement)) return;

      // `?` is always live (it is how anyone finds this list); every other
      // single key is dropped while a dialog is open, because the fields in
      // there need the keystrokes — a stray `n` behind a template picker is
      // how junk tasks get born.
      if (ev.key === '?') { ev.preventDefault(); openShortcutsHelp(); return; }
      if (openOverlays().length) return;

      if (ev.key === 'c' || ev.key === 'C') {
        if (state.drawerTaskId) { ev.preventDefault(); void completeOpenTask(); }
        return;
      }
      if (ev.key === 'n' || ev.key === 'N') { ev.preventDefault(); startQuickAdd(); return; }
      if (ev.key === 't' || ev.key === 'T') { ev.preventDefault(); openTemplatesModal(); return; }

      const idx = ['1', '2', '3', '4'].indexOf(ev.key);
      if (idx > -1 && SECTIONS[idx]) {
        ev.preventDefault();
        if (SECTIONS[idx].view !== state.view) { state.view = SECTIONS[idx].view; render(); }
      }
    });
  }

  function openModal(title, bodyEl, onSave) {
    const overlay = h('div', { class: 'overlay' });
    const close = () => overlay.remove();

    const foot = onSave ? h('div', { class: 'modal-foot' },
      h('button', { class: 'btn', onclick: close }, 'Cancel'),
      h('button', {
        class: 'btn primary',
        onclick: async e => {
          e.target.disabled = true;
          const ok = await onSave().catch(err => { toast(err.message, true); return false; });
          e.target.disabled = false;
          if (ok !== false) close();
        },
      }, 'Save')) : null;

    overlay.append(h('div', { class: 'modal' },
      h('div', { class: 'modal-head' }, h('h2', null, title), iconButton('close', { title: 'Close', className: 'x', onClick: close })),
      h('div', { class: 'modal-body' }, bodyEl),
      foot));

    overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
    document.body.append(overlay);
  }

  // Footer-free modal variant (wizard-style flows manage their own buttons).
  // Returns a close() handle so the flow can dismiss itself.
  function openModalAuto(title, bodyEl) {
    const overlay = h('div', { class: 'overlay' });
    const close = () => overlay.remove();

    overlay.append(h('div', { class: 'modal' },
      h('div', { class: 'modal-head' }, h('h2', null, title), h('button', { class: 'x', onclick: close }, '×')),
      h('div', { class: 'modal-body' }, bodyEl)));

    overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
    document.body.append(overlay);

    return close;
  }

  /* ------------------------------------------------------------------- init */

  async function boot() {
    try {
      await loadBoard();
      render();
      bindShortcuts();
      if (!IS_STAFF && !state.board?.shop?.onboarded) setTimeout(openTour, 300);   // first-run intro (admin only)
    } catch (e) {
      // Every branch renders something *specific*. Silently replacing the
      // shell with one generic sentence is what made this failure so hard to
      // read for merchants (and for us) — see DEPLOYMENT.md § Troubleshooting.
      const gate = e.code === 'reauth' || e.code === 'not_embedded' || e.code === 'staff_auth';
      if (gate) renderConnectGate(e);
      else renderBootError(e);
    }
  }

  /* --------------------------------------------------- boot failure surfaces */

  // Not embedded / not installed: explain how to get in, and offer the
  // OAuth entry point for the store domain they type in.
  function renderConnectGate(err) {
    const known = cfg.shop || err?.shop || shopFromQuery() || '';
    const asStaff = IS_STAFF;   // portal session expired — same gate, different door
    const input = h('input', {
      class: 'input', id: 'gate-shop', placeholder: 'mystore.myshopify.com',
      value: known, inputmode: 'url', autocomplete: 'off', spellcheck: 'false',
      onkeydown: ev => { if (ev.key === 'Enter') connect(); },
    });

    function connect() {
      const shop = String(input.value || '').trim().toLowerCase()
        .replace(/^https?:\/\//, '').replace(/\/.*$/, '');
      if (!/^[a-z0-9][a-z0-9-]*\.(myshopify\.com|myshopify\.io)$/.test(shop)) {
        document.getElementById('gate-err').textContent =
          'Enter the full myshopify domain, e.g. mystore.myshopify.com';
        return;
      }
      location.assign(appUrl() + '/auth/shopify?shop=' + encodeURIComponent(shop));
    }

    // Three different real causes, three different sentences — the whole point
    // of this screen is that you never have to guess which one you are in.
    let why;
    if (err?.reason === 'missing_session_token' && appBridge()) {
      why = 'App Bridge produced a session token, but it never reached PHP. The web server is '
        + 'stripping the Authorization header (typical on CGI/FastCGI/LiteSpeed shared hosting) — '
        + 'see DEPLOYMENT.md → “Authorization header never arrives”. The app.js twin header and '
        + 'public/.htaccess rules handle this; both must be deployed together.';
    } else if (err?.reason === 'not_installed') {
      why = 'This store is not connected to TaskPe yet — approve the app scopes below and the '
        + 'board will open on its own.';
    } else if (err?.code === 'reauth') {
      why = 'TaskPe could not verify your Shopify session for this store — connect a store to continue.';
    } else {
      why = 'TaskPe runs inside Shopify Admin. Opened as a plain link there is no Shopify session token, '
        + 'so the board has nothing to load — this is a login step, not a crash.';
    }

    const note = h('div', { class: 'muted small mt' });
    if (!IS_STAFF && !appBridge()) {
      note.append(h('div', null, 'No App Bridge detected on this page. That is normal when the URL is opened outside admin.shopify.com; inside the admin it is loaded from Shopify’s CDN. If you already see this screen inside Shopify Admin, the app URL/allowed origins in the Partner Dashboard need to match '
        + location.origin + '.'));
    }
    const configured = String(cfg.appUrl || '').replace(/\/+$/, '');
    if (configured && configured !== location.origin) {
      note.append(h('div', { class: 'mt' },
        h('b', null, 'Heads-up: '),
        'APP_URL on the server is ' + configured + ' but the app is being served from ' + location.origin
        + ' — Shopify session tokens are origin-bound, so keep the two identical.'));
    }
    if (err?.detail) note.append(h('div', { class: 'muted small mt' }, 'App Bridge said: ' + err.detail));

    root.replaceChildren(h('div', { class: 'gate' },
      h('div', { class: 'panel gate-card' },
        h('div', { class: 'p-head' },
          h('div', { class: 'brand-badge' }, 'T'),
          h('h2', null, asStaff ? 'Your staff link has expired' : 'Open TaskPe from your Shopify admin')),
        h('div', { class: 'p-body' },
          h('p', { class: 'muted small' }, asStaff
            ? 'The board needs a staff session for this store, and this browser has none (or it was revoked).'
            : why),
          h('ol', { class: 'gate-steps' }, asStaff
            ? h('li', null, 'Ask your store manager for a fresh ', h('a', { href: appUrl() + '/staff' }, 'staff sign-in'),
                ' (WhatsApp code) or a new portal link — Team tab → Portal link.')
            : h('div', null,
              h('li', null, 'Go to ', h('b', null, 'admin.shopify.com'), ' → ', h('b', null, 'Apps'), ' → ', h('b', null, 'TaskPe'), '.'),
              h('li', null, 'Not installed yet? Connect your store below — you will be asked to approve the app scopes once.'),
              h('li', null, 'Teammates without admin access should use the ',
                h('a', { href: appUrl() + '/staff' }, 'staff board'), ' instead.'))),
          asStaff ? null : h('div', { class: 'field mt' },
            h('label', null, 'Your store domain'),
            input,
            h('div', { class: 'login-err', id: 'gate-err' })),
          h('div', { class: 'row' },
            asStaff
              ? h('a', { class: 'btn primary', href: appUrl() + '/staff' }, 'Go to staff sign-in')
              : h('button', { class: 'btn primary', onclick: connect }, 'Connect store'),
            h('button', { class: 'btn', onclick: () => location.reload() }, 'Reload')),
          note))));
  }

  // Anything else the API said — show it verbatim plus where to look, so the
  // next person does not have to guess between "bad DB" and "bad deploy".
  function renderBootError(err) {
    const rows = [
      ['What failed', err?.message || 'Unknown error'],
      ['Endpoint', err?.url || (appUrl() + '/api/board')],
      ['HTTP status', err?.status ? String(err.status) : '—'],
    ];

    root.replaceChildren(h('div', { class: 'gate' },
      h('div', { class: 'panel gate-card' },
        h('div', { class: 'p-head' },
          h('div', { class: 'brand-badge', style: 'background:var(--critical)' }, '!'),
          h('h2', null, 'The board could not be loaded')),
        h('div', { class: 'p-body' },
          h('div', { class: 'gate-rows' },
            rows.map(([k, v]) => h('div', { class: 'gate-row' },
              h('span', { class: 'k' }, k), h('span', { class: 'v' }, v)))),
          h('div', { class: 'banner crit mt' },
            h('div', null,
              h('div', { class: 'b-title' }, 'If this shows inside Shopify Admin, it is a server-side problem'),
              h('div', { class: 'b-body' },
                'Check, in order: (1) ', h('code', null, 'php artisan migrate'), ' ran and the DB is reachable; (2) ',
                h('code', null, 'APP_KEY'), ', ', h('code', null, 'SHOPIFY_API_KEY'), '/',
                h('code', null, 'SHOPIFY_API_SECRET'), ' and ', h('code', null, 'APP_URL'),
                ' are set in .env; (3) the exact message at the end of ',
                h('code', null, 'storage/logs/laravel.log'), '.'))),
          h('div', { class: 'row mt' },
            h('button', { class: 'btn primary', onclick: () => location.reload() }, 'Try again'),
            h('a', { class: 'btn', href: appUrl() + '/staff' }, 'Staff sign-in'))))));

    if (window.console) console.error('[TaskPe] board load failed:', err);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else void boot();
})();
