import type {
    ComparisonMetric,
    Effort,
    PastYouComparison,
    PastYouTrend,
    TrendDirection,
} from '@/types/inertia';

import { formatMonthDayId, formatPace, parseNaiveLocalDate } from '@/lib/pace';

const GENERIC_COMPARISON = 'your comparable earlier runs';

export interface EvidenceReading {
    then: string;
    now: string;
}

export interface EvidenceRow {
    activityId: number;
    /** What made the pair comparable: distance, and the run it was matched against. */
    label: string;
    metric: ComparisonMetric;
    pace: EvidenceReading;
    /** Null when either run has no average heart rate. */
    hr: EvidenceReading | null;
    delta: string;
    direction: TrendDirection;
    /** The recent run's own effort — the row's leading-edge stripe, per MASTER.md. */
    effort: Effort | null;
}

/** Positive `pace_delta_sec` means the recent run was faster, so the clock went down. */
function paceDeltaLabel(seconds: number): string {
    const rounded = Math.round(Math.abs(seconds));
    return `${seconds > 0 ? '-' : '+'}${rounded} s/km`;
}

/**
 * An efficiency call is stated in words, never as a number: the row already
 * shows the pace and heart rate it came from.
 */
function deltaLabel(comparison: PastYouComparison): string {
    if (comparison.direction === 'flat') {
        return 'holding';
    }
    if (comparison.metric === 'ef') {
        return comparison.direction === 'better' ? 'less work' : 'more work';
    }
    return paceDeltaLabel(comparison.pace_delta_sec);
}

function heartRates(comparison: PastYouComparison): EvidenceReading | null {
    const then = comparison.past.average_heartrate;
    const now = comparison.current.average_heartrate;
    if (then === null || now === null) {
        return null;
    }
    return { then: `${Math.round(then)}`, now: `${Math.round(now)}` };
}

function pairLabel(comparison: PastYouComparison): string {
    const matchedOn = parseNaiveLocalDate(comparison.past.date);
    const when =
        matchedOn === null ? comparison.past.date : formatMonthDayId(matchedOn);
    return `${comparison.past.km.toFixed(1)} km vs ${when}`;
}

export function evidenceRows(trend: PastYouTrend): EvidenceRow[] {
    return trend.comparisons.map((comparison) => ({
        activityId: comparison.current.activity_id,
        label: pairLabel(comparison),
        metric: comparison.metric,
        pace: {
            then: formatPace(comparison.past.pace_sec_per_km),
            now: formatPace(comparison.current.pace_sec_per_km),
        },
        hr: heartRates(comparison),
        delta: deltaLabel(comparison),
        direction: comparison.direction,
        effort: comparison.current.effort,
    }));
}

function fasterAtHigherHeartRate(comparison: PastYouComparison): boolean {
    const then = comparison.past.average_heartrate;
    const now = comparison.current.average_heartrate;
    return (
        comparison.pace_relation === 'faster' &&
        then !== null &&
        now !== null &&
        now > then
    );
}

/** Most of the flat pairs got quicker but paid for it in heart rate. */
function flatPairsCostHeartRate(trend: PastYouTrend): boolean {
    const flat = trend.comparisons.filter(
        (comparison) => comparison.direction === 'flat',
    );
    const costly = flat.filter(fasterAtHigherHeartRate).length;
    return flat.length > 0 && costly * 2 > flat.length;
}

type MatchedSince =
    { kind: 'month'; value: string } | { kind: 'generic' } | null;

/** Names the shared month only when every displayed match belongs to it. */
function matchedSince(trend: PastYouTrend): MatchedSince {
    const parsedDates = trend.comparisons.map((comparison) =>
        parseNaiveLocalDate(comparison.past.date),
    );
    const dates = parsedDates.filter((date): date is Date => date !== null);

    if (dates.length === 0 || dates.length !== parsedDates.length) {
        return null;
    }

    const first = dates[0]!;

    const sameMonth = dates.every(
        (date) =>
            date.getFullYear() === first.getFullYear() &&
            date.getMonth() === first.getMonth(),
    );

    if (!sameMonth) {
        return { kind: 'generic' };
    }

    return {
        kind: 'month',
        value: first
            .toLocaleDateString('en-US', { month: 'long' })
            .toLowerCase(),
    };
}

function headlineByMatchContext(
    since: MatchedSince,
    frames: {
        month: (value: string) => string;
        generic: string;
        fallback: string;
    },
): string {
    if (since?.kind === 'month') {
        return frames.month(since.value);
    }
    if (since?.kind === 'generic') {
        return frames.generic;
    }
    return frames.fallback;
}

