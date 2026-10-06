/**
 * Bundle check for the six Shopify Admin extensions — the real bundler, no Partner auth:
 *
 *     node tests/js/extensions.bundle.mjs     # or: npm run test:bundle
 *
 * `shopify app build` bundles each extension with esbuild and rejects anything over
 * 64 KB. This runs the same bundler the same way (per-extension `tsconfig.json` drives
 * the JSX runtime, exactly as in the CLI) so a broken or oversized extension is caught
 * on a laptop instead of in a deploy log, and without the network or a Shopify login.
 *
 * Beyond resolution and size it answers the question a syntax check cannot: which JSX
 * factory ended up in the output. That is why the shared files carry `@jsx` pragmas —
 * esbuild reads a tsconfig only for .ts/.tsx inputs, so `jsxImportSource` never reaches
 * a .jsx living outside the extension folder, and the build would otherwise ship a
 * bundle that references React.
 *
 * It also asserts the thing a per-folder `npm install` gets wrong: `preact` must appear
 * in the graph exactly once, because two copies means `preact/hooks` state split across
 * renderers and the modal silently stops re-rendering.
 *
 * Needs the devDependencies installed: `npm install` in this repo root.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '../..');
const EXT_DIR = path.join(ROOT, 'extensions');
const LIMIT = 64 * 1024;                    // Shopify's bundle ceiling for UI extensions

let esbuild;
try {
  esbuild = await import(path.join(ROOT, 'node_modules/esbuild/lib/main.js'));
} catch {
  console.error(
    '\nesbuild is not installed — run `npm install` in the repo root first.\n'
    + 'This check is optional; `shopify app build` does the same bundling plus signing.\n');
  process.exit(1);
}

const GREEN = '\x1b[32m', RED = '\x1b[31m', DIM = '\x1b[2m', OFF = '\x1b[0m';
const kb = n => `${(n / 1024).toFixed(1)} KiB`;
let failed = 0;

const dirs = fs.readdirSync(EXT_DIR, { withFileTypes: true })
  .filter(d => d.isDirectory() && d.name.startsWith('taskpe-'))
  .map(d => d.name)
  .sort();

console.log('\nShopify Admin extensions — bundle check (esbuild ' + esbuild.version + ')\n');

for (const name of dirs) {
  const dir = path.join(EXT_DIR, name);
  const entry = fs.readdirSync(path.join(dir, 'src')).find(f => /\.(jsx|js)$/.test(f));
  if (!entry) {
    console.log(`  ${RED}✗${OFF} ${name}: no entry in src/`);
    failed++;
    continue;
  }
  try {
    const result = await esbuild.build({
      entryPoints: [path.join(dir, 'src', entry)],
      absWorkingDir: dir,          // so esbuild finds THIS extension's tsconfig.json
      bundle: true,
      format: 'esm',
      minify: true,
      target: 'es2020',
      write: false,
      metafile: true,
      logLevel: 'silent',
    });
    const bytes = result.outputFiles.reduce((n, f) => n + f.contents.length, 0);
    const out = result.outputFiles[0] ? result.outputFiles[0].text : '';
    // esbuild's default JSX factory is React.createElement, and it only reads a
    // tsconfig for .ts/.tsx inputs — a .jsx file outside the extension folder (our
    // extensions/shared/*.jsx) gets NOTHING from our tsconfig. The bundle then
    // compiles clean, uploads fine, and throws "React is not defined" the moment a
    // merchant opens the modal. The @jsx pragmas in each shared file are what pin the
    // factory to preact; this is the check that proves they are still there.
    const reactRef = /\bReact\s*\.\s*(createElement|Fragment|__spread)/.test(out);
    const inputs = Object.keys(result.metafile.inputs);
    const runtimes = inputs.filter(p => p.includes('node_modules/preact/'));
    const signals = inputs.filter(p => p.includes('node_modules/@preact/signals'));
    const over = bytes > LIMIT;
    const dup = runtimes.length === 0 || signals.length === 0;
    // Each input path is relative to absWorkingDir, so the text before
    // "node_modules/preact/" IS the tree the runtime came from. Two trees = two runtimes.
    const trees = new Set(runtimes.map(rel => rel.slice(0, rel.indexOf('node_modules'))));
    const ok = !over && !dup && trees.size === 1 && !reactRef;
    if (!ok) failed++;
    console.log(`  ${ok ? GREEN + '✓' : RED + '✗'}${OFF} ${name}`
      + ` — ${kb(bytes)} ${DIM}${inputs.length} modules · preact from ${trees.size} tree, `
      + `signals ${signals.length ? 'ok' : 'MISSING'}${OFF}`);
    if (over) console.log(`      ${RED}over the 64 KiB limit${OFF} — trim markup/strings or move logic server-side`);
    if (runtimes.length === 0) console.log(`      ${RED}preact was not bundled at all — the entry is not importing the runtime${OFF}`);
    if (signals.length === 0) console.log(`      ${RED}@preact/signals missing — "@shopify/ui-extensions/preact" imports it and npm treats it as optional${OFF}`);
    if (reactRef) {
      console.log(`      ${RED}the bundle calls React.createElement — JSX was not compiled with preact's factory${OFF}`);
      console.log(`      ${DIM}→ every file with JSX needs /* @jsxRuntime classic */ + /** @jsx h */ + the h import (see extensions/shared/*)${OFF}`);
      failed++;
    }
    if (trees.size > 1) {
      console.log(`      ${RED}preact came from ${trees.size} node_modules trees (${[...trees].join(' | ')}) `
        + '— delete extensions/*/node_modules and install at the repo root${OFF}'.replace('${OFF}', OFF));
      failed++;
    }
  } catch (e) {
    failed++;
    console.log(`  ${RED}✗${OFF} ${name}`);
    const text = (e && e.errors ? e.errors : [{ text: String(e && e.message || e) }])
      .map(x => (x.text || '') + (x.location ? ` (${x.location.file}:${x.location.line})` : ''));
    console.log(`      ${text.join('\n      ')}`);
    if (/Could not resolve "react\/jsx-runtime"/.test(text.join(' '))) {
      console.log(`      ${DIM}→ that is the tsconfig.json check: jsxImportSource must be "preact"${OFF}`);
    }
    if (/Could not resolve "preact/.test(text.join(' '))) {
      console.log(`      ${DIM}→ run npm install in the repo root; extensions/shared/ resolves preact from there${OFF}`);
    }
  }
}

console.log(failed
  ? `\n${RED}${failed} extension bundle(s) failed${OFF}\n`
  : `\n${GREEN}all six extensions bundle clean${OFF}  ${DIM}deploy with: shopify app deploy${OFF}\n`);
process.exit(failed ? 1 : 0);
