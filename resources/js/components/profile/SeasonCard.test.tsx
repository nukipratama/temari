import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { SeasonSummaryWeek } from '@/lib/plan';

import SeasonCard, { type ProfileSeason } from './SeasonCard';

function week(overrides: Partial<SeasonSummaryWeek> = {}): SeasonSummaryWeek {
    return {
        week_start: '2026-06-15',
        phase: 'base',
        type: 'history',
        planned_km: 30,
        actual_km: null,
        sessions: 5,
        ...overrides,
    };
}

const WEEKS: SeasonSummaryWeek[] = [
    week({ phase: 'base', type: 'current' }),
    week({ week_start: '2026-06-22', phase: 'build', type: 'lookahead' }),
];

const SEASON: ProfileSeason = {
    starts_at: '2026-06-12',
    ends_at: '2026-09-04',
    week_index: 2,
    total_weeks: 12,
    goals: [
        {
            id: 1,
            title: 'Complete your planned sessions',
            current: 30,
            target: 48,
            unit: 'sessions',
            is_completed: false,
        },
    ],
};

describe('SeasonCard', () => {
    it('points at Plan when there is no season yet', () => {
        render(<SeasonCard season={null} weeks={[]} />);

        expect(screen.getByText(/No season yet/)).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Start one on Plan/ }),
        ).toHaveAttribute('href', '/plan');
    });

    it('names the current phase, the date range and every phase in the arc', () => {
        render(<SeasonCard season={SEASON} weeks={WEEKS} />);

        expect(screen.getByText(/^Base ·/)).toBeInTheDocument();
        expect(screen.getByText(/Jun 12 – Sep 4/)).toBeInTheDocument();
        expect(screen.getByText('Build')).toBeInTheDocument();
    });

    it('draws the first open goal as the progress line', () => {
        render(<SeasonCard season={SEASON} weeks={WEEKS} />);

        expect(
            screen.getByText('63% · Complete your planned sessions'),
        ).toBeInTheDocument();
    });

    it('skips completed goals so the line tracks what is still open', () => {
        render(
            <SeasonCard
                season={{
                    ...SEASON,
                    goals: [
                        { ...SEASON.goals[0], is_completed: true },
                        {
                            id: 2,
                            title: 'Honor your rest days',
                            current: 3,
                            target: 12,
                            unit: 'days',
                            is_completed: false,
                        },
                    ],
                }}
                weeks={WEEKS}
            />,
        );

        expect(
            screen.getByText('25% · Honor your rest days'),
        ).toBeInTheDocument();
    });

    it('omits the progress line when the season carries no goals', () => {
        render(<SeasonCard season={{ ...SEASON, goals: [] }} weeks={WEEKS} />);

        expect(screen.queryByText(/%/)).not.toBeInTheDocument();
    });
});
