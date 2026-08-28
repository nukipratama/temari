#!/usr/bin/env node

/*
 * Source guard: keeps off-token utilities out of resources/js and the Blade
 * templates under resources/views (error pages + the first-party Pulse cards),
 * minus the published vendor templates listed in EXCLUDED.
 *
 * Two rules, both enforcing the same thing — a value a designer can move must
 * live in the `@theme` block of resources/css/app.css, not at a call site:
 *
 *   1. Colour must resolve through a semantic `--color-*` token (`bg-horizon`,
 *      `text-ink-3`, `bg-rarity-epic`), never a raw Tailwind shade
 *      (`bg-blue-500`, `text-lime-600`).
 *   2. Elevation must use the warm-tinted `--shadow-e*` scale. Tailwind's
 *      defaults are neutral black, which reads dirty on a cream ground.
 *
 * A third rule (off-scale radius: `rounded-2xl`/`3xl`/`4xl` sitting outside
 * the `--radius-*` scale) existed from the v2 token set until F2, which
 * tokened the rest of Tailwind's built-in radius keywords
 * (`--radius-2xl/3xl/4xl` joined the pre-existing xs/sm/md/lg/xl/full in
 * app.css). With the whole named scale now backed by a token, there is
 * nothing left in that closed vocabulary to reject — see the note above
 * `RULES` below, and `check-raw-palette.test.ts` for the regression test.
 *
 * Rule 2 is new with the v2 token set: before it there was no elevation scale
 * to point at, so it was only ever documented as a habit. Arbitrary radii
 * (`rounded-[11px]`) are still allowed — the collectible card art is drawn to
 * its own geometry and is not app chrome.
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
const scanDirs = [
    path.join(root, 'resources/js'),
    path.join(root, 'resources/views'),
];

/**
 * Published vendor templates. Their markup is Laravel Pulse's own, written
 * against Pulse's bundled stylesheet rather than our tokens, so holding them to
 * the token vocabulary would mean rewriting a design we don't own.
 */
const EXCLUDED = [path.join(root, 'resources/views/vendor')];

const PALETTE_FAMILIES = [
    'slate',
    'gray',
    'zinc',
    'neutral',
    'stone',
    'red',
    'orange',
    'amber',
    'yellow',
    'lime',
    'green',
    'emerald',
    'teal',
    'cyan',
    'sky',
    'blue',
    'indigo',
    'violet',
    'purple',
    'fuchsia',
    'pink',
    'rose',
];
const SHADES = [
    '50',
    '100',
    '200',
    '300',
    '400',
    '500',
    '600',
    '700',
    '800',
    '900',
    '950',
];

const UTILITY_PREFIXES = [
    'bg',
    'text',
    'border',
    'ring',
    'ring-offset',
    'from',
    'via',
    'to',
    'fill',
    'stroke',
    'outline',
    'divide',
    'decoration',
    'accent',
    'caret',
    'shadow',
];

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
    /* Rule 3 (off-scale radius utility) removed in F2: `--radius-2xl/3xl/4xl`
       joined app.css's @theme static block alongside the pre-existing
       xs/sm/md/lg/xl/full, so `rounded-2xl|3xl|4xl` are now legitimately
       token-backed — every named Tailwind radius keyword resolves through
       resources/css/app.css's scale. Nothing in that closed vocabulary is
       off-scale any more; see check-raw-palette.test.ts for the regression
       test proving this removal was deliberate, not the rule silently
       going empty (R10 in plan/README.md). */
];

/** `.blade.php` has a two-part extension, so match on the suffix, not extname(). */
const SCAN_SUFFIXES = ['.ts', '.tsx', '.blade.php'];

function walk(dir) {
    return readdirSync(dir, { recursive: true, withFileTypes: true })
        .filter(
            (entry) =>
                entry.isFile() &&
                SCAN_SUFFIXES.some((suffix) => entry.name.endsWith(suffix)),
        )
        .map((entry) => path.join(entry.parentPath, entry.name))
        .filter(
            (file) =>
                !EXCLUDED.some((excluded) =>
                    file.startsWith(excluded + path.sep),
                ),
        );
}

export { RULES };

if (process.argv[1]?.endsWith('check-raw-palette.mjs')) {
    const files = scanDirs.flatMap(walk);
    const problems = new Map(RULES.map((rule) => [rule.name, []]));

    for (const file of files) {
        const lines = readFileSync(file, 'utf8').split('\n');
        lines.forEach((line, i) => {
            for (const rule of RULES) {
                for (const match of line.match(rule.re) ?? []) {
                    problems
                        .get(rule.name)
                        .push(
                            `${path.relative(root, file)}:${i + 1}  ${match}`,
                        );
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

    console.log(
        `Design token source guard: ${files.length} files scanned, zero off-token utilities ✓`,
    );
}
