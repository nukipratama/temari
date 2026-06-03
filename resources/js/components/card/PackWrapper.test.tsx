import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import PackWrapper from './PackWrapper';

describe('PackWrapper', () => {
    it('renders the sealed pack with the pull affordance and hint', () => {
        render(<PackWrapper rarity="epic" onOpen={vi.fn()} />);
        expect(screen.getByTestId('pack-wrapper')).toBeInTheDocument();
        expect(screen.getByText('Tarik')).toBeInTheDocument();
        expect(screen.getByText(/Tarik atau ketuk/)).toBeInTheDocument();
    });

    it('hides the card behind a tiled card-back motif', () => {
        const { container } = render(<PackWrapper rarity="epic" onOpen={vi.fn()} />);
        // The card-back tiles bunny glyphs so the card can't be read through it.
        expect(container.querySelectorAll('svg').length).toBeGreaterThanOrEqual(12);
    });

    it('calls onOpen when the foil is tapped', async () => {
        const onOpen = vi.fn();
        render(<PackWrapper rarity="epic" onOpen={onOpen} />);
        await userEvent.setup().click(screen.getByTestId('pack-wrapper'));
        expect(onOpen).toHaveBeenCalledTimes(1);
    });
});
