// End-to-end screenshot sweep across a viewport matrix. Runs inside the Sail
// `app` container:  ./vendor/bin/sail exec app node .claude/skills/browser-review/scripts/shoot.mjs
// Env: VIEWPORTS=mobile,se,tablet,desktop,wide (default mobile,se,desktop,wide)  BASE=http://localhost
//      OUT=storage/app/browser-review  BATCH=<date>/<time> (override the run key)
// Pages are discovered from `artisan route:list` (see lib.mjs) — nothing hardcoded.
import { rmSync } from 'node:fs';
import { chromium } from 'playwright';
import { BASE, VIEWPORT_DEFS, parseViewports, login, dismissReveal, discoverPageRoutes, SHOT, EXT } from './lib.mjs';

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

for (const vp of selected) {
  const def = VIEWPORT_DEFS[vp];
  const dir = `${OUT}/${vp}`;
  const errors = [];
  const context = await browser.newContext(def);
  const page = await context.newPage();
  page.on('console', (m) => { if (m.type() === 'error') errors.push(`[console] ${page.url()} :: ${m.text()}`); });
  page.on('pageerror', (e) => errors.push(`[pageerror] ${page.url()} :: ${e.message}`));

  console.log(`\n=== ${vp} (${def.viewport.width}x${def.viewport.height}) ===`);
  // Guest login page first, then authenticate and discover the rest.
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: `${dir}/00-login-full.${EXT}`, fullPage: true, ...SHOT });
  await login(page);
  await dismissReveal(page);
  const routes = await discoverPageRoutes(page);
  console.log(`  discovered ${routes.length} pages`);

  const seen = new Set();
  let i = 1;
  for (const { name, path } of routes) {
    try {
      await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle', timeout: 20000 });
      const landed = new URL(page.url()).pathname;
      if (seen.has(landed)) { continue; }            // dedupe redirects to an already-shot page
      seen.add(landed);
      await page.waitForTimeout(800);
      const idx = String(i).padStart(2, '0');
      await page.screenshot({ path: `${dir}/${idx}-${name}-viewport.${EXT}`, fullPage: false, ...SHOT });
      await page.screenshot({ path: `${dir}/${idx}-${name}-full.${EXT}`, fullPage: true, ...SHOT });
      console.log(`  shot ${idx}-${name} (${path})`);
      i++;
    } catch (e) {
      errors.push(`[navfail] ${path} :: ${e.message}`);
      console.log(`  FAIL ${name} (${path}): ${e.message}`);
    }
  }
  console.log(errors.length ? `  JS errors:\n   ${errors.join('\n   ')}` : '  JS errors: none');
  await context.close();
}

await browser.close();
console.log(`\nDone. Screenshots under ${OUT}/<viewport>/ — read the JPEGs to inspect.`);
console.log(`BATCH_DIR=${OUT}`);
