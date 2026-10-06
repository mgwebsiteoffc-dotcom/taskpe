/**
 * Static checks for the six Shopify Admin extensions — no build, no deps, just node:
 *
 *     node tests/js/extensions.static.mjs     # or: npm run test:extensions
 *
 * `shopify app build` runs esbuild inside Shopify CLI, which this repo cannot invoke
 * (no network, no Partner auth). Almost every failure it produces, though, is a
 * missing line in a file we DO own, so it is checked here instead:
 *
 *   "Could not resolve "preact""            → extensions/shared/*.jsx lives outside
 *                                              every extension folder, so nothing in the
 *                                              tree had to declare preact. Now the root
 *                                              package.json is a workspace root.
 *   "Could not resolve "react/jsx-runtime"" → no tsconfig.json, so esbuild used React's
 *                                              automatic JSX runtime. Each extension now
 *                                              carries the template's tsconfig with
 *                                              jsxImportSource: "preact".
 *
 * These checks also keep the two halves of the version story together: `api_version`
 * in each TOML and the `@shopify/ui-extensions` range must move as one, and one hoisted
 * copy of preact must be the only copy in any bundle (two copies = broken hooks).
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const EXT_DIR = path.join(ROOT, 'extensions');
const read = p => fs.readFileSync(p, 'utf8');
const exists = p => fs.existsSync(p);
const rel = p => path.relative(ROOT, p).split(path.sep).join('/');

const GREEN = '\x1b[32m', RED = '\x1b[31m', DIM = '\x1b[2m', OFF = '\x1b[0m';
let failed = 0;
const check = (name, fn) => {
  try {
    fn();
    console.log(`  ${GREEN}\u2713${OFF} ${name}`);
  } catch (e) {
    failed++;
    console.log(`  ${RED}\u2717${OFF} ${name}`);
    console.log(`      ${String(e.message).split('\n').join('\n      ')}`);
  }
};
const warn = msg => console.log(`  ${DIM}\u26a0 ${msg}${OFF}`);

/* --------------------------------------------------------------- fixtures --- */

const extDirs = fs.readdirSync(EXT_DIR, { withFileTypes: true })
  .filter(d => d.isDirectory() && d.name.startsWith('taskpe-'))
  .map(d => path.join(EXT_DIR, d.name))
  .sort();

const exts = extDirs.map(dir => {
  const tomlPath = path.join(dir, 'shopify.extension.toml');
  const toml = exists(tomlPath) ? read(tomlPath) : '';
  const pick = re => (toml.match(re) || [, null])[1];
  const pkg = exists(path.join(dir, 'package.json'))
    ? JSON.parse(read(path.join(dir, 'package.json'))) : {};
  const tsconfig = exists(path.join(dir, 'tsconfig.json'))
    ? JSON.parse(read(path.join(dir, 'tsconfig.json'))) : null;
  const srcDir = path.join(dir, 'src');
  const entries = exists(srcDir)
    ? fs.readdirSync(srcDir).filter(f => /\.(jsx|js)$/.test(f)).map(f => path.join(srcDir, f)) : [];
  return {
    name: path.basename(dir),
    dir,
    toml,
    handle: pick(/handle\s*=\s*"([^"]*)"/),
    type: pick(/^type\s*=\s*"([^"]*)"/m),
    apiVersion: pick(/^api_version\s*=\s*"([^"]*)"/m),
    module: pick(/module\s*=\s*"([^"]*)"/),
    target: pick(/target\s*=\s*"([^"]*)"/),
    networkAccess: /network_access\s*=\s*true/.test(toml),
    pkg,
    deps: { ...(pkg.dependencies || {}), ...(pkg.devDependencies || {}) },
    tsconfig,
    entries,
    locales: path.join(dir, 'locales', 'en.default.json'),
  };
});

const rootPkg = JSON.parse(read(path.join(ROOT, 'package.json')));
const rootDeps = { ...(rootPkg.dependencies || {}), ...(rootPkg.devDependencies || {}) };

