import { Deferred, Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Suspense, useMemo, useState } from 'react';

import type { AnalysisPayload, WeeklySnapshotWithRecap } from '@/types/inertia';

import CalendarGrid from '@/components/history/CalendarGrid';
import ConsistencyLine from '@/components/history/ConsistencyLine';
import EffortLegend from '@/components/history/EffortLegend';
import HistoryHeader from '@/components/history/HistoryHeader';
import RecapCard from '@/components/history/RecapCard';
import { Icon, IconComponent } from '@/components/ui/Icon';
import PageContainer from '@/components/ui/PageContainer';
import Skeleton, { SkeletonRows } from '@/components/ui/Skeleton';
import { useHorizontalSwipe } from '@/hooks/useHorizontalSwipe';
import { appLayout } from '@/layouts/appLayout';
import { lazyIsland } from '@/lib/lazyIsland';
import { consistencyOf } from '@/pages/Activities/calendarBars';

import { useCalendar, type CalendarCell } from './useCalendar';
import { snapshotsByWeekEnding } from './weekBuckets';

/**
 * DayRunsSheet pulls in Base UI's Overlay/Sheet chunk (~15KB gzipped): keep
 * it off Calendar's static import closure, loading it only once a multi-run
 * day is actually opened.
 */
const DayRunsSheet = lazyIsland(
    () => import('@/components/history/DayRunsSheet'),
);

export { dominantMoodOf, type CalendarCell } from './useCalendar';

/** The monthly recap payload plus the chain-head flag the controller adds. */
export type MonthlyRecap = AnalysisPayload & {
    is_chain_head: boolean;
};

interface LifetimeStats {
    total_runs: number;
    total_km: number;
    first_run_at: string | null;
}

interface CalendarProps {
    cells?: ReadonlyArray<CalendarCell>;
    month: string;
    monthLabel: string;
    prevMonth: string;
    nextMonth: string;
    todayMonth: string;
    lifetime?: LifetimeStats;
    weeklySnapshots?: ReadonlyArray<WeeklySnapshotWithRecap>;
    monthlyRecap?: MonthlyRecap;
}

/** Props reloaded on a month change, so the swipe/nav partial reload keeps the header in sync too. */
const MONTH_RELOAD_PROPS = [
    'month',
    'monthLabel',
    'prevMonth',
    'nextMonth',
    'cells',
    'weeklySnapshots',
    'monthlyRecap',
];

const calendarMonthUrl = (month: string): string =>
    `/history?view=calendar&month=${month}`;

export default function Calendar({
    cells = [],
    monthLabel,
    prevMonth,
    nextMonth,
    month,
    todayMonth,
    lifetime,
    weeklySnapshots = [],
    monthlyRecap,
}: Readonly<CalendarProps>) {
    const { weeks, dominantMood, isCurrentMonth } = useCalendar({
        cells,
        month,
        todayMonth,
    });
    const [openDay, setOpenDay] = useState<CalendarCell | null>(null);
    const [askedDayRuns, setAskedDayRuns] = useState(false);

    const snapshotsByWeek = useMemo(
        () => snapshotsByWeekEnding(weeklySnapshots),
        [weeklySnapshots],
    );
    const consistency = useMemo(() => consistencyOf(cells), [cells]);

    const touchHandlers = useHorizontalSwipe((direction) => {
        router.visit(
            calendarMonthUrl(direction === 'left' ? nextMonth : prevMonth),
            { only: MONTH_RELOAD_PROPS, preserveScroll: true },
        );
    });

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

                <div
                    key={month}
                    data-testid="calendar-swipe-area"
                    {...touchHandlers}
                >
                    <Deferred
                        data={['monthlyRecap']}
                        fallback={<Skeleton className="mb-2.5 h-16 w-full" />}
                    >
                        {() =>
                            monthlyRecap && (
                                <RecapCard
                                    mood={dominantMood}
                                    analysis={monthlyRecap}
                                    awaitingSchedule={isCurrentMonth}
                                    awaitingScheduleLabel="this month's recap isn't ready yet."
                                    isChainHead={monthlyRecap.is_chain_head}
                                    size="month"
                                    inertiaReloadProps={['monthlyRecap']}
                                    className="mb-2.5"
                                />
                            )
                        }
                    </Deferred>

                    <Deferred
                        data={['cells']}
                        fallback={
                            <Skeleton className="mx-auto mb-3 h-3 w-56" />
                        }
                    >
                        {() => (
                            <ConsistencyLine
                                stats={consistency}
                                className="mb-3"
                            />
                        )}
                    </Deferred>

                    <Deferred
                        data={['cells', 'weeklySnapshots']}
                        fallback={<SkeletonRows count={6} />}
                    >
                        {() => (
                            <CalendarGrid
                                weeks={weeks}
                                snapshotsByWeek={snapshotsByWeek}
                                onOpenDay={(cell) => {
                                    setAskedDayRuns(true);
                                    setOpenDay(cell);
                                }}
                            />
                        )}
                    </Deferred>

                    <EffortLegend className="mt-3 mb-2" />
                </div>

                {askedDayRuns && (
                    <Suspense fallback={null}>
                        <DayRunsSheet
                            cell={openDay}
                            onClose={() => setOpenDay(null)}
                        />
                    </Suspense>
                )}
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
                month={prevMonth}
                icon={ChevronLeft}
                label="Previous month"
            />
            <h2 className="font-serif text-[0.9375rem] leading-[1.2] font-semibold text-foreground">
                {label}
            </h2>
            <NavButton
                month={nextMonth}
                icon={ChevronRight}
                label="Next month"
            />
        </div>
    );
}

function NavButton({
    month,
    icon,
    label,
}: Readonly<{ month: string; icon: IconComponent; label: string }>) {
    return (
        <Link
            href={calendarMonthUrl(month)}
            only={MONTH_RELOAD_PROPS}
            aria-label={label}
            preserveScroll
            className="pressable focus-ring flex size-7 flex-none items-center justify-center rounded-full bg-card text-foreground shadow-e1"
        >
            <Icon icon={icon} width={16} height={16} aria-hidden />
        </Link>
    );
}

Calendar.layout = appLayout;
