import { router } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import { type ActiveChip } from '@/components/riwayat/ActiveFilterChips';
import { type RangeOption } from '@/components/riwayat/RiwayatFilter';
import { useLastFilter } from '@/hooks/useLastFilter';
import { MOOD_FILTER_OPTIONS, MOOD_LABEL, MOOD_ORDER } from '@/lib/mood';
import { formatIdDate, isoDateLocal, mondayOf, sundayOf } from '@/lib/pace';
import type { Activity, ActivityDetail, Mood, WeeklySnapshotWithRecap } from '@/types/inertia';

export type RunWithDetail = Activity & { detail: ActivityDetail };

export interface WeekBucket {
    weekStart: string;
    /** ISO date string for the Sunday of this week — matches WeeklySnapshot.week_ending. */
    weekEnding: string;
    label: string;
    runs: RunWithDetail[];
    totalKm: number;
    totalTrimp: number;
}

export type RangeFilterValue = '8w' | '12w' | '6m' | '1y' | 'all';
export type DistanceBand = '0-5' | '5-10' | '10-21' | '21up';
export type SortMode = 'newest' | 'longest' | 'fastest';

/**
 * Sorting is a mode switch, not just an ordering: the weekly recap cards only
 * make sense in date order, so ranking globally drops the week grouping and
 * renders one flat list. `newest` is the grouped browse view.
 */
export const SORT_OPTIONS: ReadonlyArray<{ value: SortMode; label: string; hint: string }> = [
    { value: 'newest', label: 'Terbaru dulu', hint: 'per minggu' },
    { value: 'longest', label: 'Paling jauh', hint: 'peringkat' },
    { value: 'fastest', label: 'Paling ngebut', hint: 'peringkat' },
];

/** Cut at the distances runners think in, not at even numbers. */
export const DISTANCE_OPTIONS: ReadonlyArray<{ value: DistanceBand; label: string; hint: string }> = [
    { value: '0-5', label: 'Di bawah 5K', hint: '<5' },
    { value: '5-10', label: '5K sampai 10K', hint: '5-10' },
    { value: '10-21', label: '10K sampai half', hint: '10-21' },
    { value: '21up', label: 'Half ke atas', hint: '21+' },
];

/**
 * Must match RunController::resolveRange()'s fallback and the first entry of
 * RANGE_FILTER_OPTIONS (which RiwayatFilter treats as the implicit default).
 * When it drifts, every URL carries a redundant `range=` and the "clean
 * /aktivitas" case never happens.
 */
const DEFAULT_RANGE: RangeFilterValue = '8w';
const RANGE_RELOAD_PROPS = ['runs', 'rangeFilter', 'moodFilter', 'distanceFilter', 'searchFilter', 'sortMode', 'weekFilter', 'rangeStart', 'rangeAutoWidened', 'runsTruncated', 'maxRuns', 'weeklySnapshots', 'notes', 'moods'];

/** Every filter the page owns, in one shape so callers can change one field. */
export interface FilterState {
    range: RangeFilterValue;
    moods: ReadonlySet<Mood>;
    distance: DistanceBand | null;
    search: string;
    sort: SortMode;
    /** Week deep-link scope (that week's Sunday), or null for the full history. */
    week: string | null;
}

export const DEFAULT_SORT: SortMode = 'newest';

/**
 * The query object for a filter state. Defaults are omitted so the common
 * unfiltered view stays a clean `/aktivitas`, and moods are serialised in
 * MOOD_ORDER so the same selection always produces the same shareable link.
 */
export function filterQuery({ range, moods, distance, search, sort, week }: FilterState): Record<string, string> {
    const query: Record<string, string> = {};
    // A week scope pins its own window, so carrying `range` alongside it would
    // be noise in the URL.
    if (week !== null) query.week = week;
    else if (range !== DEFAULT_RANGE) query.range = range;
    if (moods.size > 0) query.mood = MOOD_ORDER.filter((m) => moods.has(m)).join(',');
    if (distance !== null) query.dist = distance;
    if (search !== '') query.q = search;
    if (sort !== DEFAULT_SORT) query.sort = sort;

    return query;
}

