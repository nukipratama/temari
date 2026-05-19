import { Head, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';
import { lazy, Suspense, useRef } from 'react';
import AppShell from '@/layouts/AppShell';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import BriefingCard from '@/components/temari/BriefingCard';
import ConfettiBurst from '@/components/ConfettiBurst';
import DecorativeBlur from '@/components/DecorativeBlur';
import SectionHeading from '@/components/SectionHeading';
import TemariFollow from '@/components/temari/TemariFollow';
import FirstRunTooltip from '@/components/onboarding/FirstRunTooltip';
import { formStatusLabel } from '@/lib/formStatus';
import { fadeInUp } from '@/lib/motion';
import { formatIdDate } from '@/lib/pace';
import { cn } from '@/lib/cn';
import { ICON_TONE, type Tone } from '@/lib/tones';
import type {
    ActivityDetail,
    AnalysisPayload,
    BriefingResult,
    FitnessChartData,
    FormStatus,
    SharedProps,
    TrainingLoad,
    WeeklySnapshot,
} from '@/types/inertia';

// Chart.js is ~150KB — lazy-loaded.
const FitnessChart = lazy(() => import('@/components/dashboard/FitnessChart'));
const VolumeChart = lazy(() => import('@/components/dashboard/VolumeChart'));

interface DashboardProps {
    briefing: BriefingResult;
    load: TrainingLoad | null;
    snapshot: WeeklySnapshot | null;
    recentRuns: ActivityDetail[];
    chartData: FitnessChartData;
    trendAnalysis?: AnalysisPayload;
    hasNewPr?: boolean;
}

export default function Dashboard({
    briefing,
    load,
    snapshot,
    recentRuns,
    chartData,
    trendAnalysis,
    hasNewPr = false,
}: Readonly<DashboardProps>) {
    const { props } = usePage<SharedProps & DashboardProps>();
    const firstName = props.auth.user?.first_name ?? '';
    const briefingRef = useRef<HTMLElement>(null);

    const hasCharts = chartData.labels.length > 1;
    const decouplingValue = formatDecoupling(snapshot?.avg_decoupling ?? null);

    return (
        <AppShell>
            <Head title="Beranda" />
            <ConfettiBurst burstKey={hasNewPr ? 'pr-detected' : null} />
            <motion.main
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="w-full px-6 py-10"
            >
                <FirstRunTooltip recentRunCount={recentRuns.length} />

                <HeroHeader firstName={firstName} briefing={briefing} snapshot={snapshot} />

                <div className="mt-6 grid items-stretch gap-6 lg:grid-cols-3">
                    <section ref={briefingRef} className="flex lg:col-span-2">
                        <BriefingCard briefing={briefing} className="w-full" />
                    </section>
                    <AtGlance load={load} decouplingValue={decouplingValue} />
                </div>

                <TemariFollow sentinelRef={briefingRef} mood={briefing.mood} />

                {hasCharts && (
                    <section className="mt-10">
                        <SectionHeading
                            icon="mdi:chart-line"
                            title="Tren 30 Hari"
                            subtitle="Beban, fitness, dan volume mingguan."
                            tone="brand"
                        />
                        {trendAnalysis && (
                            <section
                                aria-labelledby="trend-narrative-heading"
                                className="relative mt-4 overflow-hidden rounded-2xl border border-brand-200 bg-gradient-to-br from-brand-50 via-surface-elev to-accent-50/40 p-5 shadow-md"
                            >
                                <DecorativeBlur intensity="md" className="-right-8 -top-8 h-24 w-24 bg-brand-200/40" />
                                <div className="relative flex items-start gap-3">
                                    <span aria-hidden className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-500 text-white shadow-sm ring-2 ring-white">
                                        <Icon icon="mdi:trending-up" width={20} height={20} />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <h3
                                            id="trend-narrative-heading"
                                            className="text-xs font-semibold uppercase tracking-wider text-brand-700"
                                        >
                                            Catatan Tren
                                        </h3>
                                        <p className="mt-0.5 text-xs text-ink-meta">Bagaimana 30 hari terakhir lo terlihat dari mata Temari.</p>
                                        <div className="mt-3">
                                            <AnalysisStatus
                                                analysis={trendAnalysis}
                                                inertiaReloadProps={['trendAnalysis']}
                                                size="md"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </section>
                        )}
                        {load !== null && <CoachStatStrip load={load} />}
                        <Suspense
                            fallback={
                                <div className="mt-4 h-56 animate-pulse rounded-2xl bg-line/40" />
                            }
                        >
                            <div className="mt-4 grid gap-4 md:grid-cols-2">
                                <FitnessChart data={chartData} />
                                <VolumeChart data={chartData} />
                            </div>
                        </Suspense>
                    </section>
                )}

                {load === null && <EmptyState />}
            </motion.main>
        </AppShell>
    );
}

// === Hero header ====================================================

interface HeroHeaderProps {
    firstName: string;
    briefing: BriefingResult;
    snapshot: WeeklySnapshot | null;
}

function HeroHeader({ firstName, briefing, snapshot }: Readonly<HeroHeaderProps>) {
    return (
        <section className="rounded-3xl border border-line bg-surface-warm p-6 shadow-sm">
            <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wider text-ink-meta">
                        {formatIdDate(new Date().toISOString(), 'long')}
                    </p>
                    <h1 className="mt-1 text-3xl font-semibold tracking-tight text-ink">
                        Halo, {firstName}.
                    </h1>
                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <p className="text-sm leading-relaxed text-ink-soft">
                            Berikut ringkasan lari kamu.
                        </p>
                        {briefing.streakLabel !== null && (
                            <span className="inline-flex items-center gap-1 rounded-full bg-brand-100 px-2.5 py-0.5 text-xs font-semibold text-brand-700">
                                <Icon icon="mdi:fire" width={12} height={12} aria-hidden />
                                {briefing.streakLabel}
                            </span>
                        )}
                    </div>
                </div>
                {snapshot?.distance_km != null && (
                    <div className="text-right">
                        <div className="text-xs font-semibold uppercase tracking-wider text-ink-meta">
                            Minggu ini
                        </div>
                        <div className="mt-1 text-5xl font-black leading-none text-accent-600 tabular-nums">
                            {snapshot.distance_km.toFixed(1)}
                            <span className="ml-1 text-xl font-semibold text-ink-soft">km</span>
                        </div>
                        {snapshot.runs != null && (
                            <p className="mt-1 text-xs text-ink-meta">{snapshot.runs} run minggu ini</p>
                        )}
                    </div>
                )}
            </header>
        </section>
    );
}

// === At-a-glance sidebar (3 stacked KPI rows in one card) ===========

interface AtGlanceProps {
    load: TrainingLoad | null;
    decouplingValue: string;
}

function AtGlance({ load, decouplingValue }: Readonly<AtGlanceProps>) {
    if (load === null) {
        return (
            <aside className="flex h-full items-center rounded-3xl border border-dashed border-line bg-surface-elev/40 p-5 text-sm text-ink-meta">
                Belum cukup data untuk ringkasan kondisi. Sync lari terbaru dulu.
            </aside>
        );
    }
    const monotony = monotonySignal(load.monotony);
    return (
        <aside className="relative flex h-full flex-col overflow-hidden rounded-3xl border border-brand-200 bg-gradient-to-br from-brand-50/80 via-surface-elev to-accent-50/60 p-5 shadow-md">
            <DecorativeBlur className="-right-10 -top-10 h-32 w-32 bg-accent-200/40" />
            <DecorativeBlur className="-bottom-12 -left-10 h-28 w-28 bg-brand-200/40" />
            <div className="relative text-xs font-semibold uppercase tracking-wider text-brand-700">
                Kondisi
            </div>
            <div className="relative mt-3 divide-y divide-brand-200/60">
                <StatRow
                    icon="mdi:scale-balance"
                    iconTone="brand"
                    label="Vibe"
                    value={load.form.toFixed(1)}
                    hint={formStatusLabel(load.form_status)}
                    hintTone={statusHintTone(load.form_status)}
                />
                <StatRow
                    icon="mdi:lightning-bolt"
                    iconTone="accent"
                    label="Beban minggu ini"
                    value={Math.round(load.weekly_trimp).toString()}
                    hint={`Monotony ${load.monotony.toFixed(2)} ${monotony.emoji}`}
                    hintTone={monotonyHintTone(monotony.tone)}
                />
                <StatRow
                    icon="mdi:waves"
                    iconTone="brand"
                    label="Decoupling"
                    value={decouplingValue}
                    hint="aerobic drift"
                    hintTone="meta"
                />
            </div>
        </aside>
    );
}

interface StatRowProps {
    icon: string;
    iconTone: Tone;
    label: string;
    value: string;
    hint: string;
    hintTone: 'meta' | 'cooked' | 'glow' | 'bouncy';
}

const HINT_TONE_CLASSES: Record<StatRowProps['hintTone'], string> = {
    meta: 'text-ink-meta',
    cooked: 'text-mood-cooked',
    glow: 'text-pop-600',
    bouncy: 'text-mood-bouncy',
};

function StatRow({ icon, iconTone, label, value, hint, hintTone }: Readonly<StatRowProps>) {
    return (
        <div className="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
            <span
                aria-hidden
                className={cn('flex h-9 w-9 shrink-0 items-center justify-center rounded-xl', ICON_TONE[iconTone])}
            >
                <Icon icon={icon} width={16} height={16} />
            </span>
            <div className="min-w-0 flex-1">
                <div className="text-[10px] font-semibold uppercase tracking-wider text-ink-meta">
                    {label}
                </div>
                <div className="mt-0.5 flex items-baseline gap-2">
                    <span className="text-2xl font-black tabular-nums text-ink">{value}</span>
                    <span className={cn('truncate text-xs font-medium', HINT_TONE_CLASSES[hintTone])}>{hint}</span>
                </div>
            </div>
        </div>
    );
}

// === Coach stat strip (4-tile row at top of Tren 30 Hari section) ===

function CoachStatStrip({ load }: Readonly<{ load: TrainingLoad }>) {
    const tiles: ReadonlyArray<CoachStatProps> = [
        { icon: 'mdi:lightning-bolt', iconTone: 'brand', label: 'Fitness (CTL)', value: load.ctl_42d.toFixed(1), hint: '42 hari' },
        { icon: 'mdi:battery-low', iconTone: 'accent', label: 'Fatigue (ATL)', value: load.atl_7d.toFixed(1), hint: '7 hari' },
        { icon: 'mdi:fire', iconTone: 'pop', label: 'Strain', value: Math.round(load.strain).toString(), hint: 'TRIMP × monotony' },
        { icon: 'mdi:repeat-variant', iconTone: 'brand', label: 'Monotony', value: load.monotony.toFixed(2), hint: '≥ 2 = terlalu seragam' },
    ];
    return (
        <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            {tiles.map((t) => (
                <CoachStat key={t.label} {...t} />
            ))}
        </div>
    );
}

interface CoachStatProps {
    icon: string;
    iconTone: Tone;
    label: string;
    value: string;
    hint: string;
}

const COACH_TILE_TONE: Record<Tone, string> = {
    brand: 'border-brand-200 bg-gradient-to-br from-brand-50 via-surface-elev to-brand-100/60 shadow-brand-200/40',
    accent: 'border-accent-200 bg-gradient-to-br from-accent-50 via-surface-elev to-accent-100/60 shadow-accent-200/40',
    pop: 'border-pop-200 bg-gradient-to-br from-pop-50 via-surface-elev to-pop-100/70 shadow-pop-200/40',
    neutral: 'border-line bg-surface-elev shadow-sm',
};

const COACH_VALUE_TONE: Record<Tone, string> = {
    brand: 'text-brand-700',
    accent: 'text-accent-700',
    pop: 'text-pop-700',
    neutral: 'text-ink',
};

function CoachStat({ icon, iconTone, label, value, hint }: Readonly<CoachStatProps>) {
    return (
        <div
            className={cn(
                'relative overflow-hidden rounded-2xl border p-4 shadow-md transition hover:-translate-y-0.5 hover:shadow-lg',
                COACH_TILE_TONE[iconTone],
            )}
        >
            <DecorativeBlur intensity="md" className="-right-6 -top-6 h-16 w-16 bg-white/40" />
            <div className="relative flex items-center justify-between">
                <div className="text-[10px] font-semibold uppercase tracking-wider text-ink-meta">
                    {label}
                </div>
                <span
                    aria-hidden
                    className={cn('flex h-8 w-8 items-center justify-center rounded-lg shadow-sm ring-1 ring-white/60', ICON_TONE[iconTone])}
                >
                    <Icon icon={icon} width={16} height={16} />
                </span>
            </div>
            <div className={cn('relative mt-2 text-3xl font-black tabular-nums', COACH_VALUE_TONE[iconTone])}>{value}</div>
            <div className="relative text-[11px] font-medium text-ink-meta">{hint}</div>
        </div>
    );
}

function EmptyState() {
    return (
        <section className="mt-10 rounded-2xl border border-dashed border-line bg-surface-elev/40 p-10 text-center">
            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-500/15 text-brand-600">
                <Icon icon="mdi:run-fast" width={28} height={28} aria-hidden />
            </div>
            <h2 className="mt-4 text-base font-semibold text-ink">Belum ada aktivitas tersinkron</h2>
            <p className="mx-auto mt-2 max-w-sm text-base leading-relaxed text-ink">
                Jalankan <code className="rounded bg-line/40 px-1 py-0.5 text-xs">php artisan strava:sync</code> atau tunggu sync terjadwal untuk mengisi dashboard.
            </p>
        </section>
    );
}

function monotonySignal(m: number): { emoji: string; tone: 'alert' | 'warning' | 'neutral' } {
    if (m >= 2) return { emoji: '🚨', tone: 'alert' };
    if (m >= 1.5) return { emoji: '⚠️', tone: 'warning' };
    return { emoji: 'ok', tone: 'neutral' };
}

function monotonyHintTone(tone: 'alert' | 'warning' | 'neutral'): 'meta' | 'cooked' | 'glow' {
    if (tone === 'alert') return 'cooked';
    if (tone === 'warning') return 'glow';
    return 'meta';
}

function statusHintTone(status: FormStatus): 'meta' | 'cooked' | 'glow' | 'bouncy' {
    switch (status) {
        case 'fresh':
            return 'bouncy';
        case 'fatigued':
            return 'glow';
        case 'overreaching':
            return 'cooked';
        default:
            return 'meta';
    }
}

function formatDecoupling(value: number | null): string {
    if (value === null) return '—';
    const sign = value >= 0 ? '+' : '';
    return `${sign}${value.toFixed(1)}%`;
}
