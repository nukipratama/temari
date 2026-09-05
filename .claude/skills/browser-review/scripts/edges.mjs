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
    // Resolve through a canvas rather than parsing the string. Computed styles
    // come back as oklab(...) and color-mix(...) as often as rgb(), and a regex
    // that only knows rgb() silently drops them — which is how a border at
    // 1.01:1 reads as "no data" instead of "invisible".
    const probe = document.createElement('canvas').getContext('2d', {
        willReadFrequently: true,
    });
    const rgb = (v) => {
        if (!v || v === 'none' || v === 'transparent') return null;
        probe.clearRect(0, 0, 1, 1);
        probe.fillStyle = '#000';
        probe.fillStyle = v;
        if (probe.fillStyle === '#000' && !/^#0{3,8}$|black/i.test(v)) {
            return null; // the browser rejected it
        }
        probe.clearRect(0, 0, 1, 1);
        probe.fillRect(0, 0, 1, 1);
        const [r, g, b, a] = probe.getImageData(0, 0, 1, 1).data;
        return { c: [r, g, b], a: a / 255 };
    };
    const over = (fg, bg) => fg.c.map((v, i) => v * fg.a + bg[i] * (1 - fg.a));
    const lum = (c) => {
        const s = c.map((v) => {
            const x = v / 255;
            return x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4;
        });
        return 0.2126 * s[0] + 0.7152 * s[1] + 0.0722 * s[2];
    };
    const ratio = (a, b) => {
        const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p);
        return (x + 0.05) / (y + 0.05);
    };
    // The opaque colour an edge sits against: what is OUTSIDE the element,
    // since a border separates it from its surroundings. Starting at the
    // element itself scores \`border-x bg-x\` (a fill-coloured edge, used for
    // sizing) as invisible, which it is, deliberately, and not a bug.
    const behind = (el) => {
        const stack = [];
        let node = el.parentElement;
        while (node) {
            const c = rgb(getComputedStyle(node).backgroundColor);
            if (c && c.a > 0) {
                if (c.a === 1) {
                    let out = c.c;
                    for (const layer of stack.reverse()) out = over(layer, out);
                    return out;
                }
                stack.push(c);
            }
            node = node.parentElement;
        }
        return null;
    };

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

        const ground = behind(el);
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
