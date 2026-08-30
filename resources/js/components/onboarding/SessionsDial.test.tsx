import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import SessionsDial from './SessionsDial';

describe('SessionsDial', () => {
    it('renders one bar per option, labeled by session count', () => {
        render(
            <SessionsDial
                options={[2, 3, 4, 5, 6]}
                value={null}
                onChange={vi.fn()}
            />,
        );

        for (const n of [2, 3, 4, 5, 6]) {
            expect(
                screen.getByRole('button', { name: `${n}x` }),
            ).toBeInTheDocument();
        }
    });

    it('marks the chosen value and every bar at or below it as filled', () => {
        render(
            <SessionsDial
                options={[2, 3, 4, 5, 6]}
                value={4}
                onChange={vi.fn()}
            />,
        );

        expect(screen.getByRole('button', { name: '4x' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        expect(screen.getByRole('button', { name: '5x' })).toHaveAttribute(
            'aria-pressed',
            'false',
        );
    });

    it('calls onChange with the tapped value', () => {
        const onChange = vi.fn();
        render(
            <SessionsDial
                options={[2, 3, 4, 5, 6]}
                value={null}
                onChange={onChange}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: '3x' }));

        expect(onChange).toHaveBeenCalledWith(3);
    });
});
