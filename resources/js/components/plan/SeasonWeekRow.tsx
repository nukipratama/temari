import type { PlanDay, PlanWeek, SeasonSummaryWeek } from '@/lib/plan';
import type { AnalysisPayload } from '@/types/inertia';

import TemariTake from '@/components/plan/TemariTake';
import WeekDayRow from '@/components/plan/WeekDayRow';
import WeekVolumeChart from '@/components/plan/WeekVolumeChart';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import { computeAdherence, weekRangeLabel } from '@/lib/plan';
import { cardVariants } from '@/lib/variants';

/** The dot on the season rail: filled and haloed for now, filled for done, hollow ahead. */
export function SeasonRailNode({
    type,
}: Readonly<{ type: SeasonSummaryWeek['type'] }>) {
    return (
        <span
            aria-hidden
            className={cn(
                'z-10 flex-none rounded-full',
                type === 'current' &&
                    'size-3 bg-horizon ring-4 ring-horizon/25',
                type === 'history' && 'size-2 bg-horizon',
                type === 'lookahead' &&
                    'size-2 border-2 border-border-strong bg-card',
            )}
        />
    );
}

/**
 * One week on the season rail. A week the plan has day-level rows for opens
 * into its volume chart and seven day rows; a week outside that window (older
 * history, or far enough ahead that the day plan isn't decided yet) renders as
 * a flat summary card instead.
 */
export default function SeasonWeekRow({
    week,
    weekNumber,
    detail,
    isLast,
    today,
    focus,
    narration,
    dayNarration,
    onMove,
    onSkip,
}: Readonly<{
    week: SeasonSummaryWeek;
    weekNumber: number;
    detail: PlanWeek | null;
    isLast: boolean;
    today: string;
    /** What this week is for — the periodizer's own adaptation verdict, where one exists. */
    focus: { headline: string; detail: string } | null;
    narration: AnalysisPayload | null;
    dayNarration: Record<string, AnalysisPayload>;
    onMove: (day: PlanDay, toDate: string) => void;
    onSkip: (day: PlanDay) => void;
}>) {
    const isCurrent = week.type === 'current';
    const adherence = detail === null ? null : computeAdherence(detail.days);

    return (
        <div className="flex gap-3">
            <div className="flex w-3 flex-none flex-col items-center">
                <SeasonRailNode type={week.type} />
                {!isLast && (
                    <span
                        aria-hidden
                        className={cn(
                            'mt-1 w-0.5 flex-1 rounded-full',
                            week.type === 'lookahead'
                                ? 'bg-border-strong'
                                : 'bg-horizon',
                        )}
                    />
                )}
            </div>
            <div className="min-w-0 flex-1 pb-3">
                {detail === null ? (
                    <div
                        className={cn(
                            cardVariants({ padding: 'none' }),
                            'border-border-strong px-4 py-3',
                        )}
                    >
                        <div className="flex items-center gap-3">
                            <span className="w-9 flex-none text-label-micro text-text-2">
                                Wk {weekNumber}
                            </span>
                            <p className="min-w-0 flex-1 text-sm font-semibold text-foreground">
                                {weekRangeLabel(week.week_start)}
                            </p>
                        </div>
                        <p className="mt-2 text-xs text-text-2">
                            {Math.round(week.planned_km)} km target ·{' '}
                            {week.sessions} sessions
                        </p>
                    </div>
                ) : (
                    <Collapsible
                        defaultOpen={isCurrent}
                        className={cn(
                            cardVariants({ padding: 'none' }),
                            'overflow-hidden',
                            isCurrent
                                ? 'border-icon-accent'
                                : 'border-border-strong',
                        )}
                    >
                        <CollapsibleTrigger className="group focus-ring flex w-full items-center gap-3 px-4 py-3 text-left">
                            <span className="w-9 flex-none text-label-micro text-text-2">
                                Wk {weekNumber}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block text-sm font-semibold text-foreground">
                                    {weekRangeLabel(week.week_start)}
                                </span>
                                <span className="mt-0.5 block text-label-micro text-text-3">
                                    {Math.round(week.planned_km)} km target ·{' '}
                                    {week.sessions} sessions
                                    {isCurrent && ' · this week'}
                                    {!isCurrent &&
                                        adherence != null &&
                                        ` · ${adherence}%`}
                                </span>
                            </span>
                            <Icon
                                icon="mdi:chevron-down"
                                className="size-4 flex-none text-text-2 transition-transform group-aria-expanded:rotate-180"
                                aria-hidden
                            />
                        </CollapsibleTrigger>
                        <CollapsibleContent className="border-t border-border-strong px-4 py-3">
                            {narration && (
                                <TemariTake
                                    analysis={narration}
                                    className="mb-3"
                                />
                            )}
                            {focus && (
                                <div className="mb-3">
                                    <p className="text-sm font-semibold text-foreground">
                                        {focus.headline}
                                    </p>
                                    <p className="mt-1 text-sm leading-relaxed text-text-2">
                                        {focus.detail}
                                    </p>
                                </div>
                            )}
                            <div className="flex flex-col gap-2">
                                <WeekVolumeChart
                                    days={detail.days}
                                    isCurrent={isCurrent}
                                />
                                {detail.days.map((day) => (
                                    <WeekDayRow
                                        key={day.date}
                                        day={day}
                                        weekDays={detail.days}
                                        today={today}
                                        narration={
                                            dayNarration[day.date] ?? null
                                        }
                                        onMove={(toDate) => onMove(day, toDate)}
                                        onSkip={() => onSkip(day)}
                                    />
                                ))}
                            </div>
                        </CollapsibleContent>
                    </Collapsible>
                )}
            </div>
        </div>
    );
}
