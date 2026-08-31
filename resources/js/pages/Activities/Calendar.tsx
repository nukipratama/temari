import { Head, Link } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { memo, useRef, type ReactNode } from 'react';

import type { AnalysisPayload } from '@/types/inertia';

import HistoryNav from '@/components/history/HistoryNav';
import RecapCard from '@/components/history/RecapCard';
import CoachMark from '@/components/onboarding/CoachMark';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import { useCountUp } from '@/hooks/useCountUp';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';
import {
    MOOD_FILL,
    MOOD_HINT,
    MOOD_LABEL,
    MOOD_ORDER,
    MOOD_SOFT_FILL,
} from '@/lib/mood';
import { fadeInUp } from '@/lib/motion';
import { formatPace, formatShortDateId } from '@/lib/pace';
import { stripEdgeQuotes } from '@/lib/richText';
import { activityUrl } from '@/lib/routes';
import { cardVariants } from '@/lib/variants';

import { useCalendar, type CalendarCell, type WeekRow } from './useCalendar';

export { dominantMoodOf, type CalendarCell } from './useCalendar';

/** The monthly recap payload plus the chain-head flag the controller adds. */
export type MonthlyRecap = AnalysisPayload & {
    is_chain_head: boolean;
    /** Remaining Telegram-send cooldown for this month's recap, or null. */
    notification_retry_after_seconds: number | null;
};

interface LifetimeStats {
    total_runs: number;
    total_km: number;
    first_run_at: string | null;
}

