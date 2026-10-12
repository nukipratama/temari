import type { SeasonSummaryWeek } from '@/lib/plan';

export type PhaseState = 'done' | 'current' | 'upcoming';

export interface Phase {
    key: string;
    avgKm: number;
    state: PhaseState;
}

/**
 * One entry per distinct phase of the season, in season order, each with its
 * mean weekly volume and where the athlete stands in it. Built from the real
 * phase sequence rather than a fixed base/build/peak/taper four, so a
 * self-scaled season's repeating build/deload cycle renders honestly. Read by
 * Profile's season card.
 */
export function phasesOf(weeks: SeasonSummaryWeek[]): Phase[] {
    const order: string[] = [];
    const totals = new Map<string, { km: number; count: number }>();
    const states = new Map<string, PhaseState>();

    for (const week of weeks) {
        const key = phaseGroupKey(week);
        if (!totals.has(key)) {
            order.push(key);
            totals.set(key, { km: 0, count: 0 });
        }
        const total = totals.get(key)!;
        total.km += week.planned_km;
        total.count += 1;

        const seen = states.get(key);
        if (week.type === 'current') {
            states.set(key, 'current');
        } else if (seen === undefined) {
            states.set(key, week.type === 'history' ? 'done' : 'upcoming');
        } else if (seen === 'done' && week.type === 'lookahead') {
            states.set(key, 'upcoming');
        }
    }

    return order.map((key) => {
        const total = totals.get(key)!;
        return {
            key,
            avgKm: total.km / total.count,
            state: states.get(key) ?? 'upcoming',
        };
    });
}

/** The shared group key every week before the race block opens displays under, regardless of the self-scaled cycle's own build/deload phase. */
export const GENERAL_PHASE_KEY = 'general';

/**
 * The identity a week displays as: its own phase inside the race block, or
 * one shared "maintain" identity for every `zone: general` week — the
 * self-scaled cycle alternates Build/Deload before the block opens, and
 * naming that would read as noise rather than as the season's real arc. Used
 * everywhere a week's phase would otherwise be shown or grouped: the phase
 * ribbon, the phase legend ({@see phasesOf}), and the timeline's phase runs.
 */
export function phaseGroupKey(week: SeasonSummaryWeek): string {
    return week.zone === 'general' ? GENERAL_PHASE_KEY : week.phase;
}

/** The season card's phase: like phaseGroupKey, but a deload inside the general zone still reads as one. */
export function seasonPhaseKey(week: SeasonSummaryWeek): string {
    return week.zone === 'general' && week.phase === 'deload'
        ? 'deload'
        : phaseGroupKey(week);
}

export const PHASE_LABEL: Record<string, string> = {
    base: 'base',
    build: 'build',
    peak: 'peak',
    taper: 'taper',
    deload: 'deload',
    [GENERAL_PHASE_KEY]: 'maintain',
};
