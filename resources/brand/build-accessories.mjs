import { writeFileSync, mkdirSync } from 'node:fs';
import { mascot } from './build-mascot.mjs';
import { rootVars, COLOR, MOOD, RARITY, RARITY_INK } from './build-tokens.mjs';

/* The 25 unlocks from config/temari_unlocks.php, drawn against the six slot
   shapes. Colour carries rarity; a small detail carries the item's theme, so
   two rare shirts still read as different objects. */

const CX = 50;
const tick = (d, w = 2, c = COLOR.cream) =>
  `<path d="${d}" fill="none" stroke="${c}" stroke-width="${w}" stroke-linecap="round"/>`;

// themed overlays, drawn in mascot coordinates on top of the slot shape
const DETAIL = {
  sunrise: tick(`M${CX - 9} 72.5 a9 9 0 0 1 18 0`, 2.2),
  rain: tick(`M${CX - 10} 70.5 v3 M${CX} 70 v4 M${CX + 10} 70.5 v3`, 2),
  splitShorts: `<rect x="${CX}" y="75" width="31" height="7" fill="${COLOR.cream}" opacity="0.34"/>`,
  speedStripe: tick(`M${CX - 18} 87 h8 M${CX + 10} 87 h8`, 2),
  lugs: tick(`M${CX - 18} 90 v2 M${CX - 14} 90.5 v2 M${CX - 10} 90 v2
              M${CX + 10} 90 v2 M${CX + 14} 90.5 v2 M${CX + 18} 90 v2`, 1.6, COLOR.ink),
  crownStud: `<circle cx="${CX + 14}" cy="26" r="2.4" fill="${COLOR.cream}"/>`,
  medalStar: `<circle cx="${CX}" cy="85" r="2.6" fill="${COLOR.sky}" opacity="0.55"/>`,
  medalRing: `<circle cx="${CX}" cy="85" r="3.4" fill="none" stroke="${COLOR.sky}"
     stroke-width="1.3" opacity="0.5"/>`,
};

const AURA = (colour, dash) =>
  `<circle cx="50" cy="52" r="47" fill="none" stroke="${colour}" stroke-width="2.6"
     stroke-dasharray="${dash}" stroke-linecap="round" opacity="0.9"/>`;

const ITEMS = [
  // medal
  ['accessory.medal_first', 'medal', 'common', 'First Medal', '#a98f6b', null],
  ['accessory.medal_silver', 'medal', 'uncommon', 'Silver Medal', '#b9c0c9', DETAIL.medalStar],
  ['accessory.medal_gold', 'medal', 'rare', 'Gold Medal', COLOR.horizon, DETAIL.medalStar],
  ['accessory.medal_platinum', 'medal', 'epic', 'Platinum Medal', '#dfe6f2', DETAIL.medalRing],
  // headband — named by rarity, so the loot ladder is the theme
  ['accessory.headband_uncommon', 'headband', 'uncommon', 'Uncommon Headband', RARITY.uncommon, null],
  ['accessory.headband_rare', 'headband', 'rare', 'Rare Headband', RARITY.rare, null],
  ['accessory.headband_epic', 'headband', 'epic', 'Epic Headband', RARITY.epic, DETAIL.crownStud],
  ['accessory.headband_legendary', 'headband', 'legendary', 'Legendary Headband', RARITY.legendary, DETAIL.crownStud],
  // shirt
  ['accessory.shirt_beginner', 'shirt', 'common', 'Beginner Shirt', COLOR.stone, null],
  ['accessory.shirt_early_bird', 'shirt', 'uncommon', 'Early Bird Shirt', MOOD.easy, DETAIL.sunrise],
  ['accessory.shirt_rain_warrior', 'shirt', 'rare', 'Rain Warrior Shirt', RARITY.rare, DETAIL.rain],
  ['accessory.shirt_legendary', 'shirt', 'legendary', 'Legendary Shirt', RARITY.legendary, null],
  // shorts
  ['accessory.shorts_lightweight', 'shorts', 'common', 'Lightweight Shorts', COLOR.stone, null],
  ['accessory.shorts_explorer', 'shorts', 'uncommon', 'Explorer Shorts', COLOR['leaf-deep'], null],
  ['accessory.shorts_negative_split', 'shorts', 'rare', 'Negative Split Shorts', RARITY.rare, DETAIL.splitShorts],
  ['accessory.shorts_marathon', 'shorts', 'epic', 'Marathon Shorts', RARITY.epic, null],
  // shoes
  ['accessory.shoes_basic', 'shoes', 'common', 'Basic Shoes', COLOR.stone, null],
  ['accessory.shoes_speed', 'shoes', 'uncommon', 'Speed Shoes', MOOD.wobbly, DETAIL.speedStripe],
  ['accessory.shoes_rugged', 'shoes', 'rare', 'Rugged Shoes', COLOR['leaf-deep'], DETAIL.lugs],
  ['accessory.shoes_legendary', 'shoes', 'legendary', 'Legendary Shoes', RARITY.legendary, DETAIL.speedStripe],
  // aura — the one slot where the detail *is* the item
  ['accessory.aura_warmup', 'aura', 'common', 'Warm-Up Aura', COLOR.horizon, null, AURA(COLOR.horizon, '1.5 7')],
  ['accessory.aura_heatwave', 'aura', 'uncommon', 'Heatwave Aura', COLOR['ember-deep'], null, AURA(COLOR['ember-deep'], '3 5')],
  ['accessory.aura_calm', 'aura', 'rare', 'Calm Aura', MOOD.chill, null, AURA(MOOD.chill, '0.1 6')],
  ['accessory.aura_champion', 'aura', 'epic', 'Champion Aura', RARITY.epic, null, AURA(RARITY.epic, '999')],
  ['accessory.aura_windrunner', 'aura', 'rare', 'Windrunner Aura', RARITY.rare, null, AURA(RARITY.rare, '14 6')],
].map(([key, slot, rarity, name, colour, detail, override]) =>
  ({ key, slot, rarity, name, colour, detail, override }));

