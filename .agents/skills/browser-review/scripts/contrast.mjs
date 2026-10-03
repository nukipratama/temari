import { chromium } from 'playwright';
import { BASE, VIEWPORT_DEFS, login, discoverPageRoutes } from './lib.mjs';
import { HELPERS } from './scans.mjs';

const theme = globalThis.process.argv[2] ?? 'dark';

/**
 * Real rendered contrast, not token pairings: for every element that actually
 * paints text, resolve its effective background by walking ancestors and
 * compositing every translucent layer on the way, then score it.
 */
const AUDIT = `(() => {
  ${HELPERS}

  // Null means "cannot be resolved to a flat colour" -- a gradient, image, map
  // tile or video behind the text. Scoring those against an ancestor's colour
  // invents a failure that is not on screen, so they are skipped instead.
  const bgOf = (el) => {
    const stack = [];
    let node = el;
    while (node) {
      const cs = getComputedStyle(node);
      if (cs.backgroundImage && cs.backgroundImage !== 'none') return null;
      const cls = node.className?.toString?.() ?? '';
      if (/leaflet/.test(cls)) return null;
      if (node.querySelector && node.querySelector(':scope > img, :scope > canvas, :scope > video')) return null;
      const c = rgb(cs.backgroundColor);
      if (c && c.a > 0) {
        if (c.a === 1) return stack.reverse().reduce((out, layer) => over(layer, out), c.c);
        stack.push(c);
      }
      node = node.parentElement;
    }
    return stack.reverse().reduce((out, layer) => over(layer, out), [255, 255, 255]);
  };

  const out = [];
  for (const el of document.querySelectorAll('*')) {
    const direct = [...el.childNodes].some(
      (n) => n.nodeType === 3 && n.textContent.trim().length > 1,
    );
    if (!direct) continue;
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.display === 'none' || +cs.opacity === 0) continue;
    const r = el.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) continue;

    const fg = rgb(cs.color);
    if (!fg) continue;
    const bg = bgOf(el);
    if (bg === null) continue;
    const score = ratio(fg.a === 1 ? fg.c : over(fg, bg), bg);

    const px = parseFloat(cs.fontSize);
    const bold = +cs.fontWeight >= 700;
    const min = px >= 24 || (bold && px >= 18.66) ? 3 : 4.5;
    if (score < min) {
      out.push({
        ratio: +score.toFixed(2),
        min,
        px: Math.round(px),
        text: el.textContent.trim().replace(/\\s+/g, ' ').slice(0, 45),
        cls: (el.className?.toString?.() ?? '').slice(0, 70),
      });
    }
  }
  return out;
})()`;

const browser = await chromium.launch({
  executablePath: '/usr/bin/chromium',
  args: ['--no-sandbox', '--disable-dev-shm-usage'],
});
const ctx = await browser.newContext({ ...VIEWPORT_DEFS.desktop });
await ctx.addInitScript((t) => {
  try { localStorage.setItem('temari-theme', t); } catch { /* private mode */ }
}, theme);
const page = await ctx.newPage();
await login(page);

const pages = await discoverPageRoutes(page);
let total = 0;
for (const p of pages) {
  await page.goto(`${BASE}${p.path}`, { waitUntil: 'networkidle' }).catch(() => {});
  const rows = await page.evaluate(AUDIT);
  const seen = new Map();
  for (const r of rows) seen.set(`${r.cls}|${r.ratio}`, r);
  if (seen.size) {
    console.log(`\n## ${theme} ${p.path} — ${seen.size}`);
    for (const r of seen.values()) {
      console.log(`  ${r.ratio} (min ${r.min}, ${r.px}px) "${r.text}"  ::  ${r.cls}`);
    }
    total += seen.size;
  }
}
console.log(`\nTOTAL ${theme}: ${total}`);
await browser.close();
