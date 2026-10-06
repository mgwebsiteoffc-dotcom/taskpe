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
  append(...nodes) { for (const n of nodes) if (n) this.children.push(n); }
  replaceChildren(...nodes) { this.children = []; this.append(...nodes); }
  addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }
  fire(type, ev = {}) { for (const fn of this.listeners[type] || []) fn({ preventDefault() {}, target: this, ...ev }); }
  remove() {}
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

  const document = {
    readyState: 'complete',
    title: '',
    body,
    createElement: t => new El(t),
    createTextNode: t => Object.assign(new El('#text', 3), { _text: String(t) }),
    // Real-ish: searches the live tree, so handlers that look up their
    // sibling nodes (gate-err, col-name…) behave like they do in a browser.
    getElementById: id => byId[id] || [root, body].map(n => n.find(x => x.attrs.id === id)[0]).find(Boolean) || null,
    addEventListener() {},
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

const reply = (status, data) => async () => ({ status, ok: status >= 200 && status < 300, json: async () => data });

const okBoard = {
  shop: { domain: 'demo.myshopify.com', name: 'Demo Store', plan: 'free', plan_cfg: {}, timezone: 'Asia/Kolkata', currency: 'INR', onboarded: true },
  plans: {}, task_templates: {},
  columns: [{ id: 1, name: 'To Do', position: 0, is_done_stage: false, tasks: [] }],
  members: [], me: null,
  whatsapp: { plan_allowed: false, master_on: false, has_key: false, enabled: false },
};

const settle = (ms = 60) => new Promise(r => setTimeout(r, ms));

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
