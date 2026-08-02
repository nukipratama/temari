import { describe, expect, it } from 'vitest';

import { mdiBundle } from './iconBundle';

// Raw source of every app .ts/.tsx (not the modules), so we can scan for the
// mdi: icon literals actually referenced and prove each is in the bundle.
const sources = import.meta.glob('../**/*.{ts,tsx}', {
    query: '?raw',
    import: 'default',
    eager: true,
}) as Record<string, string>;

describe('mdi icon bundle', () => {
    it('contains every mdi: icon referenced in the app', () => {
        const used = new Set<string>();
        for (const [path, content] of Object.entries(sources)) {
            if (/\.test\.tsx?$/.test(path) || path.endsWith('/iconBundle.ts')) {
                continue;
            }
            for (const match of content.matchAll(/mdi:[a-z0-9-]+/g)) {
                used.add(match[0].slice('mdi:'.length));
            }
        }

        const bundled = new Set([
            ...Object.keys(mdiBundle.icons),
            ...Object.keys(mdiBundle.aliases ?? {}),
        ]);
        const missing = [...used].filter((name) => !bundled.has(name));

        expect(
            missing,
            `mdi icons used in the app but missing from the bundle — run \`npm run icons:build\`:\n  ${missing.join('\n  ')}`,
        ).toEqual([]);
    });
});
