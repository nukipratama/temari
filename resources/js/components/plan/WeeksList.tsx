import { ChevronRight } from 'lucide-react';

import type { PlanWeek, SeasonSummaryWeek } from '@/lib/plan';

import Chip from '@/components/ui/Chip';
import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import {
    computeAdherence,
    PHASE_LABEL,
    phaseGroupKey,
    weekRangeLabel,
} from '@/lib/plan';
import { cardVariants } from '@/lib/variants';

function weekKm(week: SeasonSummaryWeek): string {
    if (week.type !== 'history') {
        return `${Math.round(week.planned_km)} km`;
    }

    return week.actual_km === null ? '—' : `${Math.round(week.actual_km)} km`;
}

/**
 * Every week of the season as one compact row: its number, dates, phase and
 * km (what was run for a week behind, what is planned otherwise), plus the
 * adherence of a week behind that the page holds day rows for. A row the
 * page holds day rows for is a button that shows that week above; the rest
 * are plain summary rows.
 */
export default function WeeksList({
    weeks,
    detailByWeekStart,
    shownWeekStart,
    onShow,
}: Readonly<{
    weeks: SeasonSummaryWeek[];
    detailByWeekStart: Record<string, PlanWeek>;
    shownWeekStart: string;
    onShow: (weekStart: string) => void;
}>) {
    return (
        <ol
            aria-label="season weeks"
            className={cn(
                cardVariants({ padding: 'none' }),
                'divide-y divide-border border-border-strong',
            )}
        >
            {weeks.map((week, index) => {
                const detail = detailByWeekStart[week.week_start] ?? null;
                const adherence =
                    week.type === 'history' && detail !== null
                        ? computeAdherence(detail.days)
                        : null;
                const shown = week.week_start === shownWeekStart;

                const content = (
                    <>
                        <span className="w-16 flex-none text-label-micro text-text-2">
                            Week {index + 1}
                        </span>
                        <span className="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1">
                            <span className="text-sm font-semibold text-foreground">
                                {weekRangeLabel(week.week_start)}
                            </span>
                            <Chip>
                                {PHASE_LABEL[phaseGroupKey(week)] ?? week.phase}
                            </Chip>
                            {week.type === 'current' && (
                                <span className="text-label-micro text-horizon-ink">
                                    this week
                                </span>
                            )}
                        </span>
                        <span className="flex-none text-right font-mono text-xs tabular-nums text-text-2">
                            {weekKm(week)}
                            {adherence != null && (
                                <span className="block text-text-3">
                                    {adherence}%
                                </span>
                            )}
                        </span>
                    </>
                );

                return (
                    <li key={week.week_start}>
                        {detail === null ? (
                            <div className="flex items-center gap-3 py-2.5 pr-11 pl-4">
                                {content}
                            </div>
                        ) : (
                            <button
                                type="button"
                                aria-pressed={shown}
                                onClick={() => onShow(week.week_start)}
                                className={cn(
                                    'focus-ring flex w-full items-center gap-3 border-l-2 py-2.5 pr-4 pl-3.5 text-left transition-colors hover:bg-muted',
                                    shown
                                        ? 'border-icon-accent'
                                        : 'border-transparent',
                                )}
                            >
                                {content}
                                <Icon
                                    icon={ChevronRight}
                                    className="size-4 flex-none text-text-2"
                                    aria-hidden
                                />
                            </button>
                        )}
                    </li>
                );
            })}
        </ol>
    );
}
