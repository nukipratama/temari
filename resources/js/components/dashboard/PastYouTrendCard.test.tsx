import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type {
    ComparableRun,
    PastYouComparison,
    PastYouTrend,
    TrendDirection,
} from '@/types/inertia';

import PastYouTrendCard from './PastYouTrendCard';

function run(
    activityId: number,
    date: string,
    hr: number | null,
): ComparableRun {
    return {
        activity_id: activityId,
        date,
        km: 10,
        pace_sec_per_km: 420,
        average_heartrate: hr,
        elevation_gain_m: 50,
        ingest_state: 'summary',
    };
}

function comparison(
    direction: TrendDirection,
    paceDelta: number,
    hrDelta: number | null = null,
    activityId = 2,
): PastYouComparison {
    return {
        direction,
        days_apart: 120,
        similarity: 0.9,
        pace_delta_sec: paceDelta,
        hr_delta_bpm: hrDelta,
        current: run(activityId, '2026-06-12', 152),
        past: run(
            activityId + 100,
            '2026-02-12',
            hrDelta === null ? null : 160,
        ),
    };
}

function trend(overrides: Partial<PastYouTrend> = {}): PastYouTrend {
    return {
        verdict: 'improving',
        window_days: 42,
        comparison_count: 2,
        comparisons: [
            comparison('better', 12, -6, 2),
            comparison('better', 8, -4, 3),
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

describe('PastYouTrendCard', () => {
    it('leads with the improving verdict and its evidence', () => {
        render(<PastYouTrendCard trend={trend()} />);

        expect(screen.getByText('You are getting faster')).toBeInTheDocument();
        expect(screen.getByText(/12 sec\/km faster/)).toBeInTheDocument();
        expect(screen.getByText(/6 bpm lower/)).toBeInTheDocument();
    });

    it('says plainly when the runner has slipped', () => {
        render(
            <PastYouTrendCard
                trend={trend({
                    verdict: 'slipped',
                    mean_pace_delta_sec: -9,
                    comparisons: [
                        comparison('worse', -10, 5, 2),
                        comparison('worse', -8, 4, 3),
                    ],
                })}
            />,
        );

        expect(
            screen.getByText('You have slipped a little'),
        ).toBeInTheDocument();
        expect(screen.getByText(/10 sec\/km slower/)).toBeInTheDocument();
        expect(
            screen.getByText(/sec\/km slower than the runs/),
        ).toBeInTheDocument();
    });

    it('says plainly when the runner has plateaued', () => {
        render(
            <PastYouTrendCard
                trend={trend({
                    verdict: 'plateaued',
                    mean_pace_delta_sec: 0.4,
                    comparisons: [
                        comparison('flat', 0, null, 2),
                        comparison('flat', 1, null, 3),
                    ],
                })}
            />,
        );

        expect(screen.getByText('You are holding steady')).toBeInTheDocument();
        expect(screen.getAllByText('same pace').length).toBeGreaterThan(0);
    });

    it('renders the Past You empty state rather than a fake verdict', () => {
        render(
            <PastYouTrendCard
                trend={trend({
                    verdict: 'not_enough_history',
                    comparison_count: 0,
                    comparisons: [],
                    mean_pace_delta_sec: null,
                    mean_hr_delta_bpm: null,
                })}
            />,
        );

        expect(screen.getByText('No comparable run yet')).toBeInTheDocument();
        expect(screen.queryByText(/getting faster/)).not.toBeInTheDocument();
    });

    it('omits the heart-rate line when a pair has no heart rate on both sides', () => {
        render(
            <PastYouTrendCard
                trend={trend({
                    comparisons: [comparison('better', 12, null, 2)],
                    comparison_count: 1,
                })}
            />,
        );

        expect(screen.queryByText(/bpm/)).not.toBeInTheDocument();
    });

    it('links each comparison to the run it was measured on', () => {
        render(<PastYouTrendCard trend={trend()} />);

        expect(screen.getAllByRole('link')[0]).toHaveAttribute(
            'href',
            '/activities/2',
        );
    });
});
