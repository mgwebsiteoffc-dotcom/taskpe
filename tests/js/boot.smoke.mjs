/**
 * TaskPe SPA smoke test — no build step, no test framework, just node:
 *
 *     node tests/js/boot.smoke.mjs        # or: npm run test:js
 *
 * It boots public/js/app.js against a tiny DOM stub and asserts what the user
 * actually ends up seeing for each way the board can fail to load. That screen
 * used to be one generic sentence ("Something went wrong loading the board")
 * for every cause, which made merchant reports impossible to triage — these
 * cases are the contract we now keep.
 */
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';

const SRC = fs.readFileSync(
  path.join(path.dirname(fileURLToPath(import.meta.url)), '../../public/js/app.js'), 'utf8');
const ORIGIN = 'https://taskpe.rankboosterinfotech.in';

/* ------------------------------------------------------------- fake DOM ---- */

class El {
  constructor(tag, nodeType = 1) {
    this.nodeType = nodeType;              // 1 = element, 3 = text (app.js checks it)
    this.tagName = String(tag).toUpperCase();
    this.children = [];
    this.attrs = {};
    this.dataset = {};
    this.style = {};
    this.listeners = {};
    this._text = '';
    this.parent = null;                    // set by append(), so remove() detaches
    this.disabled = false;
    this.checked = false;
    this.value = '';
  }
  set className(v) { this.attrs.class = v; }
  get className() { return this.attrs.class || ''; }
  get classList() {
    return {
      add: c => { this.className = [this.className, c].filter(Boolean).join(' '); },
      remove: c => { this.className = this.className.split(' ').filter(x => x && x !== c).join(' '); },
      contains: c => this.className.split(' ').includes(c),
      toggle: c => (this.classList.contains(c) ? this.classList.remove(c) : this.classList.add(c)),
    };
  }
  set textContent(v) { this._text = String(v); this.children = []; }
  get textContent() { return this._text + ' ' + this.children.map(c => c.textContent).join(' '); }
  setAttribute(k, v) { this.attrs[k] = String(v); }
  getAttribute(k) { return this.attrs[k]; }
  append(...nodes) { for (const n of nodes) if (n) { n.parent = this; this.children.push(n); } }
  replaceChildren(...nodes) { this.children = []; this.append(...nodes); }
  addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }
  fire(type, ev = {}) { for (const fn of this.listeners[type] || []) fn({ preventDefault() {}, target: this, ...ev }); }
  remove() {
    if (!this.parent) return;
    const kids = this.parent.children;
    const i = kids.indexOf(this);
    if (i > -1) kids.splice(i, 1);
  }
  querySelectorAll() { return []; }
  querySelector() { return null; }
  getBoundingClientRect() { return { top: 0, height: 0 }; }
  focus() {}
  click() { this.fire('click'); }
  text() { return this.textContent.replace(/\s+/g, ' ').trim(); }
  find(pred, acc = []) {
    if (pred(this)) acc.push(this);
    for (const c of this.children) c.find(pred, acc);
    return acc;
  }
}

