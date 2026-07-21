// Horizontal-overflow audit across the viewport matrix. Runs inside the Sail
// `app` container:  ./vendor/bin/sail exec app node .claude/skills/browser-review/scripts/audit.mjs
// Pages are discovered from `artisan route:list` (lib.mjs); overflow is
// breakpoint-dependent, so every discovered page is checked at every viewport.
import { chromium } from 'playwright';
import { BASE, VIEWPORT_DEFS, parseViewports, login, dismissReveal, discoverPageRoutes } from './lib.mjs';

const selected = parseViewports();

const browser = await chromium.launch({
  executablePath: '/usr/bin/chromium',
  args: ['--no-sandbox', '--disable-dev-shm-usage'],
});

for (const vp of selected) {
  const def = VIEWPORT_DEFS[vp];
  const context = await browser.newContext(def);
  const page = await context.newPage();
  console.log(`\n##### ${vp} (${def.viewport.width}x${def.viewport.height}) #####`);
  await login(page);
  await dismissReveal(page);
  const routes = await discoverPageRoutes(page);

  const seen = new Set();
  for (const { name, path } of routes) {
    await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle', timeout: 20000 });
    const landed = new URL(page.url()).pathname;
    if (seen.has(landed)) continue;
    seen.add(landed);
    await page.waitForTimeout(600);
    const r = await page.evaluate(() => {
      const vw = document.documentElement.clientWidth;
      const docW = document.documentElement.scrollWidth;
      const real = [];
      for (const el of document.querySelectorAll('*')) {
        const rect = el.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0) continue;
        if (rect.right > vw + 1) {
          // Ignore intentional scroll containers, decorative non-interactive glows, and
          // Leaflet's internal tile buffer (it always renders past the visible map edge
          // for smooth panning, clipped by the map's own overflow-hidden — never visible).
          const inScroller = el.closest('[class*="overflow-x-auto"],[class*="overflow-auto"],[class*="overflow-scroll"]');
          const decorative = el.closest('[class*="pointer-events-none"]');
          const leafletTile = el.closest('.leaflet-tile-pane');
          if ((inScroller && inScroller !== el) || decorative || leafletTile) continue;
          real.push({ tag: el.tagName.toLowerCase(), cls: (el.getAttribute('class') || '').slice(0, 70), right: Math.round(rect.right) });
        }
      }
      // docW > vw alone misses clipped overflow: an `overflow-hidden` ancestor stops
      // scrollWidth from growing while still visually cutting off descendants (e.g. a
      // grid track sized to its widest child's min-content instead of shrinking to fit),
      // so a page can have real off-screen elements in `real` while docW == vw.
      return { vw, docW, overflow: docW > vw + 1 || real.length > 0, real: real.sort((a, b) => b.right - a.right).slice(0, 5) };
    });
    console.log(`  ${landed.padEnd(22)} vw=${r.vw} docW=${r.docW} ${r.overflow ? 'HORIZ-OVERFLOW=true ⚠' : 'ok'}`);
    // Machine-parseable line: `name` matches the slug shoot.mjs uses in its filenames
    // (*-<name>-full.jpg), so the inspect workflow can glob-match flagged pages without
    // needing shoot.mjs's numeric prefix (audit and shoot run independently).
    console.log(`AUDIT vp=${vp} name=${name} overflow=${r.overflow}`);
    for (const o of r.real) console.log(`      right=${o.right} <${o.tag} class="${o.cls}">`);
  }
  await context.close();
}

await browser.close();
