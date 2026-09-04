/**
 * Light surfaces painted on the dark ground.
 *
 * The token audit scores pairings and `contrast.mjs` scores painted text; both
 * are blind to a surface that is perfectly legible but wears the wrong ground.
 * A fixed-light token (`cream`, `cream-deep`, `line`, `.skeleton`) used where a
 * reactive one was meant renders as a bright island on a near-black page, and
 * nothing fails: the dark text on it still clears AA.
 *
 * So this reports geometry, not contrast: every element whose own background is
 * lighter than the ground it sits on by a wide margin, deduped by class
 * signature. Hover-only surfaces are included by actually hovering every
 * element that carries a `hover:bg-` utility, which is where several of these
 * live and where a screenshot sweep never looks.
 *
 * Usage: node light-islands.mjs [dark|light]
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
    const lum = (bg) => {
        const m = bg.match(/rgba?\\(([^)]+)\\)/);
        if (!m) return null;
        const p = m[1].split(',').map((v) => parseFloat(v));
        if (p.length > 3 && p[3] < 0.95) return null;
        const c = p.slice(0, 3).map((v) => {
            const s = v / 255;
            return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
        });
        return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
    };
    const groundOf = (el) => {
        let node = el.parentElement;
        while (node) {
            const l = lum(getComputedStyle(node).backgroundColor);
            if (l !== null) return l;
            node = node.parentElement;
        }
        return null;
    };
    const out = [];
    for (const el of document.querySelectorAll('*')) {
        const style = getComputedStyle(el);
        const own = lum(style.backgroundColor);
        if (own === null || own < 0.3) continue;
        const box = el.getBoundingClientRect();
        if (box.width < 6 || box.height < 6) continue;
        const ground = groundOf(el);
        if (ground === null || ground > 0.15) continue;
        out.push({
            cls: (el.className?.toString?.() ?? '').slice(0, 150),
            tag: el.tagName.toLowerCase(),
            role: el.getAttribute('role') ?? '',
            bg: style.backgroundColor,
            own: Math.round(own * 100) / 100,
            ground: Math.round(ground * 100) / 100,
            size: Math.round(box.width) + 'x' + Math.round(box.height),
        });
    }
    return out;
})()`;

const routes = await discoverPageRoutes(page);
const found = new Map();

function record(rows, route, state) {
    for (const r of rows) {
        const key = `${state}|${r.cls}|${r.bg}`;
        if (!found.has(key)) found.set(key, { ...r, state, routes: new Set() });
        found.get(key).routes.add(route);
    }
}

for (const route of routes) {
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

    record(await page.evaluate(SCAN), route.path, 'rest');

    const hoverables = await page.$$('[class*="hover:bg-"]');
    for (const handle of hoverables.slice(0, 40)) {
        try {
            await handle.hover({ timeout: 1000 });
        } catch {
            continue;
        }
        record(await page.evaluate(SCAN), route.path, 'hover');
    }
}

await browser.close();

const rows = [...found.values()].sort((a, b) => b.own - a.own);
console.log(
    `GROUND=${GROUND} routes=${routes.length} light islands=${rows.length}`,
);
for (const r of rows) {
    console.log(
        `\n[${r.state}] ${r.tag}${r.role ? `[${r.role}]` : ''} ${r.size}  bg=${r.bg}  L=${r.own} on ground L=${r.ground}`,
    );
    console.log(`  ${[...r.routes].slice(0, 3).join(' ')}`);
    console.log(`  class: ${r.cls}`);
}
