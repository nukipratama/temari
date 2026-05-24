import { Head, Link } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';
import { useMemo } from 'react';
import AppShell from '@/layouts/AppShell';
import Card from '@/components/ui/Card';
import RiwayatTabs from '@/components/riwayat/RiwayatTabs';
import SectionLabel from '@/components/ui/SectionLabel';
import TemariProto from '@/components/temari/TemariProto';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';
import { formatPace } from '@/lib/pace';
import type { AnalysisPayload, Mood } from '@/types/inertia';

export interface CalendarCell {
    date: string;
    day: number;
    is_current_month: boolean;
    is_today: boolean;
    distance_km: number | null;
    pace_sec_per_km: number | null;
    avg_hr: number | null;
    trimp: number | null;
    mood: Mood | null;
    activity_id: number | null;
}

interface KalenderProps {
    cells: ReadonlyArray<CalendarCell>;
    month: string;
    monthLabel: string;
    prevMonth: string;
    nextMonth: string;
    todayMonth: string;
    monthlyRecap?: AnalysisPayload;
}

interface WeekRow {
    weekStart: string;
    days: CalendarCell[];
    totalKm: number;
    runCount: number;
}

const WEEKDAY_LABELS = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'] as const;

interface MoodTone {
    dot: string;
    cellBg: string;
    cellBorder: string;
    text: string;
}

const MOOD_TONE: Record<Mood, MoodTone> = {
    nyala: {
        dot: 'bg-mood-nyala',
        cellBg: 'bg-mood-nyala-bg',
        cellBorder: 'border-mood-nyala/40',
        text: 'text-ink',
    },
    enteng: {
        dot: 'bg-mood-enteng',
        cellBg: 'bg-mood-enteng-bg',
        cellBorder: 'border-mood-enteng/40',
        text: 'text-ink',
    },
    lemes: {
        dot: 'bg-mood-lemes',
        cellBg: 'bg-mood-lemes-bg',
        cellBorder: 'border-mood-lemes/40',
        text: 'text-ink',
    },
    oleng: {
        dot: 'bg-mood-oleng',
        cellBg: 'bg-mood-oleng-bg',
        cellBorder: 'border-mood-oleng/40',
        text: 'text-ink',
    },
    mumet: {
        dot: 'bg-mood-mumet',
        cellBg: 'bg-mood-mumet-bg',
        cellBorder: 'border-mood-mumet/40',
        text: 'text-ink',
    },
    adem: {
        dot: 'bg-mood-adem',
        cellBg: 'bg-mood-adem-bg',
        cellBorder: 'border-mood-adem/40',
        text: 'text-ink',
    },
};

const DEFAULT_TONE: MoodTone = {
    dot: 'bg-cream-deep',
    cellBg: 'bg-cream',
    cellBorder: 'border-cream-deep',
    text: 'text-ink-3',
};

export default function Kalender({ cells, monthLabel, prevMonth, nextMonth, month, todayMonth, monthlyRecap }: Readonly<KalenderProps>) {
    const weeks = useMemo<WeekRow[]>(() => groupByWeek(cells), [cells]);
    const monthlyStats = useMemo(() => computeMonthlyStats(cells), [cells]);
    const isCurrentMonth = month === todayMonth;

    return (
        <AppShell>
            <Head title={`Riwayat · Kalender — ${monthLabel}`} />
            <motion.main
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="w-full px-5 py-6 sm:px-8 lg:px-14 lg:py-10"
            >
                <header className="mb-6 flex flex-col gap-5">
                    <div>
                        <div className="mb-3.5 font-mono text-[11px] uppercase tracking-[0.18em] text-ink-3">
                            Riwayat · {monthLabel.toLowerCase()}
                        </div>
                        <h1 className="font-display text-display-lg text-ink">
                            Mood-kamu bulan ini,<br />
                            <em className="italic text-horizon-deep">dalam satu lihat.</em>
                        </h1>
                    </div>
                    <RiwayatTabs active="kalender" />
                </header>

                <MonthNav
                    label={monthLabel}
                    stats={monthlyStats}
                    prevMonth={prevMonth}
                    nextMonth={nextMonth}
                    showTodayButton={!isCurrentMonth}
                    className="mb-4"
                />

                <div className="overflow-x-auto rounded-2xl border border-line bg-surface-elev shadow-sm">
                    <div className="min-w-[840px]">
                        <CalendarHeader />
                        {weeks.map((week) => (
                            <WeekRowView key={week.weekStart} week={week} />
                        ))}
                    </div>
                </div>

                <Legend className="mt-4" />

                {monthlyRecap && (
                    <section className="mt-10">
                        <SectionLabel>Catatan bulanan Temari</SectionLabel>
                        <Card className="flex items-start gap-3.5">
                            <TemariProto pose="observational" size={56} />
                            <div className="min-w-0 flex-1">
                                <AnalysisStatus
                                    analysis={monthlyRecap}
                                    inertiaReloadProps={['monthlyRecap']}
                                    renderContent={(text) => (
                                        <p className="font-display text-quote-md italic text-ink-2">
                                            “{text}”
                                        </p>
                                    )}
                                />
                            </div>
                        </Card>
                    </section>
                )}
            </motion.main>
        </AppShell>
    );
}

