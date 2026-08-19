import { writeFileSync } from 'node:fs';

import { contrast, darkest, groundsForInk, paperGrounds } from './grounds.mjs';

/* Threadwork v2 — the token set the current app is missing.
   Colour was already largely defined; radius and elevation were not, which is
   the main reason surfaces read inconsistently. Type drops from 4 families to 3. */

const COLOR = {
  // structure — Pewter: cold near-black
  sky:            '#171f28',
  'sky-deep':     '#0b1017',
  'sky-2':        '#26303d',
  // accent / earned — Pewter: lime
  horizon:        '#ade047',
  'horizon-deep': '#95c134',
  // paper — Pewter: cold near-white
  cream:          '#f1f5f8',
  'cream-deep':   '#e2e8ee',
  // text, 3 tiers + on-dark
  ink:            '#16181b',
  'ink-2':        '#34373c',
  'ink-3':        '#60666d',
  'ink-on-sky':   '#9c9ea7',
  // surfaces
  surface:          '#f1f5f8',
  'surface-card':   '#f1f5f8',
  'surface-elev':   '#f8fbfe',
  'surface-warm':   '#f0f5fb',
  'surface-sunken': '#e2e8ee',
  // line/line-strong already drifted from app.css before this change (this
  // file predates the current live values) — left as-is, not this change's
  // drift to fix.
  line:             '#d2c9b0',
  'line-strong':    '#c3b89c',
  // semantic accents — held constant across every direction (not brand)
  leaf: '#2f8f63', 'leaf-deep': '#256f4d',
  ember: '#b23a4f', 'ember-deep': '#8d2c3d',
  citrus: '#c9971f',
  stone: '#64686c',
};

const MOOD = {
  blazing: '#c9971f', easy: '#2f8f63', wobbly: '#b23a4f',
  gassed: '#7a2030', overloaded: '#6b3fa0', chill: '#55488f',
};

/* Tinted cell behind a mood chip. Only that mood's own -ink lands here, so it
   is a ground for one family rather than paper for all of them. */
const MOOD_BG = {
  'mood-blazing-bg': '#f3e6c2', 'mood-easy-bg': '#d7ecdf', 'mood-wobbly-bg': '#f0d3d8',
  'mood-gassed-bg': '#e3c2c7', 'mood-overloaded-bg': '#ded0ee', 'mood-chill-bg': '#dcd8ee',
};

const RARITY = {
  common: '#7d8694', uncommon: '#2fb350', rare: '#2f81f7',
  epic: '#a855f7', legendary: '#f5a623',
};

/* NEW — no radius scale existed; radii came from Tailwind defaults at call sites. */
const RADIUS = {
  xs: '6px', sm: '10px', md: '14px', lg: '18px', xl: '24px', full: '9999px',
};

/* NEW — no spacing scale existed either, so every padding was hand-picked
   (23 distinct values across the previews before this landed). 4px base. */
const SPACE = { 1: '4px', 2: '8px', 3: '12px', 4: '16px', 5: '20px',
                6: '24px', 8: '32px', 10: '40px', 12: '48px', 16: '64px' };

/* Named roles, so a component asks for its *kind* rather than a number.
   Mirrors the rhythm already documented in docs/design-tokens.md. */
const PAD = {
  chip:  '4px 12px',
  panel: '12px 16px',
  card:  '16px',
  hero:  '24px',
  page:  '48px 40px 80px',
};

/* NEW — no shadow tokens existed. Shadows are warm-tinted: a neutral black
   shadow on a cream ground reads grey and dirty. */
const SHADOW = {
  e1: '0 1px 2px rgba(58,45,20,0.06), 0 1px 1px rgba(58,45,20,0.04)',
  e2: '0 4px 10px rgba(58,45,20,0.08), 0 2px 4px rgba(58,45,20,0.05)',
  e3: '0 12px 28px rgba(58,45,20,0.12), 0 4px 10px rgba(58,45,20,0.07)',
  e4: '0 24px 56px rgba(23,15,56,0.20), 0 8px 20px rgba(23,15,56,0.12)',
};

