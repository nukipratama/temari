import { writeFileSync, mkdirSync } from 'node:fs';
import { COLOR } from './build-tokens.mjs';

/* "Nested arcs" reads calm because it is concentric and starts at 12 o'clock —
   the progress-ring register. Same idea, given motion: lean, taper, and a broken
   centre so the laps drift forward instead of stacking. */

const SKY = COLOR.sky, GOLD = COLOR['horizon-ink'];
const CX = 50, CY = 52;

const pt = (cx, cy, r, deg) => [
  cx + r * Math.cos((deg * Math.PI) / 180),
  cy - r * Math.sin((deg * Math.PI) / 180),
];

/** Constant-width arc, clockwise from a0 by `sweep` degrees. */
function arc(cx, cy, r, a0, sweep) {
  const [x0, y0] = pt(cx, cy, r, a0);
  const [x1, y1] = pt(cx, cy, r, a0 - sweep);
  return `M${x0.toFixed(2)} ${y0.toFixed(2)} A${r} ${r} 0 ${sweep > 180 ? 1 : 0} 1 ${x1.toFixed(2)} ${y1.toFixed(2)}`;
}

/**
 * A tapered ribbon along an arc: sampled, then offset perpendicular by a
 * lerped half-width. Thin at the tail, thick at the head — the head is where
 * the runner is now, so the mark accelerates forward instead of sitting still.
 */
function taperedArc(cx, cy, r, a0, sweep, wTail, wHead, steps = 64) {
  const outer = [], inner = [];
  for (let i = 0; i <= steps; i++) {
    const t = i / steps;
    const a = a0 - sweep * t;
    const hw = (wTail + (wHead - wTail) * t) / 2;
    outer.push(pt(cx, cy, r + hw, a));
    inner.push(pt(cx, cy, r - hw, a));
  }
  const d = [
    `M${outer[0][0].toFixed(2)} ${outer[0][1].toFixed(2)}`,
    ...outer.slice(1).map(([x, y]) => `L${x.toFixed(2)} ${y.toFixed(2)}`),
    ...inner.reverse().map(([x, y]) => `L${x.toFixed(2)} ${y.toFixed(2)}`),
    'Z',
  ];
  return d.join(' ');
}

const LAPS = [
  { r: 36, sweep: 330, w: [2.5, 10.5], colour: 'lead' },
  { r: 24, sweep: 240, w: [2.0, 8.0], colour: 'base' },
  { r: 13, sweep: 130, w: [1.6, 6.0], colour: 'base' },
];

const fill = (c) => (c === 'lead' ? GOLD : SKY);

const VARIANTS = {
  'v0-current': () => `<g fill="none" stroke-width="9" stroke-linecap="round">
    ${LAPS.map((l) => `<path stroke="${fill(l.colour)}" d="${arc(CX, CY, l.r, 90, l.sweep)}"/>`).join('\n    ')}
  </g>`,

  'v1-lean': () => `<g transform="translate(9 0) skewX(-10)" fill="none" stroke-width="9" stroke-linecap="round">
    ${LAPS.map((l) => `<path stroke="${fill(l.colour)}" d="${arc(CX, CY, l.r, 90, l.sweep)}"/>`).join('\n    ')}
  </g>`,

  'v2-taper': () => `<g>
    ${LAPS.map((l) => `<path fill="${fill(l.colour)}" d="${taperedArc(CX, CY, l.r, 90, l.sweep, l.w[0], l.w[1])}"/>`).join('\n    ')}
  </g>`,

  'v3-taper-lean': () => `<g transform="translate(9 0) skewX(-10)">
    ${LAPS.map((l) => `<path fill="${fill(l.colour)}" d="${taperedArc(CX, CY, l.r, 90, l.sweep, l.w[0], l.w[1])}"/>`).join('\n    ')}
  </g>`,

  // laps drift forward instead of stacking concentrically — a stroboscopic trail
  'v4-drift': () => `<g fill="none" stroke-width="9" stroke-linecap="round">
    ${LAPS.map((l, i) => `<path stroke="${fill(l.colour)}" d="${arc(CX - i * 5, CY + i * 1.5, l.r, 90, l.sweep)}"/>`).join('\n    ')}
  </g>`,

  'v5-taper-drift-lean': () => `<g transform="translate(8 0) skewX(-9)">
    ${LAPS.map((l, i) => `<path fill="${fill(l.colour)}" d="${taperedArc(CX - i * 4.5, CY + i * 1.2, l.r, 90, l.sweep, l.w[0], l.w[1])}"/>`).join('\n    ')}
  </g>`,

  // start at 10 o'clock and open the gaps: reads as a swoosh, not a ring
  'v6-swoosh': () => `<g transform="translate(7 0) skewX(-8)">
    ${LAPS.map((l) => `<path fill="${fill(l.colour)}" d="${taperedArc(CX, CY, l.r, 150, l.sweep * 0.78, l.w[0], l.w[1] * 1.1)}"/>`).join('\n    ')}
  </g>`,
};

