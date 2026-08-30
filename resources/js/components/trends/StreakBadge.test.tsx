import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import StreakBadge, { type StreakSummaryLike } from './StreakBadge';

function streak(overrides: Partial<StreakSummaryLike> = {}): StreakSummaryLike {
    return {
        weeks: 0,
        rest_weeks_held: 0,
        rest_weeks_cap: 2,
        ran_this_week: false,
        week_ends_on: '2026-08-30',
        ...overrides,
    };
}

describe('StreakBadge', () => {
    it('labels a zero streak as no streak yet', () => {
        render(<StreakBadge streak={streak()} />);

        expect(screen.getByText('No streak yet')).toBeInTheDocument();
    });

    it('shows the week count for an active streak', () => {
        render(<StreakBadge streak={streak({ weeks: 6 })} />);

        expect(screen.getByText('6 weeks streak')).toBeInTheDocument();
    });

    it('marks this week as counted when a run has already logged', () => {
        render(
            <StreakBadge streak={streak({ weeks: 3, ran_this_week: true })} />,
        );

        expect(screen.getByText('This week counts')).toBeInTheDocument();
    });

    it('expands a detail line on tap and hides it again on a second tap', () => {
        render(<StreakBadge streak={streak({ weeks: 4 })} />);

        expect(
            screen.queryByText(/running with at least one logged run/),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /4 weeks streak/ }));

        expect(
            screen.getByText(/running with at least one logged run/),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /4 weeks streak/ }));

        expect(
            screen.queryByText(/running with at least one logged run/),
        ).not.toBeInTheDocument();
    });

    it('mentions rest weeks in hand when the streak has any', () => {
        render(
            <StreakBadge streak={streak({ weeks: 5, rest_weeks_held: 1 })} />,
        );

        fireEvent.click(screen.getByRole('button', { name: /5 weeks streak/ }));

        expect(screen.getByText(/1 rest week in hand/)).toBeInTheDocument();
    });

    it('points at the week close date when there is no active streak', () => {
        render(<StreakBadge streak={streak({ week_ends_on: '2026-08-30' })} />);

        fireEvent.click(screen.getByRole('button', { name: 'No streak yet' }));

        expect(
            screen.getByText(/No active streak yet\. One run before/),
        ).toBeInTheDocument();
    });
});
