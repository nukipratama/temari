import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import EffortLegend from './EffortLegend';

describe('EffortLegend', () => {
    it('names every effort in a sheet titled effort key', () => {
        render(<EffortLegend open onOpenChange={vi.fn()} />);

        expect(
            screen.getByRole('dialog', { name: 'effort key' }),
        ).toBeInTheDocument();
        for (const word of [
            'easy',
            'steady',
            'hard',
            'unscored',
            'planned rest',
        ]) {
            expect(screen.getByText(word)).toBeInTheDocument();
        }
    });

    it('carries no mood key', () => {
        render(<EffortLegend open onOpenChange={vi.fn()} />);

        expect(screen.queryByText('mood')).not.toBeInTheDocument();
        expect(screen.queryByText('blazing')).not.toBeInTheDocument();
    });

    it('draws planned rest as a flat dashed line, not a rounded pill', () => {
        render(<EffortLegend open onOpenChange={vi.fn()} />);

        const swatch = screen.getByText('planned rest').querySelector('span');

        expect(swatch).toHaveClass('border-dashed', 'h-0');
        expect(swatch).not.toHaveClass('rounded-full');
    });

    it('renders nothing while closed', () => {
        render(<EffortLegend open={false} onOpenChange={vi.fn()} />);

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
});
