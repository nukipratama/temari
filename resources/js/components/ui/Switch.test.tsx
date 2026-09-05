import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import Toggle from './Switch';

describe('Toggle', () => {
    it('exposes its state as a switch', () => {
        render(<Toggle label="Rekap mingguan" checked onChange={vi.fn()} />);
        const toggle = screen.getByRole('switch', { name: 'Rekap mingguan' });
        expect(toggle).toHaveAttribute('aria-checked', 'true');
    });

    it('reports the flipped value rather than the current one', () => {
        const onChange = vi.fn();
        render(
            <Toggle
                label="Rekap mingguan"
                checked={false}
                onChange={onChange}
            />,
        );

        fireEvent.click(screen.getByRole('switch'));
        expect(onChange).toHaveBeenCalledWith(true);
    });

    it('does not fire while disabled', () => {
        const onChange = vi.fn();
        render(
            <Toggle
                label="Rekap mingguan"
                checked={false}
                onChange={onChange}
                disabled
            />,
        );

        fireEvent.click(screen.getByRole('switch'));
        expect(onChange).not.toHaveBeenCalled();
    });

    // The knob position is the only visual state, so it is worth pinning: an
    // off switch that renders the knob on the right is silently lying.
    it('parks the knob on the side matching its state', () => {
        const { rerender } = render(
            <Toggle label="x" checked={false} onChange={vi.fn()} />,
        );
        expect(
            screen.getByRole('switch').querySelector('span')?.className,
        ).toContain('left-0.5');

        rerender(<Toggle label="x" checked onChange={vi.fn()} />);
        expect(
            screen.getByRole('switch').querySelector('span')?.className,
        ).toContain('left-[1.375rem]');
    });
});
