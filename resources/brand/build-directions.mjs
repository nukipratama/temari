import { writeFileSync } from 'node:fs';

import {
  contrast,
  darkest,
  paperGrounds,
  readColorTokens,
  readDawnShiftSurfaces,
} from './grounds.mjs';
import { inkOn, worstOn } from './build-tokens.mjs';

/* Four visual directions, one of them today's palette as a control.
   Phase 2 re-tokenized the existing pages in place instead of rebuilding them
   against the Phase 0 reference screens, so the ground, the text tier and the
   type voice never moved — 36 of 43 colour tokens are byte-identical to main.
   Everything being tokenized is what makes moving them a small diff now, and
   this page is where that move gets judged: same composition, same code, same
   size, four grounds. */

// ---- OKLCh ------------------------------------------------------------------
// Grounds are re-based rather than hand-picked: the current ladder already has
// working elevation and dawn-shift relationships, so each direction keeps those
// intervals and only moves the base. That is also why a direction converts into
// an `@theme` edit rather than a redesign.
const toLinear = (c) => (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
const toGamma = (c) => (c <= 0.0031308 ? 12.92 * c : 1.055 * c ** (1 / 2.4) - 0.055);
const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));

export function toOklch(hex) {
  const n = parseInt(hex.slice(1), 16);
  const [r, g, b] = [(n >> 16) & 255, (n >> 8) & 255, n & 255].map((v) => toLinear(v / 255));
  const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
  const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
  const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
  const L = 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s;
  const A = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s;
  const B = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s;
  return { L, C: Math.hypot(A, B), H: ((Math.atan2(B, A) * 180) / Math.PI + 360) % 360 };
}

export function fromOklch({ L, C, H }) {
  const rad = (H * Math.PI) / 180;
  const [A, B] = [Math.cos(rad) * C, Math.sin(rad) * C];
  const l = (L + 0.3963377774 * A + 0.2158037573 * B) ** 3;
  const m = (L - 0.1055613458 * A - 0.0638541728 * B) ** 3;
  const s = (L - 0.0894841775 * A - 1.291485548 * B) ** 3;
  return (
    '#' +
    [
      4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
      -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
      -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
    ]
      .map((v) => Math.round(clamp(toGamma(v), 0, 1) * 255).toString(16).padStart(2, '0'))
      .join('')
  );
}

/** The transform that carries one family onto a new base, intervals intact. */
function rebase(referenceHex, target) {
  const ref = toOklch(referenceHex);
  const dL = target.L - ref.L;
  const kC = ref.C === 0 ? 1 : target.C / ref.C;
  const dH = target.H - ref.H;
  return (hex) => {
    const { L, C, H } = toOklch(hex);
    return fromOklch({ L: clamp(L + dL, 0, 1), C: Math.max(0, C * kC), H: (H + dH + 360) % 360 });
  };
}

/** Lighten until legible on a dark fill. `inkOn` only darkens, so on-dark text
    needs the other direction; everything else routes through `inkOn`. */
function tintOn(hex, ground, target = 4.5) {
  const base = toOklch(hex);
  for (let L = base.L; L <= 1.0001; L += 0.004) {
    const candidate = fromOklch({ ...base, L });
    if (contrast(candidate, ground) >= target) return candidate;
  }
  return '#ffffff';
}

// ---- today ------------------------------------------------------------------
// Read out of app.css, never transcribed. `paperGrounds()` enumerates the papers
// from the stylesheet and the components — that is where the five dawn-shift
// surfaces and the AppShell ground come from, not from a hand-written list.
const TOKENS = readColorTokens();
const SHIFTS = { day: TOKENS.surface, ...readDawnShiftSurfaces() };
const TODAY_GROUNDS = paperGrounds();

/** Ground tokens, in the order the page prints them. */
const GROUND_TOKENS = [
  'cream', 'cream-deep', 'surface', 'surface-card',
  'surface-elev', 'surface-warm', 'surface-sunken',
];
const STRUCTURE_TOKENS = ['sky', 'sky-deep', 'sky-2'];
const ACCENT_TOKENS = ['horizon', 'horizon-deep'];
const INK_TOKENS = ['ink', 'ink-2', 'ink-3'];

/* Held constant across every direction, on purpose. The semantic accents encode
   effort and mood, and the rarity ladder is loot, not brand — moving them at the
   same time would make it impossible to tell which change did the work. Their
   `-ink` members are re-derived per direction, because those depend on ground. */
const HELD = {
  leaf: TOKENS.leaf, 'leaf-deep': TOKENS['leaf-deep'],
  ember: TOKENS.ember, 'ember-deep': TOKENS['ember-deep'],
  citrus: TOKENS.citrus,
  'rarity-common': TOKENS['rarity-common'], 'rarity-uncommon': TOKENS['rarity-uncommon'],
  'rarity-rare': TOKENS['rarity-rare'], 'rarity-epic': TOKENS['rarity-epic'],
  'rarity-legendary': TOKENS['rarity-legendary'],
  'strava-orange': TOKENS['strava-orange'],
};

