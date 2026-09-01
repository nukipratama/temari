import type { SeasonSummaryWeek } from '@/lib/plan';
import type { AnalysisPayload } from '@/types/inertia';

import TemariTake from '@/components/plan/TemariTake';
import Card from '@/components/ui/LegacyCard';
import { PHASE_COLORS, type PlanPhaseKey } from '@/lib/chartTokens';
import { cn } from '@/lib/cn';
import { formatNaiveMonthDayId } from '@/lib/pace';
import { PHASE_LABEL, phasesOf, type Phase } from '@/lib/plan';

/** Shortest bar in the arc, as a percentage of the tallest. */
const MIN_BAR_PCT = 35;

function phaseColor(phase: string): string {
    return PHASE_COLORS[phase as PlanPhaseKey] ?? 'var(--color-text-3)';
}

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
 * The season at a glance: where this week sits in it, how closely the plan
 * has been followed, the volume arc phase by phase, and Temari's read on the
 * season so far.
 */
export default function SeasonHeaderCard({
    weekIndex,
    totalWeeks,
    startsAt,
    endsAt,
    adherencePct,
    weeks,
    narration,
}: Readonly<{
    weekIndex: number;
    totalWeeks: number;
    startsAt: string;
    endsAt: string;
    adherencePct: number | null;
    weeks: SeasonSummaryWeek[];
    narration: AnalysisPayload | null;
}>) {
    const phases = phasesOf(weeks);
    const currentPhase = weeks.find((w) => w.type === 'current')?.phase;

    return (
        <Card padding="panel" className="mb-3 border-border-strong">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-label-micro text-text-2">
                        Season · Week {weekIndex} of {totalWeeks}
                    </p>
                    <p className="mt-1 text-xs text-text-2">
                        {currentPhase
                            ? `${PHASE_LABEL[currentPhase] ?? currentPhase} · `
                            : ''}
                        {formatNaiveMonthDayId(startsAt)} –{' '}
                        {formatNaiveMonthDayId(endsAt)}
                    </p>
                </div>
                {adherencePct != null && (
                    <div className="flex-none text-right">
                        <p className="font-mono text-xl font-bold tabular-nums text-horizon-ink">
                            {adherencePct}%
                        </p>
                        <p className="text-label-micro text-text-2">
                            Adherence
                        </p>
                    </div>
                )}
            </div>

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
                                            upcoming && 'border border-dashed',
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

            {narration && <TemariTake analysis={narration} className="mt-3" />}
        </Card>
    );
}
