import { writeFileSync } from 'node:fs';
import { mascot } from './build-mascot.mjs';
import { card } from './build-cards.mjs';
import { rootVars, COLOR, RARITY } from './build-tokens.mjs';

/* Key screen compositions. Built in HTML against the real tokens rather than as
   flat artwork, so Phase 2 can port them and so the token set is actually proven
   on a screen instead of only in a swatch grid. */

const mark = (size, base = 'var(--sky)', lead = 'var(--horizon-ink)') =>
  `<svg viewBox="0 0 100 100" width="${size}" height="${size}" fill="none" stroke-width="11" stroke-linecap="round">
     <path stroke="${lead}" d="M50 12.5 A37.5 37.5 0 1 1 31.25 17.52"/>
     <path stroke="${base}" d="M50 27 A23 23 0 1 1 30.09 61.5"/></svg>`;

const topbar = (title) => `
  <div class="topbar">
    <div class="brand">${mark(22)}<span>temari</span></div>
    <div class="bell">${title}</div>
  </div>`;

const tabbar = (active) => `
  <div class="tabbar">
    ${['home', 'runs', 'cards', 'you'].map((t) =>
      `<div class="tab ${t === active ? 'on' : ''}">${t}</div>`).join('')}
  </div>`;

// ---- 1. Home — "am I getting better?" --------------------------------------
const home = () => `
${topbar('')}
<div class="scroll">
  <div class="verdict">
    <div class="voice">you're faster than you were in march. clearly.</div>
    <div class="voice-by">${mascot('challenging', { size: 34, id: 'hm' })}<span>temari</span></div>
  </div>

  <div class="evidence">
    ${[['same route, same heat', '5:41', '5:12', '-29s/km', 'good'],
       ['heart rate on your usual 8k', '168', '159', '-9 bpm', 'good'],
       ['longest run', '12.0', '18.4', '+6.4 km', 'good'],
       ['weeks since you faded after km 6', '—', '7', 'holding', 'flat']].map(([label, then, now, delta, tone]) => `
      <div class="ev">
        <div class="ev-label">${label}</div>
        <div class="ev-nums">
          <span class="then">${then}</span>
          <span class="arrow">→</span>
          <span class="now">${now}</span>
          <span class="delta ${tone}">${delta}</span>
        </div>
      </div>`).join('')}
  </div>

  <div class="today">
    <div class="today-head">today</div>
    <div class="today-body">
      <div class="today-main">easy 6k</div>
      <div class="today-sub">legs are asking for it. keep it under 6:00.</div>
    </div>
  </div>
</div>
${tabbar('home')}`;

// ---- 2. Run detail — Past You as hero ---------------------------------------
const runDetail = () => `
${topbar('')}
<div class="scroll">
  <div class="run-head">
    <div class="run-dist">8.2<span>km</span></div>
    <div class="run-meta">this morning · 42:31 · 5:12 /km</div>
  </div>

  <div class="pastyou">
    <div class="py-head">you've run this before</div>
    <div class="py-grid">
      <div class="py-col"><div class="py-when">14 mar</div>
        <div class="py-val">5:41</div><div class="py-k">pace</div>
        <div class="py-val">168</div><div class="py-k">avg hr</div></div>
      <div class="py-mid">→</div>
      <div class="py-col now"><div class="py-when">today</div>
        <div class="py-val">5:12</div><div class="py-k">pace</div>
        <div class="py-val">159</div><div class="py-k">avg hr</div></div>
    </div>
    <div class="py-voice">${mascot('impressed', { size: 30, id: 'rd' })}
      <span>same route, same heat, and your heart's doing less work for more speed.</span></div>
  </div>

  <div class="panel">
    <div class="panel-head">route</div>
    <svg viewBox="0 0 100 64" class="routebox">
      <path d="M10 46 C16 34 24 50 33 43 C42 36 44 20 55 17 C66 14 72 27 67 36 C62 45 50 44 47 50 C44 55 54 56 64 54 C74 51 82 44 90 47"
        fill="var(--rarity-rare)" fill-opacity="0.14" stroke="var(--rarity-rare)" stroke-width="3.2"
        stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
  </div>

  <div class="panel">
    <div class="panel-head">splits</div>
    <div class="splits">${[62, 71, 58, 66, 74, 80, 69, 90].map((h, i) =>
      `<div class="split"><div class="bar" style="height:${h}%;background:${i === 7 ? 'var(--rarity-rare)' : 'var(--line-strong)'}"></div><span>${i + 1}</span></div>`).join('')}</div>
  </div>
</div>
${tabbar('runs')}`;

