import { act, renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import {
    DISTANCE_OPTIONS,
    filterQuery,
    groupByWeek,
    hrefWithFilters,
    labelFor,
    summariseQuery,
    useJejakFilters,
    type FilterState,
    type RunWithDetail,
} from './useJejakFilters';
import type { ActivityDetail, Mood, WeeklySnapshotWithRecap } from '@/types/inertia';

function state(overrides: Partial<FilterState> = {}): FilterState {
    return {
        range: '8w',
        moods: new Set<Mood>(),
        distance: null,
        search: '',
        sort: 'newest',
        week: null,
        ...overrides,
    };
}

function run(id: number, name: string, isoDate: string | null): RunWithDetail {
    return {
        id,
        user_id: 1,
        analyzed_at: '2026-05-19',
        detail: {
            id,
            activity_id: id,
            name,
            start_date_local: isoDate,
            distance: 5000,
            moving_time: 1800,
            trimp_edwards: 50,
            average_heartrate: 145,
        } as ActivityDetail,
    };
}

function hookProps(overrides: Partial<Parameters<typeof useJejakFilters>[0]> = {}) {
    return {
        runs: [] as ReadonlyArray<RunWithDetail>,
        weeklySnapshots: [] as ReadonlyArray<WeeklySnapshotWithRecap>,
        rangeFilter: '8w' as const,
        moodFilter: [] as ReadonlyArray<Mood>,
        distanceFilter: null,
        searchFilter: null,
        sortMode: 'newest' as const,
        weekFilter: null,
        ...overrides,
    };
}

describe('filterQuery', () => {
    // Defaults are omitted so the common unfiltered view stays a clean /aktivitas.
    it('omits every default so the unfiltered view is a bare url', () => {
        expect(filterQuery(state())).toEqual({});
    });

    it('serialises moods in MOOD_ORDER so the same selection is always the same link', () => {
        expect(filterQuery(state({ moods: new Set<Mood>(['adem', 'nyala', 'oleng']) }))).toEqual({
            mood: 'nyala,oleng,adem',
        });
    });

    it('carries every non-default axis', () => {
        expect(
            filterQuery(state({ range: '1y', distance: '21up', search: 'tempo', sort: 'longest' })),
        ).toEqual({ range: '1y', dist: '21up', q: 'tempo', sort: 'longest' });
    });

    // A week scope pins its own window, so carrying `range` alongside it would
    // be noise in the URL.
    it('drops the range when a week scope pins its own window', () => {
        expect(filterQuery(state({ range: '1y', week: '2026-05-17' }))).toEqual({ week: '2026-05-17' });
    });
});

describe('hrefWithFilters', () => {
    it('stays a clean /aktivitas when nothing is filtered', () => {
        expect(hrefWithFilters(state())).toBe('/aktivitas');
    });

    it('builds a query string for an active filter', () => {
        expect(hrefWithFilters(state({ range: '1y', distance: '0-5' }))).toBe('/aktivitas?range=1y&dist=0-5');
    });
});

describe('labelFor', () => {
    it('resolves an option label', () => {
        expect(labelFor(DISTANCE_OPTIONS, '21up')).toBe('Half ke atas');
    });

    it('falls back to the raw value for an unknown option', () => {
        expect(labelFor(DISTANCE_OPTIONS, 'nope')).toBe('nope');
    });
});

describe('summariseQuery', () => {
    it('is null for an empty query, so nothing is offered', () => {
        expect(summariseQuery({})).toBeNull();
    });

    it('names each axis in the popover order', () => {
        expect(
            summariseQuery({ week: '2026-05-17', range: '1y', sort: 'longest', dist: '21up', mood: 'nyala,adem', q: 'tempo' }),
        ).toBe('satu minggu · Setahun penuh · Paling jauh · Half ke atas · Nyala, Adem · "tempo"');
    });

    it('ignores a mood value that is not a real mood', () => {
        expect(summariseQuery({ mood: 'bogus' })).toBeNull();
    });
});

describe('groupByWeek', () => {
    it('buckets runs by ISO week, Monday-start', () => {
        const buckets = groupByWeek([
            run(101, 'Selasa', '2026-05-19T06:00:00'),
            run(102, 'Minggu', '2026-05-24T06:00:00'),
            run(103, 'Minggu lalu', '2026-05-12T06:00:00'),
        ]);

        expect(buckets.map((b) => b.weekStart)).toEqual(['2026-05-18', '2026-05-11']);
        expect(buckets[0].weekEnding).toBe('2026-05-24');
        expect(buckets[0].runs.length).toBe(2);
        expect(buckets[0].totalKm).toBeCloseTo(10);
        expect(buckets[0].totalTrimp).toBe(100);
    });

    it('collects dateless runs into one bucket at the end', () => {
        const buckets = groupByWeek([
            run(101, 'Selasa', '2026-05-19T06:00:00'),
            run(999, 'Tanpa tanggal', null),
        ]);

        expect(buckets[buckets.length - 1]).toMatchObject({
            weekStart: 'orphans',
            weekEnding: 'orphans',
            label: 'Tanpa tanggal',
            totalKm: 5,
            totalTrimp: 50,
        });
    });

    it('skips a row with no detail rather than throwing', () => {
        const headless = { id: 1, user_id: 1, analyzed_at: null, detail: null } as unknown as RunWithDetail;

        expect(groupByWeek([headless])).toEqual([]);
    });

    it('treats a missing distance or TRIMP as zero', () => {
        const bare = run(101, 'Tanpa metrik', '2026-05-19T06:00:00');
        bare.detail.distance = null;
        bare.detail.trimp_edwards = null;

        const [bucket] = groupByWeek([bare]);
        expect(bucket.totalKm).toBe(0);
        expect(bucket.totalTrimp).toBe(0);
    });
});

describe('useJejakFilters', () => {
    const KEY = 'temari:riwayat:last-filter';

    afterEach(() => window.localStorage.clear());

    it('groups the runs and indexes the snapshots by their week', () => {
        const snapshot = { id: 1, week_ending: '2026-05-24T00:00:00Z' } as unknown as WeeklySnapshotWithRecap;
        const { result } = renderHook(() =>
            useJejakFilters(hookProps({ runs: [run(101, 'Pagi', '2026-05-19T06:00:00')], weeklySnapshots: [snapshot] })),
        );

        expect(result.current.buckets.length).toBe(1);
        expect(result.current.snapshotsByWeek.get('2026-05-24')).toBe(snapshot);
    });

    it('reports no active filter and the grouped mode by default', () => {
        const { result } = renderHook(() => useJejakFilters(hookProps()));

        expect(result.current.anyFilterActive).toBe(false);
        expect(result.current.ranked).toBe(false);
        expect(result.current.chips).toEqual([]);
    });

    // The range alone is not "a filter" — it is the default browse window.
    it.each([
        ['mood', { moodFilter: ['enteng' as Mood] }],
        ['distance', { distanceFilter: '21up' as const }],
        ['search', { searchFilter: 'tempo' }],
        ['week', { weekFilter: '2026-05-17' }],
    ])('counts a %s filter as active', (_axis, override) => {
        const { result } = renderHook(() => useJejakFilters(hookProps(override)));

        expect(result.current.anyFilterActive).toBe(true);
    });

    // Ranking globally is a mode switch, not a re-ordering.
    it('switches to the ranked mode for a non-default sort', () => {
        const { result } = renderHook(() => useJejakFilters(hookProps({ sortMode: 'longest' })));

        expect(result.current.ranked).toBe(true);
    });

    it('emits one chip per active filter, in popover order', () => {
        const { result } = renderHook(() =>
            useJejakFilters(
                hookProps({
                    weekFilter: '2026-05-17',
                    rangeFilter: '1y',
                    sortMode: 'longest',
                    distanceFilter: '21up',
                    moodFilter: ['adem', 'nyala'],
                    searchFilter: 'tempo',
                }),
            ),
        );

        expect(result.current.chips.map((c) => c.key)).toEqual([
            'week:2026-05-17',
            'range:1y',
            'sort:longest',
            'dist:21up',
            'mood:nyala',
            'mood:adem',
            'search',
        ]);
        expect(result.current.chips.map((c) => c.label)).toEqual([
            'Satu minggu',
            'Setahun penuh',
            'Paling jauh',
            'Half ke atas',
            'Nyala',
            'Adem',
            '"tempo"',
        ]);
    });

    // Every chip drops its own axis and leaves the others alone, so a narrowed
    // list can be widened one reason at a time.
    it.each([
        // Removing the week scope hands the range back its say in the URL.
        ['week:2026-05-17', { range: '1y', mood: 'nyala', dist: '21up', q: 'tempo', sort: 'longest' }],
        // A week scope pins its own window, so the range was never in the URL.
        ['range:1y', { week: '2026-05-17', mood: 'nyala', dist: '21up', q: 'tempo', sort: 'longest' }],
        ['sort:longest', { week: '2026-05-17', mood: 'nyala', dist: '21up', q: 'tempo' }],
        ['dist:21up', { week: '2026-05-17', mood: 'nyala', q: 'tempo', sort: 'longest' }],
        ['mood:nyala', { week: '2026-05-17', dist: '21up', q: 'tempo', sort: 'longest' }],
        ['search', { week: '2026-05-17', mood: 'nyala', dist: '21up', sort: 'longest' }],
    ])('drops the %s chip and keeps the rest of the filter', (key, expected) => {
        vi.mocked(router.get).mockReset();
        const { result } = renderHook(() =>
            useJejakFilters(
                hookProps({
                    weekFilter: '2026-05-17',
                    rangeFilter: '1y',
                    sortMode: 'longest',
                    distanceFilter: '21up',
                    moodFilter: ['nyala'],
                    searchFilter: 'tempo',
                }),
            ),
        );

        act(() => result.current.chips.find((c) => c.key === key)!.onRemove());

        expect(router.get).toHaveBeenCalledWith(
            '/aktivitas',
            expected,
            expect.objectContaining({ preserveScroll: true, preserveState: true }),
        );
    });

    it('resets every axis back to a bare /aktivitas', () => {
        vi.mocked(router.get).mockReset();
        const { result } = renderHook(() =>
            useJejakFilters(
                hookProps({ rangeFilter: '1y', moodFilter: ['nyala'], distanceFilter: '21up', searchFilter: 'tempo', sortMode: 'fastest', weekFilter: '2026-05-17' }),
            ),
        );

        act(() => result.current.resetFilters());

        expect(router.get).toHaveBeenCalledWith('/aktivitas', {}, expect.anything());
    });

    describe('sections', () => {
        it('builds a shareable href per range option, carrying the other filters', () => {
            const { result } = renderHook(() => useJejakFilters(hookProps({ moodFilter: ['nyala'] })));

            expect(result.current.sections.range.hrefFor('1y')).toBe('/aktivitas?range=1y&mood=nyala');
        });

        it('toggles a mood on and off through the url', () => {
            vi.mocked(router.get).mockReset();
            const { result, rerender } = renderHook((props: Parameters<typeof useJejakFilters>[0]) => useJejakFilters(props), {
                initialProps: hookProps(),
            });

            act(() => result.current.sections.mood.onToggle('enteng'));
            expect(router.get).toHaveBeenLastCalledWith('/aktivitas', { mood: 'enteng' }, expect.anything());

            rerender(hookProps({ moodFilter: ['enteng'] }));
            act(() => result.current.sections.mood.onToggle('enteng'));
            expect(router.get).toHaveBeenLastCalledWith('/aktivitas', {}, expect.anything());
        });

        // Tapping the active band clears it, so the popover needs no extra "any" row.
        it('clears the distance band when the active one is picked again', () => {
            vi.mocked(router.get).mockReset();
            const { result } = renderHook(() => useJejakFilters(hookProps({ distanceFilter: '21up' })));

            act(() => result.current.sections.distance.onSelect('21up'));
            expect(router.get).toHaveBeenLastCalledWith('/aktivitas', {}, expect.anything());

            act(() => result.current.sections.distance.onSelect('0-5'));
            expect(router.get).toHaveBeenLastCalledWith('/aktivitas', { dist: '0-5' }, expect.anything());
        });

        it('trims a search term so a stray space cannot make a different url', () => {
            vi.mocked(router.get).mockReset();
            const { result } = renderHook(() => useJejakFilters(hookProps()));

            act(() => result.current.sections.search.onSubmit('  tempo  '));

            expect(router.get).toHaveBeenCalledWith('/aktivitas', { q: 'tempo' }, expect.anything());
        });

        it('omits the default sort from the url', () => {
            vi.mocked(router.get).mockReset();
            const { result } = renderHook(() => useJejakFilters(hookProps({ sortMode: 'longest' })));

            act(() => result.current.sections.sort.onSelect('newest'));

            expect(router.get).toHaveBeenCalledWith('/aktivitas', {}, expect.anything());
        });
    });

    // Remembered, but never applied behind the user's back: landing on a
    // silently pre-filtered list reads as a history that lost runs.
    describe('resume offer', () => {
        it('offers nothing when nothing was ever saved', () => {
            const { result } = renderHook(() => useJejakFilters(hookProps()));

            expect(result.current.resume).toBeNull();
        });

        it('names what resuming would apply', () => {
            window.localStorage.setItem(KEY, JSON.stringify({ mood: 'nyala', dist: '21up' }));
            const { result } = renderHook(() => useJejakFilters(hookProps()));

            expect(result.current.resume?.summary).toBe('Half ke atas · Nyala');
        });

        it('offers nothing when the saved query summarises to nothing', () => {
            window.localStorage.setItem(KEY, JSON.stringify({ bogus: 'x' }));
            const { result } = renderHook(() => useJejakFilters(hookProps()));

            expect(result.current.resume).toBeNull();
        });

        it('applies the saved query only when asked', () => {
            vi.mocked(router.get).mockReset();
            window.localStorage.setItem(KEY, JSON.stringify({ mood: 'nyala' }));
            const { result } = renderHook(() => useJejakFilters(hookProps()));

            expect(router.get).not.toHaveBeenCalled();

            act(() => result.current.resume!.apply());
            expect(router.get).toHaveBeenCalledWith('/aktivitas', { mood: 'nyala' }, expect.anything());
        });

        it('forgets the saved query when dismissed, so it cannot nag', () => {
            window.localStorage.setItem(KEY, JSON.stringify({ mood: 'nyala' }));
            const { result } = renderHook(() => useJejakFilters(hookProps()));

            act(() => result.current.resume!.dismiss());

            expect(result.current.resume).toBeNull();
            expect(window.localStorage.getItem(KEY)).toBeNull();
        });

        it('stays hidden while a filter is already active', () => {
            window.localStorage.setItem(KEY, JSON.stringify({ mood: 'nyala' }));
            const { result } = renderHook(() => useJejakFilters(hookProps({ moodFilter: ['enteng'] })));

            expect(result.current.resume).toBeNull();
        });
    });
});
