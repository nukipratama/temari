import { mkdirSync, writeFileSync } from 'node:fs';
import { mascot } from './build-mascot.mjs';
import { COLOR, RARITY as RARITY_FILL, RARITY_INK, rootVars } from './build-tokens.mjs';

const CREAM = COLOR.cream, SKY = COLOR.sky, INK = COLOR.ink, INK3 = COLOR['ink-3'],
      LINE = COLOR.line, SUNKEN = COLOR['surface-sunken'], GOLD = COLOR.horizon;

/* Frame weight and treatment are the card's own concern; the colours come from
   the token set. `ink` is the text-safe variant — the rarity label is text. */
const FRAME = {
  common:    { w: 1.6, label: 'COMMON',    frame: 'plain'  },
  uncommon:  { w: 2.2, label: 'UNCOMMON',  frame: 'ticks'  },
  rare:      { w: 2.6, label: 'RARE',      frame: 'double' },
  epic:      { w: 3.0, label: 'EPIC',      frame: 'orn'    },
  legendary: { w: 3.4, label: 'LEGENDARY', frame: 'foil'   },
};
const RARITY = Object.fromEntries(Object.entries(FRAME).map(([k, v]) =>
  [k, { ...v, c: RARITY_FILL[k], ink: RARITY_INK[k] }]));

const W = 300, H = 440, PAD = 18, R = 18;
// RouteGlyph's real geometry: viewBox 100x64, pad 8, stroke thins with distance.
const RVB_W = 100, RVB_H = 64, RPAD = 8;

const SAMPLE = {
  distance: '8.2', date: '12 AUG 2026', pace: '5:12', time: '42:31', hr: '159',
  title: 'NEGATIVE SPLIT', badges: ['early bird', 'z2 master', 'climber'],
};

const mono = (x, y, s, t, { fill = INK, w = 700, anchor = 'start', ls = 0 } = {}) =>
  `<text x="${x}" y="${y}" font-family="JetBrains Mono, ui-monospace, monospace" font-size="${s}"
     font-weight="${w}" fill="${fill}" text-anchor="${anchor}" letter-spacing="${ls}">${t}</text>`;

const sans = (x, y, s, t, { fill = INK, w = 700, anchor = 'start', ls = 0 } = {}) =>
  `<text x="${x}" y="${y}" font-family="Plus Jakarta Sans, ui-sans-serif, system-ui" font-size="${s}"
     font-weight="${w}" fill="${fill}" text-anchor="${anchor}" letter-spacing="${ls}">${t}</text>`;

// Authored in RouteGlyph's own 100x64 viewBox so the mock and the component agree.
const ROUTE = 'M10 46 C16 34 24 50 33 43 C42 36 44 20 55 17 C66 14 72 27 67 36 '
            + 'C62 45 50 44 47 50 C44 55 54 56 64 54 C74 51 82 44 90 47';

/** Mirrors RouteGlyph: max(2.2, 3.8 - log2(km) * 0.5). */
const strokeForDistance = (km) =>
  km == null ? 3.8 : Math.max(2.2, 3.8 - Math.log2(Math.max(km, 1)) * 0.5);

/**
 * The card art window, matching RouteGlyph's three tiers:
 * real route -> pace bars -> faint mark watermark.
 */
