#!/usr/bin/env node

/*
 * Source guard: keeps off-token utilities out of resources/js.
 *
 * Three rules, all enforcing the same thing — a value a designer can move must
 * live in the `@theme` block of resources/css/app.css, not at a call site:
 *
 *   1. Colour must resolve through a semantic `--color-*` token (`bg-horizon`,
 *      `text-ink-3`, `bg-rarity-epic`), never a raw Tailwind shade
 *      (`bg-blue-500`, `text-lime-600`).
 *   2. Elevation must use the warm-tinted `--shadow-e*` scale. Tailwind's
 *      defaults are neutral black, which reads dirty on a cream ground.
 *   3. Radius must use the `--radius-*` scale (xs/sm/md/lg/xl/full).
 *      `rounded-2xl` / `rounded-3xl` are Tailwind defaults that sit outside it,
 *      which is how one screen ends up with four different card corners.
 *
 * Rules 2 and 3 are new with the v2 token set: before it there was no radius or
 * elevation scale to point at, so both were only ever documented as habits.
 * Arbitrary radii (`rounded-[11px]`) are still allowed — the collectible card
 * art is drawn to its own geometry and is not app chrome.
 *
 * Colour matching requires a Tailwind palette family name *and* one of its real
 * numeric shade steps (50/100/.../950) — not just `-\d`, which would false-
 * positive on legitimate tokens that happen to end in a digit (`bg-sky-2`,
 * `ring-mood-blazing/60`, …). `sky` and `stone` are also token names, so this is
 * the one place the two vocabularies can collide; the shade-suffix requirement
 * is what keeps them apart.
 *
 * Standalone: no build required, just a source-tree grep.
 *   node scripts/check-raw-palette.mjs
 */

import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const scanDir = path.join(root, 'resources/js');

const PALETTE_FAMILIES = [
    'slate', 'gray', 'zinc', 'neutral', 'stone',
    'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal',
    'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose',
];
const SHADES = ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950'];

const UTILITY_PREFIXES = [
    'bg', 'text', 'border', 'ring', 'ring-offset', 'from', 'via', 'to',
    'fill', 'stroke', 'outline', 'divide', 'decoration', 'accent', 'caret', 'shadow',
];

/** Radius sides, so `rounded-t-2xl` / `rounded-br-3xl` are caught too. */
const RADIUS_SIDES = ['t', 'r', 'b', 'l', 'tl', 'tr', 'br', 'bl', 's', 'e', 'ss', 'se', 'ee', 'es'];

const RULES = [
    {
        name: 'raw Tailwind palette utility',
        fix: 'Use a semantic --color-* token (see docs/design-tokens.md), e.g. `bg-blue-500` → `bg-sky` / `bg-horizon`.',
        re: new RegExp(
            `\\b(?:${UTILITY_PREFIXES.join('|')})-(?:${PALETTE_FAMILIES.join('|')})-(?:${SHADES.join('|')})\\b`,
            'g',
        ),
    },
    {
        name: 'off-token shadow utility',
        fix: 'Use the elevation scale: `shadow-e1` resting card · `shadow-e2` floating UI · `shadow-e3` sheet · `shadow-e4` modal.',
        re: /\bshadow-(?:xs|sm|md|lg|xl|2xl|inner)\b/g,
    },
    {
        name: 'off-scale radius utility',
        fix: 'Use the radius scale: `rounded-xs|sm|md|lg|xl|full`. `md` is the card/panel corner.',
        re: new RegExp(`\\brounded-(?:(?:${RADIUS_SIDES.join('|')})-)?(?:2xl|3xl|4xl)\\b`, 'g'),
    },
];

const SCAN_EXTENSIONS = new Set(['.ts', '.tsx']);

function walk(dir) {
    return readdirSync(dir, { recursive: true, withFileTypes: true })
        .filter((entry) => entry.isFile() && SCAN_EXTENSIONS.has(path.extname(entry.name)))
        .map((entry) => path.join(entry.parentPath, entry.name));
}

const files = walk(scanDir);
const problems = new Map(RULES.map((rule) => [rule.name, []]));

for (const file of files) {
    const lines = readFileSync(file, 'utf8').split('\n');
    lines.forEach((line, i) => {
        for (const rule of RULES) {
            for (const match of line.match(rule.re) ?? []) {
                problems.get(rule.name).push(`${path.relative(root, file)}:${i + 1}  ${match}`);
            }
        }
    });
}

const failed = RULES.filter((rule) => problems.get(rule.name).length > 0);

if (failed.length > 0) {
    console.error(`\n[31m✗ Design token source guard[0m\n`);
    for (const rule of failed) {
        const found = problems.get(rule.name);
        console.error(`  Found ${found.length} ${rule.name}(s):`);
        for (const p of found) console.error(`    • ${p}`);
        console.error(`  ${rule.fix}\n`);
    }
    process.exit(1);
}

console.log(`Design token source guard: ${files.length} files scanned, zero off-token utilities ✓`);
