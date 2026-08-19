// One-off derivation, not a build step: computes Pewter's full deployable
// token set (every --color-* app.css declares, plus the dawn-shift surface
// overrides) by reusing the exact same palette(dir) the direction-comparison
// page already uses — not re-deriving the OKLCH math by hand.
//
// Semantic/loot colours (leaf, ember, citrus, rarity-*, strava-orange,
// mood-*, and rarity-*-ink) are held exactly as app.css declares them today:
// palette() never touches them (see build-directions.mjs's HELD/INK_FAMILIES
// comments — "not brand, moving them would make it impossible to tell which
// change did the work"), and mood-*/rarity-*-ink were never in scope for the
// direction-comparison page at all. Run: node resources/brand/apply-pewter.mjs
import { DIRECTIONS, palette } from './build-directions.mjs';
import { readColorTokens, readDawnShiftSurfaces } from './grounds.mjs';

const TOKENS = readColorTokens();
const SHIFTS = readDawnShiftSurfaces();
const pewterDir = DIRECTIONS.find((d) => d.key === 'pewter');
if (!pewterDir) throw new Error('pewter direction missing from DIRECTIONS');

const { colors, shifts } = palette(pewterDir);

const finalTokens = { ...TOKENS, ...colors };
const finalShifts = { ...SHIFTS, ...shifts };

const changed = Object.entries(finalTokens).filter(([k, v]) => TOKENS[k] !== v);
const shiftsChanged = Object.entries(finalShifts).filter(([k, v]) => SHIFTS[k] !== v);

console.log('--- changed --color-* tokens (old -> new) ---');
for (const [k, v] of changed) console.log(`--color-${k}: ${TOKENS[k]} -> ${v}`);

console.log('\n--- changed dawn-shift surfaces (old -> new) ---');
for (const [k, v] of shiftsChanged) console.log(`${k}: ${SHIFTS[k]} -> ${v}`);

console.log('\n--- full token set (for app.css) ---');
console.log(JSON.stringify(finalTokens, null, 2));

console.log('\n--- full dawn-shift set ---');
console.log(JSON.stringify(finalShifts, null, 2));
