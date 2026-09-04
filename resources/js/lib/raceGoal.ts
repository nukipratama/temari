import { formatDurationHMS, formatPace } from '@/lib/pace';

/**
 * The race-goal bounds, mirrored from the server so a form cannot offer a
 * submission that is guaranteed to come back 422. `StoreRaceGoalRequest` and
 * `CompleteOnboardingRequest` both enforce these; keeping one copy here is what
 * stops the two forms drifting from each other and from the rules.
 */
export const MIN_GOAL_TIME_SEC = 300;
export const MAX_GOAL_TIME_SEC = 259_200;

// A pace floor a touch below current world-record pace (~2:31-2:51/km
// depending on distance) - not personalized to the athlete, just a sanity
// check that the numbers are physically plausible for anyone.
const IMPOSSIBLE_PACE_SEC_PER_KM = 155;

// How much faster than the athlete's own best-case (low_sec) projection
// counts as significantly more ambitious than their data supports - not
// impossible, just a real stretch worth a gut check.
export const PERSONALIZED_STRETCH_RATIO = 0.9;

/** Earliest race day the server accepts, as a local calendar date (`after:today`). */
export function earliestRaceDate(now: Date = new Date()): string {
    const date = new Date(now.getTime());
    date.setDate(date.getDate() + 1);
    const month = `${date.getMonth() + 1}`.padStart(2, '0');
    const day = `${date.getDate()}`.padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

/** Null when the goal time is submittable, otherwise the reason it is not. */
export function goalTimeError(goalTimeSec: number): string | null {
    if (goalTimeSec < MIN_GOAL_TIME_SEC) {
        return 'Goal time has to be at least 5 minutes.';
    }
    if (goalTimeSec > MAX_GOAL_TIME_SEC) {
        return 'Goal time has to be under 72 hours.';
    }

    return null;
}

/**
 * Null unless the goal implies a pace quicker than anyone could plausibly
 * run. Not gated on `goalTimeError` - a physically implausible pace can fall
 * well inside the server's raw min/max bounds.
 */
export function impossiblePaceWarning(
    distanceKm: number,
    goalTimeSec: number,
): string | null {
    if (distanceKm <= 0 || goalTimeSec <= 0) {
        return null;
    }
    const paceSecPerKm = goalTimeSec / distanceKm;
    if (paceSecPerKm >= IMPOSSIBLE_PACE_SEC_PER_KM) {
        return null;
    }

    return `That's ${formatPace(paceSecPerKm)}/km, quicker than world-record pace for most distances. Worth double-checking, but you can still save it.`;
}

/**
 * Null unless the goal is well ahead of the athlete's own projected range -
 * only meaningful when `projection` was actually computed for this exact
 * distance, since a fresh projection can't be derived client-side for an
 * arbitrary custom distance.
 */
export function ambitiousGoalWarning(
    distanceKm: number,
    goalTimeSec: number,
    projection: {
        distanceKm: number;
        lowSec: number;
        highSec: number;
    } | null,
): string | null {
    if (projection == null || goalTimeSec <= 0) {
        return null;
    }
    if (Math.abs(distanceKm - projection.distanceKm) >= 0.01) {
        return null;
    }
    if (goalTimeSec >= projection.lowSec * PERSONALIZED_STRETCH_RATIO) {
        return null;
    }

    return `That's well ahead of your own projected range (${formatDurationHMS(projection.lowSec)}–${formatDurationHMS(projection.highSec)}). Ambitious, but you can still save it.`;
}
