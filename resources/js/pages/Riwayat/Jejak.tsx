import { Head, router, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { memo, useCallback, useMemo, useState } from 'react';
import AppShell from '@/layouts/AppShell';
import JourneyStrip, { type JourneyMatchData } from '@/components/aktivitas/JourneyStrip';
import RingkasanCard from '@/components/aktivitas/RingkasanCard';
import RunListRow, { type RunNote } from '@/components/run/RunListRow';
import Card from '@/components/ui/Card';
import PageHero from '@/components/ui/PageHero';
import RiwayatFilter, { type MoodOption, type RangeOption } from '@/components/riwayat/RiwayatFilter';
import RiwayatTabs from '@/components/riwayat/RiwayatTabs';
import BackLink from '@/components/ui/BackLink';
import StravaSyncButton from '@/components/StravaSyncButton';
import Temari from '@/components/temari/Temari';
import { cn } from '@/lib/cn';
import { poseForFormStatus } from '@/lib/temariPose';
import { formStatusLabel } from '@/lib/formStatus';
import { MOOD_HINT, MOOD_LABEL, MOOD_FILL, MOOD_ORDER } from '@/lib/mood';
import { moodFromActivity } from '@/lib/moodFromActivity';
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
}

interface RunsIndexProps {
    runs: ReadonlyArray<Activity & { detail: ActivityDetail }>;
    notes?: Record<number, RunNote>;
    moods?: Record<number, Mood>;
    rangeFilter: RangeFilterValue;
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

const DEFAULT_RANGE: RangeFilterValue = '12w';
const RANGE_RELOAD_PROPS = ['runs', 'rangeFilter', 'rangeStart', 'rangeAutoWidened', 'runsTruncated', 'maxRuns', 'weeklySnapshots', 'notes', 'moods'];

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

    const [moodFilter, setMoodFilter] = useState<ReadonlySet<Mood>>(new Set());
    const toggleMood = useCallback((mood: Mood) => {
        setMoodFilter((prev) => {
            const next = new Set(prev);
            if (next.has(mood)) next.delete(mood);
            else next.add(mood);
            return next;
        });
    }, []);
    const resetFilters = useCallback(() => {
        setMoodFilter(new Set());
        if (rangeFilter !== DEFAULT_RANGE) {
            router.get('/aktivitas', { range: DEFAULT_RANGE }, {
                preserveScroll: true,
                preserveState: true,
                only: RANGE_RELOAD_PROPS,
            });
        }
    }, [rangeFilter]);
    const matchedRunIds = useMemo(() => {
        if (moodFilter.size === 0) return null;
        const ids = new Set<number>();
        for (const run of runs) {
            const mood = notes[run.id]?.mood ?? moods[run.id] ?? moodFromActivity(run.detail);
            if (moodFilter.has(mood)) ids.add(run.id);
        }
        return ids;
    }, [runs, notes, moods, moodFilter]);

    // Stable prop objects so toggling a mood doesn't hand RiwayatFilter a fresh
    // `range` literal (which never changes here) on every keystroke/toggle.
    const rangeSection = useMemo(
        () => ({
            value: rangeFilter,
            options: RANGE_FILTER_OPTIONS,
            hrefFor: (r: RangeFilterValue) => `/aktivitas?range=${r}`,
            only: RANGE_RELOAD_PROPS,
        }),
        [rangeFilter],
    );
    const moodSection = useMemo(
        () => ({
            selected: moodFilter,
            options: MOOD_FILTER_OPTIONS,
            onToggle: toggleMood,
        }),
        [moodFilter, toggleMood],
    );

    const hasRuns = runs.length > 0;

    return (
        <AppShell>
            <Head title="Riwayat · Jejak" />
            <PageContainer>
                <header className="flex flex-col gap-5">
                    <PageHero
                        eyebrow={`Riwayat · ${runs.length} aktivitas`}
                        lead="Setiap lari"
                        emph="ada ceritanya."
                        noItalic
                    />
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <RiwayatTabs active="jejak" />
                        <RiwayatFilter
                            range={rangeSection}
                            mood={moodSection}
                            onReset={resetFilters}
                        />
                    </div>
                </header>

                <JourneyStrip match={journeyMatch} className="mt-6 mb-6" />

                {hasRuns ? (
                    <div className="space-y-8">
                        {rangeAutoWidened && <RangeWidenedNote rangeFilter={rangeFilter} />}
                        {runsTruncated && <RunsTruncatedNote maxRuns={maxRuns} />}
                        {buckets.map((bucket) => (
                            <WeekSection
                                key={bucket.weekStart}
                                bucket={bucket}
                                snapshot={snapshotsByWeek.get(bucket.weekEnding) ?? null}
                                notes={notes}
                                moods={moods}
                                matchedRunIds={matchedRunIds}
                            />
                        ))}
                    </div>
                ) : (
                    <EmptyState />
                )}
            </PageContainer>
        </AppShell>
    );
}

