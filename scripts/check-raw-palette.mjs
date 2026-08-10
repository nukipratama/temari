#!/usr/bin/env node

/*
 * Source guard: keeps raw Tailwind palette utilities out of resources/js.
 *
 * docs/design-tokens.md has described this as an automated sweep for a while,
 * but until now it was only ever a manual `rg` instruction to run before
 * merging — nothing in scripts/, tests/, or CI actually enforced it. Every
 * color must resolve through a semantic Threadwork `--color-*` token
 * (`bg-horizon`, `text-ink-3`, `bg-rarity-epic`, …), never a raw Tailwind
 * shade (`bg-blue-500`, `text-lime-600`). A retheme is exactly the moment
 * a stray raw utility is most likely to sneak in (copy-pasting a "close
 * enough" color while a token is mid-flight), so this guard exists to keep
 * the discipline enforced rather than just documented.
 *
 * Matches on Tailwind's default palette family names *and* one of its real
 * numeric shade steps (50/100/.../950) — not just `-\d`, which would false-
 * positive on legitimate Threadwork tokens that happen to end in a digit
 * (`bg-sky-2`, `ring-mood-blazing/60`, …). `sky` and `stone` are also
 * Threadwork token names, so this is the one place the two vocabularies
 * can collide; the shade-suffix requirement is what keeps them apart.
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

const RAW_PALETTE_RE = new RegExp(
    `\\b(?:${UTILITY_PREFIXES.join('|')})-(?:${PALETTE_FAMILIES.join('|')})-(?:${SHADES.join('|')})\\b`,
    'g',
);

const SCAN_EXTENSIONS = new Set(['.ts', '.tsx']);

function walk(dir) {
    return readdirSync(dir, { recursive: true, withFileTypes: true })
        .filter((entry) => entry.isFile() && SCAN_EXTENSIONS.has(path.extname(entry.name)))
        .map((entry) => path.join(entry.parentPath, entry.name));
}

function fail(lines) {
    console.error(`\n[31m✗ Raw Tailwind palette guard[0m\n`);
    for (const line of lines) console.error(`  ${line}`);
    console.error('');
    process.exit(1);
}

const files = walk(scanDir);
const problems = [];

for (const file of files) {
    const content = readFileSync(file, 'utf8');
    const lines = content.split('\n');
    lines.forEach((line, i) => {
        const matches = line.match(RAW_PALETTE_RE);
        if (!matches) return;
        for (const match of matches) {
            problems.push(`${path.relative(root, file)}:${i + 1}  ${match}`);
        }
    });
}

if (problems.length > 0) {
    fail([
        `Found ${problems.length} raw Tailwind palette utility usage(s):`,
        ...problems.map((p) => `    • ${p}`),
        '',
        '  Use a semantic Threadwork token instead (see docs/design-tokens.md),',
        '  e.g. `bg-blue-500` → `bg-sky` / `bg-horizon` / whichever token matches intent.',
    ]);
}

console.log(`Raw Tailwind palette guard: ${files.length} files scanned, zero raw palette utilities ✓`);
