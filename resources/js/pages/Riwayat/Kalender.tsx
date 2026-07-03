import { Head, Link, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { memo, useCallback, useMemo, useState, type ReactNode } from 'react';
import AppShell from '@/layouts/AppShell';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import Temari from '@/components/temari/Temari';
import RiwayatFilter, { type MoodOption } from '@/components/riwayat/RiwayatFilter';
import RiwayatTabs from '@/components/riwayat/RiwayatTabs';
import SendToTelegramButton from '@/components/SendToTelegramButton';
import { cn } from '@/lib/cn';
import PageContainer from '@/components/ui/PageContainer';
import { MOOD_FILL, MOOD_HINT, MOOD_LABEL, MOOD_ORDER, MOOD_SOFT_FILL, moodSigilColor } from '@/lib/mood';
import { formatPace, formatShortDateId } from '@/lib/pace';
import { renderBold } from '@/lib/richText';
import { aktivitasUrl } from '@/lib/routes';
import { MOOD_TO_POSE } from '@/lib/temariPose';
import type { AnalysisPayload, Mood, SharedProps } from '@/types/inertia';

/** The monthly recap payload plus the chain-head flag the controller adds. */
export type MonthlyRecap = AnalysisPayload & { is_chain_head: boolean };

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

interface LifetimeStats {
    total_runs: number;
    total_km: number;
    first_run_at: string | null;
}

interface KalenderProps {
    cells: ReadonlyArray<CalendarCell>;
    month: string;
    monthLabel: string;
    prevMonth: string;
    nextMonth: string;
    todayMonth: string;
    lifetime?: LifetimeStats;
    todayQuote?: string | null;
    monthlyRecap?: MonthlyRecap;
}

interface WeekRow {
    weekStart: string;
    weekNumber: number;
    days: CalendarCell[];
    totalKm: number;
    runCount: number;
}

const WEEKDAY_LABELS = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as const;

const MOOD_GLYPH: Record<Mood, string> = {
    nyala: 'N',
    enteng: 'E',
    oleng: 'O',
    lemes: 'L',
    mumet: 'M',
    adem: 'A',
};

const MOOD_FILTER_OPTIONS: ReadonlyArray<MoodOption> = MOOD_ORDER.map((mood) => ({
    mood,
    label: MOOD_LABEL[mood],
    hint: MOOD_HINT[mood],
    swatchClass: MOOD_FILL[mood],
}));

export default function Kalender({
    cells,
    monthLabel,
    prevMonth,
    nextMonth,
    month,
    todayMonth,
    lifetime,
    todayQuote = null,
    monthlyRecap,
}: Readonly<KalenderProps>) {
    const weeks = useMemo<WeekRow[]>(() => groupByWeek(cells), [cells]);
    const dominantMood = useMemo(() => dominantMoodOf(cells), [cells]);
    const isCurrentMonth = month === todayMonth;
    const [moodFilter, setMoodFilter] = useState<ReadonlySet<Mood>>(new Set());
    const toggleMood = useCallback((mood: Mood) => {
        setMoodFilter((prev) => {
            const next = new Set(prev);
            if (next.has(mood)) next.delete(mood);
            else next.add(mood);
            return next;
        });
    }, []);
    const resetFilter = useCallback(() => setMoodFilter(new Set()), []);

    return (
        <AppShell>
            <Head title={`Riwayat · Kalender · ${monthLabel}`} />
            <PageContainer>
                <header className="mb-8 min-w-0">
                    <LifetimeEyebrow lifetime={lifetime} />
                    <h1 className="font-display text-display-lg text-ink">
                        Setiap lari,<br />
                        <em className="not-italic text-horizon-deep">ada ceritanya.</em>
                    </h1>
                </header>

                <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <RiwayatTabs active="kalender" />
                    <div className="flex flex-wrap items-center gap-2.5">
                        <MonthNav
                            label={monthLabel}
                            prevMonth={prevMonth}
                            nextMonth={nextMonth}
                            showTodayButton={!isCurrentMonth}
                        />
                        <RiwayatFilter
                            mood={{
                                selected: moodFilter,
                                options: MOOD_FILTER_OPTIONS,
                                onToggle: toggleMood,
                            }}
                            onReset={resetFilter}
                        />
                    </div>
                </div>

                {monthlyRecap && (
                    <MonthlyRecapCard
                        recap={monthlyRecap}
                        month={month}
                        monthLabel={monthLabel}
                        mood={dominantMood}
                        awaitingSchedule={isCurrentMonth}
                    />
                )}

                <Legend className="mb-4" />

                <div className="relative">
                    <div className="overflow-x-auto rounded-2xl border border-line/70 bg-surface-warm">
                        {/* Forces horizontal scroll on phones; from md (incl. 834px tablets) all
                            7 weekday columns fit, so the Sunday column is no longer clipped. */}
                        <div className="min-w-[840px] md:min-w-0">
                            <CalendarHeader />
                            {weeks.map((week) => (
                                <WeekRowView
                                    key={week.weekStart}
                                    week={week}
                                    todayQuote={todayQuote}
                                    moodFilter={moodFilter}
                                />
                            ))}
                        </div>
                    </div>
                    {/* Phone-only: the 7-day grid is wider than the viewport, so hint that it scrolls. */}
                    <div
                        aria-hidden
                        className="pointer-events-none absolute inset-y-0 right-0 w-10 rounded-r-2xl bg-gradient-to-l from-surface-warm to-transparent md:hidden"
                    />
                </div>
                <p className="mt-2 text-label-micro text-ink-3 md:hidden">
                    Geser buat lihat seminggu penuh →
                </p>
            </PageContainer>
        </AppShell>
    );
}

function LifetimeEyebrow({ lifetime }: Readonly<{ lifetime?: LifetimeStats }>) {
    const stats: string[] = [];
    if (lifetime && lifetime.total_runs > 0) {
        stats.push(`${lifetime.total_runs} lari`, `${lifetime.total_km.toFixed(0)} km`);
        if (lifetime.first_run_at) {
            stats.push(`sejak ${formatShortDateId(lifetime.first_run_at)}`);
        }
    }
    return (
        <div className="mb-3.5 font-mono font-bold text-[11px] uppercase tracking-[0.18em] text-ink-2 lg:text-xs">
            {['Riwayat', ...stats].join(' · ')}
        </div>
    );
}

/**
 * The mood Temari wears on the month's recap: the most frequent run mood among
 * the viewed month's own days (padding days from adjacent months are excluded).
 * Ties resolve by {@link MOOD_ORDER} so the pick is deterministic. Null when the
 * month has no runs, letting the card fall back to a neutral pose.
 */
export function dominantMoodOf(cells: ReadonlyArray<CalendarCell>): Mood | null {
    const counts = new Map<Mood, number>();
    for (const cell of cells) {
        if (!cell.is_current_month || cell.mood === null) {
            continue;
        }
        counts.set(cell.mood, (counts.get(cell.mood) ?? 0) + 1);
    }

    let dominant: Mood | null = null;
    let topCount = 0;
    for (const mood of MOOD_ORDER) {
        const count = counts.get(mood) ?? 0;
        if (count > topCount) {
            topCount = count;
            dominant = mood;
        }
    }

    return dominant;
}

/**
 * Temari's narrative recap for the viewed month, keyed to that month's
 * MonthlyRecap analysis. MonthlyRecap is a connected + chained kind: the
 * "Coba lagi" / "Minta Temari bacain" actions resume the chain from the
 * earliest unfilled month, and "Baca ulang" (regenerate) shows only on the
 * latest narrated month (`is_chain_head`). No rule-based fallback exists for
 * monthly, so unfilled months simply show the empty / resume state. The
 * still-running current month (`awaitingSchedule`) suppresses every trigger and
 * waits for the scheduler, so its incomplete recap can't be generated on demand.
 * Temari wears the month's dominant run mood, mirroring the weekly recap on Jejak.
 */
function MonthlyRecapCard({
    recap,
    month,
    monthLabel,
    mood,
    awaitingSchedule,
}: Readonly<{ recap: MonthlyRecap; month: string; monthLabel: string; mood: Mood | null; awaitingSchedule: boolean }>) {
    const telegramConnected = usePage<SharedProps>().props.telegramConnected ?? false;
    return (
        <section
            className="mb-4 rounded-2xl border border-line bg-surface-warm p-4 shadow-sm sm:p-5"
            aria-label={`Catatan Temari bulan ${monthLabel}`}
        >
            <div className="font-mono text-xs font-bold uppercase tracking-wider text-ink-2">
                Catatan Temari · {monthLabel}
            </div>
            <div className="mt-2 flex items-start gap-3.5">
                <Temari
                    pose={mood ? MOOD_TO_POSE[mood] : 'observational'}
                    size={48}
                    animate={false}
                    className="shrink-0"
                />
                <div className="min-w-0 flex-1">
                    <AnalysisStatus
                        analysis={recap}
                        inertiaReloadProps={['monthlyRecap']}
                        chained
                        isChainHead={recap.is_chain_head}
                        awaitingSchedule={awaitingSchedule}
                        awaitingScheduleLabel="Recap bulan ini belum tersedia."
                        size="md"
                        renderContent={(content) => (
                            <p className="text-sm leading-relaxed text-ink">{renderBold(content)}</p>
                        )}
                    />
                    {telegramConnected && recap.status === 'done' && (
                        <div className="mt-3">
                            <SendToTelegramButton url={`/rekap-bulanan/${month}/telegram`} />
                        </div>
                    )}
                </div>
            </div>
        </section>
    );
}

function MonthNav({
    label,
    prevMonth,
    nextMonth,
    showTodayButton,
}: Readonly<{
    label: string;
    prevMonth: string;
    nextMonth: string;
    showTodayButton: boolean;
}>) {
    return (
        <div className="flex items-center gap-2">
            <NavButton href={`/kalender?month=${prevMonth}`} icon="mdi:chevron-left" label="Bulan sebelumnya" />
            <h2 className="min-w-[7rem] text-center text-base font-semibold tracking-tight text-ink lg:text-lg">
                {label}
            </h2>
            <NavButton href={`/kalender?month=${nextMonth}`} icon="mdi:chevron-right" label="Bulan berikutnya" />
            {showTodayButton && (
                <Link
                    href="/kalender"
                    className="focus-ring ml-1 rounded-full border border-leaf/40 bg-leaf/10 px-3 py-1 text-xs font-semibold text-leaf-deep transition hover:border-leaf hover:bg-leaf/15"
                >
                    Hari ini
                </Link>
            )}
        </div>
    );
}

function NavButton({ href, icon, label }: Readonly<{ href: string; icon: string; label: string }>) {
    return (
        <Link
            href={href}
            aria-label={label}
            preserveScroll
            className="focus-ring flex h-9 w-9 items-center justify-center rounded-full border border-line/60 text-ink-2 transition hover:border-line hover:bg-surface-warm hover:text-ink"
        >
            <Icon icon={icon} width={18} height={18} aria-hidden />
        </Link>
    );
}

function CalendarHeader() {
    return (
        <div className="grid grid-cols-[5.5rem_repeat(7,minmax(0,1fr))] border-b border-line/60 bg-surface-sunken/60 lg:grid-cols-[6rem_repeat(7,minmax(0,1fr))]">
            <div className="px-3 py-2.5">
                <span className="sr-only">Pekan</span>
            </div>
            {WEEKDAY_LABELS.map((label) => (
                <div
                    key={label}
                    className="px-2 py-2.5 text-center font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-ink-2 lg:text-xs"
                >
                    {label}
                </div>
            ))}
        </div>
    );
}

function WeekRowView({
    week,
    todayQuote,
    moodFilter,
}: Readonly<{ week: WeekRow; todayQuote: string | null; moodFilter: ReadonlySet<Mood> }>) {
    return (
        <div className="grid grid-cols-[5.5rem_repeat(7,minmax(0,1fr))] border-b border-line/50 last:border-b-0 lg:grid-cols-[6rem_repeat(7,minmax(0,1fr))]">
            <WeekSummary week={week} />
            {week.days.map((day) => (
                <DayCellView
                    key={day.date}
                    cell={day}
                    todayQuote={todayQuote}
                    filteredOut={isFilteredOut(day, moodFilter)}
                />
            ))}
        </div>
    );
}

/**
 * Precomputed in the parent so toggling the mood filter passes a stable boolean
 * to each memoized cell, letting React skip cells whose dimmed state is unchanged.
 */
function isFilteredOut(cell: CalendarCell, moodFilter: ReadonlySet<Mood>): boolean {
    return moodFilter.size > 0 && (cell.mood === null || !moodFilter.has(cell.mood));
}

function WeekSummary({ week }: Readonly<{ week: WeekRow }>) {
    return (
        <div className="flex flex-col items-start justify-center gap-1 border-r border-line/50 p-3">
            {week.runCount > 0 ? (
                <>
                    <span className="text-base font-bold tabular-nums leading-none text-ink lg:text-lg">
                        {week.totalKm.toFixed(1)}{' '}
                        <span className="text-xs font-medium text-ink-3 lg:text-sm">km</span>
                    </span>
                    <span className="font-mono font-bold text-[11px] uppercase tracking-[0.14em] text-ink-2">
                        WK {week.weekNumber}
                    </span>
                </>
            ) : (
                <span className="text-xs text-ink-3">—</span>
            )}
        </div>
    );
}

const DayCellView = memo(function DayCellView({
    cell,
    todayQuote,
    filteredOut,
}: Readonly<{ cell: CalendarCell; todayQuote: string | null; filteredOut: boolean }>) {
    if (cell.is_today) {
        return <TodayCell cell={cell} quote={todayQuote} />;
    }

    const hasRun = cell.distance_km !== null && cell.distance_km > 0;
    const muted = !cell.is_current_month;

    const cellChrome = cn(
        'group relative flex min-h-[120px] flex-col gap-1.5 border-l border-line/50 p-2.5 transition lg:min-h-[140px] lg:p-3',
        muted && 'opacity-60',
        filteredOut && 'opacity-30',
        hasRun && cell.mood && !filteredOut ? MOOD_SOFT_FILL[cell.mood] : 'bg-surface-elev',
    );

    const inner = (
        <>
            <div className="flex items-start justify-between">
                <span className={cn('text-base font-bold tabular-nums lg:text-lg', hasRun ? 'text-ink' : 'text-ink-2')}>
                    {cell.day}
                </span>
                {hasRun && cell.mood && (
                    <span
                        aria-hidden
                        className="font-mono text-[11px] font-semibold uppercase tracking-wider text-ink/60"
                        title={MOOD_LABEL[cell.mood]}
                    >
                        {MOOD_GLYPH[cell.mood]}
                    </span>
                )}
            </div>
            {hasRun && (
                <div className="mt-auto">
                    <div className="text-headline-xs font-black leading-none tabular-nums text-ink">
                        {cell.distance_km?.toFixed(2)}
                        <span className="ml-0.5 text-[11px] font-bold text-ink-2 lg:text-xs">km</span>
                    </div>
                    {(cell.pace_sec_per_km !== null || cell.avg_hr !== null) && (
                        <div className="mt-1.5 flex items-baseline gap-1.5 font-mono text-[11px] tabular-nums text-ink-3 lg:text-xs">
                            {cell.pace_sec_per_km !== null && <span>{formatPace(cell.pace_sec_per_km)}</span>}
                            {cell.pace_sec_per_km !== null && cell.avg_hr !== null && <span aria-hidden>·</span>}
                            {cell.avg_hr !== null && (
                                <span className="inline-flex items-baseline gap-0.5">
                                    <span aria-hidden>♡</span>
                                    {cell.avg_hr}
                                </span>
                            )}
                        </div>
                    )}
                </div>
            )}
        </>
    );

    const moodAriaPart = hasRun && cell.mood ? `, mood ${MOOD_LABEL[cell.mood]}` : '';
    const ariaLabel = hasRun
        ? `${cell.date}: ${cell.distance_km} km${moodAriaPart}`
        : `${cell.date}: tidak ada lari`;

    if (cell.activity_id !== null) {
        return (
            <Link
                href={aktivitasUrl({ activity_id: cell.activity_id })}
                className={cn(cellChrome, 'focus-ring')}
                aria-label={ariaLabel}
            >
                {inner}
            </Link>
        );
    }

    return <div className={cellChrome} aria-label={ariaLabel}>{inner}</div>;
});

function TodayCell({ cell, quote }: Readonly<{ cell: CalendarCell; quote: string | null }>) {
    const chrome =
        'group relative flex min-h-[120px] flex-col gap-2 border-l border-line/50 bg-sky p-2.5 text-cream transition lg:min-h-[140px] lg:p-3';
    const hasRun = cell.distance_km !== null && cell.distance_km > 0;

    let body: ReactNode = null;
    if (quote) {
        body = (
            <p className="mt-auto font-display text-xs italic leading-snug text-cream/90 lg:text-sm">“{quote}”</p>
        );
    } else if (hasRun) {
        body = (
            <div className="mt-auto">
                <div className="text-headline-xs font-black leading-none tabular-nums text-cream">
                    {cell.distance_km?.toFixed(2)}
                    <span className="ml-0.5 text-[11px] font-bold text-cream/70 lg:text-xs">km</span>
                </div>
            </div>
        );
    }

    const inner = (
        <>
            <div className="flex items-start justify-between gap-2">
                <span className="text-base font-bold tabular-nums text-cream lg:text-lg">{cell.day}</span>
                <span className="font-mono text-[11px] font-semibold uppercase tracking-[0.18em] text-horizon">
                    Hari ini
                </span>
            </div>
            {body}
        </>
    );

    const moodAriaPart = hasRun && cell.mood ? `, mood ${MOOD_LABEL[cell.mood]}` : '';
    const distancePart = hasRun ? `, ${cell.distance_km} km` : '';
    const ariaLabel = `${cell.date}: hari ini${distancePart}${moodAriaPart}`;

    if (cell.activity_id !== null) {
        return (
            <Link
                href={aktivitasUrl({ activity_id: cell.activity_id })}
                className={cn(chrome, 'focus-ring-on-sky hover:bg-sky-2')}
                aria-label={ariaLabel}
            >
                {inner}
            </Link>
        );
    }

    return <div className={chrome} aria-label={ariaLabel}>{inner}</div>;
}

function Legend({ className }: Readonly<{ className?: string }>) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-center gap-x-5 gap-y-2 rounded-xl border border-line/60 bg-surface-warm/40 px-4 py-3',
                className,
            )}
        >
            <span className="font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-ink-2 lg:text-xs">
                Mood
            </span>
            {MOOD_ORDER.map((mood) => (
                <span key={mood} className="inline-flex whitespace-nowrap items-center gap-2 text-xs lg:text-sm">
                    <span
                        className={cn('inline-block h-3.5 w-3.5 rounded-sm border lg:h-4 lg:w-4', MOOD_SOFT_FILL[mood])}
                        style={{ borderColor: moodSigilColor(mood) }}
                        aria-hidden
                    />
                    <span className="font-medium text-ink">{MOOD_LABEL[mood]}</span>
                    <span className="font-mono text-[11px] text-ink-3 lg:text-xs">· {MOOD_HINT[mood]}</span>
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
            weekNumber: weeks.length + 1,
            days,
            totalKm,
            runCount,
        });
    }
    return weeks;
}

