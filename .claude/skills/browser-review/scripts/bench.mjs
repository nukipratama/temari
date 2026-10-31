/**
 * Times scripted interactions against the native-feel budgets and prints one row per step.
 *
 * Usage:
 *   node bench.mjs <scenario.mjs> [--runs=5] [--viewport=mobile] [--ground=dark]
 *       [--throttle=fast4g|none] [--label=<name>]
 *
 * A scenario exports `route` and a default `async ({ page, measure }) => { ... }`, where
 * `measure(label, selector, { until })` taps the first match and times the response. Without
 * `until` a step is a navigation and finishes when Inertia is idle and no `.skeleton` is visible;
 * with `until` it finishes when that selector is visible. A scenario that exports `launch = true`
 * is a cold launch instead: each run clears the HTTP cache and loads `route`, then loads it once
 * more warm.
 *
 * Columns, all in ms from the tap's pointerdown (launch: from navigation start):
 *   response  first frame after the DOM changed      budget 100
 *   swap      first frame after inertia:navigate     (no budget; where the new page lands)
 *   done      first frame the step is finished       budget 400
 *   shift     layout shift after the swap            budget 0.05
 *   inp       longest event duration in the step     budget 200
 * Medians and maxima per label go to stdout, every run to storage/app/bench/<label>.json.
 *
 * Service workers are blocked so the CDP throttle covers every request.
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { basename, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromium } from './playwright.mjs';
import { BASE, THROTTLES, VIEWPORT_DEFS, applyThrottle, login } from './lib.mjs';

const BUDGETS = { response: 100, done: 400, shift: 0.05, inp: 200 };
const QUIET_MS = 500;
const STEP_TIMEOUT_MS = 15000;

const args = process.argv.slice(2);
const flag = (name, fallback) => args.find((a) => a.startsWith(`--${name}=`))?.slice(name.length + 3) ?? fallback;
const [scenarioPath] = args.filter((a) => !a.startsWith('--'));
const runs = Number(flag('runs', '5'));
const viewportKey = flag('viewport', 'mobile');
const ground = flag('ground', 'dark');
const throttle = THROTTLES[flag('throttle', 'fast4g')];
const label = flag('label', basename(scenarioPath ?? '', '.mjs'));

if (!scenarioPath || !Number.isInteger(runs) || runs < 1 || !VIEWPORT_DEFS[viewportKey] || !['dark', 'light'].includes(ground) || throttle === undefined) {
    console.error(
        `Usage: node bench.mjs <scenario.mjs> [--runs=5] [--viewport=${Object.keys(VIEWPORT_DEFS).join('|')}] [--ground=dark|light] [--throttle=${Object.keys(THROTTLES).join('|')}] [--label=<name>]`,
    );
    process.exit(2);
}

const scenario = await import(pathToFileURL(resolve(scenarioPath)).href);
const press = VIEWPORT_DEFS[viewportKey].hasTouch ? 'tap' : 'click';

function installProbe() {
    const s = { shifts: [], events: [], lcp: 0, active: 0, step: null };
    window.__bench = s;
    new PerformanceObserver((l) => l.getEntries().forEach((e) => s.shifts.push({ t: e.startTime, v: e.value }))).observe({ type: 'layout-shift', buffered: true });
    new PerformanceObserver((l) => l.getEntries().forEach((e) => s.events.push({ t: e.startTime, d: e.duration }))).observe({ type: 'event', buffered: true, durationThreshold: 16 });
    new PerformanceObserver((l) => l.getEntries().forEach((e) => { s.lcp = e.startTime; })).observe({ type: 'largest-contentful-paint', buffered: true });

    document.addEventListener('inertia:start', () => { s.active++; });
    document.addEventListener('inertia:finish', () => { s.active = Math.max(0, s.active - 1); });
    document.addEventListener('inertia:navigate', () => { if (s.step && s.step.tapAt !== null && s.step.navigateAt === null) s.step.navigateAt = performance.now(); });
    addEventListener('pointerdown', (e) => { if (s.step && s.step.tapAt === null) s.step.tapAt = e.timeStamp; }, true);

    const visible = (el) => {
        const r = el.getBoundingClientRect();
        return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden' && el.closest('[hidden]') === null;
    };
    s.finished = (until) => until
        ? [...document.querySelectorAll(until)].some(visible)
        : s.active === 0
            && document.getElementById('app')?.childElementCount > 0
            && ![...document.querySelectorAll('.skeleton')].some(visible);

    s.begin = (until, origin) => {
        const step = { until, tapAt: origin, mutatedAt: null, navigateAt: null, response: null, swap: null, done: null };
        s.step = step;
        const mo = new MutationObserver(() => { if (step.tapAt !== null && step.mutatedAt === null) step.mutatedAt = performance.now(); });
        mo.observe(document.documentElement, { subtree: true, childList: true, attributes: true, characterData: true });
        const frame = () => {
            const t = performance.now();
            if (step.mutatedAt !== null && step.response === null) step.response = t;
            if (step.navigateAt !== null && step.swap === null) step.swap = t;
            const navigated = until || origin !== null || step.navigateAt !== null;
            if (step.tapAt !== null && step.done === null && navigated && s.finished(until)) step.done = t;
            if (step.done === null) requestAnimationFrame(frame);
            else mo.disconnect();
        };
        requestAnimationFrame(frame);
    };
    s.end = () => {
        const { tapAt, response, swap, done } = s.step;
        const since = (t) => (t === null ? null : Math.round(t - tapAt));
        const after = swap ?? response ?? tapAt;
        return {
            response: since(response),
            swap: since(swap),
            done: since(done),
            shift: Number(s.shifts.filter((x) => x.t >= after).reduce((a, x) => a + x.v, 0).toFixed(4)),
            inp: Math.round(Math.max(0, ...s.events.filter((x) => x.t >= tapAt - 1).map((x) => x.d))),
        };
    };
}

const browser = await chromium.launch({
    executablePath: '/usr/bin/chromium',
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
});

async function openContext() {
    const ctx = await browser.newContext({ ...VIEWPORT_DEFS[viewportKey], serviceWorkers: 'block' });
    await ctx.addInitScript((g) => {
        if (location.protocol.startsWith('http')) localStorage.setItem('temari-theme', g);
    }, ground);
    await ctx.addInitScript(installProbe);
    const page = await ctx.newPage();
    page.on('pageerror', (e) => console.error(`[pageerror] ${e.message}`));
    await login(page);
    const cdp = await ctx.newCDPSession(page);
    await applyThrottle(cdp, throttle);
    return { ctx, page, cdp };
}

async function settle(page) {
    await page.waitForFunction(() => window.__bench?.finished(null), null, { timeout: STEP_TIMEOUT_MS });
    await page.waitForTimeout(QUIET_MS);
}

const rows = [];

async function measure(page, run, step, selector, { until } = {}) {
    await page.locator(selector).first().waitFor({ timeout: STEP_TIMEOUT_MS });
    await page.evaluate(([u]) => window.__bench.begin(u, null), [until ?? null]);
    await page.locator(selector).first()[press]({ timeout: STEP_TIMEOUT_MS });
    await page.waitForFunction(() => window.__bench.step.done !== null, null, { timeout: STEP_TIMEOUT_MS });
    await page.waitForTimeout(QUIET_MS);
    rows.push({ run, step, ...(await page.evaluate(() => window.__bench.end())) });
}

async function launch(page, cdp, run, step) {
    if (step === 'cold') await cdp.send('Network.clearBrowserCache');
    await page.goto('about:blank');
    await page.goto(`${BASE}${scenario.route}`, { waitUntil: 'commit' });
    await page.evaluate(() => window.__bench.begin(null, 0));
    await page.waitForFunction(() => window.__bench.step.done !== null, null, { timeout: STEP_TIMEOUT_MS });
    await page.waitForTimeout(QUIET_MS);
    const nav = await page.evaluate(() => {
        const [n] = performance.getEntriesByType('navigation');
        const fcp = performance.getEntriesByName('first-contentful-paint')[0]?.startTime ?? null;
        return { ttfb: Math.round(n.responseStart), fcp: fcp && Math.round(fcp), lcp: Math.round(window.__bench.lcp), ...window.__bench.end() };
    });
    rows.push({ run, step, ...nav, response: null, swap: null });
}

for (let run = 1; run <= runs; run++) {
    const { ctx, page, cdp } = await openContext();
    if (scenario.launch) {
        await launch(page, cdp, run, 'cold');
        await launch(page, cdp, run, 'warm');
    } else {
        await page.goto(`${BASE}${scenario.route}`, { waitUntil: 'load' });
        await settle(page);
        await scenario.default({ page, measure: (step, selector, opts) => measure(page, run, step, selector, opts) });
    }
    await ctx.close();
}
await browser.close();

const median = (xs) => {
    const v = xs.filter((x) => x !== null).sort((a, b) => a - b);
    if (v.length === 0) return null;
    const m = Math.floor(v.length / 2);
    return v.length % 2 ? v[m] : Number(((v[m - 1] + v[m]) / 2).toFixed(4));
};
const metrics = scenario.launch ? ['ttfb', 'fcp', 'lcp', 'done', 'shift', 'inp'] : ['response', 'swap', 'done', 'shift', 'inp'];
const summary = [...new Set(rows.map((r) => r.step))].map((step) => {
    const mine = rows.filter((r) => r.step === step);
    const row = { step };
    for (const m of metrics) {
        const med = median(mine.map((r) => r[m]));
        const max = Math.max(...mine.map((r) => r[m] ?? -Infinity));
        const over = BUDGETS[m] !== undefined && med !== null && med > BUDGETS[m] ? '!' : '';
        row[m] = med === null ? '-' : `${med}${over} (${max === -Infinity ? '-' : max})`;
    }
    return row;
});

const meta = { label, base: BASE, viewport: viewportKey, ground, throttle: flag('throttle', 'fast4g'), runs, at: new Date().toISOString() };
mkdirSync('storage/app/bench', { recursive: true });
writeFileSync(`storage/app/bench/${label}.json`, `${JSON.stringify({ meta, summary, rows }, null, 2)}\n`);
console.log(JSON.stringify(meta));
console.log('median (max); ! = median over budget');
console.table(summary);
