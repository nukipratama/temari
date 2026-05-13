import { Head, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';
import { lazy, Suspense, useRef } from 'react';
import AppShell from '@/layouts/AppShell';
import BriefingCard from '@/components/temari/BriefingCard';
import TemariFollow from '@/components/temari/TemariFollow';
import VerdictStrip from '@/components/temari/VerdictStrip';
import KpiTile from '@/components/dashboard/KpiTile';
import FirstRunTooltip from '@/components/onboarding/FirstRunTooltip';
import { formStatusLabel, formStatusTone } from '@/lib/formStatus';
import { fadeInUp } from '@/lib/motion';
import type {
    ActivityDetail,
    BriefingResult,
    FitnessChartData,
    SharedProps,
    Tone,
    TrainingLoad,
    VerdictTimelineItem,
    WeeklySnapshot,
} from '@/types/inertia';

// Chart.js is ~150KB. Defer it until "Tren 30 hari" disclosure expands.
const FitnessChart = lazy(() => import('@/components/dashboard/FitnessChart'));
const VolumeChart = lazy(() => import('@/components/dashboard/VolumeChart'));

interface DashboardProps {
    briefing: BriefingResult;
    verdicts: VerdictTimelineItem[];
    load: TrainingLoad | null;
    snapshot: WeeklySnapshot | null;
    recentRuns: ActivityDetail[];
    chartData: FitnessChartData;
}

export default function Dashboard({
    briefing,
    verdicts,
    load,
    snapshot,
    recentRuns,
    chartData,
}: Readonly<DashboardProps>) {
    const { props } = usePage<SharedProps & DashboardProps>();
    const firstName = props.auth.user?.first_name ?? '';
    const briefingRef = useRef<HTMLElement>(null);

    const hasCharts = chartData.labels.length > 1;

    const volumeValue = snapshot?.distance_km != null ? `${snapshot.distance_km.toFixed(1)} km` : '—';
    const volumeSub = snapshot?.runs != null ? `${snapshot.runs} run` : null;
    const decouplingValue = formatDecoupling(snapshot?.avg_decoupling ?? null);

    return (
        <AppShell>
            <Head title="Beranda" />
            <motion.main
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="mx-auto max-w-7xl px-6 py-10"
            >
                <FirstRunTooltip recentRunCount={recentRuns.length} verdictCount={verdicts.length} />

                <header className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight text-ink dark:text-ink-dark">Halo, {firstName}.</h1>
                        <p className="mt-1 text-sm leading-relaxed text-ink-soft dark:text-ink-soft-dark">Berikut ringkasan lari kamu.</p>
                    </div>
                </header>

                {/* KPI strip — most-checked numbers, shown first */}
                {load !== null && (
                    <KpiSection load={load} volumeValue={volumeValue} volumeSub={volumeSub} />
                )}

                {/* Briefing — AI commentary, below the numbers */}
                <section ref={briefingRef} className="mt-6">
                    <BriefingCard briefing={briefing} />
                </section>

                <TemariFollow
                    sentinelRef={briefingRef}
                    mood={briefing.mood}
                    sigilPattern={briefing.sigilPattern}
                />

                {/* Kata Temari — run-by-run verdicts */}
                {verdicts.length > 0 && (
                    <section className="mt-8">
                        <h2 className="text-lg font-bold tracking-tight text-ink dark:text-ink-dark">Kata Temari</h2>
                        <p className="mt-1 text-sm text-ink-soft leading-relaxed dark:text-ink-soft-dark">
                            Komentar Temari tiap kelar lari.
                        </p>
                        <VerdictStrip items={verdicts} />
                    </section>
                )}

                {load === null ? (
                    <EmptyState />
                ) : (
                    <Disclosure icon="mdi:chart-bell-curve" label="Rincian coach mode" className="mt-6">
                        <div className="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                            <KpiTile label="Fitness (CTL)" value={load.ctl_42d.toFixed(1)} sub="42 hari" />
                            <KpiTile label="Fatigue (ATL)" value={load.atl_7d.toFixed(1)} sub="7 hari" tone="warning" />
                            <KpiTile label="Strain" value={Math.round(load.strain).toString()} sub="TRIMP × monotony" />
                            <KpiTile label="Avg decoupling" value={decouplingValue} sub="aerobic drift" />
                        </div>
                    </Disclosure>
                )}

                {hasCharts && (
                    <Disclosure icon="mdi:chart-line" label="Tren 30 hari" className="mt-4" defaultOpen>
                        <Suspense fallback={<div className="mt-4 h-56 animate-pulse rounded-2xl bg-line/40 dark:bg-line-dark" />}>
                            <div className="mt-4 grid gap-4 md:grid-cols-2">
                                <FitnessChart data={chartData} />
                                <VolumeChart data={chartData} />
                            </div>
                        </Suspense>
                    </Disclosure>
                )}
            </motion.main>
        </AppShell>
    );
}

function KpiSection({
    load,
    volumeValue,
    volumeSub,
}: Readonly<{ load: TrainingLoad; volumeValue: string; volumeSub: string | null }>) {
    const monotony = monotonySignal(load.monotony);
    return (
        <section className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <KpiTile
                label="Vibe"
                value={load.form.toFixed(1)}
                sub={formStatusLabel(load.form_status)}
                tone={formStatusTone(load.form_status)}
            />
            <KpiTile
                label="Beban minggu ini"
                value={Math.round(load.weekly_trimp).toString()}
                sub={`Monotony ${load.monotony.toFixed(2)} ${monotony.emoji}`}
                tone={monotony.tone}
            />
            <KpiTile label="Volume minggu ini" value={volumeValue} sub={volumeSub} />
        </section>
    );
}

function Disclosure({
    icon,
    label,
    className,
    defaultOpen = false,
    children,
}: Readonly<{ icon: string; label: string; className: string; defaultOpen?: boolean; children: React.ReactNode }>) {
    return (
        <details
            open={defaultOpen}
            className={`group rounded-2xl bg-line/20 p-4 transition hover:bg-line/30 dark:bg-line-dark/40 dark:hover:bg-line-dark/60 ${className}`}
        >
            <summary className="flex cursor-pointer items-center justify-between text-xs font-semibold uppercase tracking-wider text-ink-meta dark:text-ink-meta-dark">
                <span className="flex items-center gap-2">
                    <Icon icon={icon} width={14} height={14} aria-hidden />
                    {label}
                </span>
                <Icon icon="mdi:chevron-down" width={16} height={16} className="transition group-open:rotate-180" aria-hidden />
            </summary>
            {children}
        </details>
    );
}

function EmptyState() {
    return (
        <section className="mt-10 rounded-2xl border border-dashed border-line bg-surface-elev/40 p-10 text-center dark:border-line-dark dark:bg-surface-dark-elev/40">
            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-500/15 text-brand-600 dark:text-brand-400">
                <Icon icon="mdi:run-fast" width={28} height={28} aria-hidden />
            </div>
            <h2 className="mt-4 text-base font-semibold text-ink dark:text-ink-dark">Belum ada aktivitas tersinkron</h2>
            <p className="mx-auto mt-2 max-w-sm text-base leading-relaxed text-ink dark:text-ink-dark">
                Jalankan <code className="rounded bg-line/40 px-1 py-0.5 text-xs dark:bg-line-dark">php artisan strava:sync</code> atau tunggu sync terjadwal untuk mengisi dashboard.
            </p>
        </section>
    );
}

function monotonySignal(m: number): { emoji: string; tone: Tone } {
    if (m >= 2) return { emoji: '🚨', tone: 'alert' };
    if (m >= 1.5) return { emoji: '⚠️', tone: 'warning' };
    return { emoji: 'ok', tone: 'neutral' };
}

function formatDecoupling(value: number | null): string {
    if (value === null) return '—';
    const sign = value >= 0 ? '+' : '';
    return `${sign}${value.toFixed(1)}%`;
}
