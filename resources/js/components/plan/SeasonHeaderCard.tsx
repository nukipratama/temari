import { ChevronDown } from 'lucide-react';

import type { SeasonSummaryWeek } from '@/lib/plan';
import type { AnalysisPayload } from '@/types/inertia';

import PhaseRibbon from '@/components/plan/PhaseRibbon';
import TemariTake from '@/components/plan/TemariTake';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Icon } from '@/components/ui/Icon';
import { phaseColor } from '@/lib/chartTokens';
import { cn } from '@/lib/cn';
import { formatNaiveMonthDayId } from '@/lib/pace';
import {
    generalZoneSpan,
    PHASE_LABEL,
    phaseGroupKey,
    phasesOf,
    type Phase,
} from '@/lib/plan';
import { cardVariants } from '@/lib/variants';

/** Shortest bar in the arc, as a percentage of the tallest. */
const MIN_BAR_PCT = 35;

function barHeightPct(phase: Phase, phases: Phase[]): number {
    const values = phases.map((p) => p.avgKm);
    const min = Math.min(...values);
    const max = Math.max(...values);
    if (max === min) {
        return 100;
    }
    return (
        MIN_BAR_PCT + ((phase.avgKm - min) / (max - min)) * (100 - MIN_BAR_PCT)
    );
}

/**
 * The season as a one-line band: where this week sits in it, how closely the
 * plan has been followed and the phase ribbon. Opening it shows the volume arc
 * phase by phase and Temari's read on the season so far.
 */
export default function SeasonHeaderCard({
    weekIndex,
    totalWeeks,
    startsAt,
    endsAt,
    adherencePct,
    weeks,
    narration,
    underReadyLine = null,
}: Readonly<{
    weekIndex: number;
    totalWeeks: number;
    startsAt: string;
    endsAt: string;
    adherencePct: number | null;
    weeks: SeasonSummaryWeek[];
    narration: AnalysisPayload | null;
    /** Served once, when the season opened with less than a full race block left. */
    underReadyLine?: string | null;
}>) {
    const phases = phasesOf(weeks);
    const currentWeek = weeks.find((w) => w.type === 'current');
    const currentGroupKey =
        currentWeek === undefined ? undefined : phaseGroupKey(currentWeek);
    // A general-zone header names its own run's span, not the season's —
    // "maintain" spanning all the way to race day would read as the block
    // belonging to it too.
    const headerSpan =
        currentWeek?.zone === 'general'
            ? (generalZoneSpan(weeks) ?? { start: startsAt, end: endsAt })
            : { start: startsAt, end: endsAt };

    return (
        <Collapsible
            className={cn(
                cardVariants({ padding: 'none' }),
                'mb-3 overflow-hidden border-border-strong',
            )}
        >
            <CollapsibleTrigger className="group focus-ring flex w-full items-center gap-3 px-3 pt-2.5 text-left">
                <span className="min-w-0 flex-1 text-label-micro text-text-2">
                    Week {weekIndex} of {totalWeeks}
                    {currentGroupKey
                        ? ` · ${PHASE_LABEL[currentGroupKey] ?? currentGroupKey}`
                        : ''}
                </span>
                {adherencePct != null && (
                    <span className="flex-none text-label-micro text-text-3">
                        <span className="font-mono text-sm font-bold tabular-nums text-horizon-ink">
                            {adherencePct}%
                        </span>{' '}
                        adherence
                    </span>
                )}
                <Icon
                    icon={ChevronDown}
                    className="size-4 flex-none text-text-2 transition-transform group-aria-expanded:rotate-180"
                    aria-hidden
                />
            </CollapsibleTrigger>
            <div className="px-3 pb-2.5">
                <PhaseRibbon weeks={weeks} />
            </div>
            <CollapsibleContent className="border-t border-border-strong px-3 py-3">
                <p className="text-xs text-text-2">
                    {formatNaiveMonthDayId(headerSpan.start)} –{' '}
                    {formatNaiveMonthDayId(headerSpan.end)}
                </p>

                {phases.length > 0 && (
                    <div className="mt-3 flex items-end gap-1.5">
                        {phases.map((phase) => {
                            const color = phaseColor(phase.key);
                            const upcoming = phase.state === 'upcoming';
                            return (
                                <div
                                    key={phase.key}
                                    className="flex flex-1 flex-col items-center gap-1"
                                >
                                    <div className="flex h-8 w-full items-end overflow-hidden rounded-xs">
                                        <div
                                            className={cn(
                                                'w-full rounded-t-xs',
                                                upcoming &&
                                                    'border border-dashed',
                                            )}
                                            style={{
                                                height: `${barHeightPct(phase, phases)}%`,
                                                backgroundColor: upcoming
                                                    ? `color-mix(in oklab, ${color} 16%, transparent)`
                                                    : color,
                                                borderColor: upcoming
                                                    ? color
                                                    : undefined,
                                                boxShadow:
                                                    phase.state === 'current'
                                                        ? `0 0 0 2px color-mix(in oklab, ${color} 35%, transparent)`
                                                        : undefined,
                                            }}
                                        />
                                    </div>
                                    <span
                                        className={cn(
                                            'text-label-micro',
                                            phase.state === 'current'
                                                ? 'text-foreground'
                                                : 'text-text-3',
                                        )}
                                    >
                                        {PHASE_LABEL[phase.key] ?? phase.key}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                )}

                {underReadyLine && (
                    <p className="mt-3 text-xs text-text-2">{underReadyLine}</p>
                )}

                {narration && (
                    <TemariTake analysis={narration} className="mt-3" />
                )}
            </CollapsibleContent>
        </Collapsible>
    );
}
