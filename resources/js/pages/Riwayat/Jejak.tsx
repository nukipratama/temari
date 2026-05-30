import { Head, Link, router } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';
import { useCallback, useMemo, useState } from 'react';
import AppShell from '@/layouts/AppShell';
import JourneyStrip, { type JourneyMatchData } from '@/components/aktivitas/JourneyStrip';
import RingkasanCard from '@/components/aktivitas/RingkasanCard';
import RunListRow, { type RunNote } from '@/components/run/RunListRow';
import Card from '@/components/ui/Card';
import PageHero from '@/components/ui/PageHero';
import RiwayatFilter, { type MoodOption, type RangeOption } from '@/components/riwayat/RiwayatFilter';
import RiwayatTabs from '@/components/riwayat/RiwayatTabs';
import TemariMascot from '@/components/temari/TemariMascot';
import Temari from '@/components/temari/Temari';
import { type TemariPose } from '@/components/temari/TemariProto';
import { cn } from '@/lib/cn';
import { MOOD_FILL, MOOD_LABEL } from '@/lib/mood';
import { moodFromActivity } from '@/lib/moodFromActivity';
import { formatIdDate, isoDateLocal, mondayOf, sundayOf } from '@/lib/pace';
import { fadeInUp } from '@/lib/motion';
import type { Activity, ActivityDetail, AnalysisPayload, FormStatus, Mood } from '@/types/inertia';

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
    recap_analysis: AnalysisPayload;
}

interface RunsIndexProps {
    runs: ReadonlyArray<Activity & { detail: ActivityDetail }>;
    notes?: Record<number, RunNote>;
    rangeFilter: RangeFilterValue;
    rangeStart: string;
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

export type RangeFilterValue = '8w' | '12w' | '6m' | '1y';

const DEFAULT_RANGE: RangeFilterValue = '12w';
const RANGE_RELOAD_PROPS = ['runs', 'rangeFilter', 'rangeStart', 'weeklySnapshots', 'notes'];

const RANGE_FILTER_OPTIONS: ReadonlyArray<RangeOption<RangeFilterValue>> = [
    { value: '8w', label: '2 bulan terakhir', hint: '8w' },
    { value: '12w', label: '3 bulan terakhir', hint: '12w' },
    { value: '6m', label: 'Setengah tahun', hint: '6m' },
    { value: '1y', label: 'Setahun penuh', hint: '1y' },
];

const MOOD_HINT_BY_KEY: Record<Mood, string> = {
    nyala: 'PR / win',
    enteng: 'easy',
    oleng: 'HR drift',
    lemes: 'high strain',
    mumet: 'overreaching',
    adem: 'rest',
};

const MOOD_FILTER_OPTIONS: ReadonlyArray<MoodOption> = (
    ['nyala', 'enteng', 'oleng', 'lemes', 'mumet', 'adem'] as const
).map((mood) => ({
    mood,
    label: MOOD_LABEL[mood],
    hint: MOOD_HINT_BY_KEY[mood],
    swatchClass: MOOD_FILL[mood],
}));

const FORM_CHIP_LABEL: Record<FormStatus, string> = {
    fresh: 'Segar',
    optimal: 'Pas',
    fatigued: 'Lelah',
    overreaching: 'Terlalu Diforsir',
};

const FORM_CHIP_CLASS: Record<FormStatus, string> = {
    fresh: 'bg-leaf/15 text-leaf-deep',
    optimal: 'bg-mood-enteng/15 text-mood-enteng',
    fatigued: 'bg-mood-nyala/20 text-citrus-deep',
    overreaching: 'bg-mood-lemes/15 text-mood-lemes',
};

export default function RunsIndex({
    runs,
    notes = {},
    rangeFilter,
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
    const onRangeChange = useCallback((next: RangeFilterValue) => {
        if (next === rangeFilter) return;
        router.get('/aktivitas', { range: next }, {
            preserveScroll: true,
            preserveState: true,
            only: RANGE_RELOAD_PROPS,
        });
    }, [rangeFilter]);

    const matchedRunIds = useMemo(() => {
        if (moodFilter.size === 0) return null;
        const ids = new Set<number>();
        for (const run of runs) {
            const noteMood = notes[run.id]?.mood ?? null;
            const mood = noteMood ?? moodFromActivity(run.detail);
            if (moodFilter.has(mood)) ids.add(run.id);
        }
        return ids;
    }, [runs, notes, moodFilter]);

    const hasRuns = runs.length > 0;

    return (
        <AppShell>
            <Head title="Riwayat · Jejak" />
            <motion.main
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="w-full px-5 py-6 sm:px-8 lg:px-14 lg:py-8"
            >
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
                            range={{
                                value: rangeFilter,
                                options: RANGE_FILTER_OPTIONS,
                                onChange: onRangeChange,
                            }}
                            mood={{
                                selected: moodFilter,
                                options: MOOD_FILTER_OPTIONS,
                                onToggle: toggleMood,
                            }}
                            onReset={resetFilters}
                        />
                    </div>
                </header>

                <JourneyStrip match={journeyMatch} className="mt-6 mb-6" />

                {hasRuns ? (
                    <div className="space-y-8">
                        {buckets.map((bucket) => (
                            <WeekSection
                                key={bucket.weekStart}
                                bucket={bucket}
                                snapshot={snapshotsByWeek.get(bucket.weekEnding) ?? null}
                                notes={notes}
                                matchedRunIds={matchedRunIds}
                            />
                        ))}
                    </div>
                ) : (
                    <EmptyState />
                )}
            </motion.main>
        </AppShell>
    );
}

interface WeekSectionProps {
    bucket: WeekBucket;
    snapshot: WeeklySnapshotRow | null;
    notes: Record<number, RunNote>;
    /** When non-null, runs whose id is not in the set are dimmed. Null = no filter. */
    matchedRunIds: ReadonlySet<number> | null;
}

function WeekSection({ bucket, snapshot, notes, matchedRunIds }: Readonly<WeekSectionProps>) {
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
                            <RunListRow detail={activity.detail} note={notes[activity.id] ?? null} />
                        </div>
                    );
                })}
            </div>
        </Card>
    );
}

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
                    {FORM_CHIP_LABEL[snapshot.form_status]}
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
            <span className="font-mono text-[10px] uppercase tracking-wider text-ink-3">{label}</span>
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