export function hrefWithFilters(state: FilterState): string {
    const query = new URLSearchParams(filterQuery(state)).toString();

    return query === '' ? '/aktivitas' : `/aktivitas?${query}`;
}

/** Looks up an option's label by value, falling back to the raw value itself. */
export function labelFor(options: ReadonlyArray<{ value: string; label: string }>, value: string): string {
    return options.find((o) => o.value === value)?.label ?? value;
}

export const RANGE_FILTER_OPTIONS: ReadonlyArray<RangeOption<RangeFilterValue>> = [
    { value: '8w', label: '2 bulan terakhir', hint: '8w' },
    { value: '12w', label: '3 bulan terakhir', hint: '12w' },
    { value: '6m', label: 'Setengah tahun', hint: '6m' },
    { value: '1y', label: 'Setahun penuh', hint: '1y' },
    { value: 'all', label: 'Semua lari', hint: 'all' },
];

/**
 * Human summary of a saved filter query, so the resume offer says what it would
 * apply rather than "your last filter". Order matches the popover's sections.
 */
export function summariseQuery(query: Record<string, string>): string | null {
    const parts: string[] = [];

    if (query.week) parts.push('satu minggu');
    if (query.range) {
        parts.push(labelFor(RANGE_FILTER_OPTIONS, query.range));
    }
    if (query.sort) {
        parts.push(labelFor(SORT_OPTIONS, query.sort));
    }
    if (query.dist) {
        parts.push(labelFor(DISTANCE_OPTIONS, query.dist));
    }
    if (query.mood) {
        const moods = query.mood.split(',').filter((m): m is Mood => MOOD_ORDER.includes(m as Mood));
        if (moods.length > 0) parts.push(moods.map((m) => MOOD_LABEL[m]).join(', '));
    }
    if (query.q) parts.push(`"${query.q}"`);

    return parts.length > 0 ? parts.join(' · ') : null;
}

function weekRangeLabel(monday: Date): string {
    const sunday = sundayOf(monday);
    const start = formatIdDate(monday.toISOString(), 'long');
    const end = formatIdDate(sunday.toISOString(), 'long');
    return `${start} - ${end}`;
}

/**
 * Bucket activities by ISO week (Monday-start). Activities without a
 * start_date_local fall into a single "Lainnya" bucket at the end.
 */
export function groupByWeek(rows: ReadonlyArray<RunWithDetail>): WeekBucket[] {
    const byKey = new Map<string, WeekBucket>();
    const ordered: string[] = [];
    const orphans: RunWithDetail[] = [];

    for (const row of rows) {
        if (!row.detail) continue;
        const iso = row.detail.start_date_local;
        if (iso === null) {
            orphans.push(row);
            continue;
        }
        const monday = mondayOf(iso);
        const key = isoDateLocal(monday);
        let bucket = byKey.get(key);
        if (!bucket) {
            bucket = {
                weekStart: key,
                weekEnding: isoDateLocal(sundayOf(monday)),
                label: weekRangeLabel(monday),
                runs: [],
                totalKm: 0,
                totalTrimp: 0,
            };
            byKey.set(key, bucket);
            ordered.push(key);
        }
        bucket.runs.push(row);
        if (row.detail.distance !== null) bucket.totalKm += row.detail.distance / 1000;
        if (row.detail.trimp_edwards !== null) bucket.totalTrimp += row.detail.trimp_edwards;
    }

    const buckets = ordered.map((k) => byKey.get(k)!);

    if (orphans.length > 0) {
        buckets.push({
            weekStart: 'orphans',
            weekEnding: 'orphans',
            label: 'Tanpa tanggal',
            runs: orphans,
            totalKm: orphans.reduce((acc, r) => acc + (r.detail.distance ?? 0) / 1000, 0),
            totalTrimp: orphans.reduce((acc, r) => acc + (r.detail.trimp_edwards ?? 0), 0),
        });
    }

    return buckets;
}

