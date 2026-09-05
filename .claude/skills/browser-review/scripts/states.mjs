/**
 * The audits, run in states a page load never reaches.
 *
 * Every other script here scans a page as it first renders. That is where the
 * coverage keeps failing: the `.skeleton` bug lived in a loading state the demo
 * seed could not produce, and the three invisible edges of #723 lived inside a
 * collapsed accordion and an unopened modal. All four passed clean sweeps.
 *
 * So this drives the page first. It discovers its own triggers — anything with
 * `aria-expanded`, `aria-haspopup`, a dialog/menu role, or a summary element —
 * rather than reading a maintained list, because a maintained list drifts the
 * moment a component ships and nobody updates it. That is the same failure mode
 * as the `onSky` prop nine of nine call sites forgot.
 *
 * Findings already visible before the click are subtracted, so what it reports
 * is what the *state* introduced, not what the page always had.
 *
 * Usage: node states.mjs [dark|light]
 */
import { chromium } from 'playwright';
import {
    BASE,
    login,
    dismissReveal,
    discoverPageRoutes,
    DEVTOOLS_AUTH,
} from './lib.mjs';
import { ISLANDS, EDGES } from './scans.mjs';

const GROUND = process.argv[2] ?? 'dark';
const EDGE_SCAN = EDGES();
/** Per page, so one accordion-heavy screen cannot eat the whole run. */
const MAX_TRIGGERS = 12;

const browser = await chromium.launch({
    executablePath: '/usr/bin/chromium',
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
});
const ctx = await browser.newContext({
    viewport: { width: 390, height: 844 },
    ...DEVTOOLS_AUTH,
});
const page = await ctx.newPage();

await page.goto(`${BASE}/login`, { waitUntil: 'load' });
await page.evaluate((g) => {
    localStorage.setItem('theme', g);
    document.documentElement.setAttribute('data-theme', g);
}, GROUND);
await login(page);
await dismissReveal(page);

const TRIGGERS = [
    '[aria-expanded]',
    '[aria-haspopup]',
    '[aria-controls]',
    'summary',
    '[data-slot="collapsible-trigger"]',
].join(', ');

async function scan() {
    return [
        ...(await page.evaluate(ISLANDS)),
        ...(await page.evaluate(EDGE_SCAN)),
    ];
}

const key = (f) => `${f.kind}|${f.cls}|${f.detail}`;

async function settle() {
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.evaluate(
        (g) => document.documentElement.setAttribute('data-theme', g),
        GROUND,
    );
    await page.waitForTimeout(250);
}

const routes = await discoverPageRoutes(page);
const found = new Map();
let opened = 0;

for (const route of routes) {
    try {
        await page.goto(`${BASE}${route.path}`, {
            waitUntil: 'load',
            timeout: 20000,
        });
    } catch {
        continue;
    }
    await settle();

    const baseline = new Set((await scan()).map(key));
    const count = Math.min(await page.locator(TRIGGERS).count(), MAX_TRIGGERS);

    for (let i = 0; i < count; i += 1) {
        // Re-resolve each time: opening one control frequently re-renders the
        // list the others live in, and a stale handle silently no-ops.
        const trigger = page.locator(TRIGGERS).nth(i);
        let label = '';
        try {
            label = (
                (await trigger.getAttribute('aria-label')) ||
                (await trigger.textContent()) ||
                ''
            )
                .trim()
                .slice(0, 32);
            await trigger.click({ timeout: 1500 });
        } catch {
            continue;
        }
        opened += 1;
        await page.waitForTimeout(350);

        for (const f of await scan()) {
            if (baseline.has(key(f))) continue;
            const k = `${route.path}|${key(f)}`;
            if (!found.has(k)) {
                found.set(k, { ...f, route: route.path, via: label });
            }
        }

        // Escape closes a dialog or menu; a re-click closes a disclosure. Both
        // are best-effort, so reload rather than trust the page came back.
        await page.keyboard.press('Escape').catch(() => {});
        await page
            .goto(`${BASE}${route.path}`, { waitUntil: 'load' })
            .catch(() => {});
        await settle();
    }
}

await browser.close();

const rows = [...found.values()].sort((a, b) => a.score - b.score);
console.log(
    `GROUND=${GROUND} routes=${routes.length} triggers opened=${opened} state-only findings=${rows.length}`,
);
for (const r of rows) {
    console.log(
        `\n${r.kind.toUpperCase()} ${r.score}  ${r.detail}  ${r.size}  ${r.route}  via "${r.via}"`,
    );
    console.log(`  class: ${r.cls}`);
}