function makeEnv({ taskpe = {}, shopify, fetchImpl } = {}) {
  const root = new El('main');
  const body = new El('body');
  const byId = { root };
  const navigations = [];
  const calls = [];

  const docListeners = {};
  const document = {
    readyState: 'complete',
    activeElement: null,
    title: '',
    body,
    createElement: t => new El(t),
    createElementNS: (ns, t) => new El(t),        // SVG nodes behave like the rest
    createTextNode: t => Object.assign(new El('#text', 3), { _text: String(t) }),
    // Real-ish: searches the live tree, so handlers that look up their
    // sibling nodes (gate-err, col-name…) behave like they do in a browser.
    getElementById: id => byId[id] || [root, body].map(n => n.find(x => x.attrs.id === id)[0]).find(Boolean) || null,
    addEventListener: (type, fn) => { (docListeners[type] ||= []).push(fn); },
    // Keyboard events are document-level, so the harness needs to raise them.
    fire: (type, ev = {}) => { for (const fn of docListeners[type] || []) fn({ preventDefault() {}, target: body, ...ev }); },
    listeners: docListeners,
  };

  const location = {
    href: ORIGIN + '/',
    origin: ORIGIN,
    search: '',
    assign: u => navigations.push(u),
    replace: u => navigations.push(u),
    reload: () => navigations.push('#reload'),
  };

  const sandbox = {
    document,
    location,
    root,
    navigations,
    calls,
    fetch: async (url, init) => {
      calls.push({ url, init });
      return (fetchImpl || (async () => ({ status: 599, ok: false, json: async () => ({}) })))(url, init, calls.length);
    },
    setTimeout, clearTimeout, Intl, URL, URLSearchParams,
    console: { log() {}, warn() {}, error() {} },        // keep the harness output readable Promise, JSON,
    Math, Number, String, Array, Object, Error, RegExp, Boolean, Symbol, TypeError,
    localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    confirm: () => true,
    prompt: () => null,
    navigator: { language: 'en-IN' },
  };
  sandbox.window = sandbox;
  sandbox.globalThis = sandbox;
  sandbox.open = (url, target) => { navigations.push(url + ' [' + target + ']'); return null; };
  sandbox.shopify = shopify;
  sandbox.__TASKPE__ = { appUrl: ORIGIN, ...taskpe };

  vm.runInContext(SRC, vm.createContext(sandbox), { filename: 'app.js' });
  return sandbox;
}

/** status-for-every-path, or per-path payloads: reply({ '/api/settings': … }) */
const reply = (status, data) => async (url) => {
  const body = typeof status === 'object' ? (status[url.split('?')[0]] ?? okBoard) : data;
  const code = typeof status === 'object' ? 200 : status;
  return { status: code, ok: code >= 200 && code < 300, json: async () => body };
};

const okSettings = {
  whatify: { connected: false },
  settings: {
    has_api_key: false, api_key_hint: null, whatsapp_account_id: null, templates: {},
    notify: { whatsapp_on: false, on_assign: true },
    digest: { enabled: true, time: '09:00' },
    automation: { cod_auto: false, ndr_auto: false, weekly_remittance: false },
    ndr_intake_url: null,
  },
  plan: { name: 'Free', cfg: { whatsapp: false, digest: false } },
  logs: [],
};

const okBoard = {
  shop: { domain: 'demo.myshopify.com', name: 'Demo Store', plan: 'free', plan_cfg: {}, timezone: 'Asia/Kolkata', currency: 'INR', onboarded: true },
  plans: { free: { name: 'Free', prices: {}, trial_days: 0 },
           starter: { name: 'Starter', prices: { USD: 5.99, INR: 499 }, trial_days: 7 } },
  task_templates: {
    cod_confirm: { icon: 'phone', name: 'COD confirmation', tagline: 'Verify before ship',
                   resource_type: 'order', priority: 'high', due_in_hours: 24,
                   title: 'Confirm COD order {order}', checklist: ['Call buyer', 'Confirm address'] },
  },
  columns: [{
    id: 1, name: 'To Do', position: 0, is_done_stage: false,
    tasks: [{
      id: 11, column_id: 1, title: 'Confirm COD order #1042', priority: 'urgent',
      description: '- [x] Call the buyer\n- [ ] Note the address', due_at: '2026-10-05T18:00:00+05:30',
      overdue: true, position: 0, completed_at: null, created_at: '2026-10-04T09:00:00+05:30',
      created_by: 'Ravi', assignee: { id: 1, name: 'Ravi Kumar', initials: 'RK' },
      resource: { type: 'order', id: 1042, gid: null, label: 'Order', title: '#1042', url: '#' },
    }, {
      id: 12, column_id: 1, title: 'Done-ish task', priority: 'low', description: null,
      due_at: null, overdue: false, position: 1, completed_at: '2026-10-03T10:00:00+05:30',
      created_at: '2026-10-01T10:00:00+05:30', created_by: null, assignee: null, resource: null,
    }],
  }, { id: 2, name: 'Done', position: 1, is_done_stage: true, tasks: [] }],
  members: [{ id: 1, name: 'Ravi Kumar', initials: 'RK', phone: '919876500001', role: 'owner',
              active: true, whatsapp_verified: true, portal_active: true }],
  me: 1,
  whatsapp: { plan_allowed: false, master_on: false, has_key: false, enabled: false },
};

