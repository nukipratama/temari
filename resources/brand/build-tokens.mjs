import { contrast, darkest, darkGrounds, groundsForInk, paperGrounds, toRgb } from './grounds.mjs';

/* The colour derivation behind resources/css/app.css: the raw palette, and the
   -ink tiers derived from it per ground so a label always clears contrast on
   whatever it lands on. The radius, spacing, elevation and type scales used to
   live here too and were swept by W2 — app.css declares those directly, nothing
   derives them, and keeping a second unread copy here was pure drift risk. */

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

// ---- contrast ---------------------------------------------------------------
// A saturated colour cannot be both a fill and text on cream. Vivid values stay
// for fills/dots/strokes; each family also gets an `-ink` variant, darkened until it
// clears 4.5:1 on paper, for labels and icons. Derived, so it cannot drift.
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

/** Lighten until the colour clears `target` against every ground in `grounds`
 *  — the inverse move from inkOn(): on a dark ground the vivid fill already
 *  reads, so it is darkening (toward black) rather than lightening (toward
 *  white) that kills contrast. Returns the hex unchanged when it already
 *  clears every ground, so a fill that is already legible stays vivid rather
 *  than being needlessly bleached. */
export function inkOnDark(hex, grounds, target = 4.5) {
  const against = Object.values(grounds);
  if (against.every((bg) => contrast(hex, bg) >= target)) return hex;
  for (let f = 0; f <= 1; f += 0.0025) {
    const c = toHex(toRgb(hex).map((v) => v + (255 - v) * f));
    if (against.every((bg) => contrast(c, bg) >= target)) return c;
  }
  return '#ffffff';
}

/* Read out of app.css and resources/js rather than written down here — see
   resources/brand/grounds.mjs. */
export const GROUNDS = paperGrounds();

/** The ground a token has to survive: the darkest paper the app can render. */
export const PAPER = darkest(GROUNDS);

/** The dark ground's three surfaces (sky-deep/sky/sky-2) — see grounds.mjs. */
export const GROUNDS_DARK = darkGrounds();

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

/* Every accent family whose light -ink value inverts on the dark ground
   (F2's "problem 1" — see plan/README.md's token model). horizon was excluded
   on the reasoning that the app reaches for icon-accent instead on dark; 33
   text-horizon-ink call sites say otherwise, and they were rendering #546d23
   on #0b1017 at 2.9:1. inkOnDark returns the vivid fill unchanged here, so the
   dark value is the #ade047 that reasoning assumed was already in use. */
const DARK_INK_FAMILIES = ['horizon', 'leaf', 'ember', 'citrus'];
export const DARK_INK = Object.fromEntries(
  DARK_INK_FAMILIES.map((family) => [family, inkOnDark(COLOR[family], GROUNDS_DARK)]),
);
export const RARITY_INK_DARK = Object.fromEntries(
  Object.entries(RARITY).map(([k, v]) => [k, inkOnDark(v, GROUNDS_DARK)]),
);

export { RARITY_INK, MOOD_INK, COLOR, MOOD, RARITY };