/* 4 families -> 3. Oswald is dropped: it was Kartu-only and is the reason the
   collection reads as a different product. Cards now use the same stack. */
const FONT = {
  sans:    "'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif",
  mono:    "'JetBrains Mono', ui-monospace, SFMono-Regular, monospace",
  display: "'Fraunces', Georgia, serif",
};

// ---- contrast ---------------------------------------------------------------
// A saturated colour cannot be both a fill and text on cream. Vivid values stay
// for fills/dots/strokes; each family also gets an `-ink` variant, darkened until it
// clears 4.5:1 on paper, for labels and icons. Derived, so it cannot drift.
const toRgb = (h) => { const n = parseInt(h.slice(1), 16); return [(n >> 16) & 255, (n >> 8) & 255, n & 255]; };
const toHex = (a) => '#' + a.map((v) => Math.round(Math.max(0, Math.min(255, v))).toString(16).padStart(2, '0')).join('');
/** Darken until the colour clears `target` against every ground in `grounds`. */
export function inkOn(hex, grounds, target = 4.5) {
  const against = Object.values(grounds);
  for (let f = 1; f > 0; f -= 0.0025) {
    const c = toHex(toRgb(hex).map((v) => v * f));
    if (against.every((bg) => contrast(c, bg) >= target)) return c;
  }
  return hex;
}

/* Read out of app.css and resources/js rather than written down here — see
   resources/brand/grounds.mjs. */
export const GROUNDS = paperGrounds();

/** The ground a token has to survive: the darkest paper the app can render. */
export const PAPER = darkest(GROUNDS);

/** Worst ratio a foreground scores across a ground set, and where it scored it. */
export function worstOn(fg, grounds = GROUNDS) {
  return Object.entries(grounds)
    .map(([ground, bg]) => ({ ground, ratio: contrast(fg, bg) }))
    .reduce((a, b) => (a.ratio <= b.ratio ? a : b));
}

/* Keyed the way the tokens ship (`mood-easy`, `rarity-epic`), not the way the
   MOOD/RARITY maps are keyed, so a family name resolves to its fill. */
const FILLS = {
  ...COLOR,
  ...MOOD_BG,
  ...Object.fromEntries(Object.entries(MOOD).map(([k, v]) => [`mood-${k}`, v])),
  ...Object.fromEntries(Object.entries(RARITY).map(([k, v]) => [`rarity-${k}`, v])),
};

const inkGrounds = (family) => groundsForInk(family, FILLS, GROUNDS);

const RARITY_INK = Object.fromEntries(
  Object.entries(RARITY).map(([k, v]) => [k, inkOn(v, inkGrounds(`rarity-${k}`))]),
);
const MOOD_INK = Object.fromEntries(
  Object.entries(MOOD).map(([k, v]) => [k, inkOn(v, inkGrounds(`mood-${k}`))]),
);
/* Accent families that carry text. `-deep` is a CTA fill and stays one; the
   `-ink` member is what a label reaches for. */
const INK_FAMILIES = ['horizon', 'leaf', 'ember', 'citrus'];
for (const family of INK_FAMILIES) {
  COLOR[`${family}-ink`] = inkOn(COLOR[family], inkGrounds(family));
}
COLOR.line = inkOn(COLOR.line, GROUNDS, 1.4);

const PAIRS = [
  // text on paper — must clear 4.5
  ['ink', 'paper', 'body text', 4.5],
  ['ink-2', 'paper', 'secondary text', 4.5],
  ['ink-3', 'paper', 'meta text', 4.5],
  // text on dark
  ['cream', 'sky', 'text on indigo', 4.5],
  ['ink-on-sky', 'sky', 'muted on indigo', 4.5],
  // text on a fill — the CTA cases
  ['ink', 'horizon', 'text on gold CTA', 4.5],
  ['cream', 'leaf-deep', 'text on leaf CTA', 4.5],
  ['cream', 'ember-deep', 'text on ember CTA', 4.5],
  ['cream', 'sky-2', 'text on sky-2', 4.5],
  // non-text UI — 3.0 for meaningful graphics, 1.4 for separators
  ['horizon', 'sky', 'gold mark on indigo', 3.0],
  ['line', 'paper', 'separator', 1.4],
];

