import { Head, Link } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useMemo, useRef } from 'react';

import type { AnalysisPayload, WeeklySnapshotWithRecap } from '@/types/inertia';

import CalendarWeekRow from '@/components/history/CalendarWeekRow';
import HistoryHeader from '@/components/history/HistoryHeader';
import RecapCard from '@/components/history/RecapCard';
import CoachMark from '@/components/onboarding/CoachMark';
import { Icon } from '@/components/ui/Icon';
import PageContainer from '@/components/ui/PageContainer';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';
import { MOOD_FILL, MOOD_LABEL, MOOD_ORDER } from '@/lib/mood';
import { fadeInUp } from '@/lib/motion';

import { useCalendar, type CalendarCell } from './useCalendar';

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
    weeklySnapshots?: ReadonlyArray<WeeklySnapshotWithRecap>;
    monthlyRecap?: MonthlyRecap;
}

const WEEKDAY_LABELS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'] as const;

export default function Calendar({
    cells,
    monthLabel,
    prevMonth,
    nextMonth,
    month,
    todayMonth,
    lifetime,
    weeklySnapshots = [],
    monthlyRecap,
}: Readonly<CalendarProps>) {
    const { weeks, dominantMood, monthTotals, isCurrentMonth } = useCalendar({
        cells,
        month,
        todayMonth,
    });
    const gridRef = useRef<HTMLDivElement>(null);

    const snapshotsByWeek = useMemo(() => {
        const map = new Map<string, WeeklySnapshotWithRecap>();
        for (const snap of weeklySnapshots)
            map.set(snap.week_ending.slice(0, 10), snap);
        return map;
    }, [weeklySnapshots]);

    return (
        <>
            <Head title={`History · Calendar · ${monthLabel}`} />
            <PageContainer>
                <HistoryHeader
                    active="calendar"
                    activityCount={lifetime?.total_runs}
                />

                <div className="mt-8 mb-2.5">
                    <MonthNav
                        label={monthLabel}
                        prevMonth={prevMonth}
                        nextMonth={nextMonth}
                    />
                </div>

                <div className="mb-2.5 text-center font-mono text-[0.59375rem] leading-[1.2] text-text-3">
                    {monthTotals.runs} run{monthTotals.runs === 1 ? '' : 's'} ·{' '}
                    {monthTotals.km.toFixed(1)} km ·{' '}
                    {monthTotals.trimp === null
                        ? '— TRIMP'
                        : `${Math.round(monthTotals.trimp)} TRIMP`}
                </div>

                {monthlyRecap && (
                    <RecapCard
                        mood={dominantMood}
                        analysis={monthlyRecap}
                        awaitingSchedule={isCurrentMonth}
                        awaitingScheduleLabel="this month's recap isn't ready yet."
                        isChainHead={monthlyRecap.is_chain_head}
                        size="month"
                        inertiaReloadProps={['monthlyRecap']}
                        notification={{
                            url: `/recaps/monthly/${month}/send`,
                            retryAfterSeconds:
                                monthlyRecap.notification_retry_after_seconds,
                        }}
                        className="mb-2.5"
                    />
                )}

                <Legend />

                {/* The CoachMark anchor lives on this stable wrapper, not on the
                    keyed motion.div below: anchor tracking sets up its
                    IntersectionObserver once and never re-attaches, so a
                    `key={month}` remount on the ref'd element would detach it
                    from the DOM the observer is still watching. */}
                <div ref={gridRef} data-coachmark="calendar-grid">
                    <div className="mb-1.5 grid grid-cols-[30px_repeat(7,minmax(0,1fr))] gap-0.75">
                        <span aria-hidden />
                        {WEEKDAY_LABELS.map((label) => (
                            <span
                                key={label}
                                className="text-center font-mono text-[0.46875rem] leading-[1.2] font-extrabold text-text-3 uppercase"
                            >
                                {label}
                            </span>
                        ))}
                    </div>
                    <motion.div
                        key={month}
                        initial="hidden"
                        animate="visible"
                        variants={fadeInUp}
                    >
                        {weeks.map((week) => (
                            <CalendarWeekRow
                                key={week.weekStart}
                                week={week}
                                snapshot={
                                    snapshotsByWeek.get(week.weekEnding) ?? null
                                }
                            />
                        ))}
                    </motion.div>
                </div>
                <CoachMark
                    id="calendar-grid"
                    anchorRef={gridRef}
                    placement="top"
                    title="tap any day"
                    body="days you ran open straight into the run itself. tap a week for temari's recap."
                />
            </PageContainer>
        </>
    );
}

function MonthNav({
    label,
    prevMonth,
    nextMonth,
}: Readonly<{
    label: string;
    prevMonth: string;
    nextMonth: string;
}>) {
    return (
        <div className="flex w-full items-center justify-between gap-2">
            <NavButton
                href={`/history?view=calendar&month=${prevMonth}`}
                icon="mdi:chevron-left"
                label="Previous month"
            />
            <h2 className="font-serif text-[0.9375rem] leading-[1.2] font-semibold text-foreground">
                {label}
            </h2>
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

function Legend() {
    return (
        <div className="mb-3.5 flex flex-wrap gap-x-3 gap-y-1.75 px-0.5">
            {MOOD_ORDER.map((mood) => (
                <div
                    key={mood}
                    className="flex items-center gap-1 font-mono text-[0.5rem] leading-[1.2] font-bold tracking-[.03em] text-foreground uppercase"
                >
                    <span
                        className={cn(
                            'size-1.5 flex-none rounded-full',
                            MOOD_FILL[mood],
                        )}
                        aria-hidden
                    />
                    {MOOD_LABEL[mood]}
                </div>
            ))}
        </div>
    );
}

Calendar.layout = appLayout;
