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
 */
import { chromium } from 'playwright';
import { BASE, login, dismissReveal, DEVTOOLS_AUTH } from './lib.mjs';

const args = process.argv.slice(2);
const clickArg = args.find((a) => a.startsWith('--click='));
const rest = args.filter((a) => !a.startsWith('--'));
const [route, ground = 'dark', expression] = rest;

if (!route || !expression) {
    console.error(
        "Usage: node probe.mjs <route> [dark|light] [--click=<text>] '<expression>'",
    );
    process.exit(2);
}

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
}, ground);
await login(page);
await dismissReveal(page);

await page.goto(`${BASE}${route}`, { waitUntil: 'load' });
await page.waitForLoadState('networkidle').catch(() => {});
await page.evaluate(
    (g) => document.documentElement.setAttribute('data-theme', g),
    ground,
);
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

try {
    const result = await page.evaluate(`(() => (${expression}))()`);
    console.log(JSON.stringify(result, null, 1));
} catch (error) {
    console.error(`EVAL FAILED: ${error.message.split('\n')[0]}`);
    process.exitCode = 1;
}

await browser.close();