function routeWindow(x, y, w, colour, { variant = 'route', km = 8.2, uid = '' } = {}) {
  const h = (w * RVB_H) / RVB_W;
  const inner = () => {
    if (variant === 'pace') {
      const paces = [312, 305, 318, 300, 296, 309, 322, 298, 291, 288];
      const fast = Math.min(...paces), slow = Math.max(...paces), range = slow - fast;
      const innerW = RVB_W - RPAD * 2, innerH = RVB_H - RPAD * 2, gap = 1.5;
      const bw = (innerW - gap * (paces.length - 1)) / paces.length;
      return paces.map((p, i) => {
        const norm = range > 0 ? (slow - p) / range : 1;
        const bh = norm * (innerH * 0.78) + innerH * 0.22;
        return `<rect x="${(RPAD + i * (bw + gap)).toFixed(1)}" y="${(RVB_H - RPAD - bh).toFixed(1)}"
          width="${bw.toFixed(1)}" height="${bh.toFixed(1)}" rx="1" fill="${colour}"
          opacity="${p === fast ? 0.9 : 0.4}"/>`;
      }).join('');
    }
    if (variant === 'glyph') {
      return `<rect width="${RVB_W}" height="${RVB_H}" fill="${colour}" opacity="0.1"/>
        <g transform="translate(32 14) scale(0.36)" opacity="0.5" fill="none" stroke-width="9" stroke-linecap="round">
          <path stroke="${SKY}" d="M50 14 A36 36 0 1 1 32 18.82"/>
          <path stroke="${SKY}" d="M50 25 A25 25 0 1 1 28.35 62.5"/>
          <path stroke="${SKY}" d="M50 36 A14 14 0 0 1 62.12 57"/></g>`;
    }
    return `<path class="rt" pathLength="1" d="${ROUTE}" fill="${colour}" fill-opacity="0.14"
        stroke="${colour}" stroke-width="${strokeForDistance(km).toFixed(2)}"
        stroke-linecap="round" stroke-linejoin="round"
        style="filter:drop-shadow(0 0 1.5px ${colour})"/>`;
  };
  return `<svg x="${x}" y="${y}" width="${w}" height="${h.toFixed(1)}"
     viewBox="0 0 ${RVB_W} ${RVB_H}" preserveAspectRatio="xMidYMid meet"
     data-variant="${variant}" data-uid="${uid}">${inner()}</svg>`;
}

/** The login hero's draw-in, as inline CSS so a standalone SVG animates on open. */
const DRAW_CSS = `<style>
    .rt { stroke-dasharray: 1; stroke-dashoffset: 1;
          animation: rt-draw 2.6s cubic-bezier(0.4,0,0.2,1) forwards; }
    @keyframes rt-draw { to { stroke-dashoffset: 0; } }
    @media (prefers-reduced-motion: reduce) {
      .rt { animation: none; stroke-dashoffset: 0; }
    }
  </style>`;

function frame(r, uid) {
  const { c, w, frame: kind } = r;
  const box = `<rect x="${w / 2}" y="${w / 2}" width="${W - w}" height="${H - w}" rx="${R}"
      fill="${CREAM}" stroke="${c}" stroke-width="${w}"/>`;
  if (kind === 'plain') return box;
  if (kind === 'ticks') {
    const t = (x, y, dx, dy) =>
      `<path d="M${x} ${y} h${dx} M${x} ${y} v${dy}" stroke="${c}" stroke-width="2" fill="none" stroke-linecap="round"/>`;
    return box + t(11, 11, 13, 13) + t(W - 11, 11, -13, 13) + t(11, H - 11, 13, -13) + t(W - 11, H - 11, -13, -13);
  }
  if (kind === 'double')
    return box + `<rect x="9" y="9" width="${W - 18}" height="${H - 18}" rx="${R - 5}"
      fill="none" stroke="${c}" stroke-width="0.9" opacity="0.55"/>`;
  if (kind === 'orn') {
    const orn = (x, y, sx, sy) => `<g transform="translate(${x} ${y}) scale(${sx} ${sy})">
      <path d="M0 16 L0 4 Q0 0 4 0 L16 0" fill="none" stroke="${c}" stroke-width="2.4" stroke-linecap="round"/>
      <circle cx="7" cy="7" r="1.8" fill="${c}"/></g>`;
    return box + `<rect x="9" y="9" width="${W - 18}" height="${H - 18}" rx="${R - 5}"
      fill="none" stroke="${c}" stroke-width="0.9" opacity="0.5"/>`
      + orn(16, 16, 1, 1) + orn(W - 16, 16, -1, 1) + orn(16, H - 16, 1, -1) + orn(W - 16, H - 16, -1, -1);
  }
  // foil — legendary
  const orn = (x, y, sx, sy) => `<g transform="translate(${x} ${y}) scale(${sx} ${sy})">
    <path d="M0 20 L0 5 Q0 0 5 0 L20 0" fill="none" stroke="${c}" stroke-width="2.8" stroke-linecap="round"/>
    <path d="M0 27 L0 22" stroke="${c}" stroke-width="2.8" stroke-linecap="round"/>
    <path d="M27 0 L22 0" stroke="${c}" stroke-width="2.8" stroke-linecap="round"/>
    <circle cx="8" cy="8" r="2.2" fill="${c}"/></g>`;
  const hatch = Array.from({ length: 9 }, (_, i) =>
    `<path d="M${-40 + i * 42} ${H} L${40 + i * 42} 0" stroke="${c}" stroke-width="9" opacity="0.05"/>`).join('');
  return `<g><rect x="${w / 2}" y="${w / 2}" width="${W - w}" height="${H - w}" rx="${R}" fill="${CREAM}"/>
    <g clip-path="url(#foil-${uid})">${hatch}</g>
    <rect x="${w / 2}" y="${w / 2}" width="${W - w}" height="${H - w}" rx="${R}" fill="none" stroke="${c}" stroke-width="${w}"/>
    <rect x="10" y="10" width="${W - 20}" height="${H - 20}" rx="${R - 6}" fill="none" stroke="${c}" stroke-width="1" opacity="0.6"/>
    ${orn(17, 17, 1, 1)}${orn(W - 17, 17, -1, 1)}${orn(17, H - 17, 1, -1)}${orn(W - 17, H - 17, -1, -1)}</g>`;
}

