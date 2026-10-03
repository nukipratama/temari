/**
 * Records one scripted interaction as a CDP screencast: JPEG frames plus an ffconcat
 * timeline that keeps the real timing. Encoding is done by clips.sh.
 *
 * Usage:
 *   node record.mjs <feature> <scenario> [--side=before|after] [--ground=dark|light]
 *       [--viewport=<key>] [--reduced-motion] [--no-view-transitions]
 *
 * <scenario> is a .json file ({ "route": "/history", "steps": [...] }) or a .mjs module
 * (export const route = '/history'; export default async (page) => { ... }).
 *
 * JSON steps: ["goto", route] ["click", selector] ["hover", selector] ["waitFor", selector]
 * ["wait", ms] ["press", key] ["scroll", dy] ["back"]. Selectors are Playwright selectors;
 * the first match is used.
 *
 * The recording is named <feature>-<ground>-<viewport>[-reduced]-<side> and written to
 * storage/app/clips/.frames/<name>/. Point BASE at another stack to record a different build.
 * `--no-view-transitions` removes document.startViewTransition, which is the "before" of a
 * change that adds a view transition.
 */
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromium } from 'playwright';
import { BASE, VIEWPORT_DEFS, login, DEVTOOLS_AUTH } from './lib.mjs';

const LEAD_MS = 300;
const TAIL_MS = 600;
const MAX_FRAME_WIDTH = 1280;

const args = process.argv.slice(2);
const flag = (name) => args.find((a) => a.startsWith(`--${name}=`))?.slice(name.length + 3) ?? null;
const [feature, scenarioPath] = args.filter((a) => !a.startsWith('--'));
const side = flag('side') ?? 'after';
const ground = flag('ground') ?? 'dark';
const viewportKey = flag('viewport') ?? 'mobile';
const reduced = args.includes('--reduced-motion');
const noViewTransitions = args.includes('--no-view-transitions');

if (!feature || !scenarioPath || !VIEWPORT_DEFS[viewportKey] || !['before', 'after'].includes(side)) {
    console.error(
        `Usage: node record.mjs <feature> <scenario.json|.mjs> [--side=before|after] [--ground=dark|light] [--viewport=${Object.keys(VIEWPORT_DEFS).join('|')}] [--reduced-motion] [--no-view-transitions]`,
    );
    process.exit(2);
}

const scenario = scenarioPath.endsWith('.json')
    ? (await import(pathToFileURL(resolve(scenarioPath)).href, { with: { type: 'json' } })).default
    : await import(pathToFileURL(resolve(scenarioPath)).href);
const route = scenario.route ?? '/';

const steps = {
    goto: (page, to) => page.goto(`${BASE}${to}`, { waitUntil: 'load' }),
    click: (page, selector) => page.locator(selector).first().click({ timeout: 8000 }),
    hover: (page, selector) => page.locator(selector).first().hover({ timeout: 8000 }),
    waitFor: (page, selector) => page.locator(selector).first().waitFor({ timeout: 8000 }),
    wait: (page, ms) => page.waitForTimeout(ms),
    press: (page, key) => page.keyboard.press(key),
    scroll: (page, dy) => page.mouse.wheel(0, dy),
    back: (page) => page.goBack({ waitUntil: 'load' }),
};

const name = `${feature}-${ground}-${viewportKey}${reduced ? '-reduced' : ''}-${side}`;
const dir = `storage/app/clips/.frames/${name}`;

const browser = await chromium.launch({
    executablePath: '/usr/bin/chromium',
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
});
const device = VIEWPORT_DEFS[viewportKey];
const ctx = await browser.newContext({
    ...device,
    ...(reduced ? { reducedMotion: 'reduce' } : {}),
    ...DEVTOOLS_AUTH,
});
if (noViewTransitions) {
    await ctx.addInitScript(() => { delete Document.prototype.startViewTransition; });
}
const page = await ctx.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(`[pageerror] ${e.message}`));

await page.goto(`${BASE}/login`, { waitUntil: 'load' });
await page.evaluate((g) => {
    localStorage.setItem('theme', g);
    document.documentElement.setAttribute('data-theme', g);
}, ground);
await login(page);
await page.goto(`${BASE}${route}`, { waitUntil: 'load' });
await page.waitForLoadState('networkidle').catch(() => {});
await page.evaluate((g) => document.documentElement.setAttribute('data-theme', g), ground);
await page.waitForTimeout(400);

const { width, height } = device.viewport;
const k = Math.min(2, MAX_FRAME_WIDTH / width);
const frames = [];
const cdp = await ctx.newCDPSession(page);
cdp.on('Page.screencastFrame', ({ data, metadata, sessionId }) => {
    frames.push({ at: metadata.timestamp, data });
    cdp.send('Page.screencastFrameAck', { sessionId }).catch(() => {});
});
await cdp.send('Page.startScreencast', {
    format: 'jpeg',
    quality: 80,
    maxWidth: Math.round(width * k),
    maxHeight: Math.round(height * k),
    everyNthFrame: 1,
});

await page.waitForTimeout(LEAD_MS);
if (scenario.steps) {
    for (const [step, arg] of scenario.steps) await steps[step](page, arg);
} else {
    await scenario.default(page);
}
await page.waitForTimeout(TAIL_MS);
const endedAt = Date.now() / 1000;
await cdp.send('Page.stopScreencast');
await browser.close();

if (frames.length === 0) {
    console.error('no frames captured');
    process.exit(1);
}

rmSync(dir, { recursive: true, force: true });
mkdirSync(dir, { recursive: true });
const lines = ['ffconcat version 1.0'];
frames.forEach((frame, i) => {
    const file = `f${String(i).padStart(6, '0')}.jpg`;
    writeFileSync(`${dir}/${file}`, Buffer.from(frame.data, 'base64'));
    const next = frames[i + 1]?.at ?? Math.max(endedAt, frame.at + 0.05);
    lines.push(`file '${file}'`, `duration ${Math.max(next - frame.at, 0.001).toFixed(4)}`);
});
lines.push(`file 'f${String(frames.length - 1).padStart(6, '0')}.jpg'`);
writeFileSync(`${dir}/frames.ffconcat`, `${lines.join('\n')}\n`);

const seconds = (frames.at(-1).at - frames[0].at).toFixed(2);
console.log(JSON.stringify({ recording: name, frames: frames.length, seconds: Number(seconds), errors }));
