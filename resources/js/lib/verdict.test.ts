import { describe, expect, it } from 'vitest';

import type {
    ComparableRun,
    PastYouComparison,
    PastYouTrend,
    TrendDirection,
} from '@/types/inertia';

import {
    decidingMetric,
    evidenceRows,
    verdictHeadline,
    verdictMetric,
    verdictSupport,
} from './verdict';

function run(
    activityId: number,
    date: string,
    paceSec: number,
    hr: number | null,
): ComparableRun {
    return {
        activity_id: activityId,
        date,
        km: 8.2,
        pace_sec_per_km: paceSec,
        average_heartrate: hr,
        elevation_gain_m: 40,
        ingest_state: 'summary',
    };
}

function comparison({
    direction = 'better',
    paceDelta = 12,
    hrDelta = -6,
    activityId = 2,
    currentHr = 152,
    pastHr = 158,
}: Partial<{
    direction: TrendDirection;
    paceDelta: number;
    hrDelta: number | null;
    activityId: number;
    currentHr: number | null;
    pastHr: number | null;
}> = {}): PastYouComparison {
    return {
        direction,
        days_apart: 120,
        similarity: 0.9,
        pace_delta_sec: paceDelta,
        hr_delta_bpm: hrDelta,
        current: run(activityId, '2026-06-12', 420, currentHr),
        past: run(activityId + 100, '2026-03-14', 420 + paceDelta, pastHr),
    };
}

function trend(overrides: Partial<PastYouTrend> = {}): PastYouTrend {
    return {
        verdict: 'improving',
        window_days: 42,
        comparison_count: 2,
        comparisons: [
            comparison({ activityId: 2 }),
            comparison({ activityId: 3, paceDelta: 8, hrDelta: -4 }),
        ],
        mean_pace_delta_sec: 10,
        mean_hr_delta_bpm: -5,
        fitness_delta_ctl: 2.4,
        pace_consistency_now: null,
        pace_consistency_then: null,
        relative_effort_band: null,
        ...overrides,
    };
}

describe('decidingMetric', () => {
    it('lets pace decide once the gap clears the noise band', () => {
        expect(decidingMetric(comparison({ paceDelta: 12 }))).toBe('pace');
        expect(decidingMetric(comparison({ paceDelta: -9 }))).toBe('pace');
    });

    it('falls to heart rate only when pace came back flat', () => {
        expect(decidingMetric(comparison({ paceDelta: 1, hrDelta: -7 }))).toBe(
            'hr',
        );
    });

    it('stays on pace when the flat pair has no heart rate on both sides', () => {
        expect(
            decidingMetric(
                comparison({ paceDelta: 1, hrDelta: null, pastHr: null }),
            ),
        ).toBe('pace');
    });

    it('stays on pace when both readings sit inside the noise band', () => {
        expect(decidingMetric(comparison({ paceDelta: 2, hrDelta: -1 }))).toBe(
            'pace',
        );
    });
});

describe('evidenceRows', () => {
    it('shows the pace pair before and after, with the delta signed by direction', () => {
        const [row] = evidenceRows(
            trend({ comparisons: [comparison({ paceDelta: 12 })] }),
        );

        expect(row.metric).toBe('pace');
        expect(row.then).toBe('7:12');
        expect(row.now).toBe('7:00');
        expect(row.delta).toBe('-12 s/km');
        expect(row.label).toBe('8.2 km · pace vs Mar 14');
    });

    it('marks a slower pair with a positive delta', () => {
        const [row] = evidenceRows(
            trend({
                comparisons: [
                    comparison({ direction: 'worse', paceDelta: -10 }),
                ],
            }),
        );

        expect(row.delta).toBe('+10 s/km');
        expect(row.direction).toBe('worse');
    });

    it('shows heart rate instead when that is what decided the row', () => {
        const [row] = evidenceRows(
            trend({
                comparisons: [comparison({ paceDelta: 1, hrDelta: -7 })],
            }),
        );

        expect(row.metric).toBe('hr');
        expect(row.then).toBe('158');
        expect(row.now).toBe('152');
        expect(row.delta).toBe('-7 bpm');
        expect(row.label).toBe('8.2 km · avg HR vs Mar 14');
    });

    it('reads a flat pair as holding rather than a noisy number', () => {
        const [row] = evidenceRows(
            trend({
                comparisons: [
                    comparison({ direction: 'flat', paceDelta: 2, hrDelta: 1 }),
                ],
            }),
        );

        expect(row.delta).toBe('holding');
    });

    it('renders one row per matched pair', () => {
        expect(evidenceRows(trend())).toHaveLength(2);
    });
});