/* A pair whose ground is `paper` is scored on every ground the app can paint
   under text — every dawn-shift surface and every background grounds.json calls
   paper — and reported at its worst, so a token that only clears AA on the
   lightest of them fails here. */
const scored = (fg, hex, bg, use, min, extra = {}) => {
  if (bg !== 'paper') {
    const ratio = contrast(hex, COLOR[bg]);
    return { fg, bg, use, min, ratio, pass: ratio >= min, ...extra };
  }
  const { ground, ratio } = worstOn(hex, extra.grounds ?? GROUNDS);
  const { grounds, ...rest } = extra;
  return { fg, bg: ground, use, min, ratio, pass: ratio >= min, ...rest };
};

export function audit() {
  const rows = PAIRS.map(([fg, bg, use, min]) => scored(fg, COLOR[fg], bg, use, min));
  for (const family of INK_FAMILIES) {
    rows.push(scored(`${family}-ink`, COLOR[`${family}-ink`], 'paper', `${family} label (text)`, 4.5,
                     { grounds: inkGrounds(family) }));
  }
  /* Fills: a light fill can't reach 3:1 on paper without losing the vibrancy that
     makes a legendary pull feel legendary. WCAG 1.4.11 is satisfied by the object's
     *edge*, so the rule is: any fill under 3:1 must be drawn with its -ink outline,
     and the outline is what gets tested. */
  const fill = (family, k, vivid, ink) =>
    worstOn(vivid).ratio >= 3.0
      ? scored(`${family}-${k}`, vivid, 'paper', `${family} dot (fill)`, 3.0)
      : scored(`${family}-${k} + outline`, ink, 'paper',
               `${family} dot (needs outline)`, 3.0, { outlined: true });

  for (const [family, vivid, inks] of [['rarity', RARITY, RARITY_INK], ['mood', MOOD, MOOD_INK]]) {
    for (const [k, v] of Object.entries(vivid)) {
      rows.push(fill(family, k, v, inks[k]));
      rows.push(scored(`${family}-${k}-ink`, inks[k], 'paper', `${family} label (text)`, 4.5,
                       { grounds: inkGrounds(`${family}-${k}`) }));
    }
  }
  return rows;
}

export { RARITY_INK, MOOD_INK, COLOR, MOOD, RARITY, RADIUS, SHADOW, FONT, SPACE, PAD };

/** Token maps as a :root block, for previews that can't use Tailwind's @theme. */
export function rootVars() {
  const all = { ...Object.fromEntries(Object.entries(COLOR).map(([k, v]) => [k, v])),
    ...MOOD_BG,
    ...Object.fromEntries(Object.entries(MOOD).map(([k, v]) => [`mood-${k}`, v])),
    ...Object.fromEntries(Object.entries(MOOD_INK).map(([k, v]) => [`mood-${k}-ink`, v])),
    ...Object.fromEntries(Object.entries(RARITY).map(([k, v]) => [`rarity-${k}`, v])),
    ...Object.fromEntries(Object.entries(RARITY_INK).map(([k, v]) => [`rarity-${k}-ink`, v])) };
  return [...Object.entries(all).map(([k, v]) => `--${k}:${v}`),
    ...Object.entries(RADIUS).map(([k, v]) => `--r-${k}:${v}`),
    ...Object.entries(SPACE).map(([k, v]) => `--s-${k}:${v}`),
    ...Object.entries(PAD).map(([k, v]) => `--pad-${k}:${v}`),
    ...Object.entries(SHADOW).map(([k, v]) => `--e-${k}:${v}`),
    ...Object.entries(FONT).map(([k, v]) => `--font-${k}:${v}`)].join(';');
}

