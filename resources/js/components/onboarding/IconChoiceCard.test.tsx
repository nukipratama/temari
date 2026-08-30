import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import IconChoiceCard from './IconChoiceCard';

describe('IconChoiceCard', () => {
    it('renders the label, description and icon', () => {
        render(
            <IconChoiceCard
                icon="mdi:sprout"
                label="New to running"
                description="First few months, learning the ropes."
                active={false}
                onClick={vi.fn()}
            />,
        );

        expect(screen.getByText('New to running')).toBeInTheDocument();
        expect(
            screen.getByText('First few months, learning the ropes.'),
        ).toBeInTheDocument();
        expect(
            screen
                .getByRole('button')
                .querySelector('[data-icon="mdi:sprout"]'),
        ).toBeInTheDocument();
    });

    it('marks the active option as pressed', () => {
        render(
            <IconChoiceCard
                icon="mdi:sprout"
                label="New to running"
                active
                onClick={vi.fn()}
            />,
        );

        expect(screen.getByRole('button')).toHaveAttribute(
            'aria-pressed',
            'true',
        );
    });

    it('calls onClick when tapped', () => {
        const onClick = vi.fn();
        render(
            <IconChoiceCard
                icon="mdi:sprout"
                label="New to running"
                active={false}
                onClick={onClick}
            />,
        );

        fireEvent.click(screen.getByRole('button'));

        expect(onClick).toHaveBeenCalledOnce();
    });
});