const settle = (ms = 60) => new Promise(r => setTimeout(r, ms));

// The board's task cards — matched on the class *token*, because .col-cards
// (each column's list wrapper) otherwise wins a substring search.
const taskCard = env => env.root.find(n => String(n.attrs.class || '').split(' ').includes('card'))[0];

// Anything in the emoji/pictograph blocks — the app uses inline SVG instead.
const EMOJI = /[\u{1F000}-\u{1FAFF}\u{1F1E6}-\u{1F1FF}\u{2600}-\u{26FF}\u{2700}-\u{27BF}\u{FE0F}\u{2B00}-\u{2BFF}]/u;
/** modals/drawers append to <body>, so the board root alone is not enough */
const uiText = env => [...collectStrings(env.root), ...collectStrings(env.document?.body || { children: [] })];
const collectStrings = (node, acc = []) => {
  if (node._text) acc.push(node._text);
  for (const c of node.children || []) collectStrings(c, acc);
  return acc;
};

/* ----------------------------------------------------------------- cases --- */

let failed = 0;
const check = (name, fn) => {
  try { fn(); console.log(`  \x1b[32m✓\x1b[0m ${name}`); }
  catch (e) { failed++; console.log(`  \x1b[31m✗\x1b[0m ${name}\n      ${String(e.message).split('\n')[0]}`); }
};

// 1 — embedded and healthy: the board renders, token goes out on both headers.
{
  const env = makeEnv({ shopify: { idToken: async () => 'a.b.c' }, fetchImpl: reply(200, okBoard) });
  await settle();
  check('embedded + 200: board renders, no error screen', () => {
    const text = env.root.text();
    assert.ok(text.includes('To Do'), 'column not rendered: ' + text.slice(0, 160));
    assert.ok(!/could not be loaded|Something went wrong/.test(text), text.slice(0, 160));
  });
  check('session token is sent as Bearer *and* the twin header', () => {
    const h = env.calls[0].init.headers;
    assert.equal(h.Authorization, 'Bearer a.b.c');
    assert.equal(h['X-TaskPe-Auth'], 'a.b.c');
    assert.equal(h.Accept, 'application/json');
  });
  check('same-origin APP_URL → XHRs stay relative (no CORS, no stale host)', () => {
    assert.ok(env.calls[0].url.startsWith('/api/board'), 'got ' + env.calls[0].url);
  });
}

// 2 — APP_URL configured to another host is honoured, not silently rewritten.
{
  const env = makeEnv({
    taskpe: { appUrl: 'https://app.old-domain.com' },
    shopify: { idToken: async () => 'a.b.c' },
    fetchImpl: reply(200, okBoard),
  });
  await settle();
  check('cross-host APP_URL is respected', () => {
    assert.ok(env.calls[0].url.startsWith('https://app.old-domain.com/api/board'), 'got ' + env.calls[0].url);
  });
}