const INK_FAMILIES = ['horizon', 'leaf', 'ember', 'citrus'];

// ---- type voices ------------------------------------------------------------
// Only faces the app already loads (app.blade.php:85 — Fraunces upright + italic,
// Plus Jakarta Sans, JetBrains Mono) and system fallbacks. No direction here needs
// a new webfont, so none of them carries a licensing question.
const FRAUNCES = "'Fraunces', Georgia, 'Times New Roman', serif";
const JAKARTA = "'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif";
const JETBRAINS = "'JetBrains Mono', ui-monospace, Menlo, Consolas, monospace";

const VOICES = {
  editorialItalic: {
    note: 'Fraunces italic display · Jakarta prose · JetBrains numerals — today’s assignment.',
    display: { family: FRAUNCES, weight: 600, style: 'italic', tracking: '-0.01em', transform: 'none', lh: '1.08' },
    body: { family: JAKARTA, weight: 400, lh: '1.6' },
    num: { family: JETBRAINS, weight: 700, tracking: '-0.02em' },
    label: { family: JETBRAINS, weight: 700, tracking: '0.12em' },
  },
  instrument: {
    note: 'JetBrains Mono is promoted to the display face; labels drop to Jakarta. The whole page reads as an instrument, not a letter.',
    display: { family: JETBRAINS, weight: 700, style: 'normal', tracking: '-0.03em', transform: 'uppercase', lh: '1.02' },
    body: { family: JAKARTA, weight: 400, lh: '1.6' },
    num: { family: JETBRAINS, weight: 800, tracking: '-0.03em' },
    label: { family: JAKARTA, weight: 700, tracking: '0.14em' },
  },
  roman: {
    note: 'Fraunces upright rather than italic, at display weight. Same face as today, an entirely different register — a printed magazine instead of a handwritten aside.',
    display: { family: FRAUNCES, weight: 700, style: 'normal', tracking: '-0.025em', transform: 'none', lh: '1.02' },
    body: { family: JAKARTA, weight: 400, lh: '1.62' },
    num: { family: JETBRAINS, weight: 500, tracking: '-0.01em' },
    label: { family: JETBRAINS, weight: 700, tracking: '0.16em' },
  },
  fieldbook: {
    note: 'Roles inverted: Jakarta goes heavy and tight for headlines, Fraunces upright carries the body copy, numerals thin out and space open. Reads like a logbook.',
    display: { family: JAKARTA, weight: 800, style: 'normal', tracking: '-0.04em', transform: 'none', lh: '1.0' },
    body: { family: FRAUNCES, weight: 400, lh: '1.58' },
    num: { family: JETBRAINS, weight: 500, tracking: '0.01em' },
    label: { family: JAKARTA, weight: 700, tracking: '0.16em' },
  },
};

// ---- directions -------------------------------------------------------------
export const DIRECTIONS = [
  {
    key: 'almanac',
    name: 'Almanac',
    control: true,
    tagline: 'today, rendered by this same code',
    story:
      'The shipped palette. Warm yellow-cream paper, indigo structure, gold as the earned colour, ' +
      'Fraunces italic for Temari’s voice. Included so the other three are judged against what is ' +
      'actually on screen rather than against a memory of it.',
    voice: 'editorialItalic',
  },
  {
    key: 'graphite',
    name: 'Graphite',
    tagline: 'cold paper, near-black structure, one electric signal',
    story:
      'The ground goes cool and almost achromatic — the page stops being a warm object. Structure ' +
      'drops from indigo to graphite, and every earned moment is carried by a single lime signal ' +
      'that appears nowhere else. Mono is promoted to the display face.',
    ground: { L: 0.968, C: 0.006, H: 250 },
    structure: { L: 0.235, C: 0.022, H: 255 },
    accent: { L: 0.842, C: 0.185, H: 126 },
    inkHue: 254,
    inkChroma: 0.45,
    voice: 'instrument',
  },
  {
    key: 'terracotta',
    name: 'Terracotta',
    tagline: 'clay paper, espresso structure, teal as the earned colour',
    story:
      'Still warm, but red-side warm instead of yellow-side. Paper sits a step deeper so cards ' +
      'lift off it without a shadow doing the work, structure is espresso rather than indigo, and ' +
      'the accent crosses to teal so “earned” never reads as the ground it sits on.',
    ground: { L: 0.945, C: 0.024, H: 44 },
    structure: { L: 0.238, C: 0.05, H: 34 },
    accent: { L: 0.742, C: 0.108, H: 199 },
    inkHue: 36,
    inkChroma: 0.9,
    voice: 'roman',
  },
  {
    key: 'field',
    name: 'Field',
    tagline: 'sage paper, forest structure, burnt orange signal',
    story:
      'A cool green ground — the one family the app has never used as paper, so nothing about it ' +
      'reads as the current product. Structure is deep forest, the accent is burnt orange, and ' +
      'body copy moves into Fraunces upright. Closest thing here to a paper logbook.',
    ground: { L: 0.96, C: 0.013, H: 146 },
    structure: { L: 0.255, C: 0.055, H: 155 },
    accent: { L: 0.702, C: 0.152, H: 50 },
    inkHue: 148,
    inkChroma: 0.7,
    voice: 'fieldbook',
  },
];