interface CalendarProps {
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

const WEEKDAY_LABELS = [
    'Mon',
    'Tue',
    'Wed',
    'Thu',
    'Fri',
    'Sat',
    'Sun',
] as const;

export default function Calendar({
    cells,
    monthLabel,
    prevMonth,
    nextMonth,
    month,
    todayMonth,
    lifetime,
    todayQuote = null,
    monthlyRecap,
}: Readonly<CalendarProps>) {
    const { weeks, dominantMood, monthTotals, isCurrentMonth } = useCalendar({
        cells,
        month,
        todayMonth,
    });
    const gridRef = useRef<HTMLDivElement>(null);

    return (
        <>
            <Head title={`History · Calendar · ${monthLabel}`} />
            <PageContainer>
                <header className="flex flex-col gap-5">
                    <PageHero
                        eyebrow={<LifetimeEyebrow lifetime={lifetime} />}
                        size="quote-lg"
                        italic
                    >
                        every run,
                        <br />
                        <em className="text-horizon-ink">has a story.</em>
                    </PageHero>
                    <HistoryNav active="calendar" />
                </header>

                <div className="mt-8 mb-2.5 flex items-center justify-between">
                    <MonthNav
                        label={monthLabel}
                        prevMonth={prevMonth}
                        nextMonth={nextMonth}
                        showTodayButton={!isCurrentMonth}
                    />
                </div>

                <div className="mb-2.5 text-center font-mono text-[9.5px] leading-[1.2] text-text-3">
                    <MonthMeta totals={monthTotals} />
                </div>

                {monthlyRecap && (
                    <RecapCard
                        mood={dominantMood}
                        analysis={monthlyRecap}
                        awaitingSchedule={isCurrentMonth}
                        awaitingScheduleLabel="This month's recap isn't ready yet."
                        isChainHead={monthlyRecap.is_chain_head}
                        size="month"
                        inertiaReloadProps={['monthlyRecap']}
                        notification={{
                            url: `/recaps/monthly/${month}/send`,
                            retryAfterSeconds:
                                monthlyRecap.notification_retry_after_seconds,
                        }}
                        className="mb-4"
                    />
                )}

                <Legend className="mb-4" />

                {/* All 7 weekday columns fit at every width: phones get a calendar-first
                    view (date + mood dot per cell, run stats deferred to the day drill-in),
                    lg+ gets the full km/pace/HR cells. No horizontal scroll. */}
                {/* The ref/data-coachmark anchor lives on this stable wrapper, not on
                    the keyed motion.div below: CoachMark's anchor tracking sets up its
                    IntersectionObserver once and never re-attaches, so a `key={month}`
                    remount on the ref'd element itself would detach it from the DOM the
                    observer is still watching, dropping the mark for the rest of the visit. */}
                <div ref={gridRef} data-coachmark="calendar-grid">
                    <motion.div
                        key={month}
                        initial="hidden"
                        animate="visible"
                        variants={fadeInUp}
                        className={cn(
                            cardVariants({ padding: 'none' }),
                            'overflow-hidden',
                        )}
                    >
                        <CalendarHeader />
                        {weeks.map((week) => (
                            <WeekRowView
                                key={week.weekStart}
                                week={week}
                                todayQuote={todayQuote}
                            />
                        ))}
                    </motion.div>
                </div>
                <CoachMark
                    id="calendar-grid"
                    anchorRef={gridRef}
                    placement="top"
                    title="Tap any day"
                    body="Days you ran open straight into the run itself."
                />
            </PageContainer>
        </>
    );
}

function LifetimeEyebrow({ lifetime }: Readonly<{ lifetime?: LifetimeStats }>) {
    const hasLifetime = Boolean(lifetime && lifetime.total_runs > 0);
    const totalRuns = useCountUp(lifetime?.total_runs ?? 0);
    const totalKm = useCountUp(lifetime?.total_km ?? 0);

    return (
        <Eyebrow token="hero" tone="ink-2" className="mb-3.5 lg:text-xs">
            History
            {hasLifetime && (
                <>
                    {' · '}
                    {Math.round(totalRuns)} runs · {totalKm.toFixed(0)} km
                    {lifetime?.first_run_at && (
                        <>
                            {' · since '}
                            {formatShortDateId(lifetime.first_run_at)}
                        </>
                    )}
                </>
            )}
        </Eyebrow>
    );
}

function MonthMeta({
    totals,
}: Readonly<{ totals: { runs: number; km: number; trimp: number | null } }>) {
    return (
        <>
            {totals.runs} run{totals.runs === 1 ? '' : 's'} ·{' '}
            {totals.km.toFixed(1)} km ·{' '}
            {totals.trimp === null
                ? '— TRIMP'
                : `${Math.round(totals.trimp)} TRIMP`}
        </>
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
        <div className="flex w-full items-center justify-between gap-2">
            <NavButton
                href={`/history?view=calendar&month=${prevMonth}`}
                icon="mdi:chevron-left"
                label="Previous month"
            />
            <div className="flex items-center gap-2">
                <h2 className="font-serif text-[15px] leading-[1.2] font-semibold text-foreground">
                    {label}
                </h2>
                {showTodayButton && (
                    <Link
                        href="/history?view=calendar"
                        aria-label="Jump to current month"
                        className="pressable focus-ring rounded-full border border-leaf/40 bg-leaf/10 px-3 py-1 text-xs font-semibold text-leaf-ink transition hover:border-leaf hover:bg-leaf/15"
                    >
                        Today
                    </Link>
                )}
            </div>
            <NavButton
                href={`/history?view=calendar&month=${nextMonth}`}
                icon="mdi:chevron-right"
                label="Next month"
            />
        </div>
    );
}

function NavButton({
    href,
    icon,
    label,
}: Readonly<{ href: string; icon: string; label: string }>) {
    return (
        <Link
            href={href}
            aria-label={label}
            preserveScroll
            className="pressable focus-ring flex size-7 flex-none items-center justify-center rounded-full bg-card text-foreground shadow-e1"
        >
            <Icon icon={icon} width={16} height={16} aria-hidden />
        </Link>
    );
}

function CalendarHeader() {
    return (
        <div className="grid grid-cols-[3rem_repeat(7,minmax(0,1fr))] border-b border-border/60 bg-muted/60 lg:grid-cols-[6rem_repeat(7,minmax(0,1fr))]">
            <Eyebrow
                token="micro"
                tone="ink-2"
                className="px-1 py-2.5 text-center lg:px-3 lg:text-left lg:text-xs lg:tracking-[0.14em]"
            >
                <span className="sr-only">Week, distance in kilometers</span>
                <span aria-hidden>KM</span>
            </Eyebrow>
            {WEEKDAY_LABELS.map((label) => (
                <Eyebrow
                    key={label}
                    token="micro"
                    tone="ink-2"
                    className="px-1 py-2.5 text-center lg:px-2 lg:text-xs lg:tracking-[0.14em]"
                >
                    {label}
                </Eyebrow>
            ))}
        </div>
    );
}

function WeekRowView({
    week,
    todayQuote,
}: Readonly<{
    week: WeekRow;
    todayQuote: string | null;
}>) {
    return (
        <div className="grid grid-cols-[3rem_repeat(7,minmax(0,1fr))] border-b border-border/50 last:border-b-0 lg:grid-cols-[6rem_repeat(7,minmax(0,1fr))]">
            <WeekSummary week={week} />
            {week.days.map((day) => (
                <DayCellView
                    key={day.date}
                    cell={day}
                    todayQuote={todayQuote}
                />
            ))}
        </div>
    );
}

function WeekSummary({ week }: Readonly<{ week: WeekRow }>) {
    return (
        <div className="flex flex-col items-center justify-center gap-0.5 border-r border-border/50 p-1.5 text-center lg:items-start lg:gap-1 lg:p-3 lg:text-left">
            {week.runCount > 0 ? (
                <>
                    <span className="text-xs font-bold tabular-nums leading-none text-foreground lg:text-lg">
                        {week.totalKm.toFixed(1)}
                    </span>
                    <Eyebrow
                        as="span"
                        token="micro"
                        tone="ink-2"
                        className="lg:tracking-[0.14em]"
                    >
                        WK {week.weekNumber}
                    </Eyebrow>
                </>
            ) : (
                <span className="text-xs text-text-3">—</span>
            )}
        </div>
    );
}

const DayCellView = memo(function DayCellView({
    cell,
    todayQuote,
}: Readonly<{
    cell: CalendarCell;
    todayQuote: string | null;
}>) {
    if (cell.is_today) {
        return <TodayCell cell={cell} quote={todayQuote} />;
    }

    const hasRun = cell.distance_km !== null && cell.distance_km > 0;
    const muted = !cell.is_current_month;

    const cellChrome = cn(
        'group relative flex min-h-[52px] flex-col gap-1 border-l border-border/50 p-1.5 transition lg:min-h-[140px] lg:gap-1.5 lg:p-3',
        muted && 'opacity-60',
        hasRun && cell.mood ? MOOD_SOFT_FILL[cell.mood] : 'bg-card',
    );

    const inner = (
        <>
            <div className="flex items-center justify-between gap-1 lg:items-start">
                <span
                    className={cn(
                        'text-xs font-bold tabular-nums lg:text-lg',
                        hasRun ? 'text-foreground' : 'text-text-2',
                    )}
                >
                    {cell.day}
                </span>
                {/* Solid mood dot (distinct across all six moods, unlike the pale cell tint);
                    replaces the faint single-letter glyph that was doing all the signalling. */}
                {hasRun && cell.mood && (
                    <span
                        aria-hidden
                        className={cn(
                            'h-1.5 w-1.5 shrink-0 rounded-full lg:h-2 lg:w-2',
                            MOOD_FILL[cell.mood],
                        )}
                        title={MOOD_LABEL[cell.mood]}
                    />
                )}
            </div>
            {hasRun && (
                <div className="mt-auto hidden lg:block">
                    <div className="text-headline-xs font-black leading-none tabular-nums text-foreground">
                        {cell.distance_km?.toFixed(2)}
                        <span className="ml-0.5 text-[11px] font-bold text-text-2 lg:text-xs">
                            km
                        </span>
                    </div>
                    {(cell.pace_sec_per_km !== null ||
                        cell.avg_hr !== null) && (
                        <div className="mt-1.5 flex items-baseline gap-1.5 font-mono text-[11px] tabular-nums text-text-2 lg:text-xs">
                            {cell.pace_sec_per_km !== null && (
                                <span>{formatPace(cell.pace_sec_per_km)}</span>
                            )}
                            {cell.pace_sec_per_km !== null &&
                                cell.avg_hr !== null && (
                                    <span aria-hidden>·</span>
                                )}
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

    const moodAriaPart =
        hasRun && cell.mood ? `, mood ${MOOD_LABEL[cell.mood]}` : '';
    const ariaLabel = hasRun
        ? `${cell.date}: ${cell.distance_km} km${moodAriaPart}`
        : `${cell.date}: no run`;

    if (cell.activity_id !== null) {
        return (
            <Link
                href={activityUrl({ activity_id: cell.activity_id })}
                className={cn(cellChrome, 'pressable focus-ring')}
                aria-label={ariaLabel}
            >
                {inner}
            </Link>
        );
    }

    return (
        <div className={cellChrome} aria-label={ariaLabel}>
            {inner}
        </div>
    );
});

function TodayCell({
    cell,
    quote,
}: Readonly<{ cell: CalendarCell; quote: string | null }>) {
    const chrome =
        'group relative flex min-h-[52px] flex-col gap-1 border-l border-border/50 bg-sky p-1.5 text-cream transition lg:min-h-[140px] lg:gap-2 lg:p-3';
    const hasRun = cell.distance_km !== null && cell.distance_km > 0;

    let body: ReactNode = null;
    if (quote) {
        body = (
            <p className="mt-auto hidden font-serif text-xs italic leading-snug text-cream/90 lg:block lg:text-sm">
                “{stripEdgeQuotes(quote)}”
            </p>
        );
    } else if (hasRun) {
        body = (
            <div className="mt-auto hidden lg:block">
                <div className="text-headline-xs font-black leading-none tabular-nums text-cream">
                    {cell.distance_km?.toFixed(2)}
                    <span className="ml-0.5 text-[11px] font-bold text-cream/70 lg:text-xs">
                        km
                    </span>
                </div>
            </div>
        );
    }

    const inner = (
        <>
            <div className="flex items-center justify-between gap-1 lg:items-start lg:gap-2">
                <span className="inline-flex items-center gap-1 text-xs font-bold tabular-nums text-cream lg:text-lg">
                    {cell.day}
                    {/* Below lg, "Today" is hidden and the navy fill is the only
                        chrome difference from a highlighted/selected cell — add a
                        small persistent marker so "today" isn't signaled by color
                        alone. */}
                    <span
                        aria-hidden
                        className="h-1 w-1 rounded-full bg-horizon lg:hidden"
                    />
                </span>
                {hasRun && cell.mood && (
                    <span
                        aria-hidden
                        className={cn(
                            'h-1.5 w-1.5 shrink-0 rounded-full lg:hidden',
                            MOOD_FILL[cell.mood],
                        )}
                        title={MOOD_LABEL[cell.mood]}
                    />
                )}
                <Eyebrow
                    as="span"
                    token="hero"
                    tone="horizon"
                    className="hidden lg:inline"
                >
                    Today
                </Eyebrow>
            </div>
            {body}
        </>
    );

    const moodAriaPart =
        hasRun && cell.mood ? `, mood ${MOOD_LABEL[cell.mood]}` : '';
    const distancePart = hasRun ? `, ${cell.distance_km} km` : '';
    const ariaLabel = `${cell.date}: today${distancePart}${moodAriaPart}`;

    if (cell.activity_id !== null) {
        return (
            <Link
                href={activityUrl({ activity_id: cell.activity_id })}
                className={cn(
                    chrome,
                    'pressable focus-ring-on-sky hover:bg-sky-2',
                )}
                aria-label={ariaLabel}
            >
                {inner}
            </Link>
        );
    }

    return (
        <div className={chrome} aria-label={ariaLabel}>
            {inner}
        </div>
    );
}

function Legend({ className }: Readonly<{ className?: string }>) {
    return (
        <div
            className={cn(
                'flex flex-wrap gap-x-3 gap-y-1.75 px-0.5',
                className,
            )}
        >
            {MOOD_ORDER.map((mood) => (
                <div
                    key={mood}
                    className="flex items-center gap-1 font-mono text-[8px] leading-[1.2] font-bold tracking-[.03em] text-foreground uppercase"
                >
                    <span
                        className={cn(
                            'size-1.5 flex-none rounded-full',
                            MOOD_FILL[mood],
                        )}
                        aria-hidden
                    />
                    {MOOD_LABEL[mood]}
                    <span className="normal-case text-text-3">
                        · {MOOD_HINT[mood]}
                    </span>
                </div>
            ))}
        </div>
    );
}

Calendar.layout = appLayout;