export function verdictHeadline(trend: PastYouTrend): string {
    const since = matchedSince(trend);

    if (trend.verdict === 'not_enough_history') {
        if (trend.comparison_count === 0) {
            return 'nothing to measure this against yet.';
        }
        if (trend.comparison_count === 1) {
            return 'one match so far. not a trend yet.';
        }
        return 'two comparable runs so far. not a trend yet.';
    }

    if (trend.verdict === 'improving') {
        return improvingHeadline(trend, since);
    }

    if (trend.verdict === 'slipped') {
        return headlineByMatchContext(since, {
            month: (month) => `you've slipped since ${month}.`,
            generic: `you've slipped against ${GENERIC_COMPARISON}.`,
            fallback: "you've slipped since then.",
        });
    }

    if (trend.verdict === 'mixed') {
        return 'mixed against comparable past runs.';
    }

    if (flatPairsCostHeartRate(trend)) {
        return 'faster, but it cost more heart rate.';
    }

    return headlineByMatchContext(since, {
        month: (month) => `you're holding where you were in ${month}.`,
        generic: `you're holding steady against ${GENERIC_COMPARISON}.`,
        fallback: "you're holding where you were.",
    });
}

function improvingHeadline(trend: PastYouTrend, since: MatchedSince): string {
    if (trend.verdict_metric === 'pace') {
        return headlineByMatchContext(since, {
            month: (month) => `you're faster than you were in ${month}.`,
            generic: `you're faster than ${GENERIC_COMPARISON}.`,
            fallback: "you're faster than you were.",
        });
    }

    if (
        trend.verdict_metric === 'ef' &&
        trend.pace_relation === 'faster' &&
        trend.hr_relation === 'same'
    ) {
        return headlineByMatchContext(since, {
            month: (month) =>
                `you're faster at the same heart rate than in ${month}.`,
            generic: `you're faster at the same heart rate than ${GENERIC_COMPARISON}.`,
            fallback: "you're faster at the same heart rate.",
        });
    }

    if (trend.verdict_metric === 'ef' && trend.pace_relation === 'same') {
        return 'same pace, lower heart rate.';
    }

    return headlineByMatchContext(since, {
        month: (month) => `you're running better than you were in ${month}.`,
        generic: `you're running better than ${GENERIC_COMPARISON}.`,
        fallback: "you're running better than you were.",
    });
}

export function verdictSupport(trend: PastYouTrend): string {
    if (trend.verdict === 'not_enough_history') {
        if (trend.comparison_count === 0) {
            return "run something twice and I'll tell you exactly what changed.";
        }
        if (trend.comparison_count === 1) {
            return "one more comparable run and I'll call it.";
        }
        return "one more comparable run and I'll call the trend.";
    }

    const split = comparisonSplit(trend);

    if (trend.verdict === 'mixed') {
        return `${split}.`;
    }

    if (trend.verdict === 'plateaued') {
        return `${split}; no clear shift across the window.`;
    }

    const facts: string[] = [];
    const pace = trend.mean_pace_delta_sec;
    if (pace !== null) {
        facts.push(
            `average pace was ${Math.abs(pace).toFixed(1)} s/km ${pace > 0 ? 'faster' : 'slower'}`,
        );
    }
    const hr = trend.mean_hr_delta_bpm;
    if (hr !== null) {
        facts.push(
            `average HR was ${Math.abs(hr).toFixed(1)} bpm ${hr < 0 ? 'lower' : 'higher'}`,
        );
    }

    return facts.length === 0 ? `${split}.` : `${split}; ${facts.join(', ')}.`;
}

function comparisonSplit(trend: PastYouTrend): string {
    const better = trend.comparisons.filter(
        (comparison) => comparison.direction === 'better',
    ).length;
    const worse = trend.comparisons.filter(
        (comparison) => comparison.direction === 'worse',
    ).length;
    const flat = trend.comparisons.length - better - worse;
    const count = trend.comparisons.length;

    if (better > 0 && worse > 0) {
        return `${better} better, ${worse} worse${flat > 0 ? `, ${flat} flat` : ''} across ${count} matched runs`;
    }
    if (better > 0) {
        return `${better} of ${count} matched runs better${flat > 0 ? `, ${flat} flat` : ''}`;
    }
    if (worse > 0) {
        return `${worse} of ${count} matched runs worse${flat > 0 ? `, ${flat} flat` : ''}`;
    }

    return `${count} matched runs held flat`;
}
