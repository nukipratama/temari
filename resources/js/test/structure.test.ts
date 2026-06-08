import { describe, expect, it } from 'vitest';

// Eager: false — we only need the module paths (keys), not the modules.
const allTsx = import.meta.glob('../**/*.tsx');
// Logic-bearing TS: hooks (all stateful) + lib utilities. Pure-data modules
// are allowlisted in TS_EXEMPT below.
const allHookTs = import.meta.glob('../hooks/**/*.ts');
const allLibTs = import.meta.glob('../lib/**/*.ts');

/**
 * Components / pages intentionally without a co-located `{name}.test.tsx`.
 * This is the documented exception list to the 1:1 convention, not a backlog:
 * the Inertia entry point plus small presentational components with no logic.
 * A NEW `.tsx` not listed here must ship with a sibling test, or this fails.
 */
const EXEMPT = new Set<string>([
    'app.tsx', // Inertia entry point, not unit-testable in isolation
    'components/DecorativeBlur.tsx',
    'components/MobileBottomNav.tsx',
    'components/MobileTopBar.tsx',
    'components/PersonaBar.tsx',
    'components/StravaSyncBadge.tsx',
    'components/UserAvatar.tsx',
    'components/card/PrCard.tsx',
    'components/koleksi/CollectionHeader.tsx',
    'components/koleksi/KoleksiTabs.tsx',
    'components/riwayat/RiwayatTabs.tsx',
    'components/run/EmptyRunsState.tsx',
    'components/temari/UnavailableNote.tsx',
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
