import { mkdirSync, writeFileSync } from 'node:fs';
import { COLOR, MOOD, contrast, inkOn } from './build-tokens.mjs';

const SKY = COLOR.sky, CREAM = COLOR.cream, GOLD = COLOR.horizon, GOLD_D = COLOR['horizon-ink'];
const CX = 50, CY = 52, BODY_R = 31, HALO_R = 41, AURA_R = 47;
const EYE_Y = 50, EYE_S = 10;

// halo: [colour, stroke width] — reuses the app's mood tokens
/* The halo conveys mood, so every colour must clear 3:1 on cream — including
   `neutral`, since the ring is structural. Sourced from the mood tokens and
   darkened only where needed, so a token change propagates instead of drifting. */
const legible = (c) => (contrast(c, COLOR.cream) >= 3 ? c : inkOn(c, COLOR.cream, 3));
const HALO = {
  neutral:  [legible(COLOR.line), 6],
  easy:     [legible(MOOD.easy), 6],
  blazing:  [legible(MOOD.blazing), 8],
  gold:     [legible(COLOR.horizon), 9],
  chill:    [legible(MOOD.chill), 6],
  stone:    [legible(COLOR.stone), 5],
  wobbly:   [legible(MOOD.wobbly), 7],
  gassed:   [legible(MOOD.gassed), 6],
};

// brow offsets from the eye line: [outerY, innerY] — negative is above
// eyes: 'open' | 'wide' | 'lid' | 'wink-r'
const STATES = {
  resting:      { brow: [-10, -11],    eyes: 'open', mouth: 'smile-sm', halo: 'neutral' },
  pleased:      { brow: [-11, -12.5],  eyes: 'open', mouth: 'smile-md', halo: 'easy' },
  impressed:    { brow: [-14, -15.5],  eyes: 'wide', mouth: 'o',        halo: 'easy' },
  hyped:        { brow: [-15, -16],    eyes: 'wide', mouth: 'open',     halo: 'blazing' },
  skeptical:    { brow: [-15.5, -12], browR: [-9, -9], eyes: 'wink-r', mouth: 'wry', halo: 'chill' },
  unimpressed:  { brow: [-9, -9],      eyes: 'lid',  mouth: 'flat',     halo: 'stone' },
  challenging:  { brow: [-13.5, -8.5], eyes: 'open', mouth: 'smirk',    halo: 'gold' },
  concerned:    { brow: [-9.5, -13.5], eyes: 'open', mouth: 'frown-sm', halo: 'wobbly' },
  disappointed: { brow: [-9, -12],     eyes: 'lid',  mouth: 'frown-md', halo: 'gassed' },
  celebrating:  { brow: [-13, -14.5],  eyes: 'wide', mouth: 'open',     halo: 'gold' },
};

const L = CX - EYE_S, R = CX + EYE_S;
const stroke = (d, w = 3.8, c = SKY) =>
  `<path d="${d}" fill="none" stroke="${c}" stroke-width="${w}" stroke-linecap="round"/>`;

const brow = (x, outerY, innerY, side) => {
  const o = side === 'l' ? x - 5.5 : x + 5.5;
  const i = side === 'l' ? x + 5.5 : x - 5.5;
  return stroke(`M${o} ${(EYE_Y + outerY).toFixed(1)} L${i} ${(EYE_Y + innerY).toFixed(1)}`);
};

const eyes = (kind) => {
  const open = (x, r = 4.4) => `<circle cx="${x}" cy="${EYE_Y}" r="${r}" fill="${SKY}"/>`;
  const lid = (x) => stroke(`M${x - 4.2} ${EYE_Y} H${x + 4.2}`, 4.4);
  if (kind === 'wide') return open(L, 5.3) + open(R, 5.3);
  if (kind === 'lid') return lid(L) + lid(R);
  if (kind === 'wink-r') return open(L) + lid(R);
  return open(L) + open(R);
};