export function card(rarity, d = SAMPLE, { variant = 'route' } = {}) {
  const r = RARITY[rarity];
  const badge = (i, t) => {
    const x = PAD + 6 + i * 89;
    return `<g><rect x="${x}" y="350" width="82" height="21" rx="10.5" fill="${SUNKEN}"/>
      ${sans(x + 41, 364.5, 8.5, t.toUpperCase(), { fill: INK3, w: 700, anchor: 'middle', ls: 0.6 })}</g>`;
  };
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" role="img" aria-label="Run card — ${rarity}">
  <defs><clipPath id="foil-${rarity}"><rect x="0" y="0" width="${W}" height="${H}" rx="${R}"/></clipPath></defs>
  ${DRAW_CSS}
  ${frame(r, rarity)}

  ${mono(PAD + 6, 38, 9.5, d.date, { fill: INK3, w: 500, ls: 1.2 })}
  ${mono(W - PAD - 6, 38, 9.5, r.label, { fill: r.ink, w: 700, anchor: 'end', ls: 1.4 })}

  ${mono(PAD + 4, 106, 64, d.distance, { fill: SKY, w: 800, ls: -2.5 })}
  ${mono(PAD + 8 + d.distance.length * 38, 106, 18, 'KM', { fill: r.ink, w: 700, ls: 1 })}

  ${routeWindow(PAD + 6, 122, W - 2 * (PAD + 6), r.c, { variant, km: Number(d.distance), uid: rarity })}

  <path d="M${PAD + 6} 300 H${W - PAD - 6}" stroke="${LINE}" stroke-width="1"/>
  ${[['PACE', d.pace], ['TIME', d.time], ['HR', d.hr]].map(([k, v], i) => {
    const x = PAD + 6 + i * 89 + 41;
    return sans(x, 314, 8.5, k, { fill: INK3, w: 700, anchor: 'middle', ls: 1 })
         + mono(x, 334, 19, v, { fill: INK, w: 700, anchor: 'middle' });
  }).join('')}

  ${d.badges.slice(0, 3).map((b, i) => badge(i, b)).join('')}

  <rect x="${PAD + 6}" y="382" width="${W - 2 * (PAD + 6)}" height="26" rx="13" fill="${SKY}"/>
  ${sans(W / 2, 399, 11, d.title, { fill: CREAM, w: 800, anchor: 'middle', ls: 1.4 })}

  ${sans(W / 2, 424, 9, 'temari', { fill: INK3, w: 800, anchor: 'middle', ls: 1.2 })}
</svg>
`;
}

// ---- 9:16 share image ------------------------------------------------------
const SW = 270, SH = 480;

export function shareCard(rarity = 'rare', d = SAMPLE, uid = rarity) {
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${SW} ${SH}" width="${SW}" height="${SH}" role="img" aria-label="Share card">
  ${DRAW_CSS}
  <rect width="${SW}" height="${SH}" fill="${SKY}"/>
  ${mono(24, 56, 15, d.date, { fill: '#b0a3c9', w: 500, ls: 1.6 })}
  ${routeWindow(24, 76, SW - 48, GOLD, { km: Number(d.distance), uid: 'sh-' + uid })}
  ${mono(24, 290, 92, d.distance, { fill: CREAM, w: 800, ls: -4 })}
  ${mono(28 + d.distance.length * 55, 290, 26, 'KM', { fill: GOLD, w: 700, ls: 1 })}
  <path d="M24 312 H${SW - 24}" stroke="#3a2f7a" stroke-width="1.5"/>
  ${[['PACE', d.pace], ['TIME', d.time], ['HR', d.hr]].map(([k, v], i) => {
    const x = 24 + i * 76;
    return sans(x, 332, 10, k, { fill: '#b0a3c9', w: 700, ls: 1.2 })
         + mono(x, 356, 22, v, { fill: CREAM, w: 700 });
  }).join('')}
  <rect x="24" y="380" width="${SW - 48}" height="30" rx="15" fill="${GOLD}"/>
  ${sans(SW / 2, 399.5, 12, d.title, { fill: SKY, w: 800, anchor: 'middle', ls: 1.6 })}
  <g transform="translate(${SW - 74} 412)">${mascot('celebrating', { size: 54, id: 'share-' + uid })}</g>
  ${sans(24, SH - 28, 13, 'temari', { fill: COLOR['ink-on-sky'], w: 800, ls: -0.2 })}
</svg>
`;
}

