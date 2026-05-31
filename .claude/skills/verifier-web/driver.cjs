// Generic in-container browser driver. Logs in as the demo user, then runs a
// scenario file (path = argv[2]) that drives the page and captures evidence.
// Run via run.sh, which installs system chromium + playwright-core in the
// Sail container and invokes this from /app. See SKILL.md.
const { chromium } = require('playwright-core');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1';
const DIR = process.env.OUT || '/app/storage/app/verify/shots';
const scenarioPath = process.argv[2];

(async () => {
    if (!scenarioPath) throw new Error('usage: node driver.cjs <scenario.cjs>');
    fs.mkdirSync(DIR, { recursive: true });

    const browser = await chromium.launch({
        executablePath: process.env.CHROME || '/usr/bin/chromium-browser',
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });
    const ctx = await browser.newContext({
        viewport: { width: Number(process.env.W || 1366), height: Number(process.env.H || 1500) },
        deviceScaleFactor: 1,
    });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push('PAGEERROR: ' + e.message));

    // Demo login: POST /auth/demo with the session CSRF token (Inertia route).
    if (process.env.NO_LOGIN !== '1') {
        await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
        const cookies = await ctx.cookies();
        const xsrf = decodeURIComponent((cookies.find((c) => c.name === 'XSRF-TOKEN') || {}).value || '');
        const r = await page.request.post(`${BASE}/auth/demo`, { headers: { 'X-XSRF-TOKEN': xsrf, 'X-Requested-With': 'XMLHttpRequest' } });
        console.log('demo login:', r.status());
    }

    const h = {
        BASE,
        DIR,
        page,
        async go(p) {
            await page.goto(p.startsWith('http') ? p : `${BASE}${p}`, { waitUntil: 'networkidle' });
            await page.waitForTimeout(600);
        },
        async shot(name, opts = {}) {
            await page.waitForTimeout(400);
            await page.screenshot({ path: `${DIR}/${name}.png`, ...opts });
            console.log('shot:', name, '·', page.url());
        },
        async dumpCanvas(name) {
            const data = await page.evaluate(() => {
                const c = document.querySelector('canvas');
                return c ? c.toDataURL('image/png') : null;
            });
            if (data) {
                fs.writeFileSync(`${DIR}/${name}.png`, Buffer.from(data.split(',')[1], 'base64'));
                console.log('canvas:', name);
            } else {
                console.log('no canvas for', name);
            }
        },
    };

    const scenario = require(path.resolve(scenarioPath));
    await scenario(page, h);

    console.log('CONSOLE_ERRORS:', JSON.stringify(errors));
    await browser.close();
})().catch((e) => { console.error('DRIVER_ERROR:', e); process.exit(1); });