const MOUTHS = {
  'smile-sm': stroke(`M${CX - 7} ${EYE_Y + 13} Q${CX} ${EYE_Y + 17} ${CX + 7} ${EYE_Y + 13}`),
  'smile-md': stroke(`M${CX - 8} ${EYE_Y + 12.5} Q${CX} ${EYE_Y + 19} ${CX + 8} ${EYE_Y + 12.5}`),
  'flat':     stroke(`M${CX - 6.5} ${EYE_Y + 13.5} H${CX + 6.5}`),
  'wry':      stroke(`M${CX - 6} ${EYE_Y + 14} Q${CX + 1} ${EYE_Y + 11.5} ${CX + 7} ${EYE_Y + 13.5}`),
  'smirk':    stroke(`M${CX - 6} ${EYE_Y + 14} Q${CX + 2} ${EYE_Y + 17.5} ${CX + 8} ${EYE_Y + 11.5}`),
  'frown-sm': stroke(`M${CX - 6.5} ${EYE_Y + 15.5} Q${CX} ${EYE_Y + 11.5} ${CX + 6.5} ${EYE_Y + 15.5}`),
  'frown-md': stroke(`M${CX - 7.5} ${EYE_Y + 16.5} Q${CX} ${EYE_Y + 10.5} ${CX + 7.5} ${EYE_Y + 16.5}`),
  'o':        `<ellipse cx="${CX}" cy="${EYE_Y + 12.5}" rx="4" ry="5" fill="${SKY}"/>`,
  'open':     `<path d="M${CX - 8} ${EYE_Y + 11} Q${CX} ${EYE_Y + 21.5} ${CX + 8} ${EYE_Y + 11} Z" fill="${SKY}"/>`,
};

// ---- accessory slots -------------------------------------------------------
// Garments are drawn as plain bands and clipped to the body circle, so they
// pick up the ball's curve for free and can never escape the silhouette.
const band = (y, h, fill) => `<rect x="${CX - BODY_R}" y="${y}" width="${BODY_R * 2}" height="${h}" fill="${fill}"/>`;

const SLOTS = {
  headband: { layer: 'garment', art: (c = GOLD) =>
    band(23, 6, c) + stroke(`M${CX + 14} 26 l6 -4 M${CX + 14} 26 l6.5 3`, 2.6, c) },

  shirt: { layer: 'garment', art: (c = MOOD.easy) =>
    band(69, 6, c) + `<path d="M${CX - 4.5} 69 L${CX} 73 L${CX + 4.5} 69 Z" fill="${CREAM}"/>` },

  shorts: { layer: 'garment', art: (c = SKY) =>
    band(75, 7, c) + `<path d="M${CX} 75 V82" stroke="${CREAM}" stroke-width="1.8"/>` },

  shoes: { layer: 'under', art: (c = MOOD.wobbly) =>
    `<ellipse cx="${CX - 14}" cy="87" rx="8.5" ry="5" fill="${c}"/>` +
    `<ellipse cx="${CX + 14}" cy="87" rx="8.5" ry="5" fill="${c}"/>` },

  medal: { layer: 'over', art: (c = GOLD) =>
    stroke(`M${CX - 7} 69 L${CX} 77 L${CX + 7} 69`, 3, MOOD.wobbly) +
    `<circle cx="${CX}" cy="85" r="6" fill="${c}" stroke="${GOLD_D}" stroke-width="1.6"/>` },

  aura: { layer: 'aura', art: (c = GOLD) =>
    `<circle cx="${CX}" cy="${CY}" r="${AURA_R}" fill="none" stroke="${c}" stroke-width="2.4"
       stroke-dasharray="1.5 7" stroke-linecap="round" opacity="0.85"/>` },
};

export const SLOT_NAMES = Object.keys(SLOTS);

