import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import StreakPanel, { type StreakSummary } from './StreakPanel';

const STREAK = (overrides: Partial<StreakSummary> = {}): StreakSummary => ({
    weeks: 6,
    rest_weeks_held: 0,
    rest_weeks_cap: 2,
    weeks_to_next_rest_week: 2,
    ran_this_week: false,
    week_ends_on: '2026-08-16',
    last_forgiven_week: null,
    ...overrides,
});

describe('StreakPanel', () => {
    it('names the stake on an open week with nothing logged and no rest week held', () => {
        render(<StreakPanel streak={STREAK()} />);

        expect(screen.getByText('6')).toBeInTheDocument();
        expect(
            screen.getByText(/the count goes back to zero/i),
        ).toBeInTheDocument();
    });

    it('says the rest week absorbs the empty week instead of the streak', () => {
        render(<StreakPanel streak={STREAK({ rest_weeks_held: 2 })} />);

        expect(
            screen.getByText(
                /a rest week absorbs it and the count holds at 6/i,
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('img', { name: '2 rest weeks in hand, of 2' }),
        ).toBeInTheDocument();
    });

    it('offers no way to play a rest week, since spending is automatic', () => {
        render(<StreakPanel streak={STREAK({ rest_weeks_held: 1 })} />);

        expect(screen.queryByRole('button')).not.toBeInTheDocument();
        expect(screen.getByText(/nothing to play/i)).toBeInTheDocument();
    });

    it('confirms the week already counts once the user has run', () => {
        render(<StreakPanel streak={STREAK({ ran_this_week: true })} />);

        expect(
            screen.getByText(/already run this week, so this week counts/i),
        ).toBeInTheDocument();
    });

    it('reads a broken streak as a restart, not as a zero', () => {
        render(
            <StreakPanel
                streak={STREAK({ weeks: 0, weeks_to_next_rest_week: 4 })}
            />,
        );

        expect(screen.getByText('no streak')).toBeInTheDocument();
        expect(
            screen.getByText(/Nothing at stake this week/i),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/next one lands at week/i),
        ).not.toBeInTheDocument();
    });

    it('reports a week a rest week already forgave, and that it did not count', () => {
        render(
            <StreakPanel
                streak={STREAK({
                    ran_this_week: true,
                    last_forgiven_week: '2026-07-05',
                })}
            />,
        );

        expect(
            screen.getByText(/bridged the streak without counting toward it/i),
        ).toBeInTheDocument();
    });

    it('hides the accrual forecast once the held rest weeks are capped', () => {
        const { rerender } = render(
            <StreakPanel streak={STREAK({ weeks_to_next_rest_week: 2 })} />,
        );
        expect(
            screen.getByText(/next one lands at week 8/i),
        ).toBeInTheDocument();

        rerender(
            <StreakPanel
                streak={STREAK({
                    rest_weeks_held: 2,
                    weeks_to_next_rest_week: null,
                })}
            />,
        );
        expect(
            screen.queryByText(/next one lands at week/i),
        ).not.toBeInTheDocument();
    });
});
