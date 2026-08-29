#!/usr/bin/env node
/**
 * F3 pass 3, step 0 — TypeScript (and any case-insensitive filesystem, macOS
 * included) refuses two files in the same directory differing only by case.
 * Card.tsx/card.tsx and Toggle.tsx/toggle.tsx are genuinely different
 * components now (see plan/slices/04-F3-mechanical-sweep.md's deviations:
 * Toggle.tsx is a role="switch" control, unrelated to shadcn's pressed-button
 * toggle.tsx; Card.tsx is a generic multi-tone/polymorphic-element container
 * still needed for tones and elements shadcn's card.tsx has no equivalent
 * for), so the old ones are renamed rather than deleted.
 *
 * Renamed files' own default-export identifier is updated for clarity in
 * React DevTools/stack traces; every consumer's import path is repointed
 * (their local `Card`/`Toggle` binding name is untouched — a default import
 * can bind to any name, so JSX call sites don't change).
 *
 * Usage: node plan/codemods/03-rename-legacy-primitives.mjs [--dry-run]
 */
import {
    readFileSync,
    readdirSync,
    renameSync,
    statSync,
    writeFileSync,
} from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '../..');
const dryRun = process.argv.includes('--dry-run');

const RENAMES = [
    {
        from: 'resources/js/components/ui/Card.tsx',
        to: 'resources/js/components/ui/LegacyCard.tsx',
        oldName: 'Card',
        newName: 'LegacyCard',
    },
    {
        from: 'resources/js/components/ui/Card.test.tsx',
        to: 'resources/js/components/ui/LegacyCard.test.tsx',
        oldName: null,
        newName: null,
    },
    {
        from: 'resources/js/components/ui/Toggle.tsx',
        to: 'resources/js/components/ui/Switch.tsx',
        oldName: 'Toggle',
        newName: 'Switch',
    },
    {
        from: 'resources/js/components/ui/Toggle.test.tsx',
        to: 'resources/js/components/ui/Switch.test.tsx',
        oldName: null,
        newName: null,
    },
];

// Consumers that only ever import the legacy component: the import path
// changes, but the local binding (`Card`, `Toggle`) is left alone.
const IMPORT_PATH_REWRITES = [
    ["from '@/components/ui/Card';", "from '@/components/ui/LegacyCard';"],
    ["from '@/components/ui/Toggle';", "from '@/components/ui/Switch';"],
    ["from './Card';", "from './LegacyCard';"],
    ["from './Toggle';", "from './Switch';"],
];

// Files that import BOTH the new shadcn primitive and the legacy one under an
// explicit `LegacyCard` alias — only the aliased import's path needs fixing.
const ALIASED_IMPORT_REWRITES = [
    [
        "import LegacyCard from '@/components/ui/Card';",
        "import LegacyCard from '@/components/ui/LegacyCard';",
    ],
];

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

for (const { from, to, oldName, newName } of RENAMES) {
    const fromAbs = path.join(root, from);
    const toAbs = path.join(root, to);
    console.log(
        `${dryRun ? '[dry-run] would rename' : 'renaming'} ${from} -> ${to}`,
    );
    if (dryRun) continue;

    let text = readFileSync(fromAbs, 'utf8');
    if (oldName && newName) {
        text = text.replaceAll(
            new RegExp(String.raw`\b${oldName}\b`, 'g'),
            newName,
        );
    }
    writeFileSync(fromAbs, text);
    renameSync(fromAbs, toAbs);
}

const files = walk(path.join(root, 'resources/js'));
let changed = 0;
for (const abs of files) {
    const original = readFileSync(abs, 'utf8');
    let text = original;
    // The aliased form runs first and consumes its exact substring, so the
    // bare rewrite below can no longer match it — no ordering guard needed.
    for (const [from, to] of ALIASED_IMPORT_REWRITES) {
        text = text.replaceAll(from, to);
    }
    for (const [from, to] of IMPORT_PATH_REWRITES) {
        text = text.replaceAll(from, to);
    }
    if (text !== original) {
        changed += 1;
        if (!dryRun) writeFileSync(abs, text);
    }
}

console.log(
    `${dryRun ? '[dry-run] would update' : 'updated'} ${changed} consumer files`,
);
