import { chromium } from 'playwright';
import { BASE, VIEWPORT_DEFS, login, dismissReveal, discoverPageRoutes } from './lib.mjs';

const theme = globalThis.process.argv[2] ?? 'dark';

/**
 * Real rendered contrast, not token pairings: for every element that actually
 * paints text, resolve its effective background by walking ancestors past any
 * transparent fill, then score it.
 */
const AUDIT = () => {
  const lum = (c) => {
    const [r, g, b] = c.map((v) => {
      const s = v / 255;
      return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  };
  const parse = (s) => {
    const m = s.match(/rgba?\(([^)]+)\)/);
    if (!m) return null;
    const p = m[1].split(/[,\s/]+/).filter(Boolean).map(Number);
    return { rgb: p.slice(0, 3), a: p.length > 3 ? p[3] : 1 };
  };
  const over = (fg, bg) => fg.rgb.map((v, i) => v * fg.a + bg[i] * (1 - fg.a));

  // Null means "cannot be resolved to a flat colour" -- a gradient, image, map
  // tile or video behind the text. Scoring those against an ancestor's colour
  // invents a failure that is not on screen, so they are skipped instead.
  const bgOf = (el) => {
    let node = el;
    let acc = null;
    while (node && node !== document.documentElement.parentNode) {
      const cs = getComputedStyle(node);
      if (cs.backgroundImage && cs.backgroundImage !== 'none') return null;
      const cls = node.className?.toString?.() ?? '';
      if (/leaflet/.test(cls)) return null;
      if (node.querySelector && node.querySelector(':scope > img, :scope > canvas, :scope > video')) return null;
      const c = parse(cs.backgroundColor);
      if (c && c.a > 0) {
        acc = acc === null ? (c.a === 1 ? c.rgb : c) : acc;
        if (c.a === 1) return acc.rgb ? over(acc, c.rgb) : c.rgb;
      }
      node = node.parentElement;
    }
    return [255, 255, 255]; // nothing opaque in the chain; see SKILL.md on this fallback
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

    const fg = parse(cs.color);
    if (!fg) continue;
    const bg = bgOf(el);
    if (bg === null) continue;
    const fgRgb = fg.a === 1 ? fg.rgb : over(fg, bg);
    const [a, b] = [lum(fgRgb), lum(bg)];
    const ratio = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);

    const px = parseFloat(cs.fontSize);
    const bold = +cs.fontWeight >= 700;
    const min = px >= 24 || (bold && px >= 18.66) ? 3 : 4.5;
    if (ratio < min) {
      out.push({
        ratio: +ratio.toFixed(2),
        min,
        px: Math.round(px),
        text: el.textContent.trim().replace(/\s+/g, ' ').slice(0, 45),
        cls: (el.className?.toString?.() ?? '').slice(0, 70),
      });
    }
  }
  return out;
};

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
await dismissReveal(page);

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