function EmptyState() {
    return (
        <Card tone="empty" padding="lg" className="flex flex-col items-center text-center">
            <TemariMascot mood="enteng" sizeClass="h-32 w-32" idle="mood" />
            <p className="mt-4 font-display text-2xl italic text-ink-2">Aku lagi nungguin kamu lari 🏃‍♀️</p>
            <p className="mt-2 font-sans text-sm text-ink-2">Sync lari pertama kamu dari Strava dulu ya.</p>
            <Link
                href="/"
                className="mt-4 inline-flex items-center gap-1 font-mono text-xs uppercase tracking-[0.12em] text-horizon-deep hover:text-ember-deep"
            >
                <Icon icon="mdi:arrow-left" width={14} height={14} aria-hidden />
                Kembali ke Hari Ini
            </Link>
        </Card>
    );
}

function ruleBasedFallback(snap: WeeklySnapshotRow): string {
    const parts: string[] = [];
    if (snap.runs !== null && snap.distance_km !== null) {
        parts.push(`Minggu ini kamu lari ${snap.runs}x sejauh ${snap.distance_km.toFixed(1)} km.`);
    }
    if (snap.form !== null && snap.form_status) {
        const formLabel = FORM_CHIP_LABEL[snap.form_status];
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

function poseForFormStatus(status: FormStatus | null): TemariPose {
    switch (status) {
        case 'fresh':
            return 'proud';
        case 'optimal':
            return 'observational';
        case 'fatigued':
            return 'wobble';
        case 'overreaching':
            return 'reading';
        default:
            return 'observational';
    }
}