/** Every `--color-*` a direction sets, plus the dawn-shift surfaces it drifts to. */
export function palette(dir) {
  if (dir.control) {
    const colors = Object.fromEntries(
      [...GROUND_TOKENS, ...STRUCTURE_TOKENS, ...ACCENT_TOKENS, ...INK_TOKENS,
        'ink-on-sky', 'line', 'line-strong', 'stone']
        .map((k) => [k, TOKENS[k]]),
    );
    for (const family of INK_FAMILIES) colors[`${family}-ink`] = TOKENS[`${family}-ink`];
    return { colors: { ...colors, ...HELD }, shifts: { ...SHIFTS }, artTop: '#fcf9f3' };
  }

  const reground = rebase(TOKENS.surface, dir.ground);
  const restructure = rebase(TOKENS.sky, dir.structure);
  const reaccent = rebase(TOKENS.horizon, dir.accent);

  const colors = {};
  for (const k of GROUND_TOKENS) colors[k] = reground(TOKENS[k]);
  for (const k of STRUCTURE_TOKENS) colors[k] = restructure(TOKENS[k]);
  for (const k of ACCENT_TOKENS) colors[k] = reaccent(TOKENS[k]);

  const shifts = Object.fromEntries(Object.entries(SHIFTS).map(([k, v]) => [k, reground(v)]));
  const grounds = { ...Object.fromEntries(GROUND_TOKENS.map((k) => [k, colors[k]])), ...shifts };

  /* Text tier: hue moves onto the direction, lightness and chroma keep their
     current relationship, then `inkOn` darkens each one until it clears 4.5:1 on
     every ground above — the same derivation S2.9 built and #620 extended. */
  for (const k of INK_TOKENS) {
    const { L, C } = toOklch(TOKENS[k]);
    colors[k] = inkOn(fromOklch({ L, C: C * dir.inkChroma, H: dir.inkHue }), grounds);
  }
  colors.line = inkOn(reground(TOKENS.line), grounds, 1.4);
  colors['line-strong'] = inkOn(reground(TOKENS['line-strong']), grounds, 1.6);
  colors.stone = inkOn(reground(TOKENS.stone), grounds);
  colors['ink-on-sky'] = tintOn(restructure(TOKENS['ink-on-sky']), colors.sky);

  for (const family of INK_FAMILIES) {
    const source = family === 'horizon' ? colors.horizon : HELD[family];
    colors[`${family}-ink`] = inkOn(source, grounds);
  }

  return { colors: { ...colors, ...HELD }, shifts, artTop: reground('#fcf9f3') };
}

/** Contrast rows: the pairs that decide whether a direction can carry text. */
export function audit(dir) {
  const { colors, shifts } = palette(dir);
  const grounds = { ...Object.fromEntries(GROUND_TOKENS.map((k) => [k, colors[k]])),
    ...Object.fromEntries(Object.entries(shifts).map(([k, v]) => [`surface · ${k}`, v])) };

  const onPaper = (fg, use, min) => {
    const { ground, ratio } = worstOn(colors[fg], grounds);
    return { fg, bg: ground, use, min, ratio, pass: ratio >= min };
  };
  const onFill = (fg, bg, use, min) => {
    const ratio = contrast(colors[fg], colors[bg]);
    return { fg, bg, use, min, ratio, pass: ratio >= min };
  };

  return [
    onPaper('ink', 'body text', 4.5),
    onPaper('ink-2', 'secondary text', 4.5),
    onPaper('ink-3', 'meta text', 4.5),
    onFill('cream', 'sky', 'text on structure', 4.5),
    onFill('ink-on-sky', 'sky', 'muted on structure', 4.5),
    onFill('cream', 'sky-deep', 'card text on frame', 4.5),
    onFill('sky', 'horizon', 'text on accent CTA', 4.5),
    onFill('cream', 'ember-deep', 'text on ember CTA', 4.5),
    ...INK_FAMILIES.map((f) => onPaper(`${f}-ink`, `${f} label`, 4.5)),
    onFill('rarity-epic', 'sky-deep', 'rarity frame on card', 3.0),
    onFill('horizon', 'sky', 'accent mark on structure', 3.0),
    onPaper('line', 'separator', 1.4),
  ];
}

