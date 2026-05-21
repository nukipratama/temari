import { Head, Link } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';
import { useMemo } from 'react';
import AppShell from '@/layouts/AppShell';
import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';
import { formatPace } from '@/lib/pace';
import type { Mood } from '@/types/inertia';

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
}

interface WeekRow {
    weekStart: string;
    days: CalendarCell[];
    totalKm: number;
    runCount: number;
}

const WEEKDAY_LABELS = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'] as const;

const MOOD_TONE: Record<Mood, { dot: string; cellBg: string; cellBorder: string }> = {
    glow: { dot: 'bg-pop-500', cellBg: 'bg-pop-50', cellBorder: 'border-pop-300' },
    bouncy: { dot: 'bg-mood-bouncy', cellBg: 'bg-mood-bouncy/10', cellBorder: 'border-mood-bouncy/30' },
    wobble: { dot: 'bg-mood-glow', cellBg: 'bg-mood-glow/10', cellBorder: 'border-mood-glow/30' },
    squished: { dot: 'bg-mood-squished', cellBg: 'bg-mood-squished/10', cellBorder: 'border-mood-squished/30' },
    spinning: { dot: 'bg-mood-spinning', cellBg: 'bg-mood-spinning/10', cellBorder: 'border-mood-spinning/30' },
    dim: { dot: 'bg-ink-meta', cellBg: 'bg-surface-sunken/40', cellBorder: 'border-line' },
};

const DEFAULT_TONE = { dot: 'bg-brand-500', cellBg: 'bg-brand-50', cellBorder: 'border-brand-200' };

export default function Kalender({ cells, monthLabel, prevMonth, nextMonth, month, todayMonth }: Readonly<KalenderProps>) {
    const weeks = useMemo<WeekRow[]>(() => groupByWeek(cells), [cells]);
    const monthlyStats = useMemo(() => computeMonthlyStats(cells), [cells]);
    const isCurrentMonth = month === todayMonth;

    return (
        <AppShell>
            <Head title={`Kalender — ${monthLabel}`} />
            <motion.main
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="w-full px-4 py-6 sm:px-6 sm:py-10"
            >
                <MonthHeader
                    label={monthLabel}
                    prevMonth={prevMonth}
                    nextMonth={nextMonth}
                    showTodayButton={!isCurrentMonth}
                />

                <MonthlyStats stats={monthlyStats} className="mt-3" />

                <div className="mt-6 overflow-x-auto rounded-2xl border border-line bg-surface-elev shadow-sm">
                    <div className="min-w-[840px]">
                        <CalendarHeader />
                        {weeks.map((week) => (
                            <WeekRowView key={week.weekStart} week={week} />
                        ))}
                    </div>
                </div>
            </motion.main>
        </AppShell>
    );
}

