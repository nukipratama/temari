/**
 * Lists the visible controls that give no pressed feedback.
 *
 * Usage:
 *   node pressed.mjs [routes] [--viewport=mobile] [--ground=dark]
 *
 * For each route it forces `:active` on every visible link, button, switch and tab through CDP,
 * with transitions off, and compares the computed style of the control and its descendants
 * against the unforced state. A control whose style does not change has no pressed state: with
 * the tap highlight turned off app-wide, nothing confirms the touch. Routes default to the
 * signed-in screens.
 *
 * Chromium applies `:active` on touch unconditionally; iOS Safari needs a touch listener on the
 * control or an ancestor, which this scan cannot see.
 */
import { chromium } from './playwright.mjs';
import { BASE, VIEWPORT_DEFS, login } from './lib.mjs';

const args = process.argv.slice(2);
const flag = (name, fallback) => args.find((a) => a.startsWith(`--${name}=`))?.slice(name.length + 3) ?? fallback;
const [routeList] = args.filter((a) => !a.startsWith('--'));
const routes = (routeList ?? '/,/plan,/trends,/history,/race,/inbox,/profile,/settings').split(',');
const viewportKey = flag('viewport', 'mobile');
const ground = flag('ground', 'dark');

if (!VIEWPORT_DEFS[viewportKey] || !['dark', 'light'].includes(ground)) {
    console.error(`Usage: node pressed.mjs [routes] [--viewport=${Object.keys(VIEWPORT_DEFS).join('|')}] [--ground=dark|light]`);
    process.exit(2);
}

const CONTROLS = 'a[href], button:not([disabled]), [role="button"], [role="switch"], [role="tab"], summary';
const PROPS = ['opacity', 'transform', 'scale', 'translate', 'background-color', 'background-image', 'color', 'box-shadow', 'filter', 'border-color', 'outline-color'];

const browser = await chromium.launch({
    executablePath: '/usr/bin/chromium',
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
});
const ctx = await browser.newContext({ ...VIEWPORT_DEFS[viewportKey], serviceWorkers: 'block' });
await ctx.addInitScript((g) => {
    if (location.protocol.startsWith('http')) localStorage.setItem('temari-theme', g);
}, ground);
const page = await ctx.newPage();
await login(page);
const cdp = await ctx.newCDPSession(page);
await cdp.send('DOM.enable');
await cdp.send('CSS.enable');

const signature = (ids) => page.evaluate(([list, props]) => list.map((i) => {
    const el = document.querySelector(`[data-pressed-scan="${i}"]`);
    return [el, ...el.querySelectorAll('*')].slice(0, 30)
        .map((n) => props.map((p) => getComputedStyle(n).getPropertyValue(p)).join('|'))
        .join('/');
}), [ids, PROPS]);

const report = [];
for (const route of routes) {
    await page.goto(`${BASE}${route}`, { waitUntil: 'load' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(600);
    const controls = await page.evaluate((selector) => {
        const style = document.createElement('style');
        style.textContent = '*, *::before, *::after { transition: none !important; animation: none !important; }';
        document.head.append(style);
        return [...document.querySelectorAll(selector)]
            .filter((el) => {
                const r = el.getBoundingClientRect();
                return r.width > 1 && r.height > 1 && getComputedStyle(el).visibility !== 'hidden' && el.closest('[aria-hidden="true"], [hidden]') === null;
            })
            .map((el, i) => {
                el.dataset.pressedScan = String(i);
                const name = el.getAttribute('aria-label') ?? el.textContent.trim().replace(/\s+/g, ' ');
                return { i, tag: el.tagName.toLowerCase(), name: name.slice(0, 40) };
            });
    }, CONTROLS);
    const ids = controls.map((c) => c.i);
    const before = await signature(ids);
    const { root } = await cdp.send('DOM.getDocument', { depth: 0 });
    const missing = [];
    for (const c of controls) {
        const { nodeId } = await cdp.send('DOM.querySelector', { nodeId: root.nodeId, selector: `[data-pressed-scan="${c.i}"]` });
        await cdp.send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses: ['active'] });
        const [after] = await signature([c.i]);
        await cdp.send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses: [] });
        if (after === before[c.i]) missing.push(c);
    }
    report.push({ route, controls: controls.length, withoutFeedback: missing.length, missing: missing.map((m) => `${m.tag} "${m.name}"`) });
    console.log(`${route}: ${missing.length}/${controls.length} controls without pressed feedback`);
    for (const m of missing) console.log(`  ${m.tag} "${m.name}"`);
}
await browser.close();
console.log(JSON.stringify({ viewport: viewportKey, ground, total: report.reduce((a, r) => a + r.controls, 0), withoutFeedback: report.reduce((a, r) => a + r.withoutFeedback, 0) }));
