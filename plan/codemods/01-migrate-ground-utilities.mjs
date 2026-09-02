#!/usr/bin/env node
/**
 * F3 pass 1 — migrate ground-dependent Tailwind utility classes to the
 * semantic layer F2 introduced, per the migration table in
 * ~/.claude/plans/valiant-jumping-sky.md ("What the sweep actually changes").
 *
 * Only utilities whose VALUE must flip per ground are touched. Fixed-identity
 * utilities (--mood-*, --rarity-* fills, --color-strava-orange, the -ink
 * suffix classes whose definitions already flipped in F2 without a rename)
 * are left alone.
 *
 * Order matters: longer/more-specific tokens are replaced before the bare
 * token they're a prefix of, so a single left-to-right pass is safe.
 *
 * Usage: node plan/codemods/01-migrate-ground-utilities.mjs [--dry-run]
 */
import { readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '../..');
const dryRun = process.argv.includes('--dry-run');

function walk(dir, out = []) {
    for (const entry of readdirSync(dir)) {
        const abs = path.join(dir, entry);
        const stat = statSync(abs);
        if (stat.isDirectory()) {
            if (entry === 'node_modules') continue;
            walk(abs, out);
        } else if (/\.(ts|tsx)$/.test(entry)) {
            out.push(abs);
        }
    }
    return out;
}

const REPLACEMENTS = [
    ['bg-surface-card', 'bg-card'],
    ['bg-surface-elev', 'bg-popover'],
    ['bg-surface-sunken', 'bg-muted'],
    ['bg-surface-warm', 'bg-accent'],
    ['bg-surface', 'bg-background'],
    ['text-ink-2', 'text-text-2'],
    ['text-ink-3', 'text-text-3'],
    ['text-ink', 'text-foreground'],
    ['border-line-strong', 'border-border-strong'],
    ['border-line', 'border-border'],
    ['font-display', 'font-serif'],
];

const files = walk(path.join(root, 'resources/js'));

let filesChanged = 0;
const perTokenHits = new Map(REPLACEMENTS.map(([from]) => [from, 0]));

for (const abs of files) {
    const original = readFileSync(abs, 'utf8');
    let text = original;

    for (const [from, to] of REPLACEMENTS) {
        const re = new RegExp(String.raw`(?<![\w-])${from}(?![\w-])`, 'g');
        const matches = text.match(re);
        if (matches) {
            perTokenHits.set(from, perTokenHits.get(from) + matches.length);
            text = text.replace(re, to);
        }
    }

    if (text !== original) {
        filesChanged += 1;
        if (!dryRun) writeFileSync(abs, text);
    }
}

console.log(
    `${dryRun ? '[dry-run] would change' : 'changed'} ${filesChanged} files`,
);
for (const [from, to] of REPLACEMENTS) {
    console.log(`  ${from} -> ${to}: ${perTokenHits.get(from)}`);
}

/*
 * resources/brand/grounds.json classifies every bg-* utility in use and
 * registers panel call sites by name — both keyed on the exact token names
 * this pass just renamed. Register the new paper grounds (they take over the
 * old surface-* family's role: something ink lands on) and rewrite the panel
 * block's keys and text-token entries the same way pass 1 rewrote source.
 */
const groundsPath = path.join(root, 'resources/brand/grounds.json');
const grounds = JSON.parse(readFileSync(groundsPath, 'utf8'));

const NEW_PAPERS = ['accent', 'background', 'card', 'muted', 'popover'];
grounds.paper = [...new Set([...grounds.paper, ...NEW_PAPERS])].sort((a, b) =>
    a.localeCompare(b),
);

const PANEL_KEY_PREFIX = [
    ['surface-card/', 'card/'],
    ['surface-elev/', 'popover/'],
    ['surface-sunken/', 'muted/'],
    ['surface-warm/', 'accent/'],
    ['surface/', 'background/'],
];
const PANEL_TEXT_TOKEN = new Map([
    ['ink-2', 'text-2'],
    ['ink-3', 'text-3'],
    ['ink', 'foreground'],
]);

function renamePanelKey(key) {
    const prefix = PANEL_KEY_PREFIX.find(([from]) => key.startsWith(from));
    return prefix ? prefix[1] + key.slice(prefix[0].length) : key;
}

const newPanel = {};
for (const [key, entry] of Object.entries(grounds.panel)) {
    newPanel[renamePanelKey(key)] = {
        ...entry,
        ...(entry.text
            ? { text: entry.text.map((t) => PANEL_TEXT_TOKEN.get(t) ?? t) }
            : {}),
    };
}
grounds.panel = newPanel;

if (!dryRun) {
    writeFileSync(groundsPath, JSON.stringify(grounds, null, 2) + '\n');
}
console.log(
    `${dryRun ? '[dry-run] would ' : ''}update resources/brand/grounds.json: +${NEW_PAPERS.length} paper grounds, ${PANEL_KEY_PREFIX.length} panel key prefixes renamed`,
);
