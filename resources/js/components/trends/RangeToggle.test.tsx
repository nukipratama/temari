import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import RangeToggle from './RangeToggle';

describe('RangeToggle', () => {
    it('renders all three ranges with the current one pressed', () => {
        render(<RangeToggle value="90d" onChange={vi.fn()} />);

        expect(screen.getByRole('button', { name: '90 days' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        expect(screen.getByRole('button', { name: '30 days' })).toHaveAttribute(
            'aria-pressed',
            'false',
        );
        expect(
            screen.getByRole('button', { name: '12 months' }),
        ).toHaveAttribute('aria-pressed', 'false');
    });

    it('calls onChange with the picked range', () => {
        const onChange = vi.fn();
        render(<RangeToggle value="12mo" onChange={onChange} />);

        fireEvent.click(screen.getByRole('button', { name: '30 days' }));

        expect(onChange).toHaveBeenCalledWith('30d');
    });

    it('exposes an accessible group label', () => {
        render(<RangeToggle value="30d" onChange={vi.fn()} />);

        expect(
            screen.getByRole('group', { name: 'Time range' }),
        ).toBeInTheDocument();
    });
});
