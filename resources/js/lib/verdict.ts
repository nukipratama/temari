import type {
    PastYouComparison,
    PastYouTrend,
    TrendDirection,
} from '@/types/inertia';

import { formatMonthDayId, formatPace, parseNaiveLocalDate } from '@/lib/pace';

/**
 * Mirrors `PastYouComparison::PACE_SIGNAL_SEC` / `::HR_SIGNAL_BPM`, so a row
 * can show the reading that actually decided its direction rather than always
 * showing pace and leaving a heart-rate-driven call looking unexplained.
 */
export const PACE_SIGNAL_SEC = 5;
export const HR_SIGNAL_BPM = 3;

export type EvidenceMetric = 'pace' | 'hr';

export interface EvidenceRow {
    activityId: number;
    /** What made the pair comparable: distance, and the run it was matched against. */
    label: string;
    metric: EvidenceMetric;
    then: string;
    now: string;
    delta: string;
    direction: TrendDirection;
}

function hasBothHeartRates(comparison: PastYouComparison): boolean {
    return (
        comparison.current.average_heartrate !== null &&
        comparison.past.average_heartrate !== null
    );
}

export function decidingMetric(comparison: PastYouComparison): EvidenceMetric {
    if (Math.abs(comparison.pace_delta_sec) >= PACE_SIGNAL_SEC) {
        return 'pace';
    }
    if (
        comparison.hr_delta_bpm !== null &&
        Math.abs(comparison.hr_delta_bpm) >= HR_SIGNAL_BPM &&
        hasBothHeartRates(comparison)
    ) {
        return 'hr';
    }
    return 'pace';
}

/** Positive `pace_delta_sec` means the recent run was faster, so the clock went down. */
function paceDeltaLabel(seconds: number): string {
    const rounded = Math.round(Math.abs(seconds));
    return `${seconds > 0 ? '-' : '+'}${rounded} s/km`;
}

function heartRateDeltaLabel(bpm: number): string {
    const rounded = Math.round(Math.abs(bpm));
    return `${bpm < 0 ? '-' : '+'}${rounded} bpm`;
}

/**
 * Names the metric as well as the pair, because a pace row and a heart-rate row
 * are both "N → N" and are otherwise indistinguishable: `148 → 159` reads as a
 * broken pace until the row says it is heart rate.
 */
function pairLabel(
    comparison: PastYouComparison,
    metric: EvidenceMetric,
): string {
    const matchedOn = parseNaiveLocalDate(comparison.past.date);
    const when =
        matchedOn === null ? comparison.past.date : formatMonthDayId(matchedOn);
    const reading = metric === 'hr' ? 'avg HR' : 'pace';
    return `${comparison.past.km.toFixed(1)} km · ${reading} vs ${when}`;
}

export function evidenceRows(trend: PastYouTrend): EvidenceRow[] {
    return trend.comparisons.map((comparison) => {
        const metric = decidingMetric(comparison);
        const flat = comparison.direction === 'flat';

        if (metric === 'hr' && comparison.hr_delta_bpm !== null) {
            return {
                activityId: comparison.current.activity_id,
                label: pairLabel(comparison, metric),
                metric,
                then: `${Math.round(comparison.past.average_heartrate ?? 0)}`,
                now: `${Math.round(comparison.current.average_heartrate ?? 0)}`,
                delta: flat
                    ? 'holding'
                    : heartRateDeltaLabel(comparison.hr_delta_bpm),
                direction: comparison.direction,
            };
        }

        return {
            activityId: comparison.current.activity_id,
            label: pairLabel(comparison, 'pace'),
            metric: 'pace',
            then: formatPace(comparison.past.pace_sec_per_km),
            now: formatPace(comparison.current.pace_sec_per_km),
            delta: flat ? 'holding' : paceDeltaLabel(comparison.pace_delta_sec),
            direction: comparison.direction,
        };
    });
}

/** Mirrors `PastYouTrendBuilder::aggregateDirection` — pace leads, heart rate decides a flat window. */
export function verdictMetric(trend: PastYouTrend): EvidenceMetric {
    const pace = trend.mean_pace_delta_sec;
    if (pace !== null && Math.abs(pace) >= PACE_SIGNAL_SEC) {
        return 'pace';
    }
    const hr = trend.mean_hr_delta_bpm;
    if (hr !== null && Math.abs(hr) >= HR_SIGNAL_BPM) {
        return 'hr';
    }
    return 'pace';
}

/** The month of the oldest run the window was matched against. */
function matchedSinceMonth(trend: PastYouTrend): string | null {
    const dates = trend.comparisons
        .map((comparison) => comparison.past.date)
        .filter((date) => date !== '');
    if (dates.length === 0) {
        return null;
    }
    const oldest = parseNaiveLocalDate(dates.reduce((a, b) => (a < b ? a : b)));
    return oldest === null
        ? null
        : oldest.toLocaleDateString('en-US', { month: 'long' });
}

export function verdictHeadline(trend: PastYouTrend): string {
    const since = matchedSinceMonth(trend);

    if (trend.verdict === 'not_enough_history') {
        return trend.comparison_count === 0
            ? 'nothing to measure this against yet.'
            : 'one match so far. not a trend yet.';
    }

    if (trend.verdict === 'improving') {
        return verdictMetric(trend) === 'hr'
            ? 'same pace, less work to hold it.'
            : `you're faster than you were${since === null ? '' : ` in ${since}`}.`;
    }

    if (trend.verdict === 'slipped') {
        return `you've slipped since ${since ?? 'then'}.`;
    }

    return `you're holding where you were${since === null ? '' : ` in ${since}`}.`;
}

export function verdictSupport(trend: PastYouTrend): string {
    if (trend.verdict === 'not_enough_history') {
        return trend.comparison_count === 0
            ? "run something twice and I'll tell you exactly what changed."
            : "one more comparable run and I'll call it.";
    }

    const runs = `${trend.comparison_count} matched runs`;

    if (trend.verdict === 'plateaued') {
        return `inside a few seconds either way, across ${runs}.`;
    }

    const hr = trend.mean_hr_delta_bpm;
    if (verdictMetric(trend) === 'hr' && hr !== null) {
        return `${Math.abs(hr).toFixed(1)} bpm ${hr < 0 ? 'lower' : 'higher'} on average, across ${runs}.`;
    }

    const pace = trend.mean_pace_delta_sec;
    if (pace === null) {
        return `across ${runs}.`;
    }

    return `${Math.abs(pace).toFixed(1)} s/km ${pace > 0 ? 'faster' : 'slower'} on average, across ${runs}.`;
}
