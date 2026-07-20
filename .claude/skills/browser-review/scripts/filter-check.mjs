// One-off: prove the Jejak mood filter actually filters server-side, lands in
// the URL, survives a reload, and shows the no-match state.
//
// NOTE: mood names also appear as chips on individual run rows, so the filter
// buttons must be scoped by [aria-pressed] — only they carry it. A loose
// getByRole('button', {name: /Nyala/}) matches 9 nodes and silently clicks the
// wrong one.
import { chromium } from 'playwright';
import { BASE, VIEWPORT_DEFS, login, dismissReveal } from './lib.mjs';

const browser = await chromium.launch({
    executablePath: '/usr/bin/chromium',
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
});
const context = await browser.newContext({ ...VIEWPORT_DEFS.mobile });
const page = await context.newPage();

const errors = [];
page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
page.on('pageerror', (e) => errors.push(e.message));

await login(page);
await dismissReveal(page).catch(() => {});

const rows = () => page.locator('a[href^="/aktivitas/"]').count();
const moodButton = (name) => page.locator(`[aria-pressed]`, { hasText: name }).first();

await page.goto(`${BASE}/aktivitas`, { waitUntil: 'networkidle' });
const unfiltered = await rows();
console.log(`UNFILTERED rows=${unfiltered}`);

await page.getByLabel('Buka filter').click();
await moodButton('Nyala').click();
await page.waitForTimeout(2000);

const filtered = await rows();
const url = new URL(page.url());
console.log(`FILTERED rows=${filtered} search=${url.search}`);
console.log(`URL_CARRIES_MOOD=${url.searchParams.get('mood')}`);
console.log(`ACTUALLY_NARROWED=${filtered < unfiltered}`);

// Non-matching runs must be gone, not faded.
console.log(`DIMMED_NODES=${await page.locator('.opacity-30, .opacity-40').count()}`);

// Reload the filtered URL directly: proves it is server-applied and shareable.
await page.goto(page.url(), { waitUntil: 'networkidle' });
console.log(`AFTER_RELOAD rows=${await rows()} SURVIVES=${(await rows()) === filtered}`);

// Pressed state comes back from the server prop.
await page.getByLabel('Buka filter').click();
console.log(`PRESSED_AFTER_RELOAD=${await moodButton('Nyala').getAttribute('aria-pressed')}`);

// A mood the user has none of shows the no-match state, not the onboarding one.
await page.goto(`${BASE}/aktivitas?mood=lemes`, { waitUntil: 'networkidle' });
console.log(`NO_MATCH rows=${await rows()}`);
console.log(`NO_MATCH_STATE=${await page.getByText('Gak ada lari yang cocok.').isVisible()}`);
await page.getByRole('button', { name: /Reset filter/ }).click();
await page.waitForTimeout(2000);
console.log(`AFTER_RESET rows=${await rows()} search="${new URL(page.url()).search}"`);

console.log(`CONSOLE_ERRORS=${errors.length}`);
for (const e of errors) console.log(`  ${e}`);

await browser.close();
