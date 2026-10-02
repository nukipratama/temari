import { ArrowLeft } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';

import type { PlanDay, SeasonSummaryWeek } from '@/lib/plan';
import type { AnalysisPayload } from '@/types/inertia';

import DayDetail, {
    DayHeadline,
    hasDayDetail,
} from '@/components/plan/DayDetail';
import { DeltaPair } from '@/components/plan/DeltaPair';
import WeekStrip from '@/components/plan/WeekStrip';
import FlagWrong from '@/components/temari/FlagWrong';
import Chip from '@/components/ui/Chip';
import { Icon } from '@/components/ui/Icon';
import { useHorizontalSwipe } from '@/hooks/useHorizontalSwipe';
import { useRecommendationView } from '@/hooks/useRecommendationView';
import { rememberPlanSelectedDay } from '@/lib/navigationMemory';
import { formatNaiveMonthDayId } from '@/lib/pace';
import {
    complianceTally,
    computeAdherence,
    deltaDirection,
    isRaceWeek,
    weekdayLabel,
    weekRangeLabel,
} from '@/lib/plan';

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
 * What sets this week apart from the one before it, in words rather than in a
 * bar colour: a scheduled step down, or the week the goal race lands in.
 */
function WeekMarks({
    phase,
    raceWeek,
}: Readonly<{ phase: string; raceWeek: boolean }>) {
    if (phase !== 'deload' && !raceWeek) {
        return null;
    }

    return (
        <span className="flex flex-wrap items-center gap-1.5">
            {phase === 'deload' && <Chip>deload week</Chip>}
            {raceWeek && <Chip tone="horizon">race week</Chip>}
        </span>
    );
}

/**
 * One week laid out open: its header, how it has gone so far, the week's
 * adaptation note in full, a strip of seven day tiles and, below it, the
 * selected day's panel. Opens on the day `/plan?day=` asked for or the day
 * selected earlier in this tab session when either falls in this week, else
 * today, else the first session still to run, else the first day.
 */
export default function WeekView({
    week,
    weekNumber,
    days,
    today,
    raceDate = null,
    focus,
    dayNarration,
    focusDay = null,
    selectedDay = null,
    onBack,
    onMove,
    onSkip,
    onUnskip,
}: Readonly<{
    week: SeasonSummaryWeek;
    weekNumber: number;
    days: PlanDay[];
    today: string;
    /** The goal race's date, so the week holding it can say so. */
    raceDate?: string | null;
    /** The periodizer's adaptation verdict for this week, where one exists. */
    focus: { headline: string; detail: string } | null;
    dayNarration: Record<string, AnalysisPayload>;
    /** The day the visitor arrived asking for, from `/plan?day=`. */
    focusDay?: string | null;
    /** The day selected earlier in this tab session. */
    selectedDay?: string | null;
    /** Returns to the current week; set only while another week is shown. */
    onBack?: () => void;
    onMove: (day: PlanDay, toDate: string) => void;
    onSkip: (day: PlanDay) => void;
    onUnskip: (day: PlanDay) => void;
}>) {
    const baseId = useId();
    const panelId = `${baseId}-panel`;
    const tabId = (date: string) => `${baseId}-day-${date}`;
    const [selectedDate, setSelectedDate] = useState(() =>
        initialDate(days, today, focusDay ?? selectedDay),
    );
    const panelRef = useRef<HTMLDivElement>(null);
    const focusHere = days.some((day) => day.date === focusDay);

    useEffect(() => {
        if (focusHere) {
            panelRef.current?.scrollIntoView({ block: 'center' });
        }
    }, [focusHere]);

    const selected = days.find((day) => day.date === selectedDate) ?? null;
    useRecommendationView(panelRef, selected?.recommendation_token);
    const selectDate = (date: string) => {
        setSelectedDate(date);
        rememberPlanSelectedDay(date);
    };
    const touchHandlers = useHorizontalSwipe((direction) => {
        const index = days.findIndex((day) => day.date === selectedDate);
        const next = index + (direction === 'left' ? 1 : -1);
        if (next >= 0 && next < days.length) {
            selectDate(days[next].date);
        }
    });
    const narration =
        selected === null ? null : (dayNarration[selected.date] ?? null);
    const tally = complianceTally(days);
    const adherence = computeAdherence(days);

    return (
        <section aria-label={`week ${weekNumber}`}>
            {onBack && (
                <div className="pb-3">
                    <button
                        type="button"
                        onClick={onBack}
                        className="focus-ring pressable inline-flex items-center gap-1 rounded-full bg-muted px-2.5 py-1 text-label-micro text-foreground transition-colors hover:bg-accent"
                    >
                        <Icon icon={ArrowLeft} className="size-3" aria-hidden />
                        back to this week
                    </button>
                </div>
            )}
            <div className="flex items-center gap-3">
                <span className="w-16 flex-none text-label-micro text-text-2">
                    Week {weekNumber}
                </span>
                <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-semibold text-foreground">
                            {weekRangeLabel(week.week_start)}
                        </span>
                        <WeekMarks
                            phase={week.phase}
                            raceWeek={isRaceWeek(week.week_start, raceDate)}
                        />
                    </span>
                    <span className="mt-0.5 block text-label-micro text-text-3">
                        {week.eased_from_km == null ? (
                            `${Math.round(week.planned_km)} km target · `
                        ) : (
                            <>
                                <DeltaPair
                                    from={`${Math.round(week.eased_from_km)}`}
                                    to={`${Math.round(week.planned_km)} km target`}
                                    direction={deltaDirection(
                                        week.eased_from_km,
                                        week.planned_km,
                                    )}
                                />{' '}
                                ·{' '}
                            </>
                        )}
                        {week.sessions} sessions
                        {adherence != null && ` · ${adherence}%`}
                    </span>
                </span>
            </div>
            <div className="mt-3 flex flex-col gap-3">
                {tally !== '' && (
                    <p className="text-xs text-text-2">
                        {tally}
                        {week.type === 'current' && ' so far'}
                    </p>
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
                        onSelect={selectDate}
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
                        className="touch-pan-y border-t border-dashed border-border pt-3"
                        {...touchHandlers}
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
                                    onMove={(toDate) =>
                                        onMove(selected, toDate)
                                    }
                                    onSkip={() => onSkip(selected)}
                                    onUnskip={() => onUnskip(selected)}
                                />
                            </div>
                        )}
                    </div>
                )}
            </div>
        </section>
    );
}