// 3 — the reported bug: the app URL opened as a plain link (no App Bridge).
{
  const env = makeEnv({});
  await settle();
  check('standalone load: explains how to get in, never calls the API', () => {
    const text = env.root.text();
    assert.equal(env.calls.length, 0, 'API should not be hit without a session');
    assert.ok(text.includes('Open TaskPe from your Shopify admin'), text.slice(0, 160));
    const staffLink = env.root.find(n => n.tagName === 'A' && (n.attrs.href || '').endsWith('/staff'))[0];
    assert.ok(staffLink, 'must offer the staff board instead');
    assert.ok(!/Something went wrong/.test(text), 'the generic sentence must be gone');
  });
  check('standalone load: store domain field starts OAuth (validated + normalised)', () => {
    const field = env.root.find(n => n.attrs.id === 'gate-shop')[0];
    assert.ok(field, 'shop input missing');
    field.value = 'My-Store.myshopify.com/';
    env.root.find(n => n.tagName === 'BUTTON' && n.text().includes('Connect store'))[0].click();
    assert.equal(env.navigations.at(-1), '/auth/shopify?shop=my-store.myshopify.com');

    field.value = 'evil.example.com';
    env.root.find(n => n.tagName === 'BUTTON' && n.text().includes('Connect store'))[0].click();
    assert.ok(!env.navigations.some(u => String(u).includes('evil.example.com')), 'must not open-redirect');
  });
}

// 4 — App Bridge works, but the web server ate the Authorization header.
{
  let attempts = 0;
  const env = makeEnv({
    shopify: { idToken: async () => 'a.b.c' },
    fetchImpl: async () => {
      attempts++;
      return { status: 401, ok: false, json: async () => ({ error: 'missing_session_token', message: 'No Shopify session token reached the server.' }) };
    },
  });
  await settle(1000);                                   // retry is debounced ~700ms
  check('stripped Authorization header is named as the cause', () => {
    const text = env.root.text();
    assert.ok(text.includes('stripping the Authorization header'), text.slice(0, 240));
    assert.ok(text.includes('DEPLOYMENT.md'), 'should point at the fix');
  });
  check('missing token retries exactly once, then explains (no loop)', () => {
    assert.equal(attempts, 2, 'expected 1 try + 1 retry, got ' + attempts);
  });
}

// 5 — a genuine server-side failure (e.g. DB not migrated) surfaces verbatim.
{
  const env = makeEnv({
    taskpe: { embedded: true, shop: 'demo.myshopify.com' },
    shopify: { idToken: async () => 'a.b.c' },
    fetchImpl: reply(500, { message: "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'taskpe.shops' doesn't exist" }),
  });
  await settle();
  check('HTTP 500 shows the server message and where to look', () => {
    const text = env.root.text();
    assert.ok(text.includes('taskpe.shops'), 'real cause must be visible: ' + text.slice(0, 240));
    assert.ok(text.includes('500'), 'status code should be shown');
    assert.ok(text.includes('php artisan migrate'), text.slice(0, 240));
    assert.ok(text.includes('storage/logs/laravel.log'), text.slice(0, 240));
  });
}

// 6 — 200 but not a board payload (cached HTML, proxy error page…).
{
  const env = makeEnv({ shopify: { idToken: async () => 'a.b.c' }, fetchImpl: reply(200, { message: 'ok' }) });
  await settle();
  check('non-board 200 is reported as a payload problem, not a TypeError', () => {
    assert.ok(env.root.text().includes('/api/board'), env.root.text().slice(0, 200));
  });
}