export function mascot(state, { size = 100, halo = true, wearing = [], id = state } = {}) {
  const s = STATES[state];
  if (!s) throw new Error(`unknown state: ${state}`);
  const [hc, hw] = HALO[s.halo];
  const [bo, bi] = s.brow;
  const [ro, ri] = s.browR ?? s.brow;
  /* `wearing` accepts a slot name, or { slot, colour, detail } for a catalogue
     item — the 25 unlocks are the same six shapes in different colours. */
  const worn = wearing
    .map((w) => (typeof w === 'string' ? { slot: w } : w))
    .filter((w) => SLOTS[w.slot]);
  const of = (layer) => worn
    .filter((w) => SLOTS[w.slot].layer === layer)
    .map((w) => SLOTS[w.slot].art(w.colour) + (w.detail ?? ''))
    .join('');
  const clip = `clip-${id}`;

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="${size}" height="${size}" role="img" aria-label="Temari — ${state}">
  <defs><clipPath id="${clip}"><circle cx="${CX}" cy="${CY}" r="${BODY_R - 2.2}"/></clipPath></defs>
${of('aura')}${halo ? `  <circle cx="${CX}" cy="${CY}" r="${HALO_R}" fill="none" stroke="${hc}" stroke-width="${hw}"/>\n` : ''}${of('under')}  <circle cx="${CX}" cy="${CY}" r="${BODY_R}" fill="${CREAM}" stroke="${SKY}" stroke-width="4.5"/>
  <g clip-path="url(#${clip})">${of('garment')}</g>
${of('over')}  ${brow(L, bo, bi, 'l')}
  ${brow(R, ro, ri, 'r')}
  ${eyes(s.eyes)}
  ${MOUTHS[s.mouth]}
</svg>
`;
}

export const STATE_NAMES = Object.keys(STATES);

/* Real drawn extent inside the 100x100 viewBox, so callers that transform the
   mascot can place it without clipping. The halo is the widest thing on a bare
   character (r + half its heaviest stroke); accessories reach further down. */
const MAX_HALO_W = Math.max(...Object.values(HALO).map(([, w]) => w));
export const BOUNDS = {
  top: CY - HALO_R - MAX_HALO_W / 2,
  bottom: CY + HALO_R + MAX_HALO_W / 2,
  left: CX - HALO_R - MAX_HALO_W / 2,
  right: CX + HALO_R + MAX_HALO_W / 2,
  withAccessories: { bottom: 92, outer: AURA_R + 1.2 },
};

const CAPTION = {
  resting: 'nothing to report', pleased: 'you ran well',
  impressed: 'better than expected', hyped: 'PR territory',
  skeptical: 'not convinced', unimpressed: 'three days off',
  challenging: 'daring you', concerned: 'overreaching',
  disappointed: 'you skipped it again', celebrating: 'new PR',
};

function previewHtml() {
  const stateCell = (n) => `
    <div class="cell">
      <div class="big">${mascot(n, { size: 104 })}</div>
      <div class="sm">${mascot(n, { size: 28, id: n + '-s' })}<div class="chip">${mascot(n, { size: 28, id: n + '-c' })}</div></div>
      <div class="cap"><b>${n}</b>${CAPTION[n]}</div>
    </div>`;
  const slotCell = (w, i) => `
    <div class="cell">
      <div class="big">${mascot('resting', { size: 104, wearing: [w], id: 'w' + i })}</div>
      <div class="cap"><b>${w}</b></div>
    </div>`;
  return `<!doctype html>
<meta charset="utf-8">
<title>Temari — mascot states &amp; slots</title>
<style>
  :root { --cream:#f5f0e4; --sky:#241c54; --ink:#1a1812; --ink3:#6e6452; --line:#d8d0ba; --sunken:#ece2ce; }
  body { margin:0; padding:48px 40px 90px; background:var(--cream); color:var(--ink);
         font:14px/1.5 ui-sans-serif,system-ui,-apple-system,sans-serif; }
  h1 { font-size:20px; font-weight:650; margin:0 0 4px; }
  h2 { font-size:14px; font-weight:650; margin:52px 0 4px; text-transform:uppercase;
       letter-spacing:.08em; color:var(--ink3); }
  p.sub { margin:0 0 28px; color:var(--ink3); max-width:70ch; }
  .grid { display:flex; flex-wrap:wrap; gap:28px 24px; }
  .cell { width:150px; }
  .big { display:flex; justify-content:center; }
  .sm { display:flex; align-items:center; justify-content:center; gap:12px; margin-top:12px; }
  .chip { background:var(--sky); border-radius:11px; padding:8px; display:flex; }
  .cap { font-size:11px; color:var(--ink3); text-align:center; margin-top:10px; }
  .cap b { display:block; color:var(--ink); font-weight:600; font-size:12px; margin-bottom:2px; }
  svg { display:block; }
  .note { background:var(--sunken); border-radius:14px; padding:14px 18px; max-width:70ch;
          font-size:13px; margin:16px 0 0; }
</style>
<h1>Temari — the ten states</h1>
<p class="sub">Generated from <code>build-mascot.mjs</code>, so every face shares one geometry.
The halo is a closed ring carrying mood through colour and weight — never a fill, so it never
reads as a progress meter. Small row is 28&nbsp;px on cream and on indigo.</p>
<div class="grid">${STATE_NAMES.map(stateCell).join('')}</div>

<h2>Centering check</h2>
<p class="sub">Dashed red is the body centre line and the vertical axis. The face bounding box
(brow top to mouth bottom) is the blue frame — its centre now lands on the body centre, with equal
space above and below. Toggle the guides off by eye: the face should read as sitting in the middle,
not riding high.</p>
<div class="grid">${['resting','challenging','celebrating','unimpressed']
  .map((n, i) => `<div class="cell"><div class="big" style="position:relative">
    ${mascot(n, { size: 132, id: 'g' + i })}
    <svg viewBox="0 0 100 100" width="132" height="132" style="position:absolute;inset:0">
      <path d="M6 52 H94" stroke="#c0392b" stroke-width="0.6" stroke-dasharray="3 2.5" fill="none"/>
      <path d="M50 8 V96" stroke="#c0392b" stroke-width="0.6" stroke-dasharray="3 2.5" fill="none"/>
      <rect x="30" y="39" width="40" height="26" fill="none" stroke="#2f81f7" stroke-width="0.6"/>
    </svg></div>
    <div class="cap"><b>${n}</b></div></div>`).join('')}</div>

<h2>The six wearable slots</h2>
<p class="sub">Garments are drawn as flat bands clipped to the body circle, so they take the ball's
curve automatically and can never escape the silhouette. Colours here are placeholders — each of
the 25 catalogue items supplies its own.</p>
<div class="grid">${SLOT_NAMES.map((w, i) => slotCell(w, i)).join('')}</div>

<h2>Fully equipped</h2>
<div class="grid">
  <div class="cell"><div class="big">${mascot('challenging', { size: 104, wearing: SLOT_NAMES, id: 'all1' })}</div><div class="cap"><b>all six</b>challenging</div></div>
  <div class="cell"><div class="big">${mascot('celebrating', { size: 104, wearing: ['headband','shirt','shorts','shoes','medal'], id: 'all2' })}</div><div class="cap"><b>no aura</b>celebrating</div></div>
  <div class="cell"><div class="big">${mascot('resting', { size: 104, wearing: ['headband','shoes'], id: 'all3' })}</div><div class="cap"><b>partial</b>resting</div></div>
  <div class="cell"><div class="big">${mascot('unimpressed', { size: 28, wearing: SLOT_NAMES, id: 'all4' })}</div><div class="cap"><b>28px</b>all six</div></div>
</div>
<div class="note"><b>Watch the aura.</b> It sits at r=47 and the mood halo at r=40 — two concentric
rings. Fully equipped, that reads as busy, and on a mood with a heavy halo (gold, weight 9) they
start to compete. Worth deciding whether aura should suppress the mood halo while equipped.</div>
`;
}

if (process.argv[1]?.endsWith('build-mascot.mjs')) {
  const out = new URL('./mascot/', import.meta.url);
  mkdirSync(out, { recursive: true });
  for (const name of STATE_NAMES) writeFileSync(new URL(`./temari-${name}.svg`, out), mascot(name));
  for (const w of SLOT_NAMES) {
    writeFileSync(new URL(`./slot-${w}.svg`, out), mascot('resting', { wearing: [w], id: 'slot-' + w }));
  }
  writeFileSync(new URL('./mascot-states.html', import.meta.url), previewHtml());
  console.log(`wrote ${STATE_NAMES.length} states + ${SLOT_NAMES.length} slots + preview`);
}