describe('verdictMetric', () => {
    it('reads pace when the mean cleared the noise band', () => {
        expect(verdictMetric(trend({ mean_pace_delta_sec: 10 }))).toBe('pace');
    });

    it('reads heart rate when the mean pace came back flat', () => {
        expect(
            verdictMetric(
                trend({ mean_pace_delta_sec: 1, mean_hr_delta_bpm: -6 }),
            ),
        ).toBe('hr');
    });

    it('reads pace when neither mean is a signal', () => {
        expect(
            verdictMetric(
                trend({ mean_pace_delta_sec: 1, mean_hr_delta_bpm: -1 }),
            ),
        ).toBe('pace');
    });
});

describe('verdictHeadline', () => {
    it('names the month the improvement is measured from', () => {
        expect(verdictHeadline(trend())).toBe(
            "you're faster than you were in march.",
        );
    });

    it('credits the heart rate when pace held but effort dropped', () => {
        expect(
            verdictHeadline(
                trend({
                    mean_pace_delta_sec: 1,
                    mean_hr_delta_bpm: -6,
                }),
            ),
        ).toBe('same pace, less work to hold it.');
    });

    it('says a losing result plainly', () => {
        expect(
            verdictHeadline(
                trend({ verdict: 'slipped', mean_pace_delta_sec: -9 }),
            ),
        ).toBe("you've slipped since march.");
    });

    it('calls a plateau without dressing it up', () => {
        expect(
            verdictHeadline(
                trend({ verdict: 'plateaued', mean_pace_delta_sec: 0.4 }),
            ),
        ).toBe("you're holding where you were in march.");
    });

    it('separates nothing comparable from one pair that is not yet a trend', () => {
        expect(
            verdictHeadline(
                trend({
                    verdict: 'not_enough_history',
                    comparison_count: 0,
                    comparisons: [],
                }),
            ),
        ).toBe('nothing to measure this against yet.');

        expect(
            verdictHeadline(
                trend({
                    verdict: 'not_enough_history',
                    comparison_count: 1,
                    comparisons: [comparison()],
                }),
            ),
        ).toBe('one match so far. not a trend yet.');
    });

    it('drops the month when no pair carries a readable date', () => {
        expect(
            verdictHeadline(trend({ comparisons: [], comparison_count: 2 })),
        ).toBe("you're faster than you were.");
        expect(
            verdictHeadline(
                trend({
                    verdict: 'slipped',
                    mean_pace_delta_sec: -9,
                    comparisons: [],
                }),
            ),
        ).toBe("you've slipped since then.");
    });
});

describe('verdictSupport', () => {
    it('backs the call with the aggregate and how many pairs it came from', () => {
        expect(verdictSupport(trend())).toBe(
            '10.0 s/km faster on average, across 2 matched runs.',
        );
    });

    it('reports a slower mean as slower', () => {
        expect(
            verdictSupport(
                trend({ verdict: 'slipped', mean_pace_delta_sec: -9.2 }),
            ),
        ).toBe('9.2 s/km slower on average, across 2 matched runs.');
    });

    it('reports the heart-rate aggregate when that carried the verdict', () => {
        expect(
            verdictSupport(
                trend({ mean_pace_delta_sec: 1, mean_hr_delta_bpm: -5.4 }),
            ),
        ).toBe('5.4 bpm lower on average, across 2 matched runs.');
    });

    it('describes a plateau as a band, not a number', () => {
        expect(verdictSupport(trend({ verdict: 'plateaued' }))).toBe(
            'inside a few seconds either way, across 2 matched runs.',
        );
    });

    it('tells the runner what would earn a verdict', () => {
        expect(
            verdictSupport(
                trend({ verdict: 'not_enough_history', comparison_count: 0 }),
            ),
        ).toBe("run something twice and i'll tell you exactly what changed.");

        expect(
            verdictSupport(
                trend({ verdict: 'not_enough_history', comparison_count: 1 }),
            ),
        ).toBe("one more comparable run and i'll call it.");
    });

    it('omits the aggregate when the window shipped no mean', () => {
        expect(
            verdictSupport(
                trend({
                    mean_pace_delta_sec: null,
                    mean_hr_delta_bpm: null,
                }),
            ),
        ).toBe('across 2 matched runs.');
    });
});
