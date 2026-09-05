/**
 * Borders and rings that are not there.
 *
 * `light-islands.mjs` asks whether a *surface* wears the wrong ground. This
 * asks the same question of an *edge*, which nothing else scores: the token
 * audit scans `bg-<token>` utilities only, so a fixed-light border token on a
 * dark ground is checked by nothing and fails nothing. It does not go
 * unreadable, it goes **absent** — `border-ink/[0.18]` over a Sky card is
 * near-black on near-black, and the separator simply stops existing.
 *
 * Reports every painted edge whose contrast against the surface behind it is
 * under the separator minimum, resolving it against the nearest opaque surface
 * outside the element.
 *
 * Scope, so a clean run is not read as more than it is: this scores **borders**.
 * Ring/box-shadow detection is best-effort and misses Tailwind's composed
 * shadow chain, which begins with four transparent layers. That is not worth
 * chasing here, because the thing it would find is the elevation rim, and
 * elevation is deliberately below the separator floor on both grounds -- the
 * dark rim measures 1.28:1 and the light cast 1.11:1, against a 1.4 minimum
 * that exists for dividers, not for the edge of a resting card.
 *
 * Usage: node edges.mjs [dark|light] [minRatio]
 */
import { chromium } from 'playwright';
import {
    BASE,
    login,
    dismissReveal,
    discoverPageRoutes,
    DEVTOOLS_AUTH,
} from './lib.mjs';
import { HELPERS } from './scans.mjs';

const GROUND = process.argv[2] ?? 'dark';
/** The app's own separator floor, the minimum `line` is derived against. */
const MIN = Number(process.argv[3] ?? 1.4);

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

const SCAN = `(() => {
    ${HELPERS}
    const out = [];
    for (const el of document.querySelectorAll('*')) {
        const s = getComputedStyle(el);
        const box = el.getBoundingClientRect();
        if (box.width < 8 || box.height < 8) continue;

        const edges = [];
        for (const side of ['Top', 'Right', 'Bottom', 'Left']) {
            if (parseFloat(s['border' + side + 'Width']) > 0) {
                edges.push(['border', s['border' + side + 'Color']]);
                break;
            }
        }
        const shadow = s.boxShadow;
        if (shadow && shadow !== 'none' && /inset|0px 0px 0px/.test(shadow)) {
            const m = shadow.match(/rgba?\\([^)]+\\)/);
            if (m) edges.push(['ring', m[0]]);
        }
        if (!edges.length) continue;

        const ground = behind(el, false);
        if (!ground) continue;

        for (const [kind, colour] of edges) {
            const c = rgb(colour);
            if (!c || c.a === 0) continue;
            const painted = over(c, ground);
            const r = ratio(painted, ground);
            if (r >= ${MIN}) continue;
            out.push({
                kind,
                cls: (el.className?.toString?.() ?? '').slice(0, 110),
                colour,
                ratio: Math.round(r * 100) / 100,
                size:
                    Math.round(box.width) + 'x' + Math.round(box.height),
            });
        }
    }
    return out;
})()`;

const routes = await discoverPageRoutes(page);
const found = new Map();

for (const route of routes) {
    if (route.path.startsWith('/devtools')) continue; // swatch demos, not UI
    try {
        await page.goto(`${BASE}${route.path}`, {
            waitUntil: 'load',
            timeout: 20000,
        });
    } catch {
        continue;
    }
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.evaluate(
        (g) => document.documentElement.setAttribute('data-theme', g),
        GROUND,
    );
    await page.waitForTimeout(250);

    for (const row of await page.evaluate(SCAN)) {
        const key = `${row.kind}|${row.cls}|${row.colour}`;
        if (!found.has(key)) found.set(key, { ...row, routes: new Set() });
        found.get(key).routes.add(route.path);
    }
}

await browser.close();

const rows = [...found.values()].sort((a, b) => a.ratio - b.ratio);
console.log(
    `GROUND=${GROUND} min=${MIN} routes=${routes.length} invisible edges=${rows.length}`,
);
for (const r of rows) {
    console.log(
        `\n${r.ratio.toFixed(2)}  ${r.kind}  ${r.colour}  ${r.size}  ${[...r.routes].slice(0, 3).join(' ')}`,
    );
    console.log(`  class: ${r.cls}`);
}
