import { Head, Link } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';
import { useMemo } from 'react';
import AppShell from '@/layouts/AppShell';
import PageHero from '@/components/PageHero';
import Paginator from '@/components/Paginator';
import RunListRow, { type RunNote } from '@/components/run/RunListRow';
import { formatIdDate } from '@/lib/pace';
import { fadeInUp } from '@/lib/motion';
import type { Activity, ActivityDetail, PaginatedResponse } from '@/types/inertia';

interface RunsIndexProps {
    runs: PaginatedResponse<Activity & { detail: ActivityDetail }>;
    notes?: Record<number, RunNote>;
}

type RunWithDetail = Activity & { detail: ActivityDetail };

interface WeekBucket {
    /** ISO Monday for the start of the week. */
    weekStart: string;
    /** Display label, e.g. "11 — 17 Mei 2026". */
    label: string;
    runs: RunWithDetail[];
    totalKm: number;
    totalTrimp: number;
}

export default function RunsIndex({ runs, notes = {} }: Readonly<RunsIndexProps>) {
    const buckets = useMemo<WeekBucket[]>(() => groupByWeek(runs.data), [runs.data]);
    const hasRuns = runs.data.length > 0;

    return (
        <AppShell>
            <Head title="Aktivitas" />
            <motion.main
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="w-full px-6 py-10"
            >
                <PageHero
                    icon="mdi:run-fast"
                    title="Aktivitas"
                    subtitle="Lari kamu dirapikan per minggu — klik satu buat lihat detail."
                    className="mb-6"
                />

                {hasRuns ? (
                    <>
                        <div className="space-y-8">
                            {buckets.map((bucket) => (
                                <WeekSection key={bucket.weekStart} bucket={bucket} notes={notes} />
                            ))}
                        </div>

                        {runs.last_page > 1 && <Paginator links={runs.links} />}
                    </>
                ) : (
                    <EmptyState />
                )}
            </motion.main>
        </AppShell>
    );
}

function WeekSection({ bucket, notes }: Readonly<{ bucket: WeekBucket; notes: Record<number, RunNote> }>) {
    const trimpLabel = Math.round(bucket.totalTrimp);
    return (
        <section className="overflow-hidden rounded-2xl border border-line bg-surface-elev shadow-sm">
            <header className="flex flex-wrap items-baseline justify-between gap-3 border-b border-line bg-surface-warm/50 px-5 py-4">
                <div className="text-base font-semibold text-ink">{bucket.label}</div>
                <div className="flex flex-wrap items-center gap-2 text-xs tabular-nums">
                    <Stat icon="mdi:run" label={`${bucket.runs.length} run`} />
                    <Stat icon="mdi:map-marker-distance" label={`${bucket.totalKm.toFixed(1)} km`} />
                    <Stat icon="mdi:fire" label={`${trimpLabel} TRIMP`} />
                </div>
            </header>
            <div>
                {bucket.runs.map((activity) => (
                    <RunListRow key={activity.id} detail={activity.detail} note={notes[activity.id] ?? null} />
                ))}
            </div>
        </section>
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
            <p className="mt-3 text-base text-ink">Belum ada aktivitas yang dianalisis</p>
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
        const key = monday.toISOString().slice(0, 10);
        let bucket = byKey.get(key);
        if (!bucket) {
            bucket = {
                weekStart: key,
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

function weekRangeLabel(monday: Date): string {
    const sunday = new Date(monday);
    sunday.setDate(sunday.getDate() + 6);
    const start = formatIdDate(monday.toISOString(), 'long');
    const end = formatIdDate(sunday.toISOString(), 'long');
    return `${start} — ${end}`;
}