// ---- emit -------------------------------------------------------------------
function css() {
  const line = (k, v) => `    --${k}: ${v};`;
  return `/* Threadwork v2 tokens — proposed. Generated by build-tokens.mjs.
   Drop into resources/css/app.css @theme when Phase 2 S2.1 lands. */

@theme {
    /* fonts — 3 families (Oswald retired) */
${Object.entries(FONT).map(([k, v]) => line(`font-${k}`, v)).join('\n')}

    /* colour */
${Object.entries(COLOR).map(([k, v]) => line(`color-${k}`, v)).join('\n')}

    /* mood — vivid for fills, -bg for the tinted cell, -ink for text (derived, >=4.5:1) */
${Object.entries(MOOD).map(([k, v]) => line(`color-mood-${k}`, v)).join('\n')}
${Object.entries(MOOD_BG).map(([k, v]) => line(`color-${k}`, v)).join('\n')}
${Object.entries(MOOD_INK).map(([k, v]) => line(`color-mood-${k}-ink`, v)).join('\n')}

    /* rarity — vivid for fills, -ink for text on paper (derived, >=4.5:1) */
${Object.entries(RARITY).map(([k, v]) => line(`color-rarity-${k}`, v)).join('\n')}
${Object.entries(RARITY_INK).map(([k, v]) => line(`color-rarity-${k}-ink`, v)).join('\n')}

    /* spacing — NEW, no scale existed */
${Object.entries(SPACE).map(([k, v]) => line(`spacing-${k}`, v)).join('\n')}

    /* component padding roles */
${Object.entries(PAD).map(([k, v]) => line(`pad-${k}`, v)).join('\n')}

    /* radius — NEW, no scale existed */
${Object.entries(RADIUS).map(([k, v]) => line(`radius-${k}`, v)).join('\n')}

    /* elevation — NEW, warm-tinted for a cream ground */
${Object.entries(SHADOW).map(([k, v]) => line(`shadow-${k}`, v)).join('\n')}
}
`;
}

