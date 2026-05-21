import { Head, Link } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';
import { useMemo } from 'react';
import AppShell from '@/layouts/AppShell';
import DetailTeknisCollapsible, { type DetailStat } from '@/components/aktivitas/DetailTeknisCollapsible';
import JourneyStrip, { type JourneyMatchData } from '@/components/aktivitas/JourneyStrip';
import PageHero from '@/components/PageHero';
import RangeFilter, { type RangeFilterValue } from '@/components/aktivitas/RangeFilter';
import RingkasanCard from '@/components/aktivitas/RingkasanCard';
import RunListRow, { type RunNote } from '@/components/run/RunListRow';
import { cn } from '@/lib/cn';
import { formatIdDate } from '@/lib/pace';
import { fadeInUp } from '@/lib/motion';
import type { Activity, ActivityDetail, AnalysisPayload, FormStatus } from '@/types/inertia';

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

const FORM_CHIP_LABEL: Record<FormStatus, string> = {
    fresh: 'Segar',
    optimal: 'Pas',
    fatigued: 'Lelah',
    overreaching: 'Terlalu Diforsir',
};

const FORM_CHIP_CLASS: Record<FormStatus, string> = {
    fresh: 'bg-brand-100 text-brand-700',
    optimal: 'bg-mood-bouncy/15 text-mood-bouncy',
    fatigued: 'bg-mood-glow/20 text-pop-700',
    overreaching: 'bg-mood-cooked/15 text-mood-cooked',
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

    const hasRuns = runs.length > 0;

    return (
        <AppShell>
            <Head title="Aktivitas" />
            <motion.main
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="w-full px-4 py-6 sm:px-6 sm:py-10"
            >
                <PageHero
                    icon="mdi:run-fast"
                    title="Aktivitas"
                    subtitle="Aktivitas lari kamu, dirapikan per minggu. Klik satu untuk melihat detailnya."
                    className="mb-6"
                />

                <RangeFilter active={rangeFilter} className="mb-4" />

                <JourneyStrip match={journeyMatch} className="mb-6" />

                {hasRuns ? (
                    <>
                        <div className="space-y-8">
                            {buckets.map((bucket) => (
                                <WeekSection
                                    key={bucket.weekStart}
                                    bucket={bucket}
                                    snapshot={snapshotsByWeek.get(bucket.weekEnding) ?? null}
                                    notes={notes}
                                />
                            ))}
                        </div>
                    </>
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
}

function WeekSection({ bucket, snapshot, notes }: Readonly<WeekSectionProps>) {
    const trimpLabel = Math.round(bucket.totalTrimp);
    return (
        <section className="overflow-hidden rounded-2xl border border-line bg-surface-elev shadow-sm">
            <header className="flex flex-wrap items-baseline justify-between gap-3 border-b border-line bg-surface-warm/50 px-5 py-4">
                <div className="text-base font-semibold text-ink">{bucket.label}</div>
                <div className="flex flex-wrap items-center gap-2 text-xs tabular-nums">
                    <Stat icon="mdi:run" label={`${bucket.runs.length} run`} />
                    <Stat icon="mdi:map-marker-distance" label={`${bucket.totalKm.toFixed(1)} km`} />
                    <Stat icon="mdi:fire" label={`${trimpLabel} TRIMP`} />
                    {snapshot && <WeeklyStatusChips snapshot={snapshot} />}
                </div>
            </header>

            {snapshot && (
                <div className="space-y-3 border-b border-line bg-surface-warm/20 px-5 py-4">
                    <RingkasanCard
                        analysis={snapshot.recap_analysis}
                        fallback={ruleBasedFallback(snapshot)}
                    />
                    <DetailTeknisCollapsible
                        storageKey={snapshot.week_ending.slice(0, 10)}
                        stats={detailStatsFor(snapshot)}
                    />
                </div>
            )}

            <div>
                {bucket.runs.map((activity) => (
                    <RunListRow key={activity.id} detail={activity.detail} note={notes[activity.id] ?? null} />
                ))}
            </div>
        </section>
    );
}

function WeeklyStatusChips({ snapshot }: Readonly<{ snapshot: WeeklySnapshotRow }>) {
    return (
        <>
            {snapshot.ctl_42d !== null && (
                <span className="inline-flex items-center gap-1 rounded-full bg-brand-100 px-2.5 py-0.5 text-xs font-semibold text-brand-700">
                    Fit {snapshot.ctl_42d.toFixed(1)}
                </span>
            )}
            {snapshot.form !== null && (
                <span className="inline-flex items-center gap-1 rounded-full bg-accent-100 px-2.5 py-0.5 text-xs font-semibold text-accent-700">
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

function Stat({ icon, label }: Readonly<{ icon: string; label: string }>) {
    return (
        <span className="inline-flex items-center gap-1.5 rounded-full bg-surface-sunken px-3 py-1 text-ink">
            <Icon icon={icon} width={12} height={12} className="text-ink-meta" aria-hidden />
            <span className="font-semibold">{label}</span>
        </span>
    );
}

function EmptyState() {
    return (
        <div className="rounded-2xl border border-dashed border-line bg-surface-elev/40 p-10 text-center">
            <Icon icon="mdi:run-fast" width={28} height={28} className="mx-auto text-brand-500" aria-hidden />
            <p className="mt-3 text-base text-ink">Belum ada lari yang tercatat. Sinkronkan lari pertama kamu dari Strava dulu, ya.</p>
            <Link
                href="/"
                className="mt-3 inline-flex items-center gap-1 text-sm text-brand-700 hover:text-brand-800"
            >
                <Icon icon="mdi:arrow-left" width={14} height={14} aria-hidden />
                Kembali ke Beranda
            </Link>
        </div>
    );
}

function detailStatsFor(snap: WeeklySnapshotRow): DetailStat[] {
    return [
        { label: 'TRIMP', value: fmtInt(snap.weekly_trimp), explainerKey: 'trimp' },
        { label: 'CTL', value: fmtOne(snap.ctl_42d), explainerKey: 'ctl' },
        { label: 'ATL', value: fmtOne(snap.atl_7d), explainerKey: 'atl' },
        { label: 'Form', value: fmtOne(snap.form), explainerKey: 'form' },
        { label: 'Monotony', value: fmtTwo(snap.monotony), explainerKey: 'monotony' },
        { label: 'Strain', value: fmtInt(snap.strain), explainerKey: 'strain' },
        { label: 'Decoupling', value: snap.avg_decoupling !== null ? `${snap.avg_decoupling.toFixed(1)}%` : '—', explainerKey: 'decoupling' },
        { label: 'Volume', value: fmtKm(snap.distance_km) },
        { label: 'Run', value: snap.runs !== null ? `${snap.runs}` : '—' },
    ];
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

function fmtOne(n: number | null): string {
    return n === null ? '—' : n.toFixed(1);
}

function fmtTwo(n: number | null): string {
    return n === null ? '—' : n.toFixed(2);
}

function fmtKm(km: number | null): string {
    return km === null ? '—' : `${km.toFixed(1)} km`;
}

function fmtInt(n: number | null): string {
    return n === null ? '—' : Math.round(n).toString();
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

function mondayOf(iso: string): Date {
    const d = new Date(iso);
    d.setHours(0, 0, 0, 0);
    // getDay: 0=Sun..6=Sat. Shift so Monday=0..Sunday=6, subtract.
    const offset = (d.getDay() + 6) % 7;
    d.setDate(d.getDate() - offset);
    return d;
}

function isoDateLocal(d: Date): string {
    // toISOString() converts to UTC and rolls the date for non-UTC zones.
    // Compose YYYY-MM-DD from local fields so snapshot keys match.
    const y = d.getFullYear();
    const m = (d.getMonth() + 1).toString().padStart(2, '0');
    const day = d.getDate().toString().padStart(2, '0');
    return `${y}-${m}-${day}`;
}

function sundayOf(monday: Date): Date {
    const d = new Date(monday);
    d.setDate(d.getDate() + 6);
    return d;
}

function weekRangeLabel(monday: Date): string {
    const sunday = sundayOf(monday);
    const start = formatIdDate(monday.toISOString(), 'long');
    const end = formatIdDate(sunday.toISOString(), 'long');
    return `${start} — ${end}`;
}
