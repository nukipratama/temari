// End-to-end screenshot sweep across a viewport matrix. Runs inside the Sail
// `app` container:  ./vendor/bin/sail exec app node .claude/skills/browser-review/scripts/shoot.mjs
// Env: VIEWPORTS=mobile,se,tablet,desktop,wide (default mobile,se,desktop,wide)  BASE=http://localhost
//      OUT=storage/app/browser-review  BATCH=<date>/<time> (override the run key)
// Pages are discovered from `artisan route:list` (see lib.mjs) — nothing hardcoded.
import { rmSync } from 'node:fs';
import { chromium } from 'playwright';
import { BASE, VIEWPORT_DEFS, parseViewports, login, dismissReveal, discoverPageRoutes, fullPageScreenshot, SHOT, EXT, DEVTOOLS_AUTH } from './lib.mjs';

// Each run lands in its own dir keyed by date + execution time. Prior batches are
// cleared first, so only the latest sweep is kept (stale screenshots aren't needed):
// storage/app/browser-review/<YYYY-MM-DD>/<HHMMSS>/<viewport>/.
const BASE_OUT = process.env.OUT ?? 'storage/app/browser-review';
const now = new Date();
const p2 = (n) => String(n).padStart(2, '0');
const BATCH = process.env.BATCH
  ?? `${now.getFullYear()}-${p2(now.getMonth() + 1)}-${p2(now.getDate())}/${p2(now.getHours())}${p2(now.getMinutes())}${p2(now.getSeconds())}`;
const OUT = `${BASE_OUT}/${BATCH}`;
rmSync(BASE_OUT, { recursive: true, force: true });
const selected = parseViewports();

const browser = await chromium.launch({
  executablePath: '/usr/bin/chromium',
  args: ['--no-sandbox', '--disable-dev-shm-usage'],
});

const CONCURRENCY = 3;
const capture = (page, errors) => {
  page.on('console', (m) => { if (m.type() === 'error') errors.push(`[console] ${page.url()} :: ${m.text()}`); });
  page.on('pageerror', (e) => errors.push(`[pageerror] ${page.url()} :: ${e.message}`));
};

// Route discovery and the demo login both only depend on the account/route
// table, not the viewport — do each once (on the first viewport) and reuse.
let routes;
let authCookies;

for (const vp of selected) {
  const def = VIEWPORT_DEFS[vp];
  const dir = `${OUT}/${vp}`;
  const errors = [];
  const context = await browser.newContext({ ...def, ...DEVTOOLS_AUTH, reducedMotion: 'reduce' });
  const bootPage = await context.newPage();
  capture(bootPage, errors);

  console.log(`\n=== ${vp} (${def.viewport.width}x${def.viewport.height}) ===`);
  // Guest login page first, then authenticate and discover the rest.
  await bootPage.goto(`${BASE}/login`, { waitUntil: 'load' });
  await bootPage.waitForLoadState('networkidle').catch(() => {});
  await bootPage.waitForTimeout(400);
  await fullPageScreenshot(bootPage, `${dir}/00-login-full.${EXT}`, SHOT);
  if (!authCookies) {
    await login(bootPage);
    await dismissReveal(bootPage);
    routes = await discoverPageRoutes(bootPage);
    console.log(`  discovered ${routes.length} pages`);
    ({ cookies: authCookies } = await context.storageState());
  } else {
    // Reuse the session from the first viewport instead of clicking through
    // login again; dismissReveal is a server-side mutation on the shared
    // demo account, so it's already cleared and doesn't need repeating.
    await context.addCookies(authCookies);
  }
  await bootPage.close();

  const seen = new Set();
  let next = 0;
  const worker = async () => {
    const page = await context.newPage();
    capture(page, errors);
    while (next < routes.length) {
      const idx = next++;
      const { name, path } = routes[idx];
      try {
        await page.goto(`${BASE}${path}`, { waitUntil: 'load', timeout: 20000 });
        const landed = new URL(page.url()).pathname;
        if (seen.has(landed)) { continue; }            // dedupe redirects to an already-shot page
        seen.add(landed);
        // `load` fires before Inertia hydrates and before a code-split page
        // chunk resolves, so a flat pause after it races the first paint: a
        // contended machine produced two blank full-page captures in one sweep,
        // and a blank shot of a working page reads as "all content missing" to
        // whoever reviews it. Wait for the content itself instead of guessing a
        // longer sleep. Both waits are best-effort: a page that legitimately
        // renders no text must still be captured, not skipped.
        await page.waitForLoadState('networkidle').catch(() => {});
        await page
          .waitForFunction(
            () => {
              const el = document.querySelector('main') ?? document.body;
              return el.scrollHeight > 0 && (el.innerText ?? '').trim().length > 0;
            },
            null,
            { timeout: 5000 },
          )
          .catch(() => {});
        await page.waitForTimeout(150);
        const label = String(idx + 1).padStart(2, '0');
        await page.screenshot({ path: `${dir}/${label}-${name}-viewport.${EXT}`, fullPage: false, ...SHOT });
        await fullPageScreenshot(page, `${dir}/${label}-${name}-full.${EXT}`, SHOT);
        console.log(`  shot ${label}-${name} (${path})`);
      } catch (e) {
        errors.push(`[navfail] ${path} :: ${e.message}`);
        console.log(`  FAIL ${name} (${path}): ${e.message}`);
        await page.goto('about:blank').catch(() => {}); // reset, or the failure cascades into the next page
      }
    }
    await page.close();
  };
  await Promise.all(Array.from({ length: CONCURRENCY }, worker));

  console.log(errors.length ? `  JS errors:\n   ${errors.join('\n   ')}` : '  JS errors: none');
  await context.close();
}

await browser.close();
console.log(`\nDone. Screenshots under ${OUT}/<viewport>/ — read the JPEGs to inspect.`);
console.log(`BATCH_DIR=${OUT}`);
