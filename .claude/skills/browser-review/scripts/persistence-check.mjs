// One-off: prove the persistent Inertia layout keeps the shell MOUNTED across
// client-side visits. A full-page reload would also "look" fine in a screenshot,
// so this stamps an expando on the live nav/top-bar DOM nodes after login and
// re-checks it after each tab visit. If the shell remounted, the stamp is gone.
import { chromium } from 'playwright';
import { VIEWPORT_DEFS, login, dismissReveal } from './lib.mjs';

const TABS = [
    { label: 'Koleksi', path: '/kartu' },
    { label: 'Riwayat', path: '/aktivitas' },
    { label: 'Aku', path: '/profil' },
    { label: 'Hari Ini', path: '/' },
];

const browser = await chromium.launch({
    executablePath: '/usr/bin/chromium',
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
});
const context = await browser.newContext({ ...VIEWPORT_DEFS.mobile });
const page = await context.newPage();

const consoleErrors = [];
page.on('console', (m) => {
    if (m.type() === 'error') consoleErrors.push(`[console] ${m.text()}`);
});
page.on('pageerror', (e) => consoleErrors.push(`[pageerror] ${e.message}`));

await login(page);
await dismissReveal?.(page).catch(() => {});

// Stamp the shell's live DOM nodes.
const stamped = await page.evaluate(() => {
    // Both TopNav (desktop) and MobileBottomNav share aria-label="Primary";
    // on a mobile viewport only the fixed bottom one is visible.
    const nav = document.querySelector('nav[aria-label="Primary"].fixed');
    const header = document.querySelector('header');
    if (!nav || !header) return { ok: false, nav: !!nav, header: !!header };
    nav.__temariStamp = 'persist-me';
    header.__temariStamp = 'persist-me';
    return { ok: true };
});
console.log(`STAMP ${JSON.stringify(stamped)}`);

let allPersisted = true;

for (const tab of TABS) {
    // Click the real bottom-nav link => a client-side Inertia visit, not a reload.
    await page.locator(`nav[aria-label="Primary"].fixed a:has-text("${tab.label}")`).first().click();
    await page.waitForURL((u) => u.pathname === tab.path, { timeout: 15000 });
    await page.waitForLoadState('networkidle');

    const state = await page.evaluate(() => {
        // Both TopNav (desktop) and MobileBottomNav share aria-label="Primary";
    // on a mobile viewport only the fixed bottom one is visible.
    const nav = document.querySelector('nav[aria-label="Primary"].fixed');
        const header = document.querySelector('header');
        return {
            navPresent: !!nav,
            headerPresent: !!header,
            navPersisted: nav?.__temariStamp === 'persist-me',
            headerPersisted: header?.__temariStamp === 'persist-me',
            main: !!document.getElementById('main-content'),
            bodyText: (document.getElementById('main-content')?.innerText ?? '').trim().length,
        };
    });

    const persisted = state.navPersisted && state.headerPersisted;
    if (!persisted) allPersisted = false;

    console.log(
        `VISIT path=${tab.path} nav=${state.navPresent} header=${state.headerPresent} ` +
        `navPersisted=${state.navPersisted} headerPersisted=${state.headerPersisted} ` +
        `main=${state.main} contentChars=${state.bodyText}`,
    );
}

// The content region must replay the enter animation on a real navigation.
// Count animationstart events on #main-content while clicking a tab.
await page.evaluate(() => {
    window.__enterAnimations = 0;
    document.addEventListener(
        'animationstart',
        (e) => {
            if (e.animationName === 'page-enter') window.__enterAnimations += 1;
        },
        true,
    );
});
await page.locator('nav[aria-label="Primary"].fixed a:has-text("Riwayat")').first().click();
await page.waitForURL((u) => u.pathname === '/aktivitas', { timeout: 15000 });
await page.waitForLoadState('networkidle');
const afterNav = await page.evaluate(() => window.__enterAnimations);
console.log(`ENTER_ANIMATIONS_AFTER_NAV=${afterNav}`);

console.log(`SHELL_PERSISTED=${allPersisted}`);
console.log(`CONSOLE_ERRORS=${consoleErrors.length}`);
for (const e of consoleErrors) console.log(`  ${e}`);

await browser.close();
