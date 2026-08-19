import { writeFileSync } from 'node:fs';
import { mascot, BOUNDS } from './build-mascot.mjs';
import { rootVars } from './build-tokens.mjs';

/* Empty states. Written as blocks (art + copy + action) rather than bare
   illustrations, because the copy is most of the work — an empty state is
   where a training partner either sounds like a friend or like a 404. */

const STATES = [
  {
    key: 'no-runs',
    art: 'resting',
    scene: 'shelf',
    head: 'nothing to compare yet',
    body: 'go run something. i’ll be here doing the math.',
    cta: 'sync from strava',
    note: 'Brand new account, zero activities.',
  },
  {
    key: 'backfilling',
    art: 'impressed',
    scene: 'filling',
    head: 'pulling your history',
    body: 'strava’s handing it over. older runs will fill in as they arrive — you don’t have to wait around.',
    cta: null,
    note: 'Summary-first backfill in progress. Every public signup sees this.',
  },
  {
    key: 'no-past-match',
    art: 'challenging',
    scene: 'compare',
    head: 'nothing to measure this against',
    body: 'first time you’ve run this one. do it again and i’ll tell you exactly what changed.',
    cta: null,
    note: 'Past You has no comparable run. The USP’s own empty state.',
  },
  {
    key: 'no-gps',
    art: 'unimpressed',
    scene: 'nogps',
    head: 'no gps on this one',
    body: 'treadmill, then. i’ve still got your pace and heart rate — that’s the half that matters.',
    cta: null,
    note: 'Indoor run: RouteGlyph falls back to the pace-shape tier.',
  },
  {
    key: 'no-cards',
    art: 'resting',
    scene: 'grid',
    head: 'shelf’s empty',
    body: 'every run mints one. this fills up faster than you’d expect.',
    cta: null,
    note: 'Collection before the first card.',
  },
  {
    key: 'error',
    art: 'concerned',
    scene: 'broken',
    head: 'that didn’t work',
    body: 'not your fault. give it a minute and try again.',
    cta: 'try again',
    note: 'Generic failure. Never blames the user.',
  },
];

/* Supporting scenery, drawn behind the character. Deliberately faint: the
   mascot and the sentence carry the state, the scene only sets context. */
const SCENE = {
  shelf: `<path d="M14 84 H106" stroke="var(--line-strong)" stroke-width="2.5" stroke-linecap="round"/>
    ${[26, 50, 74].map((x) => `<rect x="${x}" y="66" width="20" height="16" rx="4"
      fill="none" stroke="var(--line-strong)" stroke-width="2" stroke-dasharray="3 3.5"/>`).join('')}`,
  filling: `${[0, 1, 2, 3].map((i) => `<rect x="16" y="${62 + i * 9}" width="${88 - i * 18}" height="5" rx="2.5"
      fill="var(--line-strong)" opacity="${0.85 - i * 0.2}"/>`).join('')}`,
  compare: `<path d="M30 78 H50 M70 78 H90" stroke="var(--line-strong)" stroke-width="2.5" stroke-linecap="round"/>
    <rect x="26" y="62" width="28" height="12" rx="4" fill="none" stroke="var(--line-strong)" stroke-width="2"/>
    <rect x="66" y="62" width="28" height="12" rx="4" fill="none" stroke="var(--line-strong)"
      stroke-width="2" stroke-dasharray="3 3.5"/>
    <path d="M57 68 h6" stroke="var(--line-strong)" stroke-width="2.5" stroke-linecap="round"/>`,
  nogps: `${[16, 30, 44, 58, 72, 86].map((x, i) => `<rect x="${x}" y="${84 - [10, 16, 8, 18, 13, 21][i]}"
      width="8" height="${[10, 16, 8, 18, 13, 21][i]}" rx="2" fill="var(--line-strong)" opacity="0.75"/>`).join('')}`,
  grid: `${[0, 1, 2, 3].map((i) => `<rect x="${18 + (i % 2) * 44}" y="${60 + Math.floor(i / 2) * 16}"
      width="36" height="13" rx="4" fill="none" stroke="var(--line-strong)" stroke-width="2" stroke-dasharray="3 3.5"/>`).join('')}`,
  broken: `<path d="M22 80 L44 80 L52 68 L60 88 L68 74 L78 80 L98 80"
      fill="none" stroke="var(--line-strong)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>`,
};

