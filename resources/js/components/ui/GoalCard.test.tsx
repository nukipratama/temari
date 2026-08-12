import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import GoalCard, { type Goal } from './GoalCard';

function makeGoal(overrides: Partial<Goal> = {}): Goal {
    return {
        id: 1,
        title: 'Complete your planned sessions',
        current: 12,
        target: 48,
        unit: 'sessions',
        is_completed: false,
        ...overrides,
    };
}

describe('GoalCard', () => {
    it('renders the title, the current/target figure and the unit', () => {
        const { container } = render(<GoalCard goal={makeGoal()} />);
        expect(
            screen.getByText('Complete your planned sessions'),
        ).toBeInTheDocument();
        expect(container.textContent).toContain('/48');
        expect(screen.getByText('sessions')).toBeInTheDocument();
    });

    it('stretches to the row height so wrapped titles stay aligned', () => {
        const { container } = render(<GoalCard goal={makeGoal()} />);
        expect((container.firstChild as HTMLElement).className).toMatch(
            /h-full/,
        );
    });

    it('labels the progress bar with the goal and its figures', () => {
        render(<GoalCard goal={makeGoal()} />);
        expect(
            screen.getByLabelText(
                'Complete your planned sessions: 12/48 sessions',
            ),
        ).toBeInTheDocument();
    });

    it('tints the surface once the goal is completed', () => {
        const { container } = render(
            <GoalCard goal={makeGoal({ current: 48, is_completed: true })} />,
        );
        expect((container.firstChild as HTMLElement).className).toMatch(
            /border-horizon\/30/,
        );
    });
});
