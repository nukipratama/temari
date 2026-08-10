import type { SeasonPhase } from '@/components/temari/TemariProto';

interface PhasedWeek {
    phase: string;
    type: 'history' | 'current' | 'lookahead';
}

/**
 * Resolves the season's current visual phase for the mascot's thread-coverage
 * tie-in (Plan tab only). Walks back from the current week past any `deload`
 * weeks to the last real phase, so a self-scaled deload week pauses coverage
 * accretion instead of resetting it — the ball never looks "less wound" than
 * it already was.
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