/** Every source file under extensions/ that the bundler may pull in. */
const allSources = (function walk(dir, acc = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (e.isDirectory() && e.name !== 'node_modules') walk(path.join(dir, e.name), acc);
    else if (/\.(jsx|js)$/.test(e.name)) acc.push(path.join(dir, e.name));
  }
  return acc;
})(EXT_DIR).sort();

const IMPORT_RE = /(?:^|\n)\s*(?:import|export)\b[^;'"]*?from\s*["']([^"']+)["']|(?:^|\n)\s*import\s*["']([^"']+)["']/g;
/** Every module specifier a file imports, relative or not. */
const importsOf = file => {
  const src = read(file);
  const out = [];
  let m;
  while ((m = IMPORT_RE.exec(src))) out.push(m[1] || m[2]);
  IMPORT_RE.lastIndex = 0;
  return out;
};
const bareImports = file => importsOf(file)
  .filter(s => !s.startsWith('.') && !s.startsWith('/') && !s.startsWith('node:'));
/** 'preact/hooks' -> 'preact', '@shopify/ui-extensions/preact' -> '@shopify/ui-extensions' */
const pkgName = spec => spec.startsWith('@') ? spec.split('/').slice(0, 2).join('/') : spec.split('/')[0];

/**
 * Which packages an import from `file` can resolve, following npm's own walk:
 * nearest package.json first, then every parent up to the repo root — that parent
 * chain is exactly what the workspace install hoists into <root>/node_modules.
 */
const resolvableFrom = file => {
  const set = new Set();
  let dir = path.dirname(file);
  for (;;) {
    const pj = path.join(dir, 'package.json');
    if (exists(pj)) {
      let p;
      try { p = JSON.parse(read(pj)); } catch { p = {}; }
      for (const k of Object.keys({ ...(p.dependencies || {}), ...(p.devDependencies || {}) })) set.add(k);
      if (dir === ROOT) break;
    }
    const parent = path.dirname(dir);
    if (parent === dir) break;
    dir = parent;
  }
  return set;
};

/* ------------------------------------------------------------------ cases --- */

console.log('\nShopify Admin extensions — static checks\n');

check('six extension folders, each with toml + package.json + tsconfig + one entry', () => {
  assert.equal(exts.length, 6, `expected 6 folders in extensions/, found ${exts.length}`);
  for (const e of exts) {
    assert.ok(e.toml, `${e.name}: no shopify.extension.toml`);
    assert.ok(e.pkg.name, `${e.name}: package.json has no "name"`);
    assert.ok(e.tsconfig, `${e.name}: no tsconfig.json — esbuild will ask for "react/jsx-runtime"`);
    assert.equal(e.entries.length, 1, `${e.name}: expected exactly one entry in src/, got ${e.entries.length}`);
    assert.equal(e.pkg.name, e.name, `${e.name}: package.json name must match the folder`);
    assert.equal(e.handle, e.name, `${e.name}: toml handle must match the folder`);
    assert.ok(exists(e.locales), `${e.name}: locales/en.default.json missing (toml name = "t:name")`);
  }
});

// The regression that broke `shopify app build` for every extension at once.
check('tsconfig pins the JSX runtime to preact (not React)', () => {
  for (const e of exts) {
    const co = (e.tsconfig && e.tsconfig.compilerOptions) || {};
    assert.equal(co.jsx, 'react-jsx', `${e.name}: compilerOptions.jsx must be "react-jsx"`);
    assert.equal(co.jsxImportSource, 'preact',
      `${e.name}: compilerOptions.jsxImportSource must be "preact", else the bundle imports `
      + `react/jsx-runtime, which is not installed on purpose`);
  }
});

check('every entry loads the extension runtime before anything else', () => {
  for (const e of exts) {
    const src = read(e.entries[0]);
    const lines = src.split('\n').filter(l => l.trim() && !l.trim().startsWith('//') && !l.trim().startsWith('*'));
    assert.ok(/^import\s*["']@shopify\/ui-extensions\/preact["'];?\s*$/.test(lines[0]),
      `${e.name}: first import must be "@shopify/ui-extensions/preact" (registers the s-* `
      + `custom elements and the global shopify object). Found: ${lines[0]}`);
    assert.ok(/export default/.test(src), `${e.name}: entry needs a default export for the runtime`);
  }
});

// What the CLI cannot see: shared/ sits outside every extension folder, so an import
// inside it resolves from extensions/shared/ upward — the root package.json is now in
// that chain. If a dependency is declared nowhere, esbuild says "Could not resolve".
check('every bare import in extensions/** is declared in a package.json up its tree', () => {
  const unmet = [];
  for (const file of allSources) {
    const ok = resolvableFrom(file);
    for (const spec of bareImports(file)) {
      if (!ok.has(pkgName(spec))) {
        unmet.push(`${rel(file)} -> "${spec}" (package "${pkgName(spec)}" declared nowhere up to the repo root)`);
      }
    }
  }
  assert.deepEqual(unmet, [], 'unresolvable imports:\n' + unmet.join('\n'));
});

check('preact is declared once, at one range (two copies = broken hooks)', () => {
  const ranges = new Map();
  for (const e of exts) {
    const r = e.deps.preact || '(missing)';
    ranges.set(r, [...(ranges.get(r) || []), e.name]);
    assert.ok(e.deps.preact, `${e.name}: declares no preact — it cannot render`);
  }
  if (rootDeps.preact) ranges.set(rootDeps.preact, [...(ranges.get(rootDeps.preact) || []), '(root)']);
  assert.equal(ranges.size, 1,
    `preact ranges differ and will not dedupe to one hoisted copy: `
    + [...ranges].map(([r, who]) => `${r} <- ${who.join(', ')}`).join(' | '));
  assert.match(String([...ranges.keys()][0]).replace(/[^\d.]/g, ''), /^10/, 'preact must be v10 (v11 changed the JSX runtime exports)');
});

// `@shopify/ui-extensions/preact` imports `@preact/signals`, and that peer is flagged
// optional in the package's own metadata — so npm will not fetch it for you. Omit it
// and the build fails inside node_modules with "Could not resolve \"@preact/signals\"".
check('every extension declares the runtime trio, at the root\u2019s ranges', () => {
  const trio = ['preact', '@preact/signals', '@shopify/ui-extensions'];
  for (const e of exts) {
    for (const name of trio) {
      assert.ok(e.deps[name],
        `${e.name}: package.json is missing "${name}" — the preact runtime needs all three`);
      const mine = String(e.deps[name]).replace(/\.x$/, '');
      const root = String(rootDeps[name]).replace(/\.x$/, '');
      assert.equal(mine, root,
        `${e.name}: ${name}@${e.deps[name]} differs from the root ${rootDeps[name]} — `
        + 'two ranges can install two copies, and two copies of preact means dead hooks');
    }
  }
  assert.ok(exists(path.join(ROOT, 'package-lock.json')),
    'commit package-lock.json: extension bundles are built on a laptop, and a lockfile is the '
    + 'only thing that makes "shopify app build" reproduce between two machines');
});

check('every file with JSX pins the factory to preact', () => {
  // esbuild reads a tsconfig for .ts/.tsx inputs only — so `jsxImportSource` never
  // reaches a .jsx file, and the fallback is React.createElement: a bundle that
  // compiles, deploys, then throws "React is not defined" in front of the merchant.
  // The per-file pragma is the one thing the bundler cannot miss.
  const jsxFiles = allSources.filter(f => /<\/(s-[a-z-]+|div|span|button|p|label)>/.test(read(f)));
  assert.ok(jsxFiles.length >= 3, `expected the shared components to be found, saw ${jsxFiles.length}`);
  for (const f of jsxFiles) {
    const src = read(f);
    assert.ok(/@jsxRuntime\s+classic/.test(src) && /@jsx\s+h\b/.test(src),
      `${rel(f)}: needs /* @jsxRuntime classic */ + /** @jsx h */ above the preact import`);
    assert.match(src, /import\s*\{[^}]*\bh\b[^}]*\}\s*from\s*["']preact["']/,
      `${rel(f)}: @jsx h points at an "h" that is not imported from preact`);
  }
});

check('the extensions are npm workspaces of the repo root (shared/ needs it)', () => {
  assert.ok(Array.isArray(rootPkg.workspaces),
    'root package.json needs "workspaces": ["extensions/taskpe-*"] so ONE hoisted node_modules '
    + 'can serve extensions/shared/*.jsx, which is outside every extension folder');
  assert.deepEqual(rootPkg.workspaces, ['extensions/taskpe-*'],
    'the workspace glob must not match extensions/shared (no package.json there)');
  assert.ok(rootPkg.devDependencies && rootPkg.devDependencies.preact,
    'root devDependencies must declare preact for extensions/shared to resolve');
});

check('@shopify/ui-extensions range tracks api_version in every toml', () => {
  const versions = new Set(exts.map(e => e.apiVersion));
  assert.equal(versions.size, 1, `api_version differs between extensions: ${[...versions].join(', ')}`);
  const [api] = [...versions];
  assert.match(String(api), /^\d{4}-\d{2}$/, `${api} is not a YYYY-MM api_version`);
  const envExample = read(path.join(ROOT, '.env.example'));
  const appApi = (envExample.match(/^SHOPIFY_API_VERSION=(.+)$/m) || [, null])[1];
  assert.equal(appApi, api, `SHOPIFY_API_VERSION (${appApi}) in .env.example must equal api_version (${api})`);
  for (const e of exts) {
    const range = e.deps['@shopify/ui-extensions'] || (rootPkg.devDependencies || {})['@shopify/ui-extensions'];
    assert.ok(range, `${e.name}: no @shopify/ui-extensions dependency to type api_version ${api}`);
    const [y, mo] = String(api).split('-');
    assert.ok(new RegExp(`${y}\\.${Number(mo)}\\.x?$`).test(String(range)),
      `${e.name}: api_version ${api} but @shopify/ui-extensions@${range} — bump them together`);
  }
});

check('targets are real admin targets and the module path exists', () => {
  const allowed = new Set([
    'admin.order-details.action.render',
    'admin.draft-order-details.action.render',
    'admin.product-details.action.render',
    'admin.customer-details.action.render',
    'admin.order-index.selection-action.render',
    'admin.order-details.block.render',
  ]);
  const seen = new Set();
  for (const e of exts) {
    assert.ok(allowed.has(e.target), `${e.name}: unknown target "${e.target}" — check the toml comment list`);
    assert.ok(!seen.has(e.target), `target "${e.target}" used twice (${e.name})`);
    seen.add(e.target);
    assert.ok(e.type === 'ui_extension', `${e.name}: type must be ui_extension`);
    if (e.target.endsWith('.block.render')) {
      assert.ok(!e.toml.includes('s-modal'), `${e.name}: s-modal is not available in admin block targets`);
    } else {
      assert.ok(e.networkAccess, `${e.name}: needs [extensions.capabilities] network_access = true to call TaskPe`);
    }
    const mod = path.join(e.dir, e.module.replace(/^\.\//, ''));
    assert.ok(exists(mod), `${e.name}: toml points at ${e.module}, which does not exist`);
  }
});

check('locales carry the keys the toml resolves through', () => {
  for (const e of exts) {
    const locale = JSON.parse(read(e.locales));
    assert.ok(locale.name, `${e.name}: locales/en.default.json needs "name" (toml name = "t:name")`);
    if (/description\s*=\s*"t:description"/.test(e.toml)) {
      assert.ok(locale.description, `${e.name}: toml asks for t:description but the locale has no "description"`);
    }
    for (const other of fs.readdirSync(path.join(e.dir, 'locales'))) {
      if (!other.endsWith('.json')) continue;
      const l = JSON.parse(read(path.join(e.dir, 'locales', other)));
      assert.deepEqual(Object.keys(l).sort(), Object.keys(locale).sort(),
        `${e.name}: ${other} has a different key set than en.default.json`);
    }
  }
});

check('the shared APP_URL is an app URL, and the placeholder is still guarded', () => {
  const api = read(path.join(EXT_DIR, 'shared', 'api.js'));
  const url = (api.match(/export const APP_URL\s*=\s*["']([^"']*)["']/) || [, null])[1];
  assert.ok(url, 'extensions/shared/api.js must export APP_URL');
  assert.match(url, /^https:\/\//, `${url}: extensions run inside https://admin.shopify.com — http is blocked`);
  assert.ok(!url.endsWith('/'), `${url}: no trailing slash (the endpoints are appended to it)`);
  assert.ok(/yourdomain|placeholder|CHANGE ME/i.test(url)
    ? /appUrlNotSet/.test(api) : true,
    'a placeholder APP_URL is only safe while appUrlNotSet() exists to banner about it');
  if (/yourdomain/i.test(url)) {
    warn(`APP_URL is still the placeholder (${url}) — edit extensions/shared/api.js before "shopify app deploy". `
      + 'The UI shows its own banner until you do, so this is a warning, not a failure.');
  }
});

check('no emoji in extension source or locale strings', () => {
  const EMOJI = /[\u{1F000}-\u{1FAFF}\u{1F1E6}-\u{1F1FF}\u{2600}-\u{26FF}\u{2700}-\u{27BF}\u{FE0F}\u{2B00}-\u{2BFF}]/u;
  const files = [...allSources, ...extDirs.flatMap(d =>
    fs.readdirSync(path.join(d, 'locales')).map(f => path.join(d, 'locales', f)))];
  const hits = files.filter(f => EMOJI.test(read(f))).map(rel);
  assert.deepEqual(hits, [], `emoji in: ${hits.join(', ')} — the admin UI uses s-* elements and text, never emoji`);
});

// Shopify's bundle ceiling for UI extensions is 64 KB; raw source is the proxy here.
check('source stays inside a sane share of the 64 KB bundle limit', () => {
  const per = exts.map(e => {
    const files = new Set();
    const visit = f => {
      if (files.has(f)) return;
      files.add(f);
      for (const spec of importsOf(f)) {                 // follow ./api.js, ./File.jsx, …
        if (!spec.startsWith('.')) continue;
        const p = path.resolve(path.dirname(f), spec);
        if (exists(p) && fs.statSync(p).isFile()) visit(p);
        else if (exists(path.join(p, 'index.jsx'))) visit(path.join(p, 'index.jsx'));
      }
    };
    visit(e.entries[0]);
    return { name: e.name, bytes: [...files].reduce((n, f) => n + read(f).length, 0) };
  });
  const over = per.filter(p => p.bytes > 64 * 1024);
  console.log(`      ${DIM}${per.map(p => `${p.name} ${(p.bytes / 1024).toFixed(1)} KiB raw`).join(', ')}${OFF}`);
  assert.deepEqual(over.map(o => `${o.name}: ${(o.bytes / 1024).toFixed(1)} KiB of source`), [],
    'minified output is smaller than raw source, but anything near this needs trimming '
    + '(drop a shared file, or move strings server-side)');
});

console.log(failed
  ? `\n${RED}${failed} extension check(s) failed${OFF}\n`
  : `\n${GREEN}all extension static checks passed${OFF}\n  ${DIM}the bundle itself is still verified by: shopify app build${OFF}\n`);
process.exit(failed ? 1 : 0);
