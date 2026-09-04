/**
 * Which surface a translucent panel actually lands on.
 *
 * The token audit worst-cases every `bg-<token>/<alpha>` panel against every
 * ground the app paints, because grounds.json records the mount as `paper` and
 * `paper` is a set. That answers "could this pairing fail", not "does it".
 * This walks the rendered DOM instead: for every element carrying one of the
 * specs, it resolves the nearest opaque ancestor background and reports it, so
 * a shortfall scored against the worst ground can be checked against the
 * ground the component is actually mounted on.
 *
 * Usage: node mounts.mjs [dark|light] [spec,spec,...]
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
const SPECS = process.argv[3]
    ? process.argv[3].split(',')
    : [
          'bg-citrus/15',
          'bg-cream-deep/40',
          'bg-destructive/10',
          'bg-destructive/20',
          'bg-destructive/30',
          'bg-ember/8',
          'bg-ember/15',
          'bg-ember/18',
          'bg-leaf/15',
          'bg-leaf/18',
          'bg-line/60',
      ];

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

const routes = await discoverPageRoutes(page);
const hits = [];

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

    const found = await page.evaluate((specs) => {
        const opaqueMount = (el) => {
            let node = el.parentElement;
            while (node) {
                const style = getComputedStyle(node);
                const m = style.backgroundColor.match(/rgba?\(([^)]+)\)/);
                if (m) {
                    const parts = m[1].split(',').map((v) => parseFloat(v));
                    const alpha = parts.length < 4 ? 1 : parts[3];
                    if (alpha === 1) {
                        return {
                            bg: style.backgroundColor,
                            gradient: style.backgroundImage !== 'none',
                        };
                    }
                }
                node = node.parentElement;
            }
            return { bg: 'none', gradient: false };
        };

        const out = [];
        for (const el of document.querySelectorAll('[class]')) {
            const cls = el.className?.toString?.() ?? '';
            for (const spec of specs) {
                if (!cls.includes(spec)) continue;
                const esc = spec.replace('/', '\\/');
                const base = new RegExp(`(^|\\s)${esc}(\\s|$)`).test(cls);
                const hover = new RegExp(`(^|\\s)hover:${esc}(\\s|$)`).test(
                    cls,
                );
                if (!base && !hover) continue;
                const mount = opaqueMount(el);
                out.push({
                    spec,
                    trigger: base ? 'always' : 'hover',
                    mount: mount.bg,
                    gradient: mount.gradient,
                });
            }
        }
        return out;
    }, SPECS);

    for (const f of found) hits.push({ ...f, route: route.path });
}

await browser.close();

const byKey = new Map();
for (const h of hits) {
    const key = `${h.spec}|${h.trigger}|${h.mount}|${h.gradient}`;
    if (!byKey.has(key)) byKey.set(key, { ...h, routes: new Set(), n: 0 });
    const entry = byKey.get(key);
    entry.routes.add(h.route);
    entry.n += 1;
}

console.log(
    `GROUND=${GROUND} routes=${routes.length} instances=${hits.length}`,
);
for (const spec of SPECS) {
    const rows = [...byKey.values()].filter((e) => e.spec === spec);
    if (rows.length === 0) {
        console.log(`\n${spec}: NOT RENDERED on any discovered page`);
        continue;
    }
    console.log(`\n${spec}:`);
    for (const r of rows.sort((a, b) => b.n - a.n)) {
        console.log(
            `  ${r.trigger.padEnd(6)} mount=${r.mount.padEnd(24)}${r.gradient ? ' +gradient' : ''} x${String(r.n).padEnd(3)} ${[...r.routes].slice(0, 4).join(' ')}`,
        );
    }
}
