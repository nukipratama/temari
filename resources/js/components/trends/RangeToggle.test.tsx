import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import RangeToggle, { TREND_RANGE_LABELS } from './RangeToggle';

describe('RangeToggle', () => {
    it('renders all four ranges with the current one pressed', () => {
        render(<RangeToggle value="90d" onChange={vi.fn()} />);

        expect(screen.getByRole('button', { name: '90 days' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        expect(screen.getByRole('button', { name: '7 days' })).toHaveAttribute(
            'aria-pressed',
            'false',
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

    it('offers 7 days alongside the existing ranges, and still keeps 30 days', () => {
        render(<RangeToggle value="7d" onChange={vi.fn()} />);

        expect(screen.getByRole('button', { name: '7 days' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        expect(
            screen.getByRole('button', { name: '30 days' }),
        ).toBeInTheDocument();
    });

    it('exports a label per range for callers outside the toggle', () => {
        expect(TREND_RANGE_LABELS).toEqual({
            '7d': '7 days',
            '30d': '30 days',
            '90d': '90 days',
            '12mo': '12 months',
        });
    });
});
