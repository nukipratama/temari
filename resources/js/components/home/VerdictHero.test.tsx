import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import type { PastYouComparison, PastYouTrend } from '@/types/inertia';

import { makeUser, setMockPage } from '@/test/setup';

import VerdictHero from './VerdictHero';

const pair: PastYouComparison = {
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

function trend(overrides: Partial<PastYouTrend> = {}): PastYouTrend {
    return {
        verdict: 'improving',
        window_days: 42,
        comparison_count: 2,
        comparisons: [
            pair,
            { ...pair, current: { ...pair.current, activity_id: 3 } },
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

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser() },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('VerdictHero', () => {
    it('leads with the improving verdict and the aggregate behind it', () => {
        render(<VerdictHero trend={trend()} verdict="improving" />);

        expect(
            screen.getByText("you're faster than you were in March."),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                '10.0 s/km faster on average, across 2 matched runs.',
            ),
        ).toBeInTheDocument();
    });

    it('states the window the verdict covers in title-case chrome', () => {
        render(<VerdictHero trend={trend()} verdict="improving" />);

        expect(
            screen.getByText(/You vs Past You · Last 42 Days/),
        ).toBeInTheDocument();
    });

    it('says a losing result plainly rather than softening it', () => {
        render(
            <VerdictHero
                trend={trend({ verdict: 'slipped', mean_pace_delta_sec: -9 })}
                verdict="slipped"
            />,
        );

        expect(
            screen.getByText("you've slipped since March."),
        ).toBeInTheDocument();
    });

    it('calls a plateau without dressing it up', () => {
        render(
            <VerdictHero
                trend={trend({ verdict: 'plateaued' })}
                verdict="plateaued"
            />,
        );

        expect(
            screen.getByText("you're holding where you were in March."),
        ).toBeInTheDocument();
    });

    // The prototype's "you vs past you" block carries no mascot and no byline;
    // the face appears only on its plan and today cards.
    it('draws no mascot byline, as the prototype does not', () => {
        const { container } = render(
            <VerdictHero trend={trend()} verdict="improving" />,
        );

        expect(container.querySelector('[data-face-icon]')).toBeNull();
        expect(screen.queryByText('temari')).not.toBeInTheDocument();
    });

    it('paints the improving headline on the accent the prototype uses', () => {
        render(<VerdictHero trend={trend()} verdict="improving" />);

        expect(
            screen.getByText("you're faster than you were in March."),
        ).toHaveClass('text-icon-accent');
    });
});