function html() {
  const rows = audit();
  const fails = rows.filter((r) => !r.pass);
  const sw = (name, hex) => `<div class="sw">
      <div class="chip" style="background:${hex}"></div>
      <div class="lbl">${name}<span>${hex}</span></div></div>`;
  const auditRow = (r) => `<tr class="${r.pass ? '' : 'bad'}">
      <td>${r.use}</td><td><code>${r.fg}</code> on <code>${r.bg}</code></td>
      <td class="num">${r.ratio.toFixed(2)}</td><td class="num">${r.min}</td>
      <td>${r.pass ? (r.outlined ? 'pass · outlined' : 'pass') : 'FAIL'}</td></tr>`;

  return `<!doctype html>
<meta charset="utf-8">
<title>Threadwork v2 — tokens</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@1,9..144,600&family=Plus+Jakarta+Sans:wght@400;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<style>
  :root{${Object.entries(COLOR).map(([k, v]) => `--${k}:${v}`).join(';')};
        ${Object.entries(RADIUS).map(([k, v]) => `--r-${k}:${v}`).join(';')};
        ${Object.entries(SHADOW).map(([k, v]) => `--e-${k}:${v}`).join(';')}}
  body{margin:0;padding:var(--pad-page);background:var(--surface);color:var(--ink);
       font:14px/1.55 'Plus Jakarta Sans',ui-sans-serif,system-ui,sans-serif}
  h1{font-size:21px;font-weight:800;margin:0 0 4px;letter-spacing:-.01em}
  h2{font-size:13px;font-weight:800;margin:52px 0 10px;text-transform:uppercase;
     letter-spacing:.09em;color:var(--ink-3)}
  p.sub{margin:0 0 22px;color:var(--ink-3);max-width:72ch}
  .row{display:flex;flex-wrap:wrap;gap:10px}
  .sw{width:132px}
  .chip{height:52px;border-radius:var(--r-sm);border:1px solid rgba(0,0,0,.08)}
  .lbl{font-size:11px;margin-top:6px;font-weight:600}
  .lbl span{display:block;color:var(--ink-3);font-weight:400;font-family:'JetBrains Mono',monospace;font-size:10px}
  table{border-collapse:collapse;width:100%;max-width:760px;font-size:13px}
  th,td{text-align:left;padding:var(--s-2) var(--s-3);border-bottom:1px solid var(--line)}
  th{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-3)}
  td.num{font-family:'JetBrains Mono',monospace;text-align:right}
  tr.bad{background:#b23a4f14}
  tr.bad td:last-child{color:var(--ember-deep);font-weight:800}
  code{font-family:'JetBrains Mono',monospace;font-size:11.5px;color:var(--ink-2)}
  .specs{display:flex;gap:18px;flex-wrap:wrap;align-items:flex-end}
  .spec{background:var(--surface-elev);border:1px solid var(--line);
        display:flex;align-items:center;justify-content:center;
        width:104px;height:76px;font-size:11px;color:var(--ink-3);font-family:'JetBrains Mono',monospace}
  .elev{background:var(--surface-card);width:132px;height:88px;border-radius:var(--r-lg);
        display:flex;align-items:center;justify-content:center;
        font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--ink-3)}
  .banner{border-radius:var(--r-md);padding:var(--pad-panel);margin:10px 0 0;max-width:72ch;font-size:13px}
  .ok{background:#2f8f6316;border:1px solid #2f8f6344}
  .warn{background:#b23a4f16;border:1px solid #b23a4f44}
</style>

<h1>Threadwork v2 — token set</h1>
<p class="sub">Proposed tokens for Phase 2 S2.1. Colour was largely already defined; <b>radius and
elevation are new</b> — their absence is why surfaces read inconsistently today. Type drops from
four families to three.</p>

<div class="banner ${fails.length ? 'warn' : 'ok'}">
  <b>Contrast audit:</b> ${rows.length - fails.length}/${rows.length} pass.
  ${fails.length ? `<b>${fails.length} fail</b> — listed below and highlighted in the table.` : 'No failures.'}
</div>

<h2>Structure &amp; paper</h2>
<div class="row">${['sky', 'sky-deep', 'sky-2', 'cream', 'cream-deep', 'surface-elev', 'surface-warm', 'surface-sunken', 'line', 'line-strong'].map((k) => sw(k, COLOR[k])).join('')}</div>

<h2>Text</h2>
<div class="row">${['ink', 'ink-2', 'ink-3', 'ink-on-sky'].map((k) => sw(k, COLOR[k])).join('')}</div>

<h2>Accent — fill vs text</h2>
<p class="sub">The vivid value is the fill; <code>-deep</code> is the fill for a dark CTA. Only the
derived <code>-ink</code> member carries a label.</p>
<div class="row">${['horizon', 'horizon-deep', 'leaf', 'leaf-deep', 'ember', 'ember-deep', 'citrus', 'stone'].map((k) => sw(k, COLOR[k])).join('')}</div>
<div class="row" style="margin-top:10px">${INK_FAMILIES.map((k) => sw(`${k}-ink`, COLOR[`${k}-ink`])).join('')}</div>

<h2>Mood — fill vs text</h2>
<div class="row">${Object.entries(MOOD).map(([k, v]) => sw(k, v)).join('')}</div>
<div class="row" style="margin-top:10px">${Object.entries(MOOD_INK).map(([k, v]) => sw(k + '-ink', v)).join('')}</div>

<h2>Rarity — fill vs text</h2>
<p class="sub">Top row is the vivid fill (dots, strokes, frames). Bottom row is the derived
<code>-ink</code> variant, darkened until it clears 4.5:1 on paper — for labels and icons.</p>
<div class="row">${Object.entries(RARITY).map(([k, v]) => sw(k, v)).join('')}</div>
<div class="row" style="margin-top:10px">${Object.entries(RARITY_INK).map(([k, v]) => sw(k + '-ink', v)).join('')}</div>

<h2>Fills that require an outline</h2>
<p class="sub">These three are too light to clear 3:1 on paper as a bare fill. Rather than dull
them, the rule is that they are always drawn with their <code>-ink</code> outline — the edge
carries the contrast, the fill keeps its vibrancy. Bare on the left, correct on the right.</p>
<div class="row">${[['rarity-legendary', RARITY.legendary, RARITY_INK.legendary],
                    ['rarity-uncommon', RARITY.uncommon, RARITY_INK.uncommon],
                    ['mood-blazing', MOOD.blazing, MOOD_INK.blazing]].map(([n, v, i]) =>
  `<div style="width:210px">
     <div style="display:flex;gap:12px;align-items:center">
       <div style="width:44px;height:44px;border-radius:999px;background:${v}"></div>
       <div style="width:44px;height:44px;border-radius:999px;background:${v};border:2px solid ${i}"></div>
       <div style="font-size:11px;color:var(--ink-3)">bare · outlined</div>
     </div>
     <div style="font-size:11px;font-weight:600;margin-top:7px">${n}</div>
   </div>`).join('')}</div>

<h2>Contrast audit</h2>
<table>
  <tr><th>use</th><th>pair</th><th>ratio</th><th>min</th><th></th></tr>
  ${rows.map(auditRow).join('')}
</table>

<h2>Spacing — new</h2>
<p class="sub">4px base. Components ask for a <em>role</em> (<code>--pad-card</code>,
<code>--pad-panel</code>, <code>--pad-chip</code>) rather than a number, which is what stops
twenty-three different paddings appearing across one app.</p>
<div class="row">${Object.entries(PAD).map(([k, v]) =>
    `<div class="sw" style="width:154px"><div style="background:var(--surface-elev);border:1px solid var(--line);border-radius:var(--r-sm);padding:${v}"><div style="background:var(--surface-sunken);height:26px;border-radius:var(--r-xs)"></div></div><div class="lbl">--pad-${k}<span>${v}</span></div></div>`).join('')}</div>

<h2>Radius — new</h2>
<p class="sub">No radius scale existed; every call site reached for a Tailwind default, which is
how you end up with four different card corners on one screen.</p>
<div class="specs">${Object.entries(RADIUS).map(([k, v]) =>
    `<div class="spec" style="border-radius:${v}">${k}<br>${v}</div>`).join('')}</div>

<h2>Elevation — new</h2>
<p class="sub">Warm-tinted rather than neutral black: a grey shadow on a cream ground reads dirty.
One step per surface role — resting card, floating UI, overlay, modal.</p>
<div class="specs">${Object.entries(SHADOW).map(([k]) =>
    `<div class="elev" style="box-shadow:var(--e-${k})">${k}</div>`).join('')}</div>

<h2>Type — 4 families to 3</h2>
<p class="sub">Oswald is retired. It was Kartu-only, and it is the single biggest reason the
collection reads as a different product from the rest of the app.</p>
<div style="display:flex;flex-direction:column;gap:14px;max-width:72ch">
  <div><div style="font:800 30px/1.1 'Plus Jakarta Sans',sans-serif;letter-spacing:-.02em">Eight point two kilometres</div>
    <code>font-sans — UI, prose, headings, and now cards</code></div>
  <div><div style="font:700 30px/1.1 'JetBrains Mono',monospace;letter-spacing:-.01em">8.2 KM · 5:12 · 159</div>
    <code>font-mono — telemetry only: numbers, stats, labels</code></div>
  <div><div style="font:italic 600 30px/1.15 Fraunces,Georgia,serif">same route, and your heart's doing less work</div>
    <code>font-display — Temari's voice, display moments</code></div>
</div>
`;
}

if (process.argv[1]?.endsWith('build-tokens.mjs')) {
  writeFileSync(new URL('./tokens.css', import.meta.url), css());
  writeFileSync(new URL('./tokens.html', import.meta.url), html());
  const fails = audit().filter((r) => !r.pass);
  console.log(`wrote tokens.css + tokens.html`);
  if (fails.length) {
    console.log(`\n${fails.length} contrast failure(s):`);
    for (const f of fails) {
      console.log(`  ${f.use.padEnd(22)} ${f.fg} on ${f.bg}`.padEnd(60)
        + `${f.ratio.toFixed(2)} (needs ${f.min})`);
    }
  } else console.log('contrast: all pass');
}