/** Only the tokens a direction actually moves, old → new. */
export function diff(dir) {
  const { colors, shifts } = palette(dir);
  const rows = [];
  for (const [k, v] of Object.entries(colors)) {
    if (TOKENS[k] !== undefined && TOKENS[k] !== v) rows.push([`--color-${k}`, TOKENS[k], v]);
  }
  for (const [bucket, v] of Object.entries(shifts)) {
    if (SHIFTS[bucket] !== v) {
      rows.push([bucket === 'day' ? '--color-surface (default)' : `--color-surface [${bucket}]`, SHIFTS[bucket], v]);
    }
  }
  return rows;
}

// ---- the composition --------------------------------------------------------
/* One fragment, repeated per direction. Taken off the real screens rather than
   invented: the hero is `HeroPanel` (resources/js/components/ui/HeroPanel.tsx),
   the tiles are `KpiTile`, the verdict block is `VerdictHero`, the buttons are
   `PillButton` + `StravaSyncButton`, and the card is `Kartu` in its `KartuMount`
   — same aspect, same 16px radius, same rarity border and `.kartu-glow` ring. */

const ROUTE =
  'M10 46 C16 34 24 50 33 43 C42 36 44 20 55 17 C66 14 72 27 67 36 C62 45 50 44 47 50 ' +
  'C44 55 54 56 64 54 C74 51 82 44 90 47';

const STRAVA_MARK = `<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true">
  <path d="M15.4 17.8 13.2 13.4h-3.2L15.4 24l5.4-10.6h-3.2zM7.3 0 1 12.4h3.7L7.3 7.2l2.6 5.2h3.7z"/></svg>`;

const kartu = () => `
<div class="mount">
  <div class="kartu">
    <div class="art">
      <svg viewBox="0 0 100 64" preserveAspectRatio="none"><path d="${ROUTE}"
        fill="var(--rarity)" fill-opacity=".16" stroke="var(--rarity)" stroke-width="3"
        stroke-linecap="round" stroke-linejoin="round"/></svg>
    </div>
    <div class="k-chip k-tl">epic</div>
    <div class="k-chip k-tr"><i></i>128</div>
    <div class="k-body">
      <div class="k-name">Kejar Matahari</div>
      <div class="k-km"><b>8.2</b><span>KM</span></div>
      <dl class="k-grid">
        ${[['pace', '5:12'], ['hr', '159'], ['elev', '86']].map(([t, v]) =>
          `<div><dt>${t}</dt><dd>${v}</dd></div>`).join('')}
      </dl>
    </div>
  </div>
</div>
<div class="why">
  <div class="eyebrow">why epic</div>
  <p>Third-fastest 8&nbsp;k you have run, and the first one under 5:15 in the heat.</p>
  <div class="pips">${['negative split', 'heat +31°', 'pr −29s'].map((p) => `<span>${p}</span>`).join('')}</div>
</div>`;

const fragment = (dir) => `
<div class="frag" data-dir="${dir.key}">
  <div class="hero">
    <div class="eyebrow on-sky">this morning · 06:12</div>
    <div class="h1">Kejar Matahari</div>
    <div class="hero-stats">
      ${[['distance', '8.2', 'km'], ['pace', '5:12', '/km'], ['avg hr', '159', 'bpm']].map(([l, v, u]) =>
        `<div><div class="eyebrow on-sky">${l}</div><div class="hstat">${v}<span>${u}</span></div></div>`).join('')}
    </div>
  </div>

  <div class="verdict">
    <div class="voice">you're faster than you were in march. clearly.</div>
    <p class="prose">Same route, same heat, and your heart is doing less work for more speed.
      Seven weeks now without fading after km&nbsp;6.</p>
    <div class="chips">
      <span class="chip accent">earned · epic</span>
      <span class="chip leaf">easy effort</span>
      <span class="chip ember">load high</span>
    </div>
  </div>

  <div class="kpis">
    ${[['this week', '31.4', 'km', 62], ['runs', '4', '', 80], ['load', '412', 'trimp', 44]].map(([l, v, u, pct]) =>
      `<div class="kpi"><div class="eyebrow">${l}</div><div class="kstat">${v}<span>${u}</span></div>
       <div class="rail"><i style="width:${pct}%"></i></div></div>`).join('')}
  </div>

  <div class="btns">
    <button class="pill cta">Claim card</button>
    <button class="pill primary">Share run</button>
    <button class="pill outline">Replay</button>
    <button class="pill strava">${STRAVA_MARK} Sync</button>
  </div>

  <div class="cardrow">${kartu()}</div>
</div>`;