function MonthHeader({
    label,
    prevMonth,
    nextMonth,
    showTodayButton,
}: Readonly<{ label: string; prevMonth: string; nextMonth: string; showTodayButton: boolean }>) {
    return (
        <header className="flex items-center justify-between gap-3">
            <NavButton href={`/kalender?month=${prevMonth}`} icon="mdi:chevron-left" label="Bulan sebelumnya" />
            <h1 className="flex-1 text-center text-lg font-bold tracking-tight text-ink sm:text-xl">{label}</h1>
            <div className="flex items-center gap-2">
                {showTodayButton && (
                    <Link
                        href="/kalender"
                        className="rounded-lg border border-line bg-surface-elev px-3 py-1.5 text-xs font-semibold text-ink-soft transition hover:border-brand-300 hover:text-ink"
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
            className="flex h-10 w-10 items-center justify-center rounded-lg border border-line bg-surface-elev text-ink-soft transition hover:border-brand-300 hover:text-ink"
        >
            <Icon icon={icon} width={20} height={20} aria-hidden />
        </Link>
    );
}

function MonthlyStats({
    stats,
    className,
}: Readonly<{ stats: { totalKm: number; runCount: number; totalTrimp: number }; className?: string }>) {
    if (stats.runCount === 0) {
        return (
            <p className={cn('text-sm text-ink-meta', className)}>Belum ada lari di bulan ini.</p>
        );
    }

    return (
        <p className={cn('flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm text-ink-soft', className)}>
            <span>
                <span className="font-bold text-ink">{stats.totalKm.toFixed(1)}</span> km
            </span>
            <span aria-hidden className="text-ink-meta">·</span>
            <span>
                <span className="font-bold text-ink">{stats.runCount}</span> lari
            </span>
            <span aria-hidden className="text-ink-meta">·</span>
            <span>
                <span className="font-bold text-ink">{Math.round(stats.totalTrimp)}</span> TRIMP
            </span>
        </p>
    );
}

function CalendarHeader() {
    return (
        <div className="grid grid-cols-[7rem_repeat(7,minmax(0,1fr))] border-b border-line bg-surface-warm/40">
            <div className="px-3 py-2 text-[10px] font-semibold uppercase tracking-wider text-ink-meta">
                Minggu
            </div>
            {WEEKDAY_LABELS.map((label) => (
                <div
                    key={label}
                    className="px-2 py-2 text-center text-[10px] font-semibold uppercase tracking-wider text-ink-meta"
                >
                    {label}
                </div>
            ))}
        </div>
    );
}

function WeekRowView({ week }: Readonly<{ week: WeekRow }>) {
    return (
        <div className="grid grid-cols-[7rem_repeat(7,minmax(0,1fr))] border-b border-line last:border-b-0">
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
        <div className="flex flex-col gap-1 border-r border-line bg-surface-warm/30 p-3 text-xs">
            {hasRuns ? (
                <>
                    <span className="text-base font-black tabular-nums text-ink">{week.totalKm.toFixed(1)}</span>
                    <span className="text-[10px] text-ink-meta">km</span>
                    <span className="mt-1 text-[10px] font-medium text-ink-meta">{week.runCount} lari</span>
                </>
            ) : (
                <span className="text-[10px] text-ink-meta">Tidak ada lari</span>
            )}
        </div>
    );
}

function DayCellView({ cell }: Readonly<{ cell: CalendarCell }>) {
    const hasRun = cell.distance_km !== null && cell.distance_km > 0;
    const tone = cell.mood ? MOOD_TONE[cell.mood] : DEFAULT_TONE;
    const muted = !cell.is_current_month;

    const containerClass = cn(
        'flex min-h-[88px] flex-col gap-1 border-r border-line p-2 last:border-r-0',
        muted && 'opacity-50',
        cell.is_today && 'ring-2 ring-brand-500 ring-inset',
    );

    const inner = (
        <>
            <div className="flex items-start justify-between">
                <span
                    className={cn(
                        'text-xs font-semibold tabular-nums',
                        cell.is_today ? 'text-brand-700' : 'text-ink-soft',
                    )}
                >
                    {cell.day}
                </span>
                {hasRun && <span aria-hidden className={cn('h-2 w-2 rounded-full', tone.dot)} />}
            </div>
            {hasRun && (
                <div
                    className={cn(
                        'mt-auto rounded-md border px-1.5 py-1 text-[11px] leading-tight',
                        tone.cellBg,
                        tone.cellBorder,
                    )}
                >
                    <div className="font-bold tabular-nums text-ink">{cell.distance_km?.toFixed(2)} km</div>
                    {(cell.pace_sec_per_km !== null || cell.avg_hr !== null) && (
                        <div className="mt-0.5 flex items-baseline gap-2 text-[10px] text-ink-meta">
                            {cell.pace_sec_per_km !== null && (
                                <span className="tabular-nums">{formatPace(cell.pace_sec_per_km)}</span>
                            )}
                            {cell.avg_hr !== null && (
                                <span className="tabular-nums text-mood-cooked">{cell.avg_hr}</span>
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
                className={cn(containerClass, 'transition hover:bg-surface-warm/60')}
                aria-label={`${cell.date}: ${cell.distance_km} km`}
            >
                {inner}
            </Link>
        );
    }

    return <div className={containerClass}>{inner}</div>;
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