interface MonthlyStats {
    totalKm: number;
    runCount: number;
    totalTrimp: number;
}

function MonthNav({
    label,
    stats,
    prevMonth,
    nextMonth,
    showTodayButton,
    className,
}: Readonly<{
    label: string;
    stats: MonthlyStats;
    prevMonth: string;
    nextMonth: string;
    showTodayButton: boolean;
    className?: string;
}>) {
    return (
        <header
            className={cn(
                'flex items-center justify-between gap-3 rounded-2xl border border-line bg-surface-elev px-3 py-2 shadow-sm',
                className,
            )}
        >
            <NavButton href={`/kalender?month=${prevMonth}`} icon="mdi:chevron-left" label="Bulan sebelumnya" />
            <div className="flex flex-1 flex-wrap items-baseline justify-center gap-x-3 gap-y-0.5 text-center">
                <h2 className="text-lg font-bold tracking-tight text-ink sm:text-xl">{label}</h2>
                {stats.runCount > 0 ? (
                    <span className="text-xs font-semibold text-ink-3">
                        <span className="text-leaf-deep">{stats.totalKm.toFixed(1)} km</span> · {stats.runCount} lari
                    </span>
                ) : (
                    <span className="text-xs text-ink-3">Belum ada lari</span>
                )}
            </div>
            <div className="flex items-center gap-2">
                {showTodayButton && (
                    <Link
                        href="/kalender"
                        className="rounded-full border border-leaf/40 bg-leaf/10 px-3 py-1.5 text-xs font-semibold text-leaf-deep transition hover:border-leaf hover:bg-leaf/15"
                    >
                        Hari ini
                    </Link>
                )}
                <NavButton href={`/kalender?month=${nextMonth}`} icon="mdi:chevron-right" label="Bulan berikutnya" />
            </div>
        </header>
    );
}

function NavButton({ href, icon, label }: Readonly<{ href: string; icon: string; label: string }>) {
    return (
        <Link
            href={href}
            aria-label={label}
            preserveScroll
            className="flex h-10 w-10 items-center justify-center rounded-full text-ink-2 transition hover:bg-line/50 hover:text-ink"
        >
            <Icon icon={icon} width={20} height={20} aria-hidden />
        </Link>
    );
}

function CalendarHeader() {
    return (
        <div className="grid grid-cols-[6rem_repeat(7,minmax(0,1fr))] border-b border-line bg-gradient-to-b from-surface-warm/80 to-surface-warm/30">
            <div className="px-3 py-2.5 text-[10px] font-bold uppercase tracking-wider text-ink-3">
                Pekan
            </div>
            {WEEKDAY_LABELS.map((label) => (
                <div
                    key={label}
                    className="border-l border-line/40 px-2 py-2.5 text-center text-[10px] font-bold uppercase tracking-wider text-ink-3"
                >
                    {label}
                </div>
            ))}
        </div>
    );
}

function WeekRowView({ week }: Readonly<{ week: WeekRow }>) {
    return (
        <div className="grid grid-cols-[6rem_repeat(7,minmax(0,1fr))] border-b border-line last:border-b-0">
            <WeekSummary week={week} />
            {week.days.map((day) => (
                <DayCellView key={day.date} cell={day} />
            ))}
        </div>
    );
}

function WeekSummary({ week }: Readonly<{ week: WeekRow }>) {
    const hasRuns = week.runCount > 0;
    return (
        <div
            className={cn(
                'flex flex-col items-start justify-center gap-0.5 border-r border-line p-3 text-xs',
                hasRuns ? 'bg-gradient-to-br from-leaf/10 to-leaf/15' : 'bg-surface-sunken/30',
            )}
        >
            {hasRuns ? (
                <>
                    <span className="text-2xl font-black leading-none tabular-nums text-leaf-deep">
                        {week.totalKm.toFixed(1)}
                    </span>
                    <span className="text-[10px] font-semibold uppercase tracking-wider text-leaf-deep">km</span>
                    <span className="mt-1 inline-flex items-center gap-1 text-[10px] font-medium text-ink-3">
                        <Icon icon="mdi:run" width={10} height={10} aria-hidden />
                        {week.runCount} lari
                    </span>
                </>
            ) : (
                <span className="text-[10px] text-ink-3">Tidak ada lari</span>
            )}
        </div>
    );
}

