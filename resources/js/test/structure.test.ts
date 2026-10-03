import { describe, expect, it } from 'vitest';

// Eager: false — we only need the module paths (keys), not the modules.
const allTsx = import.meta.glob('../**/*.tsx');
// Logic-bearing TS: hooks (all stateful) + lib utilities. Pure-data modules
// are allowlisted in TS_EXEMPT below.
const allHookTs = import.meta.glob('../hooks/**/*.ts');
const allLibTs = import.meta.glob('../lib/**/*.ts');
const componentSources = import.meta.glob<string>(
    ['../components/**/*.tsx', '!../components/**/*.test.tsx'],
    { query: '?raw', import: 'default', eager: true },
);
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
 *
 * A NEW logic-bearing `.ts` not listed here must ship with a sibling test, or
 * this fails. Do NOT add a module here to dodge writing a test for real logic.
 */
const TS_EXEMPT = new Set<string>(['lib/metricGlossary.ts', 'lib/tones.ts']);

function normalize(globKeys: string[]): string[] {
    return globKeys.map((p) => p.replace(/^\.\.\//, ''));
}

describe('component/page test coverage (1:1)', () => {
    it('every .tsx has a co-located {name}.test.tsx', () => {
        const paths = normalize(Object.keys(allTsx));
        const tests = new Set(paths.filter((p) => p.endsWith('.test.tsx')));
        // Examples are fixtures for the design catalogue; its smoke test renders every one.
        const sources = paths.filter(
            (p) => !p.endsWith('.test.tsx') && !p.endsWith('.examples.tsx'),
        );

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

/** Folders whose every component is in the design catalogue. */
const CATALOGUED_FOLDERS = ['components/ui/', 'components/temari/'];

/**
 * The rest of the catalogue's scope: the app-shell banners, and every component
 * outside ui/ and temari/ used on two or more screens from two or more call sites.
 */
const CATALOGUED_FILES = new Set([
    'components/AiCatchingUpBanner.tsx',
    'components/AiOutageBanner.tsx',
    'components/ErrorBanner.tsx',
    'components/FlashNotice.tsx',
    'components/StravaPausedBanner.tsx',
    'components/StravaZoneReconnectBanner.tsx',
    'components/DemoBlockedModal.tsx',
    'components/PushNotificationToggle.tsx',
    'components/StravaAction.tsx',
    'components/StravaSyncButton.tsx',
    'components/TemariMark.tsx',
    'components/UserAvatar.tsx',
    'components/history/EffortLegend.tsx',
    'components/history/HistoryHeader.tsx',
    'components/history/RecapCard.tsx',
    'components/history/WeeklyStatLine.tsx',
    'components/narration/DataTable.tsx',
    'components/narration/DevtoolsHeader.tsx',
    'components/narration/FlashBanner.tsx',
    'components/narration/LastOpen.tsx',
    'components/narration/SectionHeading.tsx',
    'components/onboarding/DayPicker.tsx',
    'components/onboarding/IconChoiceCard.tsx',
    'components/onboarding/SessionsDial.tsx',
    'components/plan/DayCell.tsx',
    'components/plan/DeltaPair.tsx',
    'components/profile/TimeInZoneBar.tsx',
]);

/**
 * In-scope files (`path`) and exports (`path#Name`) left out of the
 * catalogue, each with its reason. Drop an entry once the reason is gone.
 */
const NOT_CATALOGUED = new Map<string, string>([
    ['components/ui/PillLink.tsx', 'unused; its deletion is in flight'],
    ['components/ui/MiniRow.tsx', 'unused; its deletion is in flight'],
    ['components/ui/ReadMoreToggle.tsx', 'unused; its deletion is in flight'],
    ['components/ui/card.tsx#CardHeader', 'unused; its deletion is in flight'],
    ['components/ui/card.tsx#CardTitle', 'unused; its deletion is in flight'],
    [
        'components/ui/card.tsx#CardDescription',
        'unused; its deletion is in flight',
    ],
    ['components/ui/card.tsx#CardAction', 'unused; its deletion is in flight'],
    ['components/ui/card.tsx#CardContent', 'unused; its deletion is in flight'],
    ['components/ui/card.tsx#CardFooter', 'unused; its deletion is in flight'],
]);

const IMPORTS = /^import[\s\S]*?from\s+'[^']+';$/gm;

/** PascalCase runtime exports: components, plus primitives re-exported under a component name. */
function componentExports(source: string): string[] {
    const names = new Set<string>();
    for (const match of source.matchAll(
        /export\s+(?:default\s+)?(?:function|const)\s+([A-Z]\w*)/g,
    )) {
        names.add(match[1]);
    }
    for (const match of source.matchAll(/export\s*\{([^}]*)\}/g)) {
        for (const part of match[1].split(',')) {
            const name =
                part
                    .trim()
                    .split(/\s+as\s+/)
                    .pop() ?? '';
            if (/^[A-Z]/.test(name) && !part.trim().startsWith('type ')) {
                names.add(name);
            }
        }
    }

    return [...names].filter(
        (name) => name !== name.toUpperCase() && !name.endsWith('Context'),
    );
}

describe('design catalogue coverage', () => {
    const sources = Object.fromEntries(
        Object.entries(componentSources).map(([key, source]) => [
            key.replace(/^\.\.\//, ''),
            source,
        ]),
    );
    const inScope = Object.keys(sources)
        .filter((path) => !path.endsWith('.examples.tsx'))
        .filter(
            (path) =>
                CATALOGUED_FILES.has(path) ||
                CATALOGUED_FOLDERS.some(
                    (folder) =>
                        path.startsWith(folder) &&
                        !path.slice(folder.length).includes('/'),
                ),
        )
        .filter((path) => !NOT_CATALOGUED.has(path));
    const examplesOf = (path: string) =>
        sources[path.replace(/\.tsx$/, '.examples.tsx')];

    it('every in-scope component has a sibling *.examples.tsx', () => {
        const missing = inScope.filter(
            (path) => examplesOf(path) === undefined,
        );

        expect(
            missing,
            `These components are in the design catalogue's scope but have no sibling *.examples.tsx (add one, or list it in NOT_CATALOGUED in resources/js/test/structure.test.ts with a reason):\n  ${missing.join('\n  ')}`,
        ).toEqual([]);
    });

    it("every in-scope component's examples render each component it exports", () => {
        const unrendered = inScope.flatMap((path) => {
            const examples = examplesOf(path);
            if (examples === undefined) {
                return [];
            }
            const body = examples.replace(IMPORTS, '');

            return componentExports(sources[path])
                .filter((name) => !NOT_CATALOGUED.has(`${path}#${name}`))
                .filter((name) => !new RegExp(`\\b${name}\\b`).test(body))
                .map((name) => `${path}#${name}`);
        });

        expect(
            unrendered,
            `These exports have an examples file that never uses them (render them, or list them in NOT_CATALOGUED with a reason):\n  ${unrendered.join('\n  ')}`,
        ).toEqual([]);
    });

    it('lists no stale scope or exclusion', () => {
        const stale = [...CATALOGUED_FILES, ...NOT_CATALOGUED.keys()].filter(
            (entry) => sources[entry.split('#')[0]] === undefined,
        );

        expect(
            stale,
            `These catalogue scope entries point at files that no longer exist (remove them from resources/js/test/structure.test.ts):\n  ${stale.join('\n  ')}`,
        ).toEqual([]);
    });
});
