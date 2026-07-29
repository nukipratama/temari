#!/usr/bin/env node

/*
 * Build-output guard: keeps heavy vendor code off the first-paint path.
 *
 * Two regressions this repo has actually shipped, both invisible to every
 * other gate because nothing inspected the built bundle:
 *
 *   1. Rolldown swept `react` into the `charts` chunk and `react-dom` into
 *      `maps`, so every route -- Login included -- statically loaded a chart
 *      and a map engine it never rendered.
 *   2. `bareLayout` shared a module with `appLayout`, so Login's import
 *      closure reached `AppShell` and dragged all of framer-motion onto a
 *      page that animates nothing.
 *
 * Rule 1 (entry allowlist) catches the first class, rule 2 (route budget)
 * the second.
 *
 * Deliberately NOT a denylist of chunk names like "motion"/"charts".
 * Chunk names are not unique: Rolldown names auto-generated shared chunks
 * after one of their member modules, and `resources/js/lib/motion.ts` has
 * already produced a second, unrelated `motion-*.js` alongside the
 * framer-motion vendor chunk. Matching on those names yields both false
 * positives and false negatives. Membership and bytes are unambiguous.
 *
 * Standalone: no bundler, no Laravel. Requires a prior `npm run build`.
 *   node scripts/check-entry-chunks.mjs
 */

import { readFileSync, existsSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const buildDir = path.join(root, 'public/build');
const manifestPath = path.join(buildDir, 'manifest.json');

const ENTRY = 'resources/js/app.tsx';

/*
 * Everything the entry is allowed to pull in synchronously. React must be its
 * own chunk with the highest `advancedChunks` priority for this to hold --
 * see docs/architecture/frontend-architecture.md.
 */
const ENTRY_ALLOWED = ['rolldown-runtime', 'app', 'react-vendor'];

/*
 * Gzipped ceiling for a cold visit to a route: the entry closure plus that
 * page's own. Login is the only page an unauthenticated visitor sees and it
 * renders nothing animated, so it must stay clear of framer-motion (~41KB
 * gzipped on its own).
 *
 * The authenticated routes carry framer-motion legitimately -- AppShell
 * renders it on all of them -- so their budgets sit above that, sized to
 * catch a lazy-only engine turning into a static import: `charts` is ~59KB
 * gzipped and `maps` ~45KB, either of which blows the headroom here. Slow
 * creep below that is deliberately not caught; a budget tight enough to
 * catch it would fire on ordinary feature growth and get raised on sight.
 */
const ROUTE_BUDGETS_KB = [
    { name: 'Login', src: 'resources/js/pages/Auth/Login.tsx', budgetKb: 160 },
    { name: 'HariIni', src: 'resources/js/pages/HariIni.tsx', budgetKb: 240 },
    { name: 'Runs/Show', src: 'resources/js/pages/Runs/Show.tsx', budgetKb: 245 },
    { name: 'Aku', src: 'resources/js/pages/Aku.tsx', budgetKb: 230 },
];

if (!existsSync(manifestPath)) {
    fail([
        `No build manifest at ${path.relative(root, manifestPath)}`,
        '',
        'Run `npm run build` first — this guard inspects the built output.',
    ]);
}

const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));

/** Chunk keys reachable from `startKeys` by static imports only. */
function closure(startKeys) {
    const seen = new Set();
    const stack = [...startKeys];

    while (stack.length > 0) {
        const key = stack.pop();
        if (seen.has(key)) continue;
        seen.add(key);
        for (const imported of manifest[key]?.imports ?? []) stack.push(imported);
    }

    return seen;
}

/** Total raw + gzipped bytes of the JS in a set of chunk keys. */
function weigh(keys) {
    const chunks = [];
    let raw = 0;
    let gz = 0;

    for (const key of keys) {
        const chunk = manifest[key];
        if (!chunk?.file?.endsWith('.js')) continue;
        const bytes = readFileSync(path.join(buildDir, chunk.file));
        const gzipped = gzipSync(bytes, { level: 9 }).length;
        raw += bytes.length;
        gz += gzipped;
        chunks.push({ name: chunk.name ?? chunk.file, file: chunk.file, raw: bytes.length, gz: gzipped });
    }

    chunks.sort((a, b) => b.gz - a.gz);

    return { raw, gz, chunks };
}

const kb = (bytes) => (bytes / 1000).toFixed(1);

function fail(lines) {
    console.error(`\n[31m✗ Entry chunk guard[0m\n`);
    for (const line of lines) console.error(`  ${line}`);
    console.error('');
    process.exit(1);
}

if (!manifest[ENTRY]) {
    fail([`The manifest has no entry for ${ENTRY}. Did the Vite input list change?`]);
}

const problems = [];

// Rule 1 — the entry's static closure may contain nothing but the allowlist.
const entryClosure = closure([ENTRY]);
const entryWeight = weigh(entryClosure);
const strays = entryWeight.chunks.filter((c) => !ENTRY_ALLOWED.includes(c.name));

if (strays.length > 0) {
    problems.push(
        `The entry chunk statically imports ${strays.length} chunk(s) it should not:`,
        ...strays.map((c) => `    • ${c.name}  (${c.file}, ${kb(c.raw)} kB raw / ${kb(c.gz)} kB gzipped)`),
        '',
        '  Every route pays for these on first paint, including Login.',
        '  Allowed in the entry closure: ' + ENTRY_ALLOWED.join(', '),
        '',
        '  Usual cause: a vendor chunk captured React transitively, so the entry',
        '  now reaches it. Give the React group a higher `priority` in',
        '  vite.config.ts, and never express the groups as `manualChunks` —',
        '  Rolldown collapses that into a single priority-0 group.',
        '  Otherwise, something imported statically should be behind `lazy()`.',
    );
}

// Rule 2 — a cold visit to these routes must stay under budget.
for (const route of ROUTE_BUDGETS_KB) {
    if (!manifest[route.src]) {
        problems.push(`Route ${route.name} (${route.src}) is missing from the manifest.`);
        continue;
    }

    const weight = weigh(closure([ENTRY, route.src]));
    const budget = route.budgetKb * 1000;
    if (weight.gz <= budget) continue;

    problems.push(
        `${route.name} first paint is ${kb(weight.gz)} kB gzipped, over its ${route.budgetKb} kB budget by ${kb(weight.gz - budget)} kB.`,
        '  Heaviest chunks in its static closure:',
        ...weight.chunks.slice(0, 5).map((c) => `    • ${c.name}  (${c.file}, ${kb(c.gz)} kB gzipped)`),
        '',
        '  Move whatever is new behind `lazy()`, or split the module that pulls',
        '  it in. Raise the budget only with a measurement that justifies it.',
    );
}

if (problems.length > 0) {
    fail(problems);
}

console.log('Entry chunk guard: first-paint closures within budget ✓');
console.log(`  entry  ${kb(entryWeight.raw)} kB raw / ${kb(entryWeight.gz)} kB gzipped  [${entryWeight.chunks.map((c) => c.name).join(', ')}]`);
for (const route of ROUTE_BUDGETS_KB) {
    const weight = weigh(closure([ENTRY, route.src]));
    console.log(`  ${route.name.padEnd(9)} ${kb(weight.raw)} kB raw / ${kb(weight.gz)} kB gzipped  (budget ${route.budgetKb} kB)`);
}