// 6b — the nav itself: Shopify-admin section tabs, icon+label, zero emoji.
{
  const env = makeEnv({ shopify: { idToken: async () => 'a.b.c' }, fetchImpl: reply(200, okBoard) });
  await settle();
  check('section nav is a tab list (no app brand bar)', () => {
    const nav = env.root.find(n => n.attrs.class === 'appnav')[0];
    assert.ok(nav, 'appnav row missing');
    const tabs = env.root.find(n => n.attrs.class && String(n.attrs.class).split(' ').includes('tab'));
    assert.deepEqual(tabs.map(t => t.text()), ['Board', 'Team', 'Settings', 'Plan'], 'wrong tab labels');
    assert.ok(!env.root.find(n => n.attrs.class === 'brand-badge').length, 'app brand bar must be gone');
  });
  check('active tab is marked for AT (aria-selected)', () => {
    const tabs = env.root.find(n => n.attrs.class && String(n.attrs.class).split(' ').includes('tab'));
    assert.equal(tabs[0].attrs['aria-selected'], 'true');
    assert.equal(tabs[1].attrs['aria-selected'], 'false');
    assert.ok(String(tabs[0].attrs.class).includes('is-active'));
  });
  check('every tab carries an SVG icon, not a glyph', () => {
    for (const t of env.root.find(n => n.attrs.class && String(n.attrs.class).split(' ').includes('tab'))) {
      const svg = t.children.find(c => c.tagName === 'SVG');
      assert.ok(svg, 'tab missing <svg>: ' + t.text());
      assert.equal(svg.attrs.stroke, 'currentColor');
      assert.equal(svg.attrs['stroke-width'], '1.7');
    }
  });
  check('no emoji anywhere in the rendered UI (board view)', () => {
    const bad = uiText(env).filter(t => EMOJI.test(t));
    assert.deepEqual(bad, [], 'emoji found: ' + JSON.stringify(bad.slice(0, 4)));
  });
}

// 6b2 — the task drawer and the template picker: every icon button in there.
{
  const env = makeEnv({ shopify: { idToken: async () => 'a.b.c' }, fetchImpl: reply(200, okBoard) });
  await settle();
  const card = taskCard(env);
  card.click();
  await settle();
  check('task drawer renders with icons, no emoji', () => {
    assert.ok(env.root.text().includes('Checklist'), 'the drawer did not open at all');
    const bad = uiText(env).filter(t => EMOJI.test(t));
    assert.deepEqual(bad, [], 'emoji in drawer: ' + JSON.stringify(bad.slice(0, 4)));
    const labels = env.root.find(n => n.tagName === 'BUTTON').map(b => b.attrs['aria-label']).filter(Boolean);
    assert.ok(labels.length, 'icon-only buttons need accessible names');
  });
  const tmpl = env.root.find(n => n.tagName === 'BUTTON' && n.text().includes('COD / NDR task templates'))[0];
  tmpl.click();
  await settle();
  check('template picker renders with icons, no emoji', () => {
    const bad = uiText(env).filter(t => EMOJI.test(t));
    assert.deepEqual(bad, [], 'emoji in template picker: ' + JSON.stringify(bad.slice(0, 4)));
    const icon = [...env.root.find(n => n.attrs.class === 'tmpl-icon'),
                  ...env.document.body.find(n => n.attrs.class === 'tmpl-icon')][0];
    assert.ok(icon && icon.children[0].tagName === 'SVG', 'template card should carry an SVG icon');
  });
}

// 6c — the other views too (tabs are the only shared surface, so check bodies).
{
  for (const view of ['team', 'settings', 'plan']) {
    const env = makeEnv({ shopify: { idToken: async () => 'a.b.c' }, fetchImpl: reply({ '/api/settings': okSettings }) });
    await settle();
    const tabs = env.root.find(n => n.attrs.class && String(n.attrs.class).split(' ').includes('tab'));
    tabs.find(t => t.text() === view[0].toUpperCase() + view.slice(1)).click();
    check(`no emoji in the ${view} view`, () => {
      const bad = uiText(env).filter(t => EMOJI.test(t));
      assert.deepEqual(bad, [], 'emoji found: ' + JSON.stringify(bad.slice(0, 4)));
    });
  }
}