// ---- emit -------------------------------------------------------------------
const varsFor = (dir) => {
  const { colors, artTop } = palette(dir);
  const v = VOICES[dir.voice];
  return [
    ...Object.entries(colors).map(([k, val]) => `--${k}:${val}`),
    `--art-top:${artTop}`,
    `--f-display:${v.display.family}`, `--w-display:${v.display.weight}`,
    `--s-display:${v.display.style}`, `--t-display:${v.display.tracking}`,
    `--u-display:${v.display.transform}`, `--lh-display:${v.display.lh}`,
    `--f-body:${v.body.family}`, `--w-body:${v.body.weight}`, `--lh-body:${v.body.lh}`,
    `--f-num:${v.num.family}`, `--w-num:${v.num.weight}`, `--t-num:${v.num.tracking}`,
    `--f-label:${v.label.family}`, `--w-label:${v.label.weight}`, `--t-label:${v.label.tracking}`,
  ].join(';');
};

const swatch = (hex) => `<i class="sw" style="background:${hex}"></i>`;

const auditTable = (dir) => {
  const rows = audit(dir);
  const fails = rows.filter((r) => !r.pass);
  return `<table class="audit">
    <tr><th>use</th><th>pair</th><th>ratio</th><th>min</th><th></th></tr>
    ${rows.map((r) => `<tr class="${r.pass ? '' : 'bad'}">
      <td>${r.use}</td><td><code>${r.fg}</code> on <code>${r.bg}</code></td>
      <td class="num">${r.ratio.toFixed(2)}</td><td class="num">${r.min}</td>
      <td>${r.pass ? 'pass' : 'FAIL'}</td></tr>`).join('')}
  </table>
  <p class="note ${fails.length ? 'warn' : 'ok'}">${rows.length - fails.length}/${rows.length} pass.
    ${fails.length ? `<b>${fails.length} fail.</b>` : 'AA-viable.'}</p>`;
};

const groundTable = (dir) => {
  const { colors, shifts } = palette(dir);
  const all = [
    ...GROUND_TOKENS.map((k) => [`--color-${k}`, colors[k]]),
    ...Object.entries(shifts).map(([b, v]) => [b === 'day' ? 'surface · day (default)' : `surface · ${b}`, v]),
  ];
  const worstInk = (bg) => contrast(colors.ink, bg).toFixed(2);
  return `<table class="audit">
    <tr><th>ground</th><th>value</th><th>ink on it</th></tr>
    ${all.map(([k, v]) => `<tr><td>${swatch(v)} <code>${k}</code></td>
      <td class="num">${v}</td><td class="num">${worstInk(v)}</td></tr>`).join('')}
  </table>
  <p class="note">${all.length} grounds — the seven ground tokens plus the five
  <code>useDawnShift</code> surfaces (<code>day</code> is the default declaration, the other four are
  <code>body[data-time-of-day=…]</code>). <code>cream-deep</code> is the shell <code>AppShell</code>
  paints and the darkest of them, so it is what every ink is scored against.</p>`;
};

const diffTable = (dir) => {
  const rows = diff(dir);
  if (rows.length === 0) return '<p class="note ok">No token changes — this is the shipped palette.</p>';
  return `<table class="audit">
    <tr><th>token</th><th>today</th><th>${dir.name}</th></tr>
    ${rows.map(([k, from, to]) => `<tr><td><code>${k}</code></td>
      <td class="num">${swatch(from)} ${from}</td>
      <td class="num">${swatch(to)} ${to}</td></tr>`).join('')}
  </table>
  <p class="note"><b>${rows.length} tokens move.</b> Everything not listed is unchanged — the
  semantic accents (<code>leaf</code>, <code>ember</code>, <code>citrus</code>), the whole rarity
  ladder and the Strava marks are deliberately held so the ground and the type are the only
  variables on this page.</p>`;
};

const specimen = (dir) => {
  const v = VOICES[dir.voice];
  return `<div class="spec" data-dir="${dir.key}">
    <div class="sp-display">Eight point two kilometres</div>
    <div class="sp-body">Same route, same heat, and your heart is doing less work for more speed.</div>
    <div class="sp-num">8.2 KM · 5:12 /KM · 159 BPM</div>
    <div class="sp-label">this week · load · negative split</div>
    <p class="note">${v.note}</p>
  </div>`;
};

