import { Deferred, Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { type ReactNode, Suspense, useMemo, useState } from 'react';

import type { AnalysisPayload, WeeklySnapshotWithRecap } from '@/types/inertia';

import AiOutageBanner from '@/components/AiOutageBanner';
import CalendarGrid from '@/components/history/CalendarGrid';
import ConsistencyLine from '@/components/history/ConsistencyLine';
import HistoryHeader from '@/components/history/HistoryHeader';
import {
    CalendarGridSkeleton,
    ConsistencyLineSkeleton,
    MonthRecapSkeleton,
} from '@/components/history/HistorySkeleton';
import RecapCard from '@/components/history/RecapCard';
import { Icon, IconComponent } from '@/components/ui/Icon';
import PageContainer from '@/components/ui/PageContainer';
import { useHorizontalSwipe } from '@/hooks/useHorizontalSwipe';
import { appLayout } from '@/layouts/appLayout';
import { lazyIsland } from '@/lib/lazyIsland';
import { consistencyOf } from '@/pages/Activities/calendarBars';

import { useCalendar, type CalendarCell } from './useCalendar';
import useMonthReload from './useMonthReload';
import { snapshotsByWeekEnding } from './weekBuckets';

/**
 * DayRunsSheet pulls in Base UI's Overlay/Sheet chunk (~15KB gzipped): keep
 * it off Calendar's static import closure, loading it only once a multi-run
 * day is actually opened. EffortLegend rides the same chunk.
 */
const DayRunsSheet = lazyIsland(
    () => import('@/components/history/DayRunsSheet'),
);
const EffortLegend = lazyIsland(
    () => import('@/components/history/EffortLegend'),
);

export { dominantMoodOf, type CalendarCell } from './useCalendar';

/** The monthly recap payload plus the chain-head flag the controller adds. */
export type MonthlyRecap = AnalysisPayload & {
    is_chain_head: boolean;
};

interface LifetimeStats {
    total_runs: number;
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
    const [keyOpen, setKeyOpen] = useState(false);
    const [askedKey, setAskedKey] = useState(false);

    const snapshotsByWeek = useMemo(
        () => snapshotsByWeekEnding(weeklySnapshots),
        [weeklySnapshots],
    );
    const consistency = useMemo(() => consistencyOf(cells), [cells]);
    const reloading = useMonthReload(MONTH_RELOAD_PROPS);

    const touchHandlers = useHorizontalSwipe((direction) => {
        router.visit(
            calendarMonthUrl(direction === 'left' ? nextMonth : prevMonth),
            { only: MONTH_RELOAD_PROPS, preserveScroll: true },
        );
    });

    return (
        <>
            <Head title={`History · Calendar · ${monthLabel}`} />
            <AiOutageBanner />
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
                    <MonthDeferred
                        reloading={reloading}
                        data={['cells']}
                        fallback={<ConsistencyLineSkeleton />}
                    >
                        {() => (
                            <ConsistencyLine
                                stats={consistency}
                                className="mb-3"
                            />
                        )}
                    </MonthDeferred>

                    <MonthDeferred
                        reloading={reloading}
                        data={['monthlyRecap']}
                        fallback={<MonthRecapSkeleton />}
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
                    </MonthDeferred>

                    <div className="rounded-md border border-border p-3">
                        <MonthDeferred
                            reloading={reloading}
                            data={['cells', 'weeklySnapshots']}
                            fallback={<CalendarGridSkeleton />}
                        >
                            {() => (
                                <CalendarGrid
                                    weeks={weeks}
                                    snapshotsByWeek={snapshotsByWeek}
                                    onOpenKey={() => {
                                        setAskedKey(true);
                                        setKeyOpen(true);
                                    }}
                                    onOpenDay={(cell) => {
                                        setAskedDayRuns(true);
                                        setOpenDay(cell);
                                    }}
                                />
                            )}
                        </MonthDeferred>
                    </div>
                </div>

                {askedKey && (
                    <Suspense fallback={null}>
                        <EffortLegend
                            open={keyOpen}
                            onOpenChange={setKeyOpen}
                        />
                    </Suspense>
                )}

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

function MonthDeferred({
    reloading,
    data,
    fallback,
    children,
}: Readonly<{
    reloading: boolean;
    data: string[];
    fallback: ReactNode;
    children: () => ReactNode;
}>) {
    return reloading ? (
        fallback
    ) : (
        <Deferred data={data} fallback={fallback}>
            {children}
        </Deferred>
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
                label="previous month"
            />
            <h2 className="font-serif text-[0.9375rem] leading-[1.2] font-semibold text-foreground">
                {label}
            </h2>
            <NavButton
                month={nextMonth}
                icon={ChevronRight}
                label="next month"
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
