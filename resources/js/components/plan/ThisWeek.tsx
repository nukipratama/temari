import { useEffect, useId, useRef, useState } from 'react';

import type { PlanDay } from '@/lib/plan';
import type { AnalysisPayload } from '@/types/inertia';

import DayDetail, {
    DayHeadline,
    hasDayDetail,
} from '@/components/plan/DayDetail';
import WeekStrip from '@/components/plan/WeekStrip';
import FlagWrong from '@/components/temari/FlagWrong';
import { formatNaiveMonthDayId } from '@/lib/pace';
import { complianceTally, weekdayLabel } from '@/lib/plan';

function initialDate(
    days: PlanDay[],
    today: string,
    focusDay: string | null,
): string | null {
    const pick =
        days.find((day) => day.date === focusDay) ??
        days.find((day) => day.date === today) ??
        days.find(
            (day) =>
                day.session_type !== 'rest' &&
                day.status === 'planned' &&
                !day.skipped,
        ) ??
        days[0];

    return pick?.date ?? null;
}

/**
 * The current week's body: how it has gone so far, the week's adaptation
 * note in full, a strip of seven day tiles and, below it, the selected day's
 * panel. Opens on the day `/plan?day=` asked for when it falls in this week,
 * else today.
 */
export default function ThisWeek({
    days,
    today,
    focus,
    dayNarration,
    focusDay = null,
    onMove,
    onSkip,
}: Readonly<{
    days: PlanDay[];
    today: string;
    /** The periodizer's adaptation verdict for this week, where one exists. */
    focus: { headline: string; detail: string } | null;
    dayNarration: Record<string, AnalysisPayload>;
    /** The day the visitor arrived asking for, from `/plan?day=`. */
    focusDay?: string | null;
    onMove: (day: PlanDay, toDate: string) => void;
    onSkip: (day: PlanDay) => void;
}>) {
    const baseId = useId();
    const panelId = `${baseId}-panel`;
    const tabId = (date: string) => `${baseId}-day-${date}`;
    const [selectedDate, setSelectedDate] = useState(() =>
        initialDate(days, today, focusDay),
    );
    const panelRef = useRef<HTMLDivElement>(null);
    const focusHere = days.some((day) => day.date === focusDay);

    useEffect(() => {
        if (focusHere) {
            panelRef.current?.scrollIntoView({ block: 'center' });
        }
    }, [focusHere]);

    const selected = days.find((day) => day.date === selectedDate) ?? null;
    const narration =
        selected === null ? null : (dayNarration[selected.date] ?? null);
    const tally = complianceTally(days);

    return (
        <div className="flex flex-col gap-3">
            {tally !== '' && (
                <p className="text-xs text-text-2">{tally} so far</p>
            )}
            {focus && (
                <div className="rounded-md bg-muted pad-panel">
                    <p className="text-sm font-semibold text-foreground">
                        {focus.headline}
                    </p>
                    <p className="mt-1 text-sm leading-relaxed text-text-2">
                        {focus.detail}
                    </p>
                </div>
            )}
            {days.length > 0 && (
                <WeekStrip
                    days={days}
                    today={today}
                    selectedDate={selectedDate}
                    onSelect={setSelectedDate}
                    tabId={tabId}
                    panelId={panelId}
                />
            )}
            {selected !== null && (
                <div
                    ref={panelRef}
                    role="tabpanel"
                    id={panelId}
                    aria-labelledby={tabId(selected.date)}
                    className="border-t border-border-strong pt-3"
                >
                    <div className="flex items-start gap-2">
                        <div className="min-w-0 flex-1">
                            <p className="text-label-micro text-text-3">
                                {weekdayLabel(selected.date)} ·{' '}
                                {formatNaiveMonthDayId(selected.date)}
                                {selected.date === today && ' · today'}
                            </p>
                            <div className="mt-1">
                                <DayHeadline day={selected} />
                            </div>
                        </div>
                        <FlagWrong
                            subjectType="plan_day"
                            subjectId={selected.id}
                            label="flag this day"
                            flagged={selected.flagged === true}
                        />
                    </div>
                    {hasDayDetail(selected, days, today, narration) && (
                        <div className="mt-3">
                            <DayDetail
                                key={selected.date}
                                day={selected}
                                weekDays={days}
                                today={today}
                                narration={narration}
                                onMove={(toDate) => onMove(selected, toDate)}
                                onSkip={() => onSkip(selected)}
                            />
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
