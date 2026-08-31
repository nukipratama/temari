import { useState } from 'react';

import type { PlanDay, PlanWeek, SeasonSummaryWeek } from '@/lib/plan';
import type { AnalysisPayload } from '@/types/inertia';

import SeasonWeekRow from '@/components/plan/SeasonWeekRow';
import WeekCluster from '@/components/plan/WeekCluster';
import { PHASE_LABEL } from '@/lib/plan';

function plural(count: number, noun: string): string {
    return `${count} ${noun}${count === 1 ? '' : 's'}`;
}

/** The season split into runs of consecutive same-phase weeks, in season order. */
function contiguousPhaseRuns(
    weeks: SeasonSummaryWeek[],
): SeasonSummaryWeek[][] {
    const runs: SeasonSummaryWeek[][] = [];
    for (const week of weeks) {
        const last = runs[runs.length - 1];
        if (last && last[0].phase === week.phase) {
            last.push(week);
        } else {
            runs.push([week]);
        }
    }
    return runs;
}

/**
 * The season as a rail: the current phase in full, everything already behind
 * the athlete in it folded into one "N weeks behind" row, and every later
 * phase folded into "N weeks ahead" until asked for. Each week that the plan
 * has day-level rows for opens into its own chart and day list.
 */
export default function SeasonTimeline({
    weeks,
    detailByWeekStart,
    today,
    weekFocus,
    weekNarration,
    dayNarration,
    onMove,
    onSkip,
}: Readonly<{
    weeks: SeasonSummaryWeek[];
    detailByWeekStart: Record<string, PlanWeek>;
    today: string;
    /** The current week's adaptation verdict, shown as its focus line. */
    weekFocus: { headline: string; detail: string } | null;
    weekNarration: AnalysisPayload | null;
    dayNarration: Record<string, AnalysisPayload>;
    onMove: (day: PlanDay, toDate: string) => void;
    onSkip: (day: PlanDay) => void;
}>) {
    const [pastOpen, setPastOpen] = useState(false);
    const [futureOpen, setFutureOpen] = useState(false);

    const currentIndex = weeks.findIndex((w) => w.type === 'current');
    if (currentIndex === -1) {
        return null;
    }

    const current = weeks[currentIndex];
    const numberOf = (week: SeasonSummaryWeek) => weeks.indexOf(week) + 1;

    // The phase block is the contiguous run holding the current week, not
    // every week sharing its name: a self-scaled season cycles build/deload
    // repeatedly, so each pass through a phase is its own block of the
    // timeline and the weeks between two Build blocks are not "in" either.
    const runs = contiguousPhaseRuns(weeks);
    const currentRun = runs.find((run) => run.includes(current)) ?? [current];
    const runIndex = runs.indexOf(currentRun);

    const pastInPhase = currentRun.slice(0, currentRun.indexOf(current));
    const futureInPhase = currentRun.slice(currentRun.indexOf(current) + 1);
    const laterPhaseRuns = runs.slice(runIndex + 1);
    const laterPhaseWeeks = laterPhaseRuns.flat();

    const row = (week: SeasonSummaryWeek, isLast: boolean) => (
        <SeasonWeekRow
            key={week.week_start}
            week={week}
            weekNumber={numberOf(week)}
            detail={detailByWeekStart[week.week_start] ?? null}
            isLast={isLast}
            today={today}
            focus={week.type === 'current' ? weekFocus : null}
            narration={week.type === 'current' ? weekNarration : null}
            dayNarration={week.type === 'current' ? dayNarration : {}}
            onMove={onMove}
            onSkip={onSkip}
        />
    );

    const currentIsLast =
        futureInPhase.length === 0 && laterPhaseWeeks.length === 0;

    return (
        <div className="flex flex-col gap-4">
            <div>
                <p className="mb-2 text-label-micro text-text-2">
                    {PHASE_LABEL[current.phase] ?? current.phase} phase
                </p>
                <div className="flex flex-col">
                    {pastInPhase.length > 0 &&
                        (pastOpen ? (
                            pastInPhase.map((w) => row(w, false))
                        ) : (
                            <WeekCluster
                                weeks={pastInPhase}
                                label={`${plural(pastInPhase.length, 'week')} behind`}
                                isLast={false}
                                onExpand={() => setPastOpen(true)}
                            />
                        ))}
                    {row(current, currentIsLast)}
                    {futureInPhase.map((w, i) =>
                        row(
                            w,
                            i === futureInPhase.length - 1 &&
                                laterPhaseWeeks.length === 0,
                        ),
                    )}
                </div>
            </div>

            {laterPhaseWeeks.length > 0 &&
                (futureOpen ? (
                    laterPhaseRuns.map((phaseWeeks) => (
                        <div key={phaseWeeks[0].week_start}>
                            <p className="mb-2 text-label-micro text-text-2">
                                {PHASE_LABEL[phaseWeeks[0].phase] ??
                                    phaseWeeks[0].phase}{' '}
                                phase
                            </p>
                            <div className="flex flex-col">
                                {phaseWeeks.map((w, i) =>
                                    row(w, i === phaseWeeks.length - 1),
                                )}
                            </div>
                        </div>
                    ))
                ) : (
                    <WeekCluster
                        weeks={laterPhaseWeeks}
                        label={`${plural(laterPhaseWeeks.length, 'week')} ahead`}
                        isLast
                        onExpand={() => setFutureOpen(true)}
                    />
                ))}
        </div>
    );
}
