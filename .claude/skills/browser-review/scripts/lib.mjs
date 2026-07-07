// Shared helpers for the browser-review scripts. Runs inside the Sail `app`
// container, so `php artisan` is available to enumerate routes — no hardcoded
// page list to rot when pages are added.
import { execSync } from 'node:child_process';
import { devices } from 'playwright';

export const BASE = process.env.BASE ?? 'http://localhost';

// Both sides of the Tailwind lg(1024px) breakpoint, where the app swaps nav chrome.
export const VIEWPORT_DEFS = {
  mobile: { ...devices['iPhone 13'] },
  tablet: { viewport: { width: 834, height: 1112 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 },
  desktop: { viewport: { width: 1280, height: 800 } },
  wide: { viewport: { width: 1536, height: 864 } },
};

// tablet (834px) renders the same mobile nav chrome as mobile (390px) — both are
// below the lg breakpoint — so it's dropped from the default sweep to halve the
// screenshot/read cost. Opt back in with VIEWPORTS=tablet or VIEWPORTS=mobile,tablet,desktop,wide.
export function parseViewports() {
  return (process.env.VIEWPORTS ?? 'mobile,desktop,wide')
    .split(',').map((s) => s.trim()).filter(Boolean)
    .filter((v) => VIEWPORT_DEFS[v] || (console.log(`skip unknown viewport: ${v}`), false));
}

export async function login(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: /demo/i }).first().click({ timeout: 8000 });
  await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15000 });
}

// The demo user may have a pending card reveal overlaying every page. The sealed
// dialog still renders a "Tutup" button — click it once to clear it server-side.
export async function dismissReveal(page) {
  const dialog = page.getByRole('dialog', { name: /kartu baru/i });
  if (await dialog.isVisible().catch(() => false)) {
    await page.getByRole('button', { name: /tutup/i }).first().click().catch(() => {});
    await page.waitForTimeout(800);
  }
}

// URI patterns that are not screenshotable pages (apis, webhooks, auth handshakes, assets).
const SKIP = [
  /^api\//, /^auth\//, /^strava\/(webhook|sync)/, /^logout$/, /^client-errors$/,
  /^ai-usage$/, /^_/, /^up$/, /^storage\//, /\{.*\}.*\{/, // multi-param = not a simple page
];

/**
 * Enumerate GET page routes from Laravel itself. Returns [{ name, uri, path }]
 * where {param} routes are resolved to a real id by scraping the first matching
 * link off the list page (so /aktivitas/{activity} -> /aktivitas/126, live).
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
    // Single-param page: resolve the id from the list page (e.g. aktivitas/{activity}).
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
