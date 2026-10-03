import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import DistancePresets from './DistancePresets';

describe('DistancePresets', () => {
    it('presses the preset matching the distance and reports a new choice in km', () => {
        const onChange = vi.fn();
        render(<DistancePresets km={10} onChange={onChange} labelledBy="x" />);

        expect(screen.getByRole('button', { name: '10K' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        fireEvent.click(screen.getByRole('button', { name: 'half' }));

        expect(onChange).toHaveBeenCalledWith(21.1);
    });

    it('presses nothing for a custom distance', () => {
        render(<DistancePresets km={7.5} onChange={() => {}} labelledBy="x" />);

        expect(screen.queryByRole('button', { pressed: true })).toBeNull();
    });
});