interface JejakFilterProps {
    runs: ReadonlyArray<RunWithDetail>;
    weeklySnapshots: ReadonlyArray<WeeklySnapshotWithRecap>;
    rangeFilter: RangeFilterValue;
    moodFilter: ReadonlyArray<Mood>;
    distanceFilter: DistanceBand | null;
    searchFilter: string | null;
    sortMode: SortMode;
    weekFilter: string | null;
}

interface ResumeOffer {
    summary: string;
    apply: () => void;
    dismiss: () => void;
}

export function useJejakFilters({
    runs,
    weeklySnapshots,
    rangeFilter,
    moodFilter,
    distanceFilter,
    searchFilter,
    sortMode,
    weekFilter,
}: JejakFilterProps) {
    const buckets = useMemo<WeekBucket[]>(() => groupByWeek(runs), [runs]);
    const snapshotsByWeek = useMemo(() => {
        const map = new Map<string, WeeklySnapshotWithRecap>();
        for (const snap of weeklySnapshots) map.set(snap.week_ending.slice(0, 10), snap);
        return map;
    }, [weeklySnapshots]);

    const selectedMoods = useMemo(() => new Set(moodFilter), [moodFilter]);
    const current = useMemo<FilterState>(
        () => ({
            range: rangeFilter,
            moods: selectedMoods,
            distance: distanceFilter,
            search: searchFilter ?? '',
            sort: sortMode,
            week: weekFilter,
        }),
        [rangeFilter, selectedMoods, distanceFilter, searchFilter, sortMode, weekFilter],
    );

    // The filters live in the URL and are applied by the server, so a change is a
    // partial reload rather than local state. That makes a filtered view
    // shareable and restorable, and — unlike the old client-side pass — it
    // filters the runs that were *fetched*, not just the ones already on screen
    // within the current range window.
    const visitWithFilters = useCallback(
        (patch: Partial<FilterState>) => {
            router.get('/aktivitas', filterQuery({ ...current, ...patch }), {
                preserveScroll: true,
                preserveState: true,
                only: RANGE_RELOAD_PROPS,
            });
        },
        [current],
    );

    const toggleMood = useCallback(
        (mood: Mood) => {
            const next = new Set(selectedMoods);
            if (next.has(mood)) next.delete(mood);
            else next.add(mood);
            visitWithFilters({ moods: next });
        },
        [selectedMoods, visitWithFilters],
    );

    const selectDistance = useCallback(
        // Tapping the active band clears it, so the popover needs no extra "any" row.
        (band: DistanceBand) => visitWithFilters({ distance: band === distanceFilter ? null : band }),
        [distanceFilter, visitWithFilters],
    );

    const submitSearch = useCallback(
        (term: string) => visitWithFilters({ search: term.trim() }),
        [visitWithFilters],
    );

    const selectSort = useCallback(
        (sort: SortMode) => visitWithFilters({ sort }),
        [visitWithFilters],
    );

    const resetFilters = useCallback(() => {
        visitWithFilters({
            range: DEFAULT_RANGE,
            moods: new Set(),
            distance: null,
            search: '',
            sort: DEFAULT_SORT,
            week: null,
        });
    }, [visitWithFilters]);

    // Stable prop objects so toggling a mood doesn't hand RiwayatFilter a fresh
    // `range` literal (which never changes here) on every keystroke/toggle.
    const rangeSection = useMemo(
        () => ({
            value: rangeFilter,
            options: RANGE_FILTER_OPTIONS,
            hrefFor: (r: RangeFilterValue) => hrefWithFilters({ ...current, range: r }),
            only: RANGE_RELOAD_PROPS,
        }),
        [rangeFilter, current],
    );
    const moodSection = useMemo(
        () => ({
            selected: selectedMoods,
            options: MOOD_FILTER_OPTIONS,
            onToggle: toggleMood,
        }),
        [selectedMoods, toggleMood],
    );
    const distanceSection = useMemo(
        () => ({
            value: distanceFilter,
            options: DISTANCE_OPTIONS,
            onSelect: selectDistance,
        }),
        [distanceFilter, selectDistance],
    );
    const searchSection = useMemo(
        () => ({ value: searchFilter ?? '', onSubmit: submitSearch }),
        [searchFilter, submitSearch],
    );
    const sortSection = useMemo(
        () => ({ value: sortMode, options: SORT_OPTIONS, onSelect: selectSort }),
        [sortMode, selectSort],
    );

    // One chip per active filter, so a narrowed list always says why it is
    // narrow and each reason can be dropped without reopening the panel.
    const chips = useMemo<ActiveChip[]>(() => {
        const list: ActiveChip[] = [];

        if (weekFilter !== null) {
            list.push({
                key: `week:${weekFilter}`,
                label: 'Satu minggu',
                onRemove: () => visitWithFilters({ week: null }),
            });
        }
        if (rangeFilter !== DEFAULT_RANGE) {
            const label = labelFor(RANGE_FILTER_OPTIONS, rangeFilter);
            list.push({ key: `range:${rangeFilter}`, label, onRemove: () => visitWithFilters({ range: DEFAULT_RANGE }) });
        }
        if (sortMode !== DEFAULT_SORT) {
            const label = labelFor(SORT_OPTIONS, sortMode);
            list.push({ key: `sort:${sortMode}`, label, onRemove: () => visitWithFilters({ sort: DEFAULT_SORT }) });
        }
        if (distanceFilter !== null) {
            const label = labelFor(DISTANCE_OPTIONS, distanceFilter);
            list.push({ key: `dist:${distanceFilter}`, label, onRemove: () => visitWithFilters({ distance: null }) });
        }
        for (const mood of MOOD_ORDER.filter((m) => selectedMoods.has(m))) {
            list.push({
                key: `mood:${mood}`,
                label: MOOD_LABEL[mood],
                onRemove: () => {
                    const next = new Set(selectedMoods);
                    next.delete(mood);
                    visitWithFilters({ moods: next });
                },
            });
        }
        if ((searchFilter ?? '') !== '') {
            list.push({
                key: 'search',
                label: `"${searchFilter}"`,
                onRemove: () => visitWithFilters({ search: '' }),
            });
        }

        return list;
    }, [weekFilter, rangeFilter, sortMode, distanceFilter, selectedMoods, searchFilter, visitWithFilters]);

    // Remembered, but never applied behind the user's back — see useLastFilter.
    const { resumable, forget } = useLastFilter(filterQuery(current));
    const resume = useMemo<ResumeOffer | null>(() => {
        if (resumable === null) return null;
        const summary = summariseQuery(resumable);
        if (summary === null) return null;

        return {
            summary,
            apply: () =>
                router.get('/aktivitas', resumable, {
                    preserveScroll: true,
                    preserveState: true,
                    only: RANGE_RELOAD_PROPS,
                }),
            dismiss: forget,
        };
    }, [resumable, forget]);

    const anyFilterActive =
        selectedMoods.size > 0 ||
        distanceFilter !== null ||
        (searchFilter ?? '') !== '' ||
        weekFilter !== null;
    // Ranking globally is incompatible with week buckets (a weekly recap card
    // only means anything in date order), so a non-default sort switches the
    // page to a flat list.
    const ranked = sortMode !== DEFAULT_SORT;

    return {
        buckets,
        snapshotsByWeek,
        sections: {
            range: rangeSection,
            mood: moodSection,
            distance: distanceSection,
            search: searchSection,
            sort: sortSection,
        },
        chips,
        resetFilters,
        resume,
        anyFilterActive,
        ranked,
    };
}