interface WeekSectionProps {
    bucket: WeekBucket;
    snapshot: WeeklySnapshotRow | null;
    notes: Record<number, RunNote>;
    moods: Record<number, Mood>;
    /** When non-null, runs whose id is not in the set are dimmed. Null = no filter. */
    matchedRunIds: ReadonlySet<number> | null;
}

const WeekSection = memo(function WeekSection({ bucket, snapshot, notes, moods, matchedRunIds }: Readonly<WeekSectionProps>) {
    const trimpLabel = Math.round(bucket.totalTrimp);
    const matchCount = matchedRunIds
        ? bucket.runs.filter((r) => matchedRunIds.has(r.id)).length
        : bucket.runs.length;
    const wholeWeekDimmed = matchedRunIds !== null && matchCount === 0;

    return (
        <Card
            as="section"
            padding="none"
            className={cn(
                'overflow-hidden shadow-sm transition',
                wholeWeekDimmed && 'opacity-40',
            )}
        >
            <header className="flex flex-wrap items-baseline justify-between gap-3 border-b border-cream-deep bg-cream-deep/40 px-5 py-4">
                <div className="font-display text-lg italic text-ink">{bucket.label}</div>
                <div className="flex flex-wrap items-center gap-2 text-xs tabular-nums">
                    <Stat
                        icon="mdi:run"
                        label={
                            matchedRunIds
                                ? `${matchCount} / ${bucket.runs.length} run`
                                : `${bucket.runs.length} run`
                        }
                    />
                    <Stat icon="mdi:map-marker-distance" label={`${bucket.totalKm.toFixed(1)} km`} />
                    <Stat icon="mdi:fire" label={`${trimpLabel} TRIMP`} />
                    {snapshot && <WeeklyStatusChips snapshot={snapshot} />}
                </div>
            </header>

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
                        </div>
                    </div>
                </div>
            )}

            <div>
                {bucket.runs.map((activity) => {
                    const dimmed = matchedRunIds !== null && !matchedRunIds.has(activity.id);
                    return (
                        <div key={activity.id} className={cn('transition', dimmed && 'opacity-30')}>
                            <RunListRow detail={activity.detail} note={notes[activity.id] ?? null} mood={moods[activity.id] ?? null} />
                        </div>
                    );
                })}
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
                />
            )}
            {snapshot.avg_decoupling !== null && (
                <MetricChip
                    label="Drift"
                    value={`${snapshot.avg_decoupling.toFixed(1)}%`}
                    alert={decouplingAlert}
                />
            )}
            {snapshot.ctl_42d !== null && (
                <span className="inline-flex items-center gap-1 rounded-full bg-leaf/15 px-2.5 py-0.5 text-xs font-semibold text-leaf-deep">
                    Fit {snapshot.ctl_42d.toFixed(1)}
                </span>
            )}
            {snapshot.form !== null && (
                <span className="inline-flex items-center gap-1 rounded-full bg-horizon/15 px-2.5 py-0.5 text-xs font-semibold text-horizon-deep">
                    Form {snapshot.form >= 0 ? '+' : ''}
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
}: Readonly<{ label: string; value: string; alert?: boolean }>) {
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
    const label = RANGE_FILTER_OPTIONS.find((o) => o.value === rangeFilter)?.label ?? rangeFilter;
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

function ruleBasedFallback(snap: WeeklySnapshotRow): string {
    const parts: string[] = [];
    if (snap.runs !== null && snap.distance_km !== null) {
        parts.push(`Minggu ini kamu lari ${snap.runs}x sejauh ${snap.distance_km.toFixed(1)} km.`);
    }
    if (snap.form !== null && snap.form_status) {
        const formLabel = formStatusLabel(snap.form_status);
        parts.push(`Form ${snap.form >= 0 ? '+' : ''}${snap.form.toFixed(1)}, status ${formLabel.toLowerCase()}.`);
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