const NOTE = {
  'v0-current': 'what we have — concentric, upright, calm',
  'v1-lean': 'same arcs, skewed 10° forward',
  'v2-taper': 'strokes thin at the tail, thick at the head',
  'v3-taper-lean': 'taper + lean',
  'v4-drift': 'centres drift forward — a stroboscopic trail',
  'v5-taper-drift-lean': 'all three: taper, drift, lean',
  'v6-swoosh': 'starts at 10 o’clock, gaps opened — reads as a swoosh',
};

export const NAMES = Object.keys(VARIANTS);
export const svg = (name, size = 100) =>
  `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="${size}" height="${size}" role="img" aria-label="${name}">
  ${VARIANTS[name]()}
</svg>
`;

function html() {
  const row = (n) => `
    <div class="row">
      <div class="name">${n.replace(/^v\d-/, '')}<span>${NOTE[n]}</span></div>
      <div class="big">${svg(n, 128)}</div>
      <div class="sizes">${[48, 28, 20].map((s) => svg(n, s)).join('')}
        <div class="chip">${svg(n, 28)}</div></div>
    </div>`;
  return `<!doctype html>
<meta charset="utf-8">
<title>temari — sportier marks</title>
<style>
  :root{--cream:#f5f0e4;--sky:#241c54;--ink:#1a1812;--ink3:#6e6452;--line:#d2c9b0}
  body{margin:0;padding:48px 40px 90px;background:var(--cream);color:var(--ink);
       font:14px/1.55 ui-sans-serif,system-ui,-apple-system,sans-serif}
  h1{font-size:20px;font-weight:700;margin:0 0 4px}
  p.sub{margin:0 0 30px;color:var(--ink3);max-width:70ch}
  .heads{display:flex;gap:20px;padding-left:290px;font-size:11px;color:var(--ink3);
         text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px}
  .row{display:flex;align-items:center;gap:22px;padding:16px 0;border-top:1px solid var(--line)}
  .name{width:150px;flex:none;font-weight:600;font-size:13px}
  .name span{display:block;font-weight:400;color:var(--ink3);font-size:11.5px;margin-top:3px}
  .big{width:128px;flex:none}
  .sizes{display:flex;align-items:center;gap:20px}
  .chip{background:var(--sky);border-radius:11px;padding:9px;display:flex}
  .chip svg path{fill:var(--cream);stroke:var(--cream)}
  svg{display:block}
</style>
<h1>Sportier — same idea, with motion in it</h1>
<p class="sub">The concept holds: laps, each longer than the last, gold on the most recent. What was
missing is <b>motion</b>. Three levers, combined below — <b>lean</b> (forward skew), <b>taper</b>
(strokes thin at the tail and thick at the head, so the mark accelerates rather than sits), and
<b>drift</b> (centres shift forward so laps trail instead of stacking concentrically). Top row is
the current mark for comparison.</p>
<div class="heads"><div>128</div></div>
${NAMES.map(row).join('')}
`;
}

if (process.argv[1]?.endsWith('build-marks-sporty.mjs')) {
  const out = new URL('./marks-sporty/', import.meta.url);
  mkdirSync(out, { recursive: true });
  for (const n of NAMES) writeFileSync(new URL(`./${n}.svg`, out), svg(n));
  writeFileSync(new URL('./marks-sporty.html', import.meta.url), html());
  console.log(`wrote ${NAMES.length} variants + preview`);
}
