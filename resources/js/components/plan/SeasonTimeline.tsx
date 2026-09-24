import { useRef, useState } from 'react';

import type { PlanDay, PlanWeek, SeasonSummaryWeek } from '@/lib/plan';
import type { AnalysisPayload } from '@/types/inertia';

import WeeksList from '@/components/plan/WeeksList';
import WeekView from '@/components/plan/WeekView';

/**
 * The season's weeks: one week laid out open, this week by default, above a
 * list of every week of the season. Picking a week the page holds day rows
 * for shows it in place of this week until the visitor goes back.
 */
export default function SeasonTimeline({
    weeks,
    detailByWeekStart,
    today,
    raceDate = null,
    weekFocus,
    dayNarration,
    focusDay = null,
    onMove,
    onSkip,
}: Readonly<{
    weeks: SeasonSummaryWeek[];
    detailByWeekStart: Record<string, PlanWeek>;
    today: string;
    /** The goal race's date, passed down so its week is marked as one. */
    raceDate?: string | null;
    /** The current week's adaptation verdict, shown as its note. */
    weekFocus: { headline: string; detail: string } | null;
    dayNarration: Record<string, AnalysisPayload>;
    /** The day the visitor arrived asking for, from `/plan?day=`. */
    focusDay?: string | null;
    onMove: (day: PlanDay, toDate: string) => void;
    onSkip: (day: PlanDay) => void;
}>) {
    const current = weeks.find((week) => week.type === 'current') ?? null;
    const [shownWeekStart, setShownWeekStart] = useState(
        () =>
            weeks.find((week) =>
                detailByWeekStart[week.week_start]?.days.some(
                    (day) => day.date === focusDay,
                ),
            )?.week_start ??
            current?.week_start ??
            null,
    );
    const [pendingFocusDay, setPendingFocusDay] = useState(focusDay);
    const viewRef = useRef<HTMLDivElement>(null);

    if (current === null) {
        return null;
    }

    const shown =
        weeks.find((week) => week.week_start === shownWeekStart) ?? current;

    const show = (weekStart: string) => {
        setShownWeekStart(weekStart);
        setPendingFocusDay(null);
        viewRef.current?.scrollIntoView({ block: 'start' });
    };

    return (
        <div className="flex flex-col gap-6">
            <div ref={viewRef} className="scroll-mt-4">
                <WeekView
                    key={shown.week_start}
                    week={shown}
                    weekNumber={weeks.indexOf(shown) + 1}
                    days={detailByWeekStart[shown.week_start]?.days ?? []}
                    today={today}
                    raceDate={raceDate}
                    focus={shown === current ? weekFocus : null}
                    dayNarration={dayNarration}
                    focusDay={pendingFocusDay}
                    onBack={
                        shown === current
                            ? undefined
                            : () => show(current.week_start)
                    }
                    onMove={onMove}
                    onSkip={onSkip}
                />
            </div>
            <WeeksList
                weeks={weeks}
                detailByWeekStart={detailByWeekStart}
                shownWeekStart={shown.week_start}
                onShow={show}
            />
        </div>
    );
}
