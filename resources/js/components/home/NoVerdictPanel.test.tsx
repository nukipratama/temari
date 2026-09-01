import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import type { PastYouComparison, PastYouTrend } from '@/types/inertia';

import { makeUser, setMockPage } from '@/test/setup';

import NoVerdictPanel from './NoVerdictPanel';

const nearMiss: PastYouComparison = {
    direction: 'better',
    days_apart: 90,
    similarity: 0.9,
    pace_delta_sec: 12,
    hr_delta_bpm: -6,
    current: {
        activity_id: 2,
        date: '2026-06-12',
        km: 8.2,
        pace_sec_per_km: 420,
        average_heartrate: 152,
        elevation_gain_m: 40,
        ingest_state: 'summary',
    },
    past: {
        activity_id: 102,
        date: '2026-03-14',
        km: 8.2,
        pace_sec_per_km: 432,
        average_heartrate: 158,
        elevation_gain_m: 40,
        ingest_state: 'summary',
    },
};

function trend(comparisons: PastYouComparison[]): PastYouTrend {
    return {
        verdict: 'not_enough_history',
        window_days: 42,
        comparison_count: comparisons.length,
        comparisons,
        mean_pace_delta_sec: null,
        mean_hr_delta_bpm: null,
        fitness_delta_ctl: null,
        pace_consistency_now: null,
        pace_consistency_then: null,
        relative_effort_band: null,
    };
}

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser() },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('NoVerdictPanel', () => {
    it('says there is nothing comparable yet, without inventing a verdict', () => {
        render(<NoVerdictPanel trend={trend([])} />);

        expect(
            screen.getByText('nothing to measure this against yet.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                "run something twice and I'll tell you exactly what changed.",
            ),
        ).toBeInTheDocument();
    });

    it('reads one pair differently from none', () => {
        render(<NoVerdictPanel trend={trend([nearMiss])} />);

        expect(
            screen.getByText('one match so far. not a trend yet.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText("one more comparable run and I'll call it."),
        ).toBeInTheDocument();
    });

    it('shows the single pair it did find, so the near miss is visible', () => {
        render(<NoVerdictPanel trend={trend([nearMiss])} />);

        expect(screen.getByText('8.2 km · pace vs mar 14')).toBeInTheDocument();
        expect(screen.getByRole('link')).toHaveAttribute(
            'href',
            '/activities/2',
        );
    });

    it('shows no evidence list at all when nothing matched', () => {
        render(<NoVerdictPanel trend={trend([])} />);

        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('still labels the window it looked at', () => {
        render(<NoVerdictPanel trend={trend([])} />);

        expect(
            screen.getByText(/You vs Past You · Last 42 Days/),
        ).toBeInTheDocument();
    });
});
