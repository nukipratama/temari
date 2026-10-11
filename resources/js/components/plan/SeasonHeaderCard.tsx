import type { SeasonSummaryWeek } from '@/lib/plan';
import type { AnalysisPayload } from '@/types/inertia';

import PhaseRibbon from '@/components/plan/PhaseRibbon';
import TemariTake from '@/components/plan/TemariTake';
import Eyebrow from '@/components/ui/Eyebrow';
import {
    nextPhaseOf,
    PHASE_LABEL,
    phaseGroupKey,
    weeksLabel,
    weeksToRace,
} from '@/lib/plan';

/**
 * The season at a glance: the phase the athlete is in as a headline, what
 * comes next, how far off the race is and how closely the plan has been
 * followed, over the phase ribbon, then the under-ready line and Temari's read
 * on the season so far.
 */
export default function SeasonHeaderCard({
    weekIndex,
    totalWeeks,
    adherencePct,
    weeks,
    raceDate,
    narration,
    underReadyLine = null,
}: Readonly<{
    weekIndex: number;
    totalWeeks: number;
    adherencePct: number | null;
    weeks: SeasonSummaryWeek[];
    raceDate: string | null;
    narration: AnalysisPayload | null;
    /** Served once, when the season opened with less than a full race block left. */
    underReadyLine?: string | null;
}>) {
    const currentWeek = weeks.find((w) => w.type === 'current');
    const currentGroupKey =
        currentWeek === undefined ? undefined : phaseGroupKey(currentWeek);
    const next = nextPhaseOf(weeks);
    const raceWeeks =
        currentWeek === undefined || raceDate === null
            ? null
            : weeksToRace(currentWeek.week_start, raceDate);

    const meta = [
        next &&
            `next: ${PHASE_LABEL[next.key] ?? next.key} in ${weeksLabel(next.inWeeks)}`,
        raceWeeks !== null &&
            raceWeeks >= 0 &&
            (raceWeeks === 0
                ? 'race this week'
                : `race in ${weeksLabel(raceWeeks)}`),
    ].filter(Boolean);

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
            {(meta.length > 0 || adherencePct != null) && (
                <p className="mt-1 font-mono text-xs text-text-2">
                    {meta.join(' · ')}
                    {meta.length > 0 && adherencePct != null && ' · '}
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
