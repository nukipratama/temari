/**
 * The race-goal bounds, mirrored from the server so a form cannot offer a
 * submission that is guaranteed to come back 422. `StoreRaceGoalRequest` and
 * `CompleteOnboardingRequest` both enforce these; keeping one copy here is what
 * stops the two forms drifting from each other and from the rules.
 */
export const MIN_GOAL_TIME_SEC = 300;
export const MAX_GOAL_TIME_SEC = 259_200;

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