function html() {
  return `<!doctype html>
<meta charset="utf-8">
<title>Temari — visual directions</title>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,600;0,9..144,700;1,9..144,500;1,9..144,600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box}
  body{margin:0;padding:48px 40px 96px;background:#efefef;color:#16161a;
       font:14px/1.55 'Plus Jakarta Sans',ui-sans-serif,system-ui,sans-serif}
  h1{font-size:22px;font-weight:800;margin:0 0 6px;letter-spacing:-.02em}
  h2{font-size:13px;font-weight:800;margin:56px 0 12px;text-transform:uppercase;
     letter-spacing:.09em;color:#6b6b74}
  h3{font-size:17px;font-weight:800;margin:0 0 2px;letter-spacing:-.01em}
  p.lede{margin:0 0 8px;color:#54545e;max-width:86ch}
  .banner{border:1px solid #d6d6dc;background:#fff;border-radius:12px;padding:14px 18px;
          margin:22px 0 8px;max-width:86ch;font-size:13px;line-height:1.6}
  .banner b{font-weight:800}
  code{font-family:'JetBrains Mono',monospace;font-size:11.5px}
  .sw{display:inline-block;width:11px;height:11px;border-radius:3px;vertical-align:-1px;
      border:1px solid rgba(0,0,0,.16)}

  /* ---- the rack: four fragments, same code, same size ---- */
  .rack{display:flex;gap:26px;flex-wrap:wrap;align-items:flex-start;margin-top:18px}
  .col{width:404px}
  .cap{margin-bottom:10px}
  .cap b{display:block;font-size:15px;font-weight:800;letter-spacing:-.01em}
  .cap span{font-size:12px;color:#6b6b74}
  .story{font-size:12px;line-height:1.55;color:#54545e;margin:8px 0 0;min-height:76px}

  .frag{border-radius:16px;overflow:hidden;padding:20px 18px 22px;
        display:flex;flex-direction:column;gap:16px;
        background:var(--cream-deep);color:var(--ink);
        font-family:var(--f-body);font-weight:var(--w-body);line-height:var(--lh-body);
        box-shadow:0 10px 30px rgba(0,0,0,.10),0 2px 6px rgba(0,0,0,.06)}

  .eyebrow{font-family:var(--f-label);font-weight:var(--w-label);letter-spacing:var(--t-label);
           text-transform:uppercase;font-size:9.5px;color:var(--ink-3)}
  .eyebrow.on-sky{color:var(--ink-on-sky)}

  .hero{border-radius:14px;padding:18px 18px 20px;color:var(--cream);
        background:linear-gradient(160deg,var(--sky-deep) 0%,var(--sky) 60%,var(--sky-2) 100%)}
  .h1{font-family:var(--f-display);font-weight:var(--w-display);font-style:var(--s-display);
      letter-spacing:var(--t-display);text-transform:var(--u-display);line-height:var(--lh-display);
      font-size:31px;color:var(--cream);margin:6px 0 16px}
  .hero-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
  .hstat{font-family:var(--f-num);font-weight:var(--w-num);letter-spacing:var(--t-num);
         font-variant-numeric:tabular-nums;font-size:25px;color:var(--cream);margin-top:4px;line-height:1}
  .hstat span{font-size:10px;margin-left:3px;color:var(--ink-on-sky);letter-spacing:0}

  .verdict{background:var(--surface-card);border:1px solid var(--line);border-radius:14px;
           padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.05)}
  .voice{font-family:var(--f-display);font-weight:var(--w-display);font-style:var(--s-display);
         letter-spacing:var(--t-display);text-transform:var(--u-display);line-height:var(--lh-display);
         font-size:23px;color:var(--ink)}
  .prose{margin:10px 0 0;font-size:13px;color:var(--ink-2);line-height:var(--lh-body)}
  .chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:13px}
  .chip{font-family:var(--f-label);font-weight:var(--w-label);letter-spacing:var(--t-label);
        text-transform:uppercase;font-size:9px;padding:4px 10px;border-radius:999px}
  .chip.accent{background:color-mix(in oklab,var(--horizon) 20%,transparent);color:var(--horizon-ink)}
  .chip.leaf{background:color-mix(in oklab,var(--leaf) 16%,transparent);color:var(--leaf-ink)}
  .chip.ember{background:color-mix(in oklab,var(--ember) 16%,transparent);color:var(--ember-ink)}

  .kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
  .kpi{background:var(--surface-card);border:1px solid var(--line);border-radius:12px;padding:11px 12px;
       box-shadow:0 1px 2px rgba(0,0,0,.05)}
  .kstat{font-family:var(--f-num);font-weight:var(--w-num);letter-spacing:var(--t-num);
         font-variant-numeric:tabular-nums;font-size:22px;color:var(--ink);margin-top:5px;line-height:1}
  .kstat span{font-size:9.5px;margin-left:3px;color:var(--ink-3);letter-spacing:0}
  .rail{height:5px;border-radius:999px;background:var(--surface-sunken);margin-top:9px;overflow:hidden}
  .rail i{display:block;height:100%;border-radius:999px;background:var(--horizon)}

  .btns{display:flex;gap:8px;flex-wrap:wrap}
  .pill{border:0;border-radius:999px;padding:8px 13px;font-size:12px;font-weight:600;
        font-family:var(--f-body);cursor:default;display:inline-flex;align-items:center;gap:6px}
  .pill.cta{background:var(--horizon);color:var(--sky);font-weight:700}
  .pill.primary{background:var(--sky);color:var(--cream)}
  .pill.outline{background:var(--cream);color:var(--ink-2);border:1.5px solid var(--line-strong)}
  /* Strava keeps its own orange in every direction — vendor mark, not ours to theme. */
  .pill.strava{background:var(--strava-orange);color:#fff}

  .cardrow{display:flex;gap:14px;align-items:flex-start}
  .mount{width:150px;flex:none;border-radius:16px;padding:9px;
         background:linear-gradient(165deg,var(--sky-deep),var(--sky-2))}
  .kartu{--rarity:var(--rarity-epic);aspect-ratio:5/7;border-radius:16px;overflow:hidden;
         background:var(--sky-deep);border:2px solid var(--rarity-epic);padding:4px;
         display:flex;flex-direction:column;position:relative;
         box-shadow:0 0 0 1px color-mix(in oklab,var(--rarity) 55%,transparent),
                    inset 0 0 11px color-mix(in oklab,var(--rarity) 65%,transparent)}
  .art{flex:1;min-height:30%;border-radius:11px;overflow:hidden;
       background:radial-gradient(ellipse at 30% 26%,color-mix(in oklab,var(--rarity) 11%,transparent) 0%,transparent 70%),
                  linear-gradient(to bottom,var(--art-top),var(--cream-deep))}
  .art svg{width:100%;height:100%;display:block}
  .k-chip{position:absolute;background:var(--sky-deep);font-family:var(--f-body);font-weight:800;
          font-size:8.5px;letter-spacing:.04em;text-transform:uppercase;line-height:1;
          padding:5px 7px;display:inline-flex;align-items:center;gap:3px}
  .k-tl{left:0;top:0;border-bottom-right-radius:11px;color:var(--rarity-epic)}
  .k-tr{right:0;top:0;border-bottom-left-radius:11px;color:var(--cream);font-variant-numeric:tabular-nums}
  .k-tr i{width:6px;height:6px;border-radius:999px;background:var(--ember);display:block}
  .k-body{padding:7px 5px 2px;text-align:center;color:var(--cream)}
  .k-name{font-family:var(--f-body);font-weight:800;text-transform:uppercase;font-size:11px;
          letter-spacing:.01em;line-height:1.02}
  .k-km{display:flex;align-items:baseline;justify-content:center;gap:3px;margin-top:4px}
  .k-km b{font-family:var(--f-num);font-weight:var(--w-num);letter-spacing:var(--t-num);
          font-variant-numeric:tabular-nums;font-size:24px;line-height:1;color:var(--rarity-epic)}
  .k-km span{font-family:var(--f-label);font-size:7.5px;letter-spacing:.12em;color:var(--cream);opacity:.8}
  .k-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:2px 5px;margin:7px 0 0}
  .k-grid dt{font-family:var(--f-label);font-size:6.5px;letter-spacing:.14em;text-transform:uppercase;
             color:var(--cream);opacity:.7}
  .k-grid dd{margin:1px 0 0;font-family:var(--f-num);font-weight:600;font-size:10px;
             font-variant-numeric:tabular-nums;color:var(--cream)}
  .why{flex:1;min-width:0}
  .why p{margin:6px 0 0;font-size:12px;color:var(--ink-2);line-height:var(--lh-body)}
  .pips{display:flex;gap:5px;flex-wrap:wrap;margin-top:10px}
  .pips span{font-family:var(--f-label);font-weight:var(--w-label);letter-spacing:var(--t-label);
             text-transform:uppercase;font-size:8px;padding:3px 8px;border-radius:999px;
             background:color-mix(in oklab,var(--horizon) 20%,transparent);color:var(--horizon-ink)}

  /* ---- detail blocks ---- */
  .detail{border-top:1px solid #ddd;padding-top:22px;margin-top:34px;
          display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(0,1fr);gap:28px;align-items:start}
  .detail>div{min-width:0}
  .detail h4{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.09em;
             color:#6b6b74;margin:0 0 8px}
  table.audit{border-collapse:collapse;width:100%;font-size:12px}
  table.audit th,table.audit td{text-align:left;padding:4px 8px;border-bottom:1px solid #e2e2e6}
  table.audit th{font-size:9.5px;text-transform:uppercase;letter-spacing:.08em;color:#83838c;font-weight:700}
  table.audit td.num{font-family:'JetBrains Mono',monospace;text-align:right;font-size:11px}
  tr.bad{background:#b23a4f18}
  tr.bad td:last-child{color:#8d2c3d;font-weight:800}
  .note{font-size:11.5px;line-height:1.55;color:#6b6b74;margin:8px 0 0}
  .note.ok{color:#256f4d}
  .note.warn{color:#8d2c3d;font-weight:600}

  .spec{padding:16px;border-radius:12px;background:var(--surface-card);border:1px solid var(--line);
        color:var(--ink)}
  .sp-display{font-family:var(--f-display);font-weight:var(--w-display);font-style:var(--s-display);
              letter-spacing:var(--t-display);text-transform:var(--u-display);line-height:var(--lh-display);
              font-size:26px}
  .sp-body{font-family:var(--f-body);font-weight:var(--w-body);line-height:var(--lh-body);
           font-size:13px;color:var(--ink-2);margin-top:8px}
  .sp-num{font-family:var(--f-num);font-weight:var(--w-num);letter-spacing:var(--t-num);
          font-variant-numeric:tabular-nums;font-size:19px;margin-top:10px}
  .sp-label{font-family:var(--f-label);font-weight:var(--w-label);letter-spacing:var(--t-label);
            text-transform:uppercase;font-size:10px;color:var(--ink-3);margin-top:9px}
  .spec .note{color:var(--ink-3)}

${DIRECTIONS.map((d) => `  [data-dir="${d.key}"]{${varsFor(d)}}`).join('\n')}
</style>

<h1>Four visual directions</h1>
<p class="lede">Same composition, same generator, same size, four grounds. The first column is the
palette that is live today — without it the comparison is dishonest, because a palette can look new
in a swatch grid and identical in situ.</p>

<div class="banner">
  <b>What the rebrand actually moved so far.</b> Against <code>main</code>, 36 of 43
  <code>--color-*</code> tokens are byte-identical. Seven changed, all accents
  (<code>ember</code>, <code>ember-deep</code>, <code>leaf</code>, <code>leaf-deep</code>,
  <code>citrus</code>, <code>stone</code>, <code>line</code>); one was dropped
  (<code>citrus-deep</code>); 16 were added, 15 of them derived <code>-ink</code> accessibility
  variants plus <code>line-strong</code>. <code>cream</code>, <code>cream-deep</code>,
  <code>ink</code>, <code>sky</code>, <code>horizon</code> and every <code>surface</code> never
  moved — the ground, the text tier and the primary surfaces are exactly what they were. That is
  why every component still feels like before, and it is also why changing it now is cheap:
  everything is tokenized, so a direction below is an edit to the <code>@theme static</code> block,
  not a rebuild.
</div>

<div class="rack">
  ${DIRECTIONS.map((d) => `<div class="col">
    <div class="cap"><b>${d.name}${d.control ? ' — control' : ''}</b><span>${d.tagline}</span></div>
    ${fragment(d)}
    <p class="story">${d.story}</p>
  </div>`).join('')}
</div>

${DIRECTIONS.map((d) => `
<h2>${d.name}${d.control ? ' — control (today)' : ''}</h2>
<div class="detail">
  <div><h4>Type voice</h4>${specimen(d)}</div>
  <div><h4>Grounds</h4>${groundTable(d)}</div>
  <div><h4>Contrast</h4>${auditTable(d)}
       <h4 style="margin-top:20px">Token diff</h4>${diffTable(d)}</div>
</div>`).join('')}

<h2>What this page does not decide for you</h2>
<div class="banner">
  <b>Held constant on purpose:</b> the semantic accents (<code>leaf</code>, <code>ember</code>,
  <code>citrus</code>) and the whole rarity ladder are identical in all four columns, so the only
  variables are ground, structure, accent and type. Retinting those is a separate decision and a
  separate diff.<br><br>
  <b>The card barely moves, and that is real.</b> Compare the four Kartu: they are nearly
  indistinguishable. The frame is <code>sky-deep</code> and the rarity ladder is held, so a
  collectible reads as a collectible whatever the ground does. Decide whether that is the point
  (the collection is its own world) or a problem (the app's most saturated surface stays on the old
  identity). If it is a problem, the fix is a separate slice — retinting the rarity ladder — and it
  is not on this page.<br><br>
  <b>Third-party marks are fixed:</b> the Strava pill keeps <code>#fc4c02</code> in every direction.
  It is contractually not ours to theme, and it is worth checking how it sits on each ground — it is
  the one element that cannot be made to harmonise.<br><br>
  <b>Type stays inside what the app already loads.</b> Every voice here is built from Fraunces
  (upright and italic), Plus Jakarta Sans and JetBrains Mono — the three families
  <code>app.blade.php</code> already requests. No direction needs a new webfont, so none of them
  carries a licensing question. If a genuinely new face is wanted, that is a research task, not a
  choice available today.
</div>
`;
}

if (process.argv[1]?.endsWith('build-directions.mjs')) {
  writeFileSync(new URL('./directions.html', import.meta.url), html());
  console.log('wrote directions.html');
  console.log(`grounds enumerated from source: ${Object.keys(TODAY_GROUNDS).length} ` +
    `(darkest ${darkest(TODAY_GROUNDS)})`);
  for (const dir of DIRECTIONS) {
    const rows = audit(dir);
    const fails = rows.filter((r) => !r.pass);
    console.log(`  ${dir.name.padEnd(12)} ${diff(dir).length.toString().padStart(2)} tokens move · ` +
      `contrast ${rows.length - fails.length}/${rows.length}` +
      (fails.length ? ` · FAIL: ${fails.map((f) => `${f.fg} on ${f.bg} ${f.ratio.toFixed(2)}`).join(', ')}` : ''));
  }
}
