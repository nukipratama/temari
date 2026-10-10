import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import PlanHeading from './PlanHeading';

describe('PlanHeading', () => {
    it('names the page and titles the weeks ahead', () => {
        render(<PlanHeading action={null} />);

        expect(screen.getByText('Plan')).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'the weeks ahead.',
        );
    });

    it('sets the action beside the title', () => {
        render(<PlanHeading action={<button type="button">go</button>} />);

        expect(
            screen.getByRole('heading', { level: 1 }).parentElement,
        ).toContainElement(screen.getByRole('button', { name: 'go' }));
    });
});
