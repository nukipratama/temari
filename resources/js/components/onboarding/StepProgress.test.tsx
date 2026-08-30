import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import StepProgress from './StepProgress';

describe('StepProgress', () => {
    it('renders the three step labels', () => {
        render(<StepProgress step="connected" subIndex={0} />);

        expect(screen.getByText('Welcome')).toBeInTheDocument();
        expect(screen.getByText('Training')).toBeInTheDocument();
        expect(screen.getByText('Race Goal')).toBeInTheDocument();
    });

    it('shows the preferences sub-dots only while on the preferences step', () => {
        const { container: onPreferences } = render(
            <StepProgress step="preferences" subIndex={1} />,
        );
        expect(
            onPreferences.querySelectorAll(
                '[data-testid="step-progress"] .size-1',
            ),
        ).toHaveLength(4);

        const { container: onGoal } = render(
            <StepProgress step="goal" subIndex={0} />,
        );
        expect(
            onGoal.querySelectorAll('[data-testid="step-progress"] .size-1'),
        ).toHaveLength(0);
    });
});
