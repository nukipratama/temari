#!/usr/bin/env node

/*
 * Source guard: keeps off-token utilities out of resources/js and the Blade
 * templates under resources/views (error pages + the first-party Pulse cards),
 * minus the published vendor templates listed in EXCLUDED.
 *
 * Seven rules, all enforcing the same thing — a value a designer can move must
 * live in the `@theme` block of resources/css/app.css, not at a call site:
 *
 *   1. Colour must resolve through a semantic `--color-*` token (`bg-horizon`,
 *      `text-ink-3`, `bg-rarity-epic`), never a raw Tailwind shade
 *      (`bg-blue-500`, `text-lime-600`).
 *   2. Elevation must use the warm-tinted `--shadow-e*` scale. Tailwind's
 *      defaults are neutral black, which reads dirty on a cream ground.
 *   3. Font size must resolve through a `--text-*` token, never a px literal in
 *      an arbitrary class; the root steps up 20% at >=1280px and a px value
 *      opts out of that step. A rem literal at or above the floor (rule 6) is
 *      fine.
 *   4. The same, for an inline `fontSize` style prop. Canvas `ctx.font` strings
 *      are deliberately not matched — a canvas is a fixed raster, so px is
 *      correct there.
 *   5. A gradient stop must use a ground-reactive token, on either side of the
 *      ground: a fixed-light stop (cream/surface/line/ink) is unreadable on the
 *      dark ground and a fixed-dark one (the Sky family) is the mirror bug on
 *      light. Contrast audits skip gradients, so nothing else reports it.
 *   6. A rem font-size literal must not fall below the 11px floor
 *      (`0.6875rem` at the 16px phone root). Card art under
 *      resources/js/components/card/ is exempt, as MASTER.md documents.
 *   7. A radius must be a step of the `--radius-*` scale: bare `rounded` is
 *      Tailwind's 0.25rem, below the 6px `rounded-xs` floor. With no comment
 *      stripping, a bare token counts as a class only beside a hyphenated or
 *      variant utility, or alone in quotes, so prose and variables pass.
 *
 * Arbitrary radii (`rounded-[11px]`) are allowed: the collectible card art is
 * drawn to its own geometry and is not app chrome.
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
    {
        name: 'px font-size utility',
        fix: 'Use a `--text-*` token (`text-sm`, `text-headline-sm`, `text-display-lg`). A px literal opts out of the root step at >=1280px while everything around it scales.',
        re: /\btext-\[[0-9.]+px\]/g,
    },
    {
        name: 'inline px font-size',
        fix: 'Use a `--text-*` token class instead of a `fontSize` style prop, so the value scales with the root at >=1280px. Canvas `ctx.font` strings are not matched — a canvas is a fixed raster.',
        re: /\bfontSize\s*[:=][\s{]*(?:['"`][0-9.]+px['"`]|[0-9.]+)/g,
    },
    /* No off-scale radius rule: every named Tailwind radius keyword is token-backed in
       resources/css/app.css; check-raw-palette.test.ts proves the removal. */
    /* Append only: check-raw-palette.test.ts pins rules by index. */
    {
        name: 'ground-fixed gradient stop',
        fix: 'Use a ground-reactive token for a gradient stop (`from-popover`, `from-card`, `to-background`). A gradient is invisible to every contrast audit — contrast.mjs skips it for want of a flat colour to score, and an island scan misses it because `backgroundColor` on a gradient element is transparent — so a fixed-light stop under reactive text is unreadable on the dark ground and nothing reports it. Both 1.00:1 bugs the wrong-ground audit found were exactly this shape.',
        re: /\b(?:from|via|to)-(?:cream-deep|cream|surface-card|surface-elev|surface-warm|surface-sunken|surface|line-strong|line|ink-2|ink-3|ink|sky-deep|sky-2|sky)(?:\/[\w.[\]]+)?\b/g,
    },
    {
        name: 'sub-11px rem font-size',
        fix: 'Use `.text-label-micro` or `.text-meta` (11px) where the role fits, otherwise `text-xs`. The root is 16px below 1280px, so a rem literal under `0.6875rem` renders under the 11px floor on every phone; only card art (resources/js/components/card/) is exempt.',
        re: /\btext-\[(?:length:)?0?\.(?:[0-5]\d*|6(?:[0-7]\d*)?|68(?:[0-6]\d*)?|687(?:[0-4]\d*)?)rem\]/g,
        exemptDir: 'resources/js/components/card/',
    },
    {
        name: 'bare rounded utility',
        fix: 'Use a step of the radius scale: `rounded-xs` (6px) for small controls, `rounded-sm`/`rounded-md` above it. Bare `rounded` compiles to Tailwind\'s 0.25rem, below the scale\'s 6px floor.',
        re: /(?<=[^\s"'`]*\w[-:][^\s"'`]*\s+)(?:[\w-]+:)*rounded(?=[\s"'`]|$)|(?<=^|[\s"'`])(?:[\w-]+:)*rounded(?=\s+[^\s"'`]*\w[-:])|(?<=["'`])(?:[\w-]+:)*rounded(?=["'`])/g,
    },
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

function rulesFor(relativePath) {
    return RULES.filter(
        (rule) => !rule.exemptDir || !relativePath.startsWith(rule.exemptDir),
    );
}

export { RULES, rulesFor };

if (process.argv[1]?.endsWith('check-raw-palette.mjs')) {
    const files = scanDirs.flatMap(walk);
    const problems = new Map(RULES.map((rule) => [rule.name, []]));

    for (const file of files) {
        const relative = path.relative(root, file);
        const rules = rulesFor(relative);
        const lines = readFileSync(file, 'utf8').split('\n');
        lines.forEach((line, i) => {
            for (const rule of rules) {
                for (const match of line.match(rule.re) ?? []) {
                    problems
                        .get(rule.name)
                        .push(`${relative}:${i + 1}  ${match}`);
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
