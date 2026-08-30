import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import SeasonStreakPanel, { type SeasonSummary } from './SeasonStreakPanel';

const streak = {
    weeks: 6,
    rest_weeks_held: 1,
    rest_weeks_cap: 2,
    weeks_to_next_rest_week: 2,
    ran_this_week: true,
    week_ends_on: '2026-08-16',
    last_forgiven_week: null,
};

const season: SeasonSummary = {
    starts_at: '2026-06-01',
    ends_at: '2026-09-27',
    week_index: 4,
    total_weeks: 17,
    is_race_oriented: true,
    tiers_kept_from_past_seasons: 2,
    goals: [
        {
            id: 1,
            title: 'Complete your planned sessions',
            current: 12,
            target: 20,
            unit: 'sessions',
            is_completed: false,
        },
        {
            id: 2,
            title: 'Nail your quality sessions',
            current: 6,
            target: 6,
            unit: 'sessions',
            is_completed: true,
        },
    ],
};

describe('SeasonStreakPanel', () => {
    it('renders the streak weeks and a filled rest-token dot per held rest week', () => {
        render(<SeasonStreakPanel season={season} streak={streak} />);
        expect(
            screen.getByText((_, el) => el?.textContent === '6 weeks running'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('img', { name: /1 rest week in hand, of 2/ }),
        ).toBeInTheDocument();
    });

    it('shows the Live pill only when the streak is active and this week already counts', () => {
        render(<SeasonStreakPanel season={season} streak={streak} />);
        expect(screen.getByText('Live')).toBeInTheDocument();
    });

    it('hides the Live pill when the streak is zero', () => {
        render(
            <SeasonStreakPanel
                season={season}
                streak={{ ...streak, weeks: 0, ran_this_week: false }}
            />,
        );
        expect(screen.queryByText('Live')).not.toBeInTheDocument();
    });

    it('renders each season goal with its progress figure', () => {
        render(<SeasonStreakPanel season={season} streak={streak} />);
        expect(
            screen.getByText('Complete your planned sessions'),
        ).toBeInTheDocument();
        expect(screen.getByText('12/20 sessions')).toBeInTheDocument();
    });

    it('marks a completed season goal with a check glyph, not an in-progress one', () => {
        const { container } = render(
            <SeasonStreakPanel season={season} streak={streak} />,
        );
        // One check glyph (the completed goal) plus the streak's own medal
        // glyph — never one per goal.
        expect(
            container.querySelectorAll('[data-icon="mdi:check-circle"]'),
        ).toHaveLength(1);
        expect(
            container.querySelectorAll('[data-icon="mdi:medal-outline"]'),
        ).toHaveLength(1);
    });

    it('shows the tiers-kept line only when tiers were actually kept', () => {
        const { rerender } = render(
            <SeasonStreakPanel season={season} streak={streak} />,
        );
        expect(screen.getByText(/2 tiers kept/)).toBeInTheDocument();

        rerender(
            <SeasonStreakPanel
                season={{ ...season, tiers_kept_from_past_seasons: 0 }}
                streak={streak}
            />,
        );
        expect(screen.queryByText(/tiers kept/)).not.toBeInTheDocument();
    });

    it('renders a link to Plan instead of goal progress when no season exists yet', () => {
        render(<SeasonStreakPanel season={null} streak={streak} />);
        expect(screen.getByText(/No season yet\./)).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Start one on Plan' }),
        ).toHaveAttribute('href', '/plan');
        expect(
            screen.queryByText('Complete your planned sessions'),
        ).not.toBeInTheDocument();
    });
});
