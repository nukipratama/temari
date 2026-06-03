import { describe, expect, it } from 'vitest';

// Eager: false — we only need the module paths (keys), not the modules.
const allTsx = import.meta.glob('../**/*.tsx');

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
    'components/koleksi/MilestoneStrip.tsx',
    'components/koleksi/ProgressionChart.tsx',
    'components/riwayat/RiwayatTabs.tsx',
    'components/run/EmptyRunsState.tsx',
    'components/temari/UnavailableNote.tsx',
]);

describe('component/page test coverage (1:1)', () => {
    it('every .tsx has a co-located {name}.test.tsx', () => {
        const paths = Object.keys(allTsx).map((p) => p.replace(/^\.\.\//, ''));
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
});
