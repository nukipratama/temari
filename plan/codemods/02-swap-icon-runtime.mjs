#!/usr/bin/env node
/**
 * F3 pass 2 — swap every consuming file's Icon import from @iconify/react to
 * the new resources/js/components/ui/Icon.tsx wrapper (hand-authored
 * alongside this script — see its own header comment for why the call-site
 * API, including the "mdi:" string keys, is preserved unchanged rather than
 * touching every call site's icon name).
 *
 * app.tsx (removes the addCollection/mdiBundle bootstrap) and Icon.tsx itself
 * are hand-edited, not touched by this pass.
 *
 * Usage: node plan/codemods/02-swap-icon-runtime.mjs [--dry-run]
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

const SKIP = new Set(
    [
        'resources/js/app.tsx',
        'resources/js/components/ui/Icon.tsx',
        'resources/js/test/setup.ts',
    ].map((p) => path.join(root, p)),
);

const FROM = "import { Icon } from '@iconify/react';";
const TO = "import { Icon } from '@/components/ui/Icon';";

const files = walk(path.join(root, 'resources/js')).filter((f) => !SKIP.has(f));

let changed = 0;
for (const abs of files) {
    const original = readFileSync(abs, 'utf8');
    if (!original.includes(FROM)) continue;
    const text = original.replaceAll(FROM, TO);
    changed += 1;
    if (!dryRun) writeFileSync(abs, text);
}

console.log(
    `${dryRun ? '[dry-run] would change' : 'changed'} ${changed} files`,
);