function DayCellView({ cell }: Readonly<{ cell: CalendarCell }>) {
    const hasRun = cell.distance_km !== null && cell.distance_km > 0;
    const tone = cell.mood ? MOOD_TONE[cell.mood] : DEFAULT_TONE;
    const muted = !cell.is_current_month;
    const dayNumClass = dayNumberClassFor(cell.is_today, hasRun, tone);

    const cellChrome = cn(
        'group relative flex min-h-[96px] flex-col gap-1.5 border-l border-line p-2 transition',
        muted && 'opacity-40',
        hasRun ? tone.cellBg : 'bg-surface-elev',
        cell.is_today && 'ring-2 ring-leaf ring-inset',
    );

    const inner = (
        <>
            <div className="flex items-start justify-between">
                <span className={dayNumClass}>{cell.day}</span>
                {hasRun && <span aria-hidden className={cn('h-2 w-2 rounded-full', tone.dot)} />}
            </div>
            {hasRun && (
                <div className="mt-auto">
                    <div className={cn('text-[15px] font-black leading-none tabular-nums', tone.text)}>
                        {cell.distance_km?.toFixed(2)}
                        <span className="ml-0.5 text-[10px] font-bold opacity-70">km</span>
                    </div>
                    {(cell.pace_sec_per_km !== null || cell.avg_hr !== null) && (
                        <div className="mt-1 flex items-baseline gap-1.5 text-[10px] tabular-nums">
                            {cell.pace_sec_per_km !== null && (
                                <span className="font-semibold text-ink-2">{formatPace(cell.pace_sec_per_km)}</span>
                            )}
                            {cell.avg_hr !== null && (
                                <span className="font-semibold text-mood-lemes">{cell.avg_hr}♥</span>
                            )}
                        </div>
                    )}
                </div>
            )}
        </>
    );

    if (cell.activity_id !== null) {
        return (
            <Link
                href={`/aktivitas/${cell.activity_id}`}
                className={cn(cellChrome, 'hover:scale-[1.02] hover:shadow-md')}
                aria-label={`${cell.date}: ${cell.distance_km} km`}
            >
                {inner}
            </Link>
        );
    }

    return <div className={cellChrome}>{inner}</div>;
}

function dayNumberClassFor(isToday: boolean, hasRun: boolean, tone: MoodTone): string {
    if (isToday) {
        return 'text-xs font-bold tabular-nums inline-flex h-5 w-5 items-center justify-center rounded-full bg-leaf text-white';
    }
    if (hasRun) {
        return cn('text-xs font-bold tabular-nums', tone.text);
    }
    return 'text-xs font-bold tabular-nums text-ink-2';
}

function Legend({ className }: Readonly<{ className?: string }>) {
    const moods: ReadonlyArray<{ mood: Mood; label: string; hint: string }> = [
        { mood: 'nyala', label: 'Nyala', hint: 'PR / win' },
        { mood: 'enteng', label: 'Enteng', hint: 'easy / ringan' },
        { mood: 'lemes', label: 'Lemes', hint: 'overload' },
        { mood: 'oleng', label: 'Oleng', hint: 'kepayahan' },
        { mood: 'mumet', label: 'Mumet', hint: 'intervals' },
        { mood: 'adem', label: 'Adem', hint: 'recovery' },
    ];

    return (
        <div className={cn('flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-cream-deep bg-cream px-4 py-3 text-xs', className)}>
            <span className="font-mono text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-3">Mood</span>
            {moods.map(({ mood, label, hint }) => (
                <span key={mood} className="inline-flex items-baseline gap-1.5">
                    <span className={cn('inline-block h-2.5 w-2.5 rounded-full', MOOD_TONE[mood].dot)} aria-hidden />
                    <span className="font-medium text-ink">{label}</span>
                    <span className="font-display text-[11px] italic text-ink-3">· {hint}</span>
                </span>
            ))}
        </div>
    );
}

function groupByWeek(cells: ReadonlyArray<CalendarCell>): WeekRow[] {
    const weeks: WeekRow[] = [];
    for (let i = 0; i < cells.length; i += 7) {
        const days = cells.slice(i, i + 7);
        if (days.length === 0) continue;
        let totalKm = 0;
        let runCount = 0;
        for (const day of days) {
            if (day.distance_km !== null && day.distance_km > 0 && day.is_current_month) {
                totalKm += day.distance_km;
                runCount += 1;
            }
        }
        weeks.push({
            weekStart: days[0].date,
            days,
            totalKm,
            runCount,
        });
    }
    return weeks;
}

function computeMonthlyStats(cells: ReadonlyArray<CalendarCell>): { totalKm: number; runCount: number; totalTrimp: number } {
    let totalKm = 0;
    let runCount = 0;
    let totalTrimp = 0;
    for (const cell of cells) {
        if (!cell.is_current_month) continue;
        if (cell.distance_km !== null && cell.distance_km > 0) {
            totalKm += cell.distance_km;
            runCount += 1;
        }
        if (cell.trimp !== null) totalTrimp += cell.trimp;
    }
    return { totalKm, runCount, totalTrimp };
}
