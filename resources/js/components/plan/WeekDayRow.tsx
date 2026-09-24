import { ChevronDown, Feather } from 'lucide-react';
import { useEffect, useRef } from 'react';

import type { PlanDay } from '@/lib/plan';
import type { AnalysisPayload } from '@/types/inertia';

import DayDetail, {
    DayHeadline,
    hasDayDetail,
} from '@/components/plan/DayDetail';
import { zoneColor } from '@/components/plan/MiniSessionBar';
import FlagWrong from '@/components/temari/FlagWrong';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import { SESSION_TYPE_ICON, weekdayLabel } from '@/lib/plan';
import { cardVariants } from '@/lib/variants';

/** The zone the day's hardest segment sits in, which colours its type icon. */
function iconColor(day: PlanDay): string {
    if (day.session_type === 'rest') {
        return day.ran_anyway ? 'var(--color-leaf)' : 'var(--color-text-3)';
    }
    const zones = day.segments.map((s) => s.zone).sort();
    return zones.length === 0
        ? 'var(--color-text-3)'
        : zoneColor(zones[zones.length - 1]);
}

/**
 * One day of a week, collapsed to weekday + session + a zone strip, expanding
 * to Temari's read on it, the session's segment breakdown, a link to what was
 * actually run, and — on a day still ahead — the move and skip actions. A day
 * whose panel would carry none of that renders flat instead: no chevron, and
 * nothing focusable that would open onto an empty panel.
 */
export default function WeekDayRow({
    day,
    weekDays,
    today,
    narration,
    focused = false,
    onMove,
    onSkip,
}: Readonly<{
    day: PlanDay;
    weekDays: PlanDay[];
    today: string;
    narration: AnalysisPayload | null;
    /** The day the visitor arrived asking for, from `/plan?day=`. */
    focused?: boolean;
    onMove: (toDate: string) => void;
    onSkip: () => void;
}>) {
    const rowRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (focused) {
            rowRef.current?.scrollIntoView({ block: 'center' });
        }
    }, [focused]);

    const expandable = hasDayDetail(day, weekDays, today, narration);

    const weekdayAndIcon = (
        <span className="flex w-9 flex-none flex-col items-center gap-1">
            <span className="text-label-micro text-text-2">
                {weekdayLabel(day.date)}
            </span>
            <Icon
                icon={SESSION_TYPE_ICON[day.session_type] ?? Feather}
                className="size-3.5"
                style={{ color: iconColor(day) }}
                aria-hidden
            />
        </span>
    );

    const summary = <DayHeadline day={day} withMiniBar />;

    const outerClass = cn(
        cardVariants({ padding: 'none' }),
        'overflow-hidden',
        day.date === today ? 'border-icon-accent' : 'border-border-strong',
    );

    if (!expandable) {
        return (
            <div ref={rowRef} className={outerClass}>
                <div className="flex items-center pr-1">
                    <div className="flex min-w-0 flex-1 items-center gap-3 py-3 pr-2 pl-4 text-left">
                        {weekdayAndIcon}
                        {summary}
                    </div>
                    <FlagWrong
                        subjectType="plan_day"
                        subjectId={day.id}
                        label="flag this day"
                        flagged={day.flagged === true}
                    />
                </div>
            </div>
        );
    }

    return (
        <Collapsible ref={rowRef} defaultOpen={focused} className={outerClass}>
            <div className="flex items-center pr-1">
                <CollapsibleTrigger className="group focus-ring flex min-w-0 flex-1 items-center gap-3 py-3 pr-2 pl-4 text-left">
                    {weekdayAndIcon}
                    {summary}
                    <Icon
                        icon={ChevronDown}
                        className="size-4 flex-none text-text-2 transition-transform group-aria-expanded:rotate-180"
                        aria-hidden
                    />
                </CollapsibleTrigger>
                <FlagWrong
                    subjectType="plan_day"
                    subjectId={day.id}
                    label="flag this day"
                    flagged={day.flagged === true}
                />
            </div>
            <CollapsibleContent className="border-t border-border-strong px-4 py-3">
                <DayDetail
                    day={day}
                    weekDays={weekDays}
                    today={today}
                    narration={narration}
                    onMove={onMove}
                    onSkip={onSkip}
                />
            </CollapsibleContent>
        </Collapsible>
    );
}