export const RARITIES = Object.keys(RARITY);

function previewHtml() {
  const cell = (n) => `<div class="cell">${card(n)}<div class="cap"><b>${n}</b></div></div>`;
  return `<!doctype html>
<meta charset="utf-8">
<title>Temari — run cards</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;700;800&family=JetBrains+Mono:wght@500;700;800&display=swap" rel="stylesheet">
<style>
  :root{${rootVars()}}
  body { margin:0; padding:var(--pad-page); background:var(--cream); color:var(--ink);
         font:14px/1.5 "Plus Jakarta Sans", ui-sans-serif, system-ui, sans-serif; }
  h1 { font-size:20px; font-weight:700; margin:0 0 4px; }
  h2 { font-size:14px; font-weight:700; margin:52px 0 4px; text-transform:uppercase;
       letter-spacing:.08em; color:var(--ink-3); }
  p.sub { margin:0 0 28px; color:var(--ink-3); max-width:70ch; }
  .grid { display:flex; flex-wrap:wrap; gap:var(--s-6); align-items:flex-start; }
  .cap { font-size:11px; color:var(--ink-3); text-align:center; margin-top:10px; }
  .cap b { display:block; color:var(--ink); font-weight:700; font-size:12px; }
  svg { display:block; }
  .note { background:var(--surface-sunken); border-radius:14px; padding:var(--pad-panel); max-width:70ch;
          font-size:13px; margin-top:16px; }
</style>
<h1>Run cards — one layout, five rarities</h1>
<p class="sub">Every card is the same object: date and rarity on top, distance as the hero, the
route trace, three stats, up to three badges, the special move, the wordmark. Rarity changes the
frame only — never the layout — so a legendary reads as the same kind of thing as a common,
earned harder.</p>
<div class="grid">${RARITIES.map(cell).join('')}</div>

<h2>Share image · 9:16</h2>
<p class="sub">Built for a Story: readable while someone scrolls past it. Route is the hero, one
huge number, everything else supporting. This is the growth loop, so it carries the wordmark and
the promise.</p>
<div class="grid"><div>${shareCard('legendary')}</div><div>${shareCard('common', SAMPLE, 'b')}</div></div>

<h2>Art window · the three RouteGlyph tiers</h2>
<p class="sub">Matching <code>RouteGlyph.tsx</code> exactly: a decoded polyline when GPS exists,
a pace-shape bar glyph when it does not, and a faint mark watermark as the last resort — so a
treadmill run still gets a filled window. Stroke width thins with distance.</p>
<div class="grid">${[['route','real GPS'],['pace','no GPS — pace shape'],['glyph','no data — watermark']]
  .map(([v,cap]) => `<div class="cell">${card('rare', SAMPLE, { variant: v })}<div class="cap"><b>${v}</b>${cap}</div></div>`).join('')}</div>
<div class="note"><b>Text is live, not outlined.</b> These render correctly wherever Plus Jakarta
Sans and JetBrains Mono are loaded. For server-side share images the fonts must be present in the
rendering container, or the numbers fall back and the layout shifts.</div>
`;
}

if (process.argv[1]?.endsWith('build-cards.mjs')) {
  const out = new URL('./cards/', import.meta.url);
  mkdirSync(out, { recursive: true });
  for (const r of RARITIES) writeFileSync(new URL(`./card-${r}.svg`, out), card(r));
  writeFileSync(new URL('./share-9x16.svg', out), shareCard('legendary'));
  writeFileSync(new URL('./cards.html', import.meta.url), previewHtml());
  console.log(`wrote ${RARITIES.length} cards + share image + preview`);
}
