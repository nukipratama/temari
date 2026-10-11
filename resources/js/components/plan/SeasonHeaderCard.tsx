import { Link } from '@inertiajs/react';

import type { SeasonSummaryWeek } from '@/lib/plan';
import type { AnalysisPayload, PlanRaceCard } from '@/types/inertia';

import PhaseRibbon from '@/components/plan/PhaseRibbon';
import TemariTake from '@/components/plan/TemariTake';
import Eyebrow from '@/components/ui/Eyebrow';
import { formatNaiveMonthDayId, formatRaceTime } from '@/lib/pace';
import {
    nextPhaseOf,
    PHASE_LABEL,
    seasonPhaseKey,
    weeksLabel,
} from '@/lib/plan';
import { raceDistanceLabel } from '@/lib/raceGoal';
import { pillButtonVariants } from '@/lib/variants';

function RaceSlot({ raceCard }: Readonly<{ raceCard: PlanRaceCard | null }>) {
    if (raceCard === null) {
        return (
            <div className="mt-3 flex flex-col items-start gap-2 border-t border-dashed border-border pt-3">
                <p className="text-sm text-text-2">
                    add a race and the plan builds base → build → peak → taper
                    to it.
                </p>
                <Link
                    href="/race"
                    className={pillButtonVariants({
                        tone: 'muted',
                        size: 'xs',
                    })}
                >
                    + add a race
                </Link>
            </div>
        );
    }

    if (
        raceCard.state !== 'result_recorded' ||
        raceCard.finish_time_sec === null
    ) {
        return null;
    }

    return (
        <p className="mt-1 font-mono text-xs text-text-2 tabular-nums">
            {formatNaiveMonthDayId(raceCard.race_date)} ·{' '}
            {raceDistanceLabel(raceCard.distance_m)}{' '}
            {formatRaceTime(raceCard.finish_time_sec)}
            {raceCard.finish_time_sec <= raceCard.goal_time_sec && (
                <span className="text-horizon-ink"> ✓</span>
            )}
            {' · '}
            <Link
                href="/race"
                className="focus-ring hit-area font-semibold text-horizon-ink"
            >
                next race ›
            </Link>
        </p>
    );
}

/**
 * The season at a glance: the phase the athlete is in as a headline, what
 * comes next and how closely the plan has been followed, over the phase
 * ribbon, then the under-ready line and Temari's read
 * on the season so far.
 */
export default function SeasonHeaderCard({
    weekIndex,
    totalWeeks,
    adherencePct,
    weeks,
    raceCard,
    narration,
    underReadyLine = null,
}: Readonly<{
    weekIndex: number;
    totalWeeks: number;
    adherencePct: number | null;
    weeks: SeasonSummaryWeek[];
    /** No race, or a result recorded this past week, shows under the meta line. */
    raceCard: PlanRaceCard | null;
    narration: AnalysisPayload | null;
    /** Served once, when the season opened with less than a full race block left. */
    underReadyLine?: string | null;
}>) {
    const currentWeek = weeks.find((w) => w.type === 'current');
    const currentGroupKey =
        currentWeek === undefined ? undefined : seasonPhaseKey(currentWeek);
    const next = nextPhaseOf(weeks);
    const nextLine =
        next &&
        `next: ${PHASE_LABEL[next.key] ?? next.key} in ${weeksLabel(next.inWeeks)}`;

    return (
        <section>
            <Eyebrow token="small" tone="ink-2">
                season · week {weekIndex} of {totalWeeks}
            </Eyebrow>
            {currentGroupKey && (
                <h2 className="mt-2 font-serif text-quote-lg text-foreground italic">
                    {PHASE_LABEL[currentGroupKey] ?? currentGroupKey}{' '}
                    <em className="text-horizon-ink">week.</em>
                </h2>
            )}
            {(nextLine || adherencePct != null) && (
                <p className="mt-1 font-mono text-xs text-text-2">
                    {nextLine}
                    {nextLine && adherencePct != null && ' · '}
                    {adherencePct != null && (
                        <>
                            <span className="font-bold tabular-nums text-horizon-ink">
                                {adherencePct}%
                            </span>{' '}
                            adherence
                        </>
                    )}
                </p>
            )}
            <RaceSlot raceCard={raceCard} />
            <PhaseRibbon weeks={weeks} />

            {underReadyLine && (
                <p className="mt-3 text-xs text-text-2">{underReadyLine}</p>
            )}

            {narration && (
                <TemariTake
                    analysis={narration}
                    className="mt-3 border-t border-dashed border-border pt-3"
                />
            )}
        </section>
    );
}
