/**
 * One live DOM question, answered.
 *
 * The inspect phase reads screenshots, and a screenshot is a weak source: one
 * pass produced 20 findings of which about a third did not survive checking,
 * and a later pass produced 3 of which *all* three were wrong — a fixed bottom
 * nav read as a collision, a notification bell read as an empty box, a
 * token-correct inverted pill read as a wrong-ground bug. Each cost a round of
 * someone's attention.
 *
 * This makes the check cheap enough that there is no excuse for skipping it:
 * one command, one expression, a JSON answer from the running app.
 *
 * Usage:
 *   node probe.mjs <route> [dark|light] '<expression evaluated in the page>'
 *
 * Examples:
 *   node probe.mjs / dark 'document.querySelectorAll("[data-slot=card]").length'
 *   node probe.mjs /settings dark '[...document.querySelectorAll("button")].map(b => b.textContent.trim()).slice(0,10)'
 *   node probe.mjs /profile dark 'getComputedStyle(document.querySelector("h1")).fontSize'
 *
 * `click` drives a state first, so an accordion or modal can be inspected:
 *   node probe.mjs /settings dark --click='HR zones' 'document.body.innerText.length'
 *
 * `--viewport=<key>` picks a VIEWPORT_DEFS key (mobile, se, tablet, laptop, desktop);
 * the default is the 390x844 mobile context:
 *   node probe.mjs / dark --viewport=se 'innerWidth'
 *
 * Console/pageerror messages are always captured and printed alongside the
 * result — a live substitute for a browser devtools console mid-coding.
 * `--shot` also saves a full-page screenshot next to the JSON output:
 *   node probe.mjs /settings dark --shot 'document.title'
 */
import { chromium } from './playwright.mjs';
import { BASE, VIEWPORT_DEFS, login, fullPageScreenshot, SHOT, EXT, DEVTOOLS_AUTH } from './lib.mjs';

const args = process.argv.slice(2);
const clickArg = args.find((a) => a.startsWith('--click='));
const viewportKey = args.find((a) => a.startsWith('--viewport='))?.slice('--viewport='.length) ?? null;
const wantsShot = args.includes('--shot');
const rest = args.filter((a) => !a.startsWith('--'));
const [route, ground = 'dark', expression] = rest;

if (!route || !expression) {
    console.error(
        "Usage: node probe.mjs <route> [dark|light] [--click=<text>] [--viewport=<key>] [--shot] '<expression>'",
    );
    process.exit(2);
}

if (viewportKey !== null && !VIEWPORT_DEFS[viewportKey]) {
    console.error(
        `Unknown viewport "${viewportKey}". Valid: ${Object.keys(VIEWPORT_DEFS).join(', ')}`,
    );
    process.exit(2);
}

const browser = await chromium.launch({
    executablePath: '/usr/bin/chromium',
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
});
const ctx = await browser.newContext({
    ...(viewportKey ? VIEWPORT_DEFS[viewportKey] : { viewport: { width: 390, height: 844 } }),
    ...DEVTOOLS_AUTH,
});
const page = await ctx.newPage();

const errors = [];
page.on('console', (m) => { if (m.type() === 'error') errors.push(`[console] ${page.url()} :: ${m.text()}`); });
page.on('pageerror', (e) => errors.push(`[pageerror] ${page.url()} :: ${e.message}`));

await page.addInitScript((g) => localStorage.setItem('temari-theme', g), ground);
await page.goto(`${BASE}/login`, { waitUntil: 'load' });
await login(page);

await page.goto(`${BASE}${route}`, { waitUntil: 'load' });
await page.waitForLoadState('networkidle').catch(() => {});
const appliedGround = await page.evaluate(() => document.documentElement.dataset.theme);
if (appliedGround !== ground) throw new Error(`ground ${ground} did not apply (data-theme=${appliedGround})`);
await page.waitForTimeout(400);

if (clickArg) {
    const needle = clickArg.slice('--click='.length);
    const target = page
        .locator(`text=${needle}`)
        .or(page.locator(`[aria-label*="${needle}" i]`))
        .first();
    try {
        await target.click({ timeout: 3000 });
        await page.waitForTimeout(400);
    } catch {
        console.error(
            `(could not click "${needle}" — reporting the page as loaded)`,
        );
    }
}

let shotPath = null;
if (wantsShot) {
    shotPath = `/tmp/probe-${Date.now()}.${EXT}`;
    await fullPageScreenshot(page, shotPath, SHOT);
}

try {
    const result = await page.evaluate(`(() => (${expression}))()`);
    console.log(JSON.stringify({ result, console: errors, shot: shotPath }, null, 1));
} catch (error) {
    console.error(`EVAL FAILED: ${error.message.split('\n')[0]}`);
    if (errors.length) console.error(errors.join('\n'));
    process.exitCode = 1;
}

await browser.close();
