import { Lock } from 'lucide-react';

import type { PlanDay } from '@/lib/plan';

import { dayChanges } from '@/components/plan/DayDetail';
import { DeltaPair } from '@/components/plan/DeltaPair';
import { Icon } from '@/components/ui/Icon';
import { weekdayLabel } from '@/lib/plan';

type WeekChange = {
    date: string;
    from: string;
    to: string;
    state: string | null;
    locked: boolean;
};

function changeOf(day: PlanDay): WeekChange | null {
    const {
        droppedDelta,
        budgetDelta,
        hardDayDelta,
        sessionDelta,
        paceDelta,
        weekFitDelta,
    } = dayChanges(day);
    const pair =
        droppedDelta ??
        hardDayDelta ??
        (sessionDelta?.typeFrom != null
            ? { from: sessionDelta.typeFrom, to: sessionDelta.typeTo }
            : null) ??
        (sessionDelta?.distanceFrom != null
            ? {
                  from: sessionDelta.distanceFrom,
                  to: `${sessionDelta.distanceTo} km`,
              }
            : null) ??
        (budgetDelta ? { from: budgetDelta.from, to: budgetDelta.to } : null) ??
        paceDelta ??
        (weekFitDelta
            ? { from: weekFitDelta.from, to: `${weekFitDelta.to} km` }
            : null);
    if (pair === null) {
        return null;
    }

    const override = day.override;
    return {
        date: day.date,
        ...pair,
        state:
            override?.state === 'offered'
                ? 'can bring back'
                : override?.state === 'locked'
                  ? override.blocker
                  : override?.state === 'taken'
                    ? 'brought back'
                    : null,
        locked: override?.state === 'locked',
    };
}

/**
 * Every day this week the plan changed, in one list: was → now, and whether it
 * can be brought back or which rule keeps it. Picking a row opens that day.
 */
export default function WeekChanges({
    days,
    onSelect,
}: Readonly<{ days: PlanDay[]; onSelect: (date: string) => void }>) {
    const changes = days
        .map(changeOf)
        .filter((change): change is WeekChange => change !== null);
    if (changes.length === 0) {
        return null;
    }

    return (
        <div>
            <p className="text-label-micro text-text-3">changed this week</p>
            <ul className="mt-1 divide-y divide-border">
                {changes.map((change) => (
                    <li key={change.date}>
                        <button
                            type="button"
                            onClick={() => onSelect(change.date)}
                            className="pressable focus-ring flex w-full flex-wrap items-center gap-x-2 gap-y-0.5 py-1.5 text-left text-xs"
                        >
                            <span className="w-8 flex-none text-label-micro text-text-2">
                                {weekdayLabel(change.date).toLowerCase()}
                            </span>
                            <DeltaPair
                                from={change.from}
                                to={change.to}
                                direction="neutral"
                            />
                            {change.state !== null && (
                                <span className="ml-auto flex items-center gap-1 text-label-micro text-text-2">
                                    {change.locked && (
                                        <Icon
                                            icon={Lock}
                                            className="size-3"
                                            aria-label="locked"
                                        />
                                    )}
                                    {change.state}
                                </span>
                            )}
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}
