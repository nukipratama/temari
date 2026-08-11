// Shared helpers for the browser-review scripts. Runs inside the Sail `app`
// container, so `php artisan` is available to enumerate routes — no hardcoded
// page list to rot when pages are added.
import { execSync } from 'node:child_process';
import { devices } from 'playwright';

export const BASE = process.env.BASE ?? 'http://localhost';

// Screenshots are JPEG, not PNG.
//
// A mobile shot renders at deviceScaleFactor 3 (iPhone 13: 390x844 logical =
// 1170x2532 real pixels) and fullPage makes it taller still. As PNG that lands
// around 666KB -- roughly 167k tokens. Anything read into the main context is
// re-billed as a cache read on EVERY later turn, so one such image read early in
// a long session costs far more than the single read suggests.
//
// Measured on a real 23-shot sweep of this app (17.58 MB as PNG):
//
//   q85  11.48 MB  1.5x     q75  9.57 MB  1.8x     q65  8.16 MB  2.2x
//   q80  10.11 MB  1.7x     q70  8.95 MB  2.0x     q60  7.40 MB  2.4x
//
// So ~2x, not the ~8x you might expect: UI screenshots are mostly flat colour,
// which PNG already encodes well. The gain is real but modest, and returns
// diminish below q70 while artefacts start showing on text. q70 it is.
//
// Resolution is deliberately NOT reduced. Lowering deviceScaleFactor throws away
// real detail, and post-hoc width capping backfired in testing -- `sips
// --resampleWidth` UPSCALES anything narrower than the target, so the iPhone SE
// shots (640px) got bigger and the batch ended up worse than plain q80.
//
// The bigger lever is not the format: it is not reading these into the main
// context at all. See the Inspect phase, which fans disjoint sets to subagents.
export const SHOT = { type: 'jpeg', quality: 70 };
export const EXT = 'jpg';

// Both sides of the Tailwind lg(1024px) breakpoint, where the app swaps nav chrome.
export const VIEWPORT_DEFS = {
  mobile: { ...devices['iPhone 13'] },
  se: { ...devices['iPhone SE'] },
  tablet: { viewport: { width: 834, height: 1112 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 },
  desktop: { viewport: { width: 1280, height: 800 } },
  wide: { viewport: { width: 1536, height: 864 } },
};

// tablet (834px) renders the same mobile nav chrome as mobile (390px) — both are
// below the lg breakpoint — so it's dropped from the default sweep to halve the
// screenshot/read cost. Opt back in with VIEWPORTS=tablet or VIEWPORTS=mobile,tablet,desktop,wide.
//
// se (iPhone SE, 320px) stays in the default sweep despite sharing mobile's nav chrome:
// unlike tablet, it's not redundant with mobile — its narrower raw width has caught real
// overflow mobile (390px) missed entirely (a grid track sized to its widest child instead
// of shrinking, a fluid font clamp whose floor was tuned for a wider column and silently
// ellipsis-truncated real values). Width-driven CSS bugs like these don't reproduce at 390px.
export function parseViewports() {
  return (process.env.VIEWPORTS ?? 'mobile,se,desktop,wide')
    .split(',').map((s) => s.trim()).filter(Boolean)
    .filter((v) => VIEWPORT_DEFS[v] || (console.log(`skip unknown viewport: ${v}`), false));
}

export async function login(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: /demo/i }).first().click({ timeout: 8000 });
  await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15000 });
}

// The demo user may have a pending card reveal overlaying every page. The sealed
// dialog still renders a "Close" button — click it once to clear it server-side.
// CardReveal is lazy-loaded (see AppShell.tsx), so it isn't in the DOM yet right
// after login: an instant isVisible() check races the chunk load and misses it,
// leaving the reveal stuck open for the rest of the run. Wait for it instead.
export async function dismissReveal(page) {
  const dialog = page.getByRole('dialog', { name: /new card/i });
  const appeared = await dialog.waitFor({ state: 'visible', timeout: 2000 }).then(() => true).catch(() => false);
  if (appeared) {
    await page.getByRole('button', { name: /close/i }).first().click().catch(() => {});
    await page.waitForTimeout(800);
  }
}

// Chromium can't rasterize a screenshot taller than ~32,767 physical pixels —
// past that it doesn't throw, it silently writes a 0-byte file. A long
// unpaginated list (e.g. /activities with enough seeded runs) times deviceScaleFactor
// 3 on mobile crosses that with room to spare. Guard it explicitly instead of
// shipping a broken capture with no failure signal.
const MAX_CANVAS_PX = 32000;

export async function fullPageScreenshot(page, path, shotOpts) {
  const dpr = await page.evaluate(() => window.devicePixelRatio);
  const scrollHeight = await page.evaluate(() => document.documentElement.scrollHeight);
  const physical = scrollHeight * dpr;
  if (physical > MAX_CANVAS_PX) {
    console.log(`  SKIP full-page shot: ${scrollHeight}px x ${dpr}dpr = ${Math.round(physical)}px physical exceeds Chromium's ~32,767px cap`);
    return false;
  }
  await page.screenshot({ path, fullPage: true, ...shotOpts });
  return true;
}

// URI patterns that are not screenshotable pages (apis, webhooks, auth handshakes, assets).
const SKIP = [
  /^api\//, /^auth\//, /^strava\/(webhook|sync)/, /^logout$/, /^client-errors$/,
  /^ai-usage$/, /^devtools/, /^_/, /^up$/, /^storage\//, /\{.*\}.*\{/, // multi-param = not a simple page
];

/**
 * Enumerate GET page routes from Laravel itself. Returns [{ name, uri, path }]
 * where {param} routes are resolved to a real id by scraping the first matching
 * link off the list page (so /activities/{activity} -> /activities/126, live).
 * Unresolvable param routes are dropped (and logged — usually means thin data,
 * so re-run `artisan demo:seed`).
 */
export async function discoverPageRoutes(page) {
  const raw = execSync('php artisan route:list --json --except-vendor', { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 });
  const routes = JSON.parse(raw);
  const pages = [];

  for (const r of routes) {
    const methods = (r.method ?? '').split('|');
    if (!methods.includes('GET')) continue;
    const mw = Array.isArray(r.middleware) ? r.middleware.join(' ') : (r.middleware ?? '');
    if (!mw.includes('web')) continue;            // skip api/console
    if (mw.includes('guest')) continue;           // login/oauth handshakes — /login shot separately
    if ((r.action ?? '').includes('RedirectController')) continue; // legacy 301 aliases
    const uri = (r.uri ?? '').replace(/^\//, '');
    if (SKIP.some((re) => re.test(uri))) continue;

    if (!uri.includes('{')) {
      pages.push({ name: uri === '/' || uri === '' ? 'hari-ini' : uri.replaceAll('/', '-'), path: `/${uri}` });
      continue;
    }
    // Single-param page: resolve the id from the list page (e.g. activities/{activity}).
    const base = uri.split('/{')[0];
    await page.goto(`${BASE}/${base}`, { waitUntil: 'networkidle' }).catch(() => {});
    const href = await page.locator(`a[href^="/${base}/"]`).first().getAttribute('href').catch(() => null);
    if (href && new RegExp(`^/${base}/[^/]+$`).test(href)) {
      pages.push({ name: `${base}-detail`, path: href });
    } else {
      console.log(`  (no sample for /${base}/{id} — thin data? try: sail artisan demo:seed)`);
    }
  }
  return pages;
}
