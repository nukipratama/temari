import { describe, expect, it } from 'vitest';

// Eager: false — we only need the module paths (keys), not the modules.
const allTsx = import.meta.glob('../**/*.tsx');
// Logic-bearing TS: hooks (all stateful) + lib utilities. Pure-data modules
// are allowlisted in TS_EXEMPT below.
const allHookTs = import.meta.glob('../hooks/**/*.ts');
const allLibTs = import.meta.glob('../lib/**/*.ts');
// Eager here: this one needs the file *contents*, not just the paths.
const allSource = import.meta.glob('../**/*.{ts,tsx}', {
    eager: true,
    query: '?raw',
    import: 'default',
}) as Record<string, string>;

/**
 * Components / pages intentionally without a co-located `{name}.test.tsx`.
 * This is the documented exception list to the 1:1 convention, not a backlog:
 * only the Inertia entry point, which isn't unit-testable in isolation.
 * A NEW `.tsx` not listed here must ship with a sibling test, or this fails.
 */
const EXEMPT = new Set<string>([
    'app.tsx', // Inertia entry point, not unit-testable in isolation
]);

/**
 * `.ts` modules under hooks/ and lib/ intentionally without a co-located
 * `{name}.test.ts`. The 1:1 convention covers *logic*; these are pure-data /
 * declarative-constant modules with no branches to exercise, so a test would
 * just restate the literal. Each entry is verified constants-only:
 *
 *   - lib/metricGlossary.ts — a frozen `as const` record of glossary copy
 *     (acronym/label/body strings). No functions, no branches.
 *   - lib/tones.ts          — a `Record<Tone, string>` of icon-tile class names.
 *     No functions, no branches.
 *   - lib/motion.ts         — declarative Framer Motion `Variants` / fidget
 *     keyframe constants. No functions, no branches.
 *
 * A NEW logic-bearing `.ts` not listed here must ship with a sibling test, or
 * this fails. Do NOT add a module here to dodge writing a test for real logic.
 */
const TS_EXEMPT = new Set<string>([
    'lib/metricGlossary.ts',
    'lib/tones.ts',
    'lib/motion.ts',
]);

function normalize(globKeys: string[]): string[] {
    return globKeys.map((p) => p.replace(/^\.\.\//, ''));
}

describe('component/page test coverage (1:1)', () => {
    it('every .tsx has a co-located {name}.test.tsx', () => {
        const paths = normalize(Object.keys(allTsx));
        const tests = new Set(paths.filter((p) => p.endsWith('.test.tsx')));
        const sources = paths.filter((p) => !p.endsWith('.test.tsx'));

        const missing = sources.filter((p) => {
            if (EXEMPT.has(p)) {
                return false;
            }
            return !tests.has(p.replace(/\.tsx$/, '.test.tsx'));
        });

        expect(
            missing,
            `These components/pages have no co-located *.test.tsx (add one, or exempt it in resources/js/test/structure.test.ts):\n  ${missing.join('\n  ')}`,
        ).toEqual([]);
    });

    it('every logic .ts in hooks/ and lib/ has a co-located {name}.test.ts', () => {
        const paths = normalize([
            ...Object.keys(allHookTs),
            ...Object.keys(allLibTs),
        ]);
        const tests = new Set(paths.filter((p) => p.endsWith('.test.ts')));
        const sources = paths.filter((p) => !p.endsWith('.test.ts'));

        const missing = sources.filter((p) => {
            if (TS_EXEMPT.has(p)) {
                return false;
            }
            return !tests.has(p.replace(/\.ts$/, '.test.ts'));
        });

        expect(
            missing,
            `These hooks/lib .ts modules have no co-located *.test.ts (add one, or allowlist a pure-data module in TS_EXEMPT in resources/js/test/structure.test.ts):\n  ${missing.join('\n  ')}`,
        ).toEqual([]);
    });
});

describe('icon keys', () => {
    /**
     * `Icon` renders nothing for a key its map does not carry — deliberate, so
     * a bad key can never throw in front of a user. The cost is that a typo is
     * invisible: it leaves a correctly-sized, correctly-coloured, empty button.
     * That happened once (`mdi:calendar`, which is `mdi:calendar-blank-outline`)
     * and was only caught by measuring the rendered SVG. This is the guard.
     */
    it('every mdi: key referenced in source exists in the Icon map', () => {
        const iconSource = Object.entries(allSource).find(([path]) =>
            path.endsWith('components/ui/Icon.tsx'),
        )?.[1];
        expect(iconSource).toBeDefined();
        const mapped = new Set(
            [...iconSource!.matchAll(/'(mdi:[a-z0-9-]+)':/g)].map((m) => m[1]),
        );
        expect(mapped.size).toBeGreaterThan(50);

        const unmapped: string[] = [];
        for (const [path, source] of Object.entries(allSource)) {
            // Test files may reference a deliberately unmapped key to assert
            // the empty-render behaviour itself.
            if (path.endsWith('Icon.tsx') || /\.test\.tsx?$/.test(path)) {
                continue;
            }
            // Both quote styles: a JSX attribute writes "mdi:x", a map or a
            // variable writes 'mdi:x'. Matching only one made this guard pass
            // against a key proven bad by hand.
            for (const match of source.matchAll(/['"](mdi:[a-z0-9-]+)['"]/g)) {
                if (!mapped.has(match[1])) {
                    unmapped.push(
                        `${match[1]} @ ${path.replace(/^\.\.\//, '')}`,
                    );
                }
            }
        }

        expect(unmapped).toEqual([]);
    });
});
