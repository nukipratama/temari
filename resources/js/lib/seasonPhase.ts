/**
 * The season arc's four real phases. `deload` is deliberately not one: the
 * caller resolves a deload week back to the last non-deload phase, so progress
 * pauses instead of resetting.
 */
export type SeasonPhase = 'base' | 'build' | 'peak' | 'taper';

interface PhasedWeek {
    phase: string;
    type: 'history' | 'current' | 'lookahead';
}

/**
 * Resolves the season's current phase for the Plan tab's season summary. Walks
 * back from the current week past any `deload` weeks to the last real phase,
 * so a self-scaled deload week pauses the arc instead of resetting it.
 */
export function currentSeasonPhase(weeks: PhasedWeek[]): SeasonPhase {
    const currentIndex = weeks.findIndex((week) => week.type === 'current');
    if (currentIndex === -1) {
        return 'base';
    }
    for (let i = currentIndex; i >= 0; i--) {
        const phase = weeks[i].phase;
        if (phase !== 'deload') {
            return (phase as SeasonPhase) ?? 'base';
        }
    }
    return 'base';
}
