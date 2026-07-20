import { Head, router, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { memo, useCallback, useMemo } from 'react';
import { appLayout } from '@/layouts/appLayout';
import JourneyStrip, { type JourneyMatchData } from '@/components/aktivitas/JourneyStrip';
import RingkasanCard from '@/components/aktivitas/RingkasanCard';
import RunListRow, { type RunNote } from '@/components/run/RunListRow';
import Card from '@/components/ui/Card';
import PillButton from '@/components/ui/PillButton';
import SendNotificationButton from '@/components/SendNotificationButton';
import { useNotificationsReachable } from '@/hooks/useNotificationsReachable';
import { useLastFilter } from '@/hooks/useLastFilter';
import PageHero from '@/components/ui/PageHero';
import RiwayatFilter, { type MoodOption, type RangeOption } from '@/components/riwayat/RiwayatFilter';
import ActiveFilterChips, { type ActiveChip } from '@/components/riwayat/ActiveFilterChips';
import RiwayatTabs from '@/components/riwayat/RiwayatTabs';
import BackLink from '@/components/ui/BackLink';
import StravaSyncButton from '@/components/StravaSyncButton';
import Temari from '@/components/temari/Temari';
import MetricExplainer from '@/components/MetricExplainer';
import { type MetricKey } from '@/lib/metricGlossary';
import { cn } from '@/lib/cn';
import { poseForFormStatus } from '@/lib/temariPose';
import { formStatusLabel } from '@/lib/formStatus';
import { MOOD_HINT, MOOD_LABEL, MOOD_FILL, MOOD_ORDER } from '@/lib/mood';
import { formatIdDate, isoDateLocal, mondayOf, sundayOf } from '@/lib/pace';
import PageContainer from '@/components/ui/PageContainer';
import type { Activity, ActivityDetail, AnalysisPayload, FormStatus, Mood, SharedProps, StravaSyncState } from '@/types/inertia';

interface WeeklySnapshotRow {
    id: number;
    week_ending: string;
    distance_km: number | null;
    runs: number | null;
    weekly_trimp: number | null;
    atl_7d: number | null;
    ctl_42d: number | null;
    form: number | null;
    form_status: FormStatus | null;
    avg_decoupling: number | null;
    monotony: number | null;
    strain: number | null;
    /** True for the in-progress week, whose recap waits for the weekly scheduler. */
    is_current_week: boolean;
    /** True for the latest completed week, the only chain link that may regenerate. */
    is_chain_head: boolean;
    recap_analysis: AnalysisPayload;
    /** Remaining Telegram-send cooldown for this week's recap, or null. */
    notification_retry_after_seconds: number | null;
}

interface RunsIndexProps {
    runs: ReadonlyArray<Activity & { detail: ActivityDetail }>;
    notes?: Record<number, RunNote>;
    moods?: Record<number, Mood>;
    rangeFilter: RangeFilterValue;
    /** Moods the server filtered on. Empty = no mood filter. */
    moodFilter?: ReadonlyArray<Mood>;
    /** Distance band the server filtered on, or null for any distance. */
    distanceFilter?: DistanceBand | null;
    /** Free-text term the server matched against the run name, or null. */
    searchFilter?: string | null;
    /** Ordering the server applied. Anything but 'newest' renders a flat list. */
    sortMode?: SortMode;
    /** Week deep link (that week's Sunday, YYYY-MM-DD), or null. */
    weekFilter?: string | null;
    rangeStart: string | null;
    /** Server widened the requested range to reach an older run. */
    rangeAutoWidened?: boolean;
    /** Older runs beyond the per-page cap were dropped from this list. */
    runsTruncated?: boolean;
    /** The per-page cap, shown in the truncation note. */
    maxRuns?: number;
    weeklySnapshots: ReadonlyArray<WeeklySnapshotRow>;
    journeyMatch?: JourneyMatchData | null;
}

type RunWithDetail = Activity & { detail: ActivityDetail };

interface WeekBucket {
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
const SORT_OPTIONS: ReadonlyArray<{ value: SortMode; label: string; hint: string }> = [
    { value: 'newest', label: 'Terbaru dulu', hint: 'per minggu' },
    { value: 'longest', label: 'Paling jauh', hint: 'peringkat' },
    { value: 'fastest', label: 'Paling ngebut', hint: 'peringkat' },
];

/** Cut at the distances runners think in, not at even numbers. */
const DISTANCE_OPTIONS: ReadonlyArray<{ value: DistanceBand; label: string; hint: string }> = [
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
interface FilterState {
    range: RangeFilterValue;
    moods: ReadonlySet<Mood>;
    distance: DistanceBand | null;
    search: string;
    sort: SortMode;
    /** Week deep-link scope (that week's Sunday), or null for the full history. */
    week: string | null;
}

const DEFAULT_SORT: SortMode = 'newest';

/**
 * The query object for a filter state. Defaults are omitted so the common
 * unfiltered view stays a clean `/aktivitas`, and moods are serialised in
 * MOOD_ORDER so the same selection always produces the same shareable link.
 */
function filterQuery({ range, moods, distance, search, sort, week }: FilterState): Record<string, string> {
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

function hrefWithFilters(state: FilterState): string {
    const query = new URLSearchParams(filterQuery(state)).toString();

    return query === '' ? '/aktivitas' : `/aktivitas?${query}`;
}

/** Looks up an option's label by value, falling back to the raw value itself. */
function labelFor(options: ReadonlyArray<{ value: string; label: string }>, value: string): string {
    return options.find((o) => o.value === value)?.label ?? value;
}

const RANGE_FILTER_OPTIONS: ReadonlyArray<RangeOption<RangeFilterValue>> = [
    { value: '8w', label: '2 bulan terakhir', hint: '8w' },
    { value: '12w', label: '3 bulan terakhir', hint: '12w' },
    { value: '6m', label: 'Setengah tahun', hint: '6m' },
    { value: '1y', label: 'Setahun penuh', hint: '1y' },
    { value: 'all', label: 'Semua lari', hint: 'all' },
];

const MOOD_FILTER_OPTIONS: ReadonlyArray<MoodOption> = MOOD_ORDER.map((mood) => ({
    mood,
    label: MOOD_LABEL[mood],
    hint: MOOD_HINT[mood],
    swatchClass: MOOD_FILL[mood],
}));

const FORM_CHIP_CLASS: Record<FormStatus, string> = {
    fresh: 'bg-leaf/15 text-leaf-deep',
    optimal: 'bg-mood-enteng/15 text-mood-enteng',
    fatigued: 'bg-mood-nyala/20 text-citrus-deep',
    overreaching: 'bg-mood-lemes/15 text-mood-lemes',
};

export default function RunsIndex({
    runs,
    notes = {},
    moods = {},
    rangeFilter,
    moodFilter = [],
    distanceFilter = null,
    searchFilter = null,
    sortMode = DEFAULT_SORT,
    weekFilter = null,
    rangeAutoWidened = false,
    runsTruncated = false,
    maxRuns = 0,
    weeklySnapshots,
    journeyMatch = null,
}: Readonly<RunsIndexProps>) {
    const buckets = useMemo<WeekBucket[]>(() => groupByWeek(runs), [runs]);
    const snapshotsByWeek = useMemo(() => {
        const map = new Map<string, WeeklySnapshotRow>();
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
    const resumeSummary = useMemo(
        () => (resumable === null ? null : summariseQuery(resumable)),
        [resumable],
    );

    const hasRuns = runs.length > 0;
    const anyFilterActive =
        selectedMoods.size > 0 ||
        distanceFilter !== null ||
        (searchFilter ?? '') !== '' ||
        weekFilter !== null;
    // Ranking globally is incompatible with week buckets (a weekly recap card
    // only means anything in date order), so a non-default sort switches the
    // page to a flat list.
    const ranked = sortMode !== DEFAULT_SORT;

    return (
        <>
            <Head title="Riwayat · Jejak" />
            <PageContainer>
                <header className="flex flex-col gap-5">
                    <PageHero
                        eyebrow={
                            anyFilterActive
                                ? `Riwayat · ${runs.length} hasil`
                                : `Riwayat · ${runs.length} aktivitas`
                        }
                        lead="Setiap lari"
                        emph="ada ceritanya."
                        noItalic
                    />
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <RiwayatTabs active="jejak" />
                        <RiwayatFilter
                            range={rangeSection}
                            mood={moodSection}
                            distance={distanceSection}
                            search={searchSection}
                            sort={sortSection}
                            onReset={resetFilters}
                        />
                    </div>
                    <ActiveFilterChips chips={chips} onClearAll={resetFilters} />
                    {resumable !== null && resumeSummary !== null && (
                        <ResumeFilterChip
                            summary={resumeSummary}
                            onResume={() =>
                                router.get('/aktivitas', resumable, {
                                    preserveScroll: true,
                                    preserveState: true,
                                    only: RANGE_RELOAD_PROPS,
                                })
                            }
                            onDismiss={forget}
                        />
                    )}
                </header>

                <JourneyStrip match={journeyMatch} className="mt-6 mb-6" />

                {weekFilter !== null && <WeekFocusNote weekEnding={weekFilter} />}

                {hasRuns && (
                    <div className="space-y-8">
                        {rangeAutoWidened && <RangeWidenedNote rangeFilter={rangeFilter} />}
                        {runsTruncated && <RunsTruncatedNote maxRuns={maxRuns} />}
                        {ranked ? (
                            <RankedList runs={runs} notes={notes} moods={moods} sort={sortMode} />
                        ) : (
                            buckets.map((bucket) => (
                                <WeekSection
                                    key={bucket.weekStart}
                                    bucket={bucket}
                                    snapshot={snapshotsByWeek.get(bucket.weekEnding) ?? null}
                                    notes={notes}
                                    moods={moods}
                                    filtered={anyFilterActive}
                                />
                            ))
                        )}
                    </div>
                )}
                {/* A filtered view that matched nothing is a different story from
                    a genuinely empty history, so it gets its own state with a way
                    back rather than the "connect Strava" onboarding copy. */}
                {!hasRuns && anyFilterActive && <NoFilterMatchState onReset={resetFilters} />}
                {!hasRuns && !anyFilterActive && <EmptyState />}
            </PageContainer>
        </>
    );
}

/**
 * The ranked (non-chronological) view. Week cards and their recap narration are
 * deliberately absent: a weekly recap only means something in date order, so
 * ranking globally is a different mode rather than a re-ordering of this one.
 * The header says which ranking is active so the missing weeks aren't a mystery.
 */
function RankedList({
    runs,
    notes,
    moods,
    sort,
}: Readonly<{
    runs: ReadonlyArray<RunWithDetail>;
    notes: Record<number, RunNote>;
    moods: Record<number, Mood>;
    sort: SortMode;
}>) {
    const label = labelFor(SORT_OPTIONS, sort);

    return (
        <Card as="section" padding="none" className="overflow-hidden shadow-sm">
            <header className="flex flex-wrap items-baseline justify-between gap-3 border-b border-cream-deep bg-cream-deep/40 px-5 py-4">
                <div className="font-display text-lg italic text-ink">{label}</div>
                <div className="font-mono text-[11px] uppercase tracking-[0.12em] text-ink-3">
                    {runs.length} lari · diurutkan
                </div>
            </header>
            <div>
                {runs.map((activity) => (
                    <RunListRow
                        key={activity.id}
                        detail={activity.detail}
                        note={notes[activity.id] ?? null}
                        mood={moods[activity.id] ?? null}
                    />
                ))}
            </div>
        </Card>
    );
}

interface WeekSectionProps {
    bucket: WeekBucket;
    snapshot: WeeklySnapshotRow | null;
    notes: Record<number, RunNote>;
    moods: Record<number, Mood>;
    /** A filter narrowed this week's runs, so its totals describe a subset. */
    filtered: boolean;
}

const WeekSection = memo(function WeekSection({ bucket, snapshot, notes, moods, filtered }: Readonly<WeekSectionProps>) {
    const notificationsReachable = useNotificationsReachable();

    // The date-range filter can truncate a week's runs list without truncating
    // the week itself, e.g. the range boundary lands mid-week. bucket.* only
    // sums the runs actually in view, so it can undercount vs. the pre-aggregated
    // WeeklySnapshot the recap text below quotes — prefer the snapshot's totals
    // whenever one exists so the header always agrees with the narration.
    // Except: (a) the in-progress week, since WeeklyAggregator recomputes the
    // snapshot from a queued listener (DispatchPostRunAnalysis), so right after a
    // fresh sync bucket can be more current than a snapshot the worker hasn't
    // caught up to yet; and (b) a filtered view, where the snapshot describes the
    // whole week but only a subset is on screen.
    const useSnapshotTotals = snapshot !== null && !filtered && !snapshot.is_current_week;
    const runCount = useSnapshotTotals && snapshot.runs !== null ? snapshot.runs : bucket.runs.length;
    const totalKm = useSnapshotTotals && snapshot.distance_km !== null ? snapshot.distance_km : bucket.totalKm;
    const trimpLabel = Math.round(
        useSnapshotTotals && snapshot.weekly_trimp !== null ? snapshot.weekly_trimp : bucket.totalTrimp,
    );

    // Filtering removes non-matching runs outright, so a week silently loses the
    // context the old dimmed-row treatment used to convey. The WeeklySnapshot
    // already carries the week's true total, computed independently of any
    // filter, so the gap can be named without a second query. Only shown when
    // the snapshot is trustworthy for a count: the in-progress week's is still
    // being recomputed by a queued worker, so it can lag the live bucket.
    const weekTotal = snapshot !== null && !snapshot.is_current_week ? snapshot.runs : null;
    const hiddenCount = filtered && weekTotal !== null ? Math.max(0, weekTotal - bucket.runs.length) : 0;

    return (
        <Card as="section" padding="none" className="overflow-hidden shadow-sm transition">
            <header className="flex flex-wrap items-baseline justify-between gap-3 border-b border-cream-deep bg-cream-deep/40 px-5 py-4">
                <div className="font-display text-lg italic text-ink">{bucket.label}</div>
                <div className="flex flex-wrap items-center gap-2 text-xs tabular-nums">
                    <Stat
                        icon="mdi:run"
                        label={hiddenCount > 0 ? `${bucket.runs.length} dari ${weekTotal} run` : `${runCount} run`}
                    />
                    <Stat icon="mdi:map-marker-distance" label={`${totalKm.toFixed(1)} km`} />
                    <Stat icon="mdi:fire" label={`${trimpLabel} TRIMP`} />
                    {snapshot && <WeeklyStatusChips snapshot={snapshot} />}
                </div>
            </header>

            {hiddenCount > 0 && (
                <p className="flex items-center gap-2 border-b border-cream-deep bg-cream-deep/10 px-5 py-2.5 font-sans text-[12px] text-ink-3">
                    <Icon icon="mdi:eye-off-outline" width={14} height={14} className="shrink-0" aria-hidden />
                    {hiddenCount} lari lain di minggu ini gak cocok sama filternya.
                </p>
            )}

            {snapshot && (
                <div className="border-b border-cream-deep bg-cream-deep/20 px-5 py-4">
                    <div className="flex items-start gap-3.5">
                        <Temari
                            pose={poseForFormStatus(snapshot.form_status)}
                            size={48}
                            animate={false}
                        />
                        <div className="min-w-0 flex-1">
                            <RingkasanCard
                                analysis={snapshot.recap_analysis}
                                fallback={ruleBasedFallback(snapshot)}
                                awaitingSchedule={snapshot.is_current_week}
                                isChainHead={snapshot.is_chain_head}
                            />
                            {snapshot.recap_analysis.status === 'done' && (
                                <div className="mt-3">
                                    <SendNotificationButton
                                        url={`/rekap-mingguan/${snapshot.id}/kirim`}
                                        retryAfterSeconds={snapshot.notification_retry_after_seconds}
                                        reachable={notificationsReachable}
                                    />
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}

            <div>
                {bucket.runs.map((activity) => (
                    <RunListRow
                        key={activity.id}
                        detail={activity.detail}
                        note={notes[activity.id] ?? null}
                        mood={moods[activity.id] ?? null}
                    />
                ))}
            </div>
        </Card>
    );
});

function WeeklyStatusChips({ snapshot }: Readonly<{ snapshot: WeeklySnapshotRow }>) {
    // Monotony ≥ 1.5 and decoupling ≥ 8% are the runner-relevant alarm thresholds.
    // Below those, render the chip in the neutral cream tone so the row doesn't
    // light up with semantic color when nothing is wrong.
    const monotonyAlert = snapshot.monotony !== null && snapshot.monotony >= 1.5;
    const decouplingAlert = snapshot.avg_decoupling !== null && snapshot.avg_decoupling >= 8;
    return (
        <>
            {snapshot.atl_7d !== null && (
                <MetricChip label="Lelah" value={snapshot.atl_7d.toFixed(1)} />
            )}
            {snapshot.monotony !== null && (
                <MetricChip
                    label="Variasi"
                    value={snapshot.monotony.toFixed(2)}
                    alert={monotonyAlert}
                    explainerKey="monotony"
                />
            )}
            {snapshot.avg_decoupling !== null && (
                <MetricChip
                    label="Drift"
                    value={`${snapshot.avg_decoupling.toFixed(1)}%`}
                    alert={decouplingAlert}
                    explainerKey="decoupling"
                />
            )}
            {snapshot.ctl_42d !== null && (
                <span className="inline-flex items-center gap-1 rounded-full bg-leaf/15 px-2.5 py-0.5 text-xs font-semibold text-leaf-deep">
                    Fondasi {snapshot.ctl_42d.toFixed(1)}
                </span>
            )}
            {snapshot.form !== null && (
                <span className="inline-flex items-center gap-1 rounded-full bg-horizon/15 px-2.5 py-0.5 text-xs font-semibold text-horizon-deep">
                    Kesiapan {snapshot.form >= 0 ? '+' : ''}
                    {snapshot.form.toFixed(1)}
                </span>
            )}
            {snapshot.form_status && (
                <span
                    className={cn(
                        'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                        FORM_CHIP_CLASS[snapshot.form_status],
                    )}
                >
                    {formStatusLabel(snapshot.form_status)}
                </span>
            )}
        </>
    );
}

function MetricChip({
    label,
    value,
    alert = false,
    explainerKey,
}: Readonly<{ label: string; value: string; alert?: boolean; explainerKey?: MetricKey }>) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                alert
                    ? 'bg-mood-lemes/15 text-mood-lemes'
                    : 'bg-cream-deep/60 text-ink-2',
            )}
        >
            <span className="font-mono font-bold text-[11px] uppercase tracking-wider text-ink-2">{label}</span>
            <span className="tabular-nums">{value}</span>
            {explainerKey && <MetricExplainer metricKey={explainerKey} size="xs" />}
        </span>
    );
}

function Stat({ icon, label }: Readonly<{ icon: string; label: string }>) {
    return (
        <span className="inline-flex items-center gap-1.5 rounded-full bg-cream-deep/60 px-3 py-1 text-ink">
            <Icon icon={icon} width={12} height={12} className="text-ink-3" aria-hidden />
            <span className="font-semibold">{label}</span>
        </span>
    );
}

const EMPTY_COPY: Record<StravaSyncState, { line: string; sub: string }> = {
    disconnected: {
        line: 'Sambungin Strava dulu ya',
        sub: 'Aku baca lari kamu dari Strava. Sambungin biar riwayatnya keisi.',
    },
    revoked: {
        line: 'Sambungan Strava putus',
        sub: 'Token kamu udah gak aktif. Sambungin lagi biar lari baru kebaca.',
    },
    syncing: {
        line: 'Aku lagi narik lari kamu 🏃‍♀️',
        sub: 'Sebentar ya, riwayatnya muncul begitu lari pertama selesai diproses.',
    },
    ready: {
        line: 'Belum ada lari yang bisa ditampilkan',
        sub: 'Lari baru muncul di sini begitu selesai diproses. Coba sync lagi kalau baru kelar lari.',
    },
};

/**
 * Banner shown above the run list when the server widened the requested window
 * so an older run could surface. Keeps the active range chip honest.
 */
function RunsTruncatedNote({ maxRuns }: Readonly<{ maxRuns: number }>) {
    return (
        <Card tone="cream-deep" padding="sm" className="flex items-center gap-2.5">
            <Icon icon="mdi:history" width={16} height={16} className="shrink-0 text-ink-3" aria-hidden />
            <p className="font-sans text-sm text-ink-2">
                Menampilkan {maxRuns} lari terbaru. Lari yang lebih lama belum dimuat.
            </p>
        </Card>
    );
}

function RangeWidenedNote({ rangeFilter }: Readonly<{ rangeFilter: RangeFilterValue }>) {
    const label = labelFor(RANGE_FILTER_OPTIONS, rangeFilter);
    const message =
        rangeFilter === 'all'
            ? 'Menampilkan semua lari kamu, biar lari terakhir tetap kelihatan.'
            : `Rentang diperlebar otomatis ke ${label} biar lari terakhirmu kelihatan.`;
    return (
        <Card tone="cream-deep" padding="sm" className="flex items-center gap-2.5">
            <Icon icon="mdi:arrow-expand-horizontal" width={16} height={16} className="shrink-0 text-ink-3" aria-hidden />
            <p className="font-sans text-sm text-ink-2">{message}</p>
        </Card>
    );
}

/**
 * Human summary of a saved filter query, so the resume offer says what it would
 * apply rather than "your last filter". Order matches the popover's sections.
 */
function summariseQuery(query: Record<string, string>): string | null {
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

/**
 * One-tap offer to pick up the filter the user last used. Deliberately an offer
 * rather than an auto-apply: landing on a silently pre-filtered list reads as a
 * history that lost runs. Dismissing forgets it, so the row can't nag.
 */
function ResumeFilterChip({
    summary,
    onResume,
    onDismiss,
}: Readonly<{ summary: string; onResume: () => void; onDismiss: () => void }>) {
    return (
        <div className="flex flex-wrap items-center gap-2">
            <button
                type="button"
                onClick={onResume}
                className="pressable focus-ring inline-flex items-center gap-1.5 rounded-full border border-line/60 bg-surface-warm py-1 pl-3 pr-3.5 text-xs font-medium text-ink-2"
            >
                <Icon icon="mdi:history" width={13} height={13} aria-hidden />
                Lanjutkan: {summary}
            </button>
            <button
                type="button"
                onClick={onDismiss}
                aria-label="Lupakan filter terakhir"
                className="focus-ring rounded px-1 text-xs font-medium text-ink-3 hover:text-ink-2"
            >
                <Icon icon="mdi:close" width={13} height={13} aria-hidden />
            </button>
        </div>
    );
}

/**
 * Shown when the page is scoped to one week, which only happens via a deep link
 * (the weekly-recap notification). Without it the view would look like a history
 * that mysteriously lost most of its runs, so it names the week and offers the
 * way back to the full list.
 */
function WeekFocusNote({ weekEnding }: Readonly<{ weekEnding: string }>) {
    const sunday = new Date(`${weekEnding}T00:00:00`);
    const monday = new Date(sunday);
    monday.setDate(monday.getDate() - 6);

    return (
        <Card tone="cream-deep" padding="sm" className="mb-6 flex flex-wrap items-center gap-2.5">
            <Icon icon="mdi:calendar-week" width={16} height={16} className="shrink-0 text-ink-3" aria-hidden />
            <p className="font-sans text-sm text-ink-2">
                Lagi lihat minggu {formatIdDate(monday.toISOString())} - {formatIdDate(sunday.toISOString())}.
            </p>
            <BackLink href="/aktivitas" tone="accent">
                Lihat semua lari
            </BackLink>
        </Card>
    );
}

function EmptyState() {
    const { stravaSync } = usePage<SharedProps>().props;
    const state: StravaSyncState = stravaSync?.state ?? 'disconnected';
    const { line, sub } = EMPTY_COPY[state];

    // The page auto-widens to show any run the user has, so reaching the empty
    // state means there is genuinely nothing to show yet. The copy explains why
    // per connection state; the sync button is hidden while a sync is already
    // running (nothing for the user to do but wait).
    return (
        <Card tone="empty" padding="lg" className="flex flex-col items-center text-center">
            <Temari pose="excited" size={128} animate />
            <p className="mt-4 font-display text-2xl italic text-ink-2">{line}</p>
            <p className="mt-2 font-sans text-sm text-ink-2">{sub}</p>
            {state !== 'syncing' && <StravaSyncButton state={state} className="mt-4" />}
            <BackLink href="/" tone="accent" className="mt-4">
                Kembali ke Hari Ini
            </BackLink>
        </Card>
    );
}

/**
 * Shown when a filter matched nothing. Distinct from {@see EmptyState}: the user
 * has runs, they just narrowed past them, so the copy says so and the only
 * action offered is a way back out instead of Strava onboarding.
 */
function NoFilterMatchState({ onReset }: Readonly<{ onReset: () => void }>) {
    return (
        <Card tone="empty" padding="lg" className="flex flex-col items-center text-center">
            <Temari pose="observational" size={112} animate={false} />
            <p className="mt-4 font-display text-2xl italic text-ink-2">Gak ada lari yang cocok.</p>
            <p className="mt-2 font-sans text-sm text-ink-2">
                Filternya kesempitan nih. Coba longgarin dikit biar keliatan lagi.
            </p>
            <PillButton tone="outline" onClick={onReset} className="mt-4">
                <Icon icon="mdi:filter-remove-outline" width={15} height={15} aria-hidden />
                Reset filter
            </PillButton>
        </Card>
    );
}

function ruleBasedFallback(snap: WeeklySnapshotRow): string {
    const parts: string[] = [];
    if (snap.runs !== null && snap.distance_km !== null) {
        parts.push(`Minggu ini kamu lari ${snap.runs}x sejauh ${snap.distance_km.toFixed(1)} km.`);
    }
    if (snap.form !== null && snap.form_status) {
        const formLabel = formStatusLabel(snap.form_status);
        parts.push(`Kesiapan ${snap.form >= 0 ? '+' : ''}${snap.form.toFixed(1)}, status ${formLabel.toLowerCase()}.`);
    }
    return parts.join(' ') || 'Belum ada data minggu ini, sabar ya.';
}

/**
 * Bucket activities by ISO week (Monday-start). Activities without a
 * start_date_local fall into a single "Lainnya" bucket at the end.
 */
function groupByWeek(rows: ReadonlyArray<RunWithDetail>): WeekBucket[] {
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

function weekRangeLabel(monday: Date): string {
    const sunday = sundayOf(monday);
    const start = formatIdDate(monday.toISOString(), 'long');
    const end = formatIdDate(sunday.toISOString(), 'long');
    return `${start} - ${end}`;
}

RunsIndex.layout = appLayout;