// ---- 3. Collection grid -----------------------------------------------------
const collection = () => `
${topbar('')}
<div class="scroll">
  <div class="chips">
    ${['all', 'common', 'rare', 'epic', 'legendary'].map((c, i) =>
      `<div class="chip ${i === 0 ? 'on' : ''}">${c}</div>`).join('')}
  </div>
  <div class="cardgrid">
    ${['legendary', 'rare', 'epic', 'common', 'uncommon', 'rare'].map((r, i) =>
      `<div class="cardcell">${card(r, undefined, { variant: i === 3 ? 'pace' : 'route' })
        .replace('width="300" height="440"', 'width="100%" height="auto"')
        .replace(/id="foil-(\w+)"/g, `id="foil-$1-${i}"`)
        .replace(/url\(#foil-(\w+)\)/g, `url(#foil-$1-${i})`)}</div>`).join('')}
  </div>
</div>
${tabbar('cards')}`;

// ---- 4. Season --------------------------------------------------------------
const season = () => {
  const weeks = 12, done = 7;
  return `
${topbar('')}
<div class="scroll">
  <div class="season-head">
    <div class="season-mascot">${mascot('challenging', { size: 76, wearing: ['headband', 'shoes'], id: 'se' })}</div>
    <div>
      <div class="season-name">autumn block</div>
      <div class="season-sub">week ${done} of ${weeks} · build</div>
    </div>
  </div>

  <div class="track">
    ${Array.from({ length: weeks }, (_, i) => {
      const phase = i < 3 ? 'base' : i < 8 ? 'build' : i < 11 ? 'peak' : 'taper';
      return `<div class="wk ${i < done ? 'done' : ''} ${i === done - 1 ? 'here' : ''}" data-p="${phase}"></div>`;
    }).join('')}
  </div>
  <div class="phaserow"><span>base</span><span>build</span><span>peak</span><span>taper</span></div>

  <div class="panel">
    <div class="panel-head">this week</div>
    ${[['mon', 'rest', '', 'done'], ['tue', 'easy 6k', '5:48', 'done'],
       ['wed', 'intervals 5×800', '4:38', 'today'], ['thu', 'rest', '', ''],
       ['fri', 'easy 8k', '', ''], ['sun', 'long 16k', '', '']].map(([d, s, p, st]) => `
      <div class="sess ${st}">
        <div class="sess-d">${d}</div>
        <div class="sess-s">${s}</div>
        <div class="sess-p">${p}</div>
      </div>`).join('')}
  </div>

  <div class="goals">
    ${[['sub-50 10k', 78], ['500 km season', 61], ['15 runs in build', 93]].map(([g, pct]) => `
      <div class="goal">
        <div class="goal-top"><span>${g}</span><span class="goal-pct">${pct}%</span></div>
        <div class="goal-bar"><i style="width:${pct}%"></i></div>
      </div>`).join('')}
  </div>
</div>
${tabbar('you')}`;
};

const SCREENS = [
  ['Home', 'the verdict: am I getting better?', home()],
  ['Run detail', 'past you as the hero, not a footnote', runDetail()],
  ['Collection', 'one card system, rarity in the frame', collection()],
  ['Season', 'where you are in the block', season()],
];

function html() {
  return `<!doctype html>
<meta charset="utf-8">
<title>Temari — key screens</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@1,9..144,600;1,9..144,700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<style>
  :root{${rootVars()}}
  *{box-sizing:border-box}
  body{margin:0;padding:var(--pad-page);background:var(--surface-sunken);color:var(--ink);
       font-family:var(--font-sans)}
  h1{font-size:21px;font-weight:800;margin:0 0 4px;letter-spacing:-.01em}
  p.lede{margin:0 0 34px;color:var(--ink-3);max-width:72ch;font-size:14px;line-height:1.55}
  .rack{display:flex;gap:var(--s-8);flex-wrap:wrap;align-items:flex-start}
  .phone{width:390px;height:800px;background:var(--surface);border-radius:var(--r-xl);
         box-shadow:var(--e3);display:flex;flex-direction:column;overflow:hidden;
         border:1px solid var(--line)}
  .cap{width:390px;margin-top:12px;font-size:12px}
  .cap b{display:block;font-weight:700;font-size:13px}
  .cap span{color:var(--ink-3)}

  .topbar{display:flex;align-items:center;justify-content:space-between;
          padding:var(--s-4) var(--s-4) var(--s-3);flex:none}
  .brand{display:flex;align-items:center;gap:var(--s-2);font-weight:800;font-size:16px;letter-spacing:-.02em}
  .scroll{flex:1;overflow:hidden;padding:var(--s-1) var(--s-4) var(--s-3);display:flex;flex-direction:column;gap:var(--s-4)}
  .tabbar{flex:none;display:flex;border-top:1px solid var(--line);background:var(--surface-elev)}
  .tab{flex:1;text-align:center;padding:var(--s-3) 0 var(--s-4);font-size:11px;font-weight:600;
       color:var(--ink-3);text-transform:lowercase}
  .tab.on{color:var(--sky);font-weight:800}

  /* home */
  .verdict{padding:var(--s-2) 0 var(--s-1)}
  .voice{font-family:var(--font-display);font-style:italic;font-weight:600;
         font-size:27px;line-height:1.22;letter-spacing:-.01em}
  .voice-by{display:flex;align-items:center;gap:var(--s-2);margin-top:12px;
            font-size:11px;color:var(--ink-3);font-weight:600;letter-spacing:.06em}
  .evidence{display:flex;flex-direction:column;gap:1px;background:var(--line);
            border-radius:var(--r-md);overflow:hidden;box-shadow:var(--e1)}
  .ev{background:var(--surface-card);padding:var(--pad-panel)}
  .ev-label{font-size:11.5px;color:var(--ink-3);margin-bottom:5px}
  .ev-nums{display:flex;align-items:baseline;gap:var(--s-2);font-family:var(--font-mono)}
  .then{font-size:15px;color:var(--ink-3)}
  .arrow{color:var(--ink-3);font-size:12px}
  .now{font-size:19px;font-weight:700}
  .delta{margin-left:auto;font-size:11.5px;font-weight:700;padding:var(--s-1) var(--s-2);
         border-radius:var(--r-full)}
  .delta.good{color:var(--mood-easy-ink);background:color-mix(in oklab,var(--mood-easy) 15%,transparent)}
  .delta.flat{color:var(--ink-3);background:var(--surface-sunken)}
  .today{background:var(--sky);border-radius:var(--r-md);padding:var(--pad-panel);box-shadow:var(--e2)}
  .today-head{font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:var(--ink-on-sky);font-weight:700}
  .today-main{font-size:23px;font-weight:800;color:var(--cream);margin-top:6px;letter-spacing:-.02em}
  .today-sub{font-size:12.5px;color:var(--ink-on-sky);margin-top:3px}

  /* run detail */
  .run-head{padding:var(--s-2) 0 0}
  .run-dist{font-family:var(--font-mono);font-size:52px;font-weight:800;letter-spacing:-.04em;color:var(--sky)}
  .run-dist span{font-size:19px;margin-left:6px;color:var(--horizon-ink)}
  .run-meta{font-size:12px;color:var(--ink-3);margin-top:2px}
  .pastyou{background:var(--surface-warm);border:1px solid var(--line);
           border-radius:var(--r-md);padding:var(--pad-panel);box-shadow:var(--e1)}
  .py-head{font-size:10px;letter-spacing:.14em;text-transform:uppercase;
           color:var(--ink-3);font-weight:700;margin-bottom:10px}
  .py-grid{display:flex;align-items:center;gap:var(--s-4)}
  .py-col{flex:1;text-align:center}
  .py-col.now .py-val{color:var(--sky)}
  .py-when{font-size:10.5px;color:var(--ink-3);font-weight:700;margin-bottom:6px;
           text-transform:uppercase;letter-spacing:.08em}
  .py-val{font-family:var(--font-mono);font-size:21px;font-weight:700;color:var(--ink-3)}
  .py-k{font-size:9.5px;color:var(--ink-3);margin-bottom:7px;letter-spacing:.06em}
  .py-mid{color:var(--horizon-ink);font-size:19px;font-weight:700}
  .py-voice{display:flex;gap:var(--s-3);align-items:flex-start;margin-top:12px;
            padding-top:var(--s-3);border-top:1px solid var(--line)}
  .py-voice span{font-family:var(--font-display);font-style:italic;font-size:14px;line-height:1.4}
  .panel{background:var(--surface-card);border:1px solid var(--line);
         border-radius:var(--r-md);padding:var(--pad-panel);box-shadow:var(--e1)}
  .panel-head{font-size:10px;letter-spacing:.14em;text-transform:uppercase;
              color:var(--ink-3);font-weight:700;margin-bottom:10px}
  .routebox{width:100%;height:auto;display:block}
  .splits{display:flex;align-items:flex-end;gap:var(--s-2);height:78px}
  .split{flex:1;display:flex;flex-direction:column;align-items:center;height:100%;justify-content:flex-end}
  .bar{width:100%;border-radius:var(--r-xs) var(--r-xs) 0 0}
  .split span{font-size:9px;color:var(--ink-3);margin-top:4px;font-family:var(--font-mono)}

  /* collection */
  .chips{display:flex;gap:var(--s-2);flex-wrap:wrap}
  .chip{font-size:11px;font-weight:600;padding:var(--pad-chip);border-radius:var(--r-full);
        background:var(--surface-card);border:1px solid var(--line);color:var(--ink-3)}
  .chip.on{background:var(--sky);color:var(--cream);border-color:var(--sky)}
  .cardgrid{display:grid;grid-template-columns:1fr 1fr;gap:var(--s-3)}
  .cardcell{border-radius:var(--r-sm);overflow:hidden;box-shadow:var(--e1)}
  .cardcell svg{display:block;width:100%;height:auto}

  /* season */
  .season-head{display:flex;align-items:center;gap:var(--s-4);padding:var(--s-1) 0}
  .season-name{font-size:22px;font-weight:800;letter-spacing:-.02em}
  .season-sub{font-size:12.5px;color:var(--ink-3);margin-top:2px}
  .track{display:flex;gap:var(--s-1);height:38px;align-items:stretch}
  .wk{flex:1;background:var(--surface-sunken);border-radius:var(--r-xs)}
  .wk.done{background:var(--horizon-ink)}
  .wk.here{background:var(--sky);box-shadow:var(--e2)}
  .phaserow{display:flex;justify-content:space-between;font-size:9.5px;
            color:var(--ink-3);letter-spacing:.1em;text-transform:uppercase;font-weight:700;margin-top:-8px}
  .sess{display:flex;align-items:center;gap:var(--s-3);padding:var(--s-2) 0;border-bottom:1px solid var(--line);font-size:13px}
  .sess:last-child{border-bottom:0}
  .sess-d{width:34px;font-size:10.5px;color:var(--ink-3);text-transform:uppercase;
          font-weight:700;letter-spacing:.06em}
  .sess-s{flex:1}
  .sess-p{font-family:var(--font-mono);font-size:12px;color:var(--ink-3)}
  .sess.done .sess-s{color:var(--ink-3)}
  .sess.today{font-weight:800}
  .sess.today .sess-d{color:var(--horizon-ink)}
  .goals{display:flex;flex-direction:column;gap:var(--s-3)}
  .goal-top{display:flex;justify-content:space-between;font-size:12px;font-weight:600;margin-bottom:5px}
  .goal-pct{font-family:var(--font-mono);color:var(--ink-3)}
  .goal-bar{height:7px;background:var(--surface-sunken);border-radius:var(--r-full);overflow:hidden}
  .goal-bar i{display:block;height:100%;background:var(--horizon-ink);border-radius:var(--r-full)}
</style>

<h1>Key screens</h1>
<p class="lede">Built in HTML against the real token set — every colour, radius, shadow and font
here is a token, nothing is hand-picked. These are the four screens that set the system; the rest
of the app derives from them. Phone frames are 390&nbsp;px, the narrow end of the range.</p>

<div class="rack">
  ${SCREENS.map(([name, note, body]) => `
    <div>
      <div class="phone">${body}</div>
      <div class="cap"><b>${name}</b><span>${note}</span></div>
    </div>`).join('')}
</div>
`;
}

if (process.argv[1]?.endsWith('build-screens.mjs')) {
  writeFileSync(new URL('./screens.html', import.meta.url), html());
  console.log('wrote screens.html');
}
