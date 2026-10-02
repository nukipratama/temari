import type {
    Mood,
    RaceAmbition,
    RaceAmbitionState,
    RaceSupport,
} from '@/types/inertia';

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

export const ON_GOAL_TOLERANCE_SEC = 5;

export type GoalGapVerdict = 'behind' | 'ahead' | 'on';

/** The projection against the goal, in words: "8:29 behind", "2:10 ahead" or "on goal". */
export function goalGap(
    goalSec: number,
    predictedSec: number,
): { verdict: GoalGapVerdict; label: string } {
    const gapSec = Math.round(predictedSec) - goalSec;
    if (Math.abs(gapSec) <= ON_GOAL_TOLERANCE_SEC) {
        return { verdict: 'on', label: 'on goal' };
    }
    const verdict = gapSec > 0 ? 'behind' : 'ahead';

    return {
        verdict,
        label: `${formatDurationHMS(Math.abs(gapSec))} ${verdict}`,
    };
}

/** Temari's pose for how far the projection trails the goal, as a share of the goal time. */
export function goalGapPose(goalSec: number, predictedSec: number): Mood {
    const behindRatio = (predictedSec - goalSec) / goalSec;
    if (behindRatio <= 0.01) {
        return 'blazing';
    }
    if (behindRatio <= 0.03) {
        return 'easy';
    }
    if (behindRatio <= 0.08) {
        return 'wobbly';
    }

    return 'gassed';
}

const STATE_LABEL: Record<Exclude<RaceAmbitionState, 'unknown'>, string> = {
    on_track: 'on track',
    ambitious: 'ambitious',
    unsupported: 'unsupported',
    low_evidence: 'low evidence',
};

/** The race ambition in one plain sentence: the band, what it compares, and what the plan trains at. */
export function ambitionNote(
    ambition: RaceAmbition,
    support: RaceSupport,
): string {
    if (ambition.state === 'unknown' || ambition.supported_time_sec === null) {
        return (
            support.limitation ?? 'not enough recent results to compare yet.'
        );
    }
    const label = STATE_LABEL[ambition.state];
    const gap = Math.abs(ambition.gap_pct ?? 0);

    switch (ambition.state) {
        case 'low_evidence':
            return `${label}: your recent results cover less than half this distance, so the supported time is a rough guide and race pace won't be set faster than it.`;
        case 'unsupported':
            return `${label}: your target is ${gap}% faster than your recent runs support, so the plan trains at the supported effort. your target stays yours.`;
        case 'ambitious':
            return `${label}: your target is ${gap}% faster than your recent runs support. the plan trains at your target.`;
        default:
            return `${label}: your target is within 3% of what your recent runs support.`;
    }
}

/** The duel's right-hand eyebrow: "on track for" only in the on-track band with the supported time not behind the target. */
export function supportedEyebrow(ambition: RaceAmbition): string {
    if (
        ambition.state === 'on_track' &&
        ambition.supported_time_sec !== null &&
        goalGap(ambition.target_time_sec, ambition.supported_time_sec)
            .verdict !== 'behind'
    ) {
        return 'on track for';
    }

    return 'supported';
}
