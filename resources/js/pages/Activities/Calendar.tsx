import { Deferred, Head, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useMemo, useState } from 'react';

import type { AnalysisPayload, WeeklySnapshotWithRecap } from '@/types/inertia';

import HistoryHeader from '@/components/history/HistoryHeader';
import RecapCard from '@/components/history/RecapCard';
import CalendarGridA from '@/components/prototype/CalendarGridA';
import CalendarGridB from '@/components/prototype/CalendarGridB';
import CalendarGridC from '@/components/prototype/CalendarGridC';
import {
    calendarUrl,
    consistencyOf,
    type ProtoCell,
    readVariant,
    VARIANT_LABEL,
    VARIANTS,
    withFakeMultiRun,
} from '@/components/prototype/calendarProto';
import {
    ConsistencyLine,
    DayRunsSheet,
    EffortLegend,
} from '@/components/prototype/CalendarProtoParts';
import PrototypeSwitcher from '@/components/prototype/PrototypeSwitcher';
import { Icon, IconComponent } from '@/components/ui/Icon';
import PageContainer from '@/components/ui/PageContainer';
import Skeleton, { SkeletonRows } from '@/components/ui/Skeleton';
import { useHorizontalSwipe } from '@/hooks/useHorizontalSwipe';
import { appLayout } from '@/layouts/appLayout';

import { useCalendar, type CalendarCell } from './useCalendar';
import { snapshotsByWeekEnding } from './weekBuckets';

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

// PROTOTYPE — throwaway, calendar redesign variants: this page is rewired to the ?variant= grids.
const MONTH_PROPS = [
    'month',
    'monthLabel',
    'prevMonth',
    'nextMonth',
    'todayMonth',
    'cells',
    'weeklySnapshots',
    'monthlyRecap',
];

const GRIDS = { A: CalendarGridA, B: CalendarGridB, C: CalendarGridC };

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
    const { url } = usePage();
    const variant = readVariant(url);
    const protoCells = useMemo(() => withFakeMultiRun(cells), [cells]);
    const { weeks, dominantMood, isCurrentMonth } = useCalendar({
        cells: protoCells,
        month,
        todayMonth,
    });
    const stats = useMemo(() => consistencyOf(protoCells), [protoCells]);
    const [openDay, setOpenDay] = useState<ProtoCell | null>(null);

    const snapshotsByWeek = useMemo(
        () => snapshotsByWeekEnding(weeklySnapshots),
        [weeklySnapshots],
    );

    const goMonth = (target: string) =>
        router.get(
            calendarUrl(target, variant),
            {},
            { only: MONTH_PROPS, preserveState: true, preserveScroll: true },
        );
    const swipe = useHorizontalSwipe((direction) =>
        goMonth(direction === 'left' ? nextMonth : prevMonth),
    );

    const Grid = GRIDS[variant];
    const legend = <EffortLegend className="mt-4" />;

    return (
        <>
            <Head title={`History · Calendar · ${monthLabel}`} />
            <PageContainer className="pb-24">
                <HistoryHeader
                    active="calendar"
                    activityCount={lifetime?.total_runs}
                />

                <div className="mt-8 mb-2">
                    <MonthNav
                        label={monthLabel}
                        onPrev={() => goMonth(prevMonth)}
                        onNext={() => goMonth(nextMonth)}
                    />
                </div>

                <Deferred
                    data={['cells']}
                    fallback={<Skeleton className="mx-auto mb-4 h-3 w-60" />}
                >
                    {() => <ConsistencyLine stats={stats} className="mb-4" />}
                </Deferred>

                {variant === 'B' && (
                    <EffortLegend className="mb-4 justify-center" />
                )}

                <div className="touch-pan-y" {...swipe}>
                    <Deferred
                        data={['cells', 'weeklySnapshots']}
                        fallback={<SkeletonRows count={6} />}
                    >
                        {() => (
                            <div key={month} className="reveal">
                                <Grid
                                    weeks={weeks}
                                    snapshotsByWeek={snapshotsByWeek}
                                    onOpenDay={setOpenDay}
                                />
                            </div>
                        )}
                    </Deferred>
                </div>

                {variant !== 'B' && legend}

                <Deferred data={['cells', 'monthlyRecap']} fallback={<span />}>
                    {() =>
                        monthlyRecap ? (
                            <RecapCard
                                mood={dominantMood}
                                analysis={monthlyRecap}
                                awaitingSchedule={isCurrentMonth}
                                awaitingScheduleLabel="this month's recap isn't ready yet."
                                isChainHead={monthlyRecap.is_chain_head}
                                size="month"
                                inertiaReloadProps={['monthlyRecap']}
                                className="mt-6"
                            />
                        ) : null
                    }
                </Deferred>
            </PageContainer>
            <DayRunsSheet cell={openDay} onClose={() => setOpenDay(null)} />
            <PrototypeSwitcher
                variants={VARIANTS}
                labels={VARIANT_LABEL}
                current={variant}
            />
        </>
    );
}

function MonthNav({
    label,
    onPrev,
    onNext,
}: Readonly<{
    label: string;
    onPrev: () => void;
    onNext: () => void;
}>) {
    return (
        <div className="flex w-full items-center justify-between gap-2">
            <NavButton
                onClick={onPrev}
                icon={ChevronLeft}
                label="Previous month"
            />
            <h2 className="font-serif text-headline-sm text-foreground">
                {label}
            </h2>
            <NavButton
                onClick={onNext}
                icon={ChevronRight}
                label="Next month"
            />
        </div>
    );
}

function NavButton({
    onClick,
    icon,
    label,
}: Readonly<{ onClick: () => void; icon: IconComponent; label: string }>) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={label}
            className="pressable focus-ring flex size-9 flex-none items-center justify-center rounded-full bg-card text-foreground shadow-e1"
        >
            <Icon icon={icon} width={16} height={16} aria-hidden />
        </button>
    );
}

Calendar.layout = appLayout;