const SLOT_ORDER = ['headband', 'shirt', 'shorts', 'shoes', 'medal', 'aura'];

export const worn = (item, opts = {}) =>
  mascot(opts.state ?? 'resting', {
    size: opts.size ?? 100,
    id: 'acc-' + item.key.replace(/\W/g, ''),
    wearing: [{ slot: item.slot, colour: item.colour, detail: item.override ?? item.detail }],
  });

function html() {
  const tile = (it) => `
    <div class="tile">
      <div class="art">${worn(it, { size: 104 })}</div>
      <div class="nm">${it.name.toLowerCase()}</div>
      <div class="rr" style="color:${RARITY_INK[it.rarity]}">
        <i style="background:${RARITY[it.rarity]};border-color:${RARITY_INK[it.rarity]}"></i>${it.rarity}</div>
    </div>`;
  const group = (slot) => `
    <h2>${slot}<span>${ITEMS.filter((i) => i.slot === slot).length} items</span></h2>
    <div class="grid">${ITEMS.filter((i) => i.slot === slot).map(tile).join('')}</div>`;
  return `<!doctype html>
<meta charset="utf-8">
<title>temari — accessories</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<style>
  :root{${rootVars()}}
  *{box-sizing:border-box}
  body{margin:0;padding:var(--pad-page);background:var(--surface-sunken);color:var(--ink);
       font-family:var(--font-sans)}
  h1{font-size:21px;font-weight:800;margin:0 0 4px;letter-spacing:-.01em}
  p.lede{margin:0 0 var(--s-8);color:var(--ink-3);max-width:74ch;font-size:14px;line-height:1.55}
  h2{font-size:13px;font-weight:800;margin:var(--s-10) 0 var(--s-3);text-transform:uppercase;
     letter-spacing:.09em;color:var(--ink-3);display:flex;align-items:baseline;gap:var(--s-2)}
  h2 span{font-size:11px;font-weight:500;letter-spacing:0;text-transform:none;opacity:.8}
  .grid{display:flex;flex-wrap:wrap;gap:var(--s-3)}
  .tile{width:150px;background:var(--surface);border:1px solid var(--line);
        border-radius:var(--r-lg);padding:var(--pad-card);box-shadow:var(--e1);text-align:center}
  .art{display:flex;justify-content:center}
  .nm{font-size:12.5px;font-weight:700;margin-top:var(--s-2)}
  .rr{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
      margin-top:var(--s-1);display:flex;align-items:center;justify-content:center;gap:var(--s-1)}
  .rr i{width:8px;height:8px;border-radius:999px;border:1.5px solid;display:inline-block}
  svg{display:block}
</style>
<h1>Accessories — 25 items, 6 slots</h1>
<p class="lede">Straight from <code>config/temari_unlocks.php</code>. The six slot shapes stay
fixed; <b>colour carries rarity</b> and <b>a small detail carries the theme</b>, so two rare items
in the same slot still read as different objects. Rarity dots use the outline rule — the light
fills would fail 3:1 bare.</p>
${SLOT_ORDER.map(group).join('')}
`;
}

export { ITEMS };

if (process.argv[1]?.endsWith('build-accessories.mjs')) {
  const out = new URL('./accessories/', import.meta.url);
  mkdirSync(out, { recursive: true });
  for (const it of ITEMS) {
    writeFileSync(new URL(`./${it.key.replace('accessory.', '')}.svg`, out), worn(it));
  }
  writeFileSync(new URL('./accessories.html', import.meta.url), html());
  console.log(`wrote ${ITEMS.length} items + accessories.html`);
}