// 6d — keyboard shortcuts: the board is the same three clicks over and over,
// so `n` / `t` / `1-4` / `?` / Esc are contract, not decoration.
{
  const env = makeEnv({ shopify: { idToken: async () => 'a.b.c' }, fetchImpl: reply(200, okBoard) });
  await settle();
  const tabs = () => env.root.find(n => n.attrs.class && String(n.attrs.class).split(' ').includes('tab'));
  const overlays = () => env.document.body.children.filter(c => String(c.className).split(' ').includes('overlay'));

  check('n opens the add-task editor in the first open column', () => {
    assert.ok(env.document.listeners.keydown, 'the SPA never bound a keydown handler');
    env.document.fire('keydown', { key: 'n' });
  });
  await settle();
  check('…and the editor is a focused textarea, not an alert() prompt', () => {
    const area = env.root.find(n => n.tagName === 'TEXTAREA')[0];
    assert.ok(area, 'add-task textarea did not open');
    assert.ok(area.attrs.placeholder.includes('Refund'), 'unexpected placeholder: ' + area.attrs.placeholder);
  });
  env.document.fire('keydown', { key: 'Escape' });
  await settle();

  check('? opens the shortcut list, Esc closes it again', () => {
    env.document.fire('keydown', { key: '?' });
    assert.equal(overlays().length, 1, 'help modal did not open');
    assert.ok(overlays()[0].text().includes('Add a task to the first open column'), 'shortcut list is missing its rows');
    env.document.fire('keydown', { key: 'Escape' });
    assert.equal(overlays().length, 0, 'Esc must remove the topmost dialog');
  });
  await settle();

  check('1-4 switch sections (no mouse needed)', () => {
    env.document.fire('keydown', { key: '2' });
    assert.equal(tabs()[1].attrs['aria-selected'], 'true', 'Team tab not activated');
    env.document.fire('keydown', { key: '1' });
    assert.equal(tabs()[0].attrs['aria-selected'], 'true', 'Board tab not restored');
  });
  await settle();

  // Re-open the editor (the flags are one-shot by design), then type in it.
  env.document.fire('keydown', { key: 'n' });
  await settle();
  const area = env.root.find(n => n.tagName === 'TEXTAREA')[0];
  check('shortcuts are inert while typing (an "n" in a title is not a command)', () => {
    assert.ok(area && area.tagName === 'TEXTAREA', 'add-task editor did not reopen');
    area.value = 'Send a refund note abo';
    env.document.fire('keydown', { key: '2', target: area });
    assert.equal(tabs()[0].attrs['aria-selected'], 'true', 'view changed while typing in a textarea');
  });

  // `c` acts on whatever the drawer has open — no confirm dialog, no modal.
  const card = taskCard(env);
  assert.ok(card, 'no task card rendered');
  card.click();
  await settle();
  assert.ok(env.root.text().includes('Checklist'), 'the drawer must be open before c can act on it');
  env.document.fire('keydown', { key: 'c' });
  await settle();
  check('c completes the task open in the drawer', () => {
    const posts = env.calls.filter(c => c.url.endsWith('/api/tasks/11/complete') && c.init?.method === 'POST');
    assert.equal(posts.length, 1, 'expected exactly one complete call, got ' + posts.length);
  });
  check('t opens the template pack modal, and it is dismissed by Esc', () => {
    env.document.fire('keydown', { key: 't' });
    assert.equal(overlays().length, 1, 'template modal did not open from the keyboard');
    env.document.fire('keydown', { key: 'Escape' });
    assert.equal(overlays().length, 0);
  });
}

// 7 — staff portal: cookie path, and an expired session bounces to sign-in.
{
  const env = makeEnv({
    taskpe: { staff: { id: 3, name: 'Ravi', initials: 'R' } },
    fetchImpl: reply(401, { error: 'staff_auth', message: 'Staff session expired' }),
  });
  await settle();
  check('staff portal hits /staff/api/board and redirects on expiry', () => {
    assert.ok(env.calls[0].url.startsWith('/staff/api/board'), 'got ' + env.calls[0].url);
    assert.ok(env.navigations.includes('/staff'), JSON.stringify(env.navigations));
  });
}

console.log(failed ? `\n\x1b[31m${failed} check(s) failed\x1b[0m` : '\n\x1b[32mall SPA boot checks passed\x1b[0m');
process.exit(failed ? 1 : 0);