/* Placement is derived from the mascot's own bounds: scale first, then translate
   so its top edge lands on TOP_MARGIN and it stays horizontally centred. */
const VB_W = 120, SCALE = 0.62, TOP_MARGIN = 5;
const TX = VB_W / 2 - 50 * SCALE;
const TY = TOP_MARGIN - BOUNDS.top * SCALE;

const illustration = (s) => `
  <svg viewBox="0 0 ${VB_W} 100" width="150" height="125" role="img" aria-label="${s.head}">
    <g opacity="0.85">${SCENE[s.scene]}</g>
    <g transform="translate(${TX.toFixed(2)} ${TY.toFixed(2)}) scale(${SCALE})">${mascot(s.art, { size: 100, id: 'e-' + s.key })}</g>
  </svg>`;

function html() {
  const block = (s) => `
    <div class="cell">
      <div class="empty">
        ${illustration(s)}
        <div class="head">${s.head}</div>
        <div class="body">${s.body}</div>
        ${s.cta ? `<button class="cta">${s.cta}</button>` : '<div class="nocta">no action — it resolves itself</div>'}
      </div>
      <div class="note"><code>${s.key}</code>${s.note}</div>
    </div>`;
  return `<!doctype html>
<meta charset="utf-8">
<title>temari — empty states</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@1,9..144,600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<style>
  :root{${rootVars()}}
  *{box-sizing:border-box}
  body{margin:0;padding:var(--pad-page);background:var(--surface-sunken);color:var(--ink);
       font-family:var(--font-sans)}
  h1{font-size:21px;font-weight:800;margin:0 0 4px;letter-spacing:-.01em}
  p.lede{margin:0 0 34px;color:var(--ink-3);max-width:74ch;font-size:14px;line-height:1.55}
  .grid{display:flex;flex-wrap:wrap;gap:var(--s-6)}
  .cell{width:330px}
  .empty{background:var(--surface);border:1px solid var(--line);border-radius:var(--r-lg);
         box-shadow:var(--e1);padding:var(--pad-hero);text-align:center;
         display:flex;flex-direction:column;align-items:center;min-height:330px}
  .empty svg{display:block}
  .head{font-family:var(--font-display);font-style:italic;font-weight:600;font-size:21px;
        line-height:1.25;margin-top:6px;letter-spacing:-.01em}
  .body{font-size:13.5px;color:var(--ink-3);line-height:1.5;margin-top:9px;max-width:34ch}
  .cta{margin-top:auto;padding-top:0;background:var(--horizon);color:var(--ink);border:0;
       border-radius:var(--r-full);padding:var(--s-3) var(--s-6);font:800 13px var(--font-sans);
       cursor:pointer;box-shadow:var(--e1);margin-block-start:var(--s-4)}
  .nocta{margin-top:auto;font-size:11.5px;color:var(--ink-3);opacity:.75;padding-top:var(--s-4)}
  .note{margin-top:11px;font-size:11.5px;color:var(--ink-3);line-height:1.45}
  .note code{display:block;font-family:var(--font-mono);font-size:11px;color:var(--ink-2);margin-bottom:2px}
</style>
<h1>Empty states</h1>
<p class="lede">Six, not the three in the brief. An empty state is where a training partner either
sounds like a friend or like a 404, so the sentence matters more than the drawing — the art is
deliberately faint and the copy carries it. Two rules held throughout: never blame the user, and
never apologise for something that is about to fix itself.</p>
<div class="grid">${STATES.map(block).join('')}</div>
`;
}

export { STATES };

if (process.argv[1]?.endsWith('build-empty.mjs')) {
  writeFileSync(new URL('./empty-states.html', import.meta.url), html());
  console.log(`wrote empty-states.html (${STATES.length} states)`);
}
