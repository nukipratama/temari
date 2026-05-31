import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import PackWrapper from './PackWrapper';

describe('PackWrapper', () => {
    it('renders the tear hint and the zip-strip tab', () => {
        render(<PackWrapper rarity="epic" onOpen={vi.fn()} />);
        expect(screen.getByText(/Tarik atau ketuk/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Tarik buat buka/ })).toBeInTheDocument();
    });

    it('calls onOpen when the foil is tapped', async () => {
        const onOpen = vi.fn();
        render(<PackWrapper rarity="epic" onOpen={onOpen} />);
        await userEvent.setup().click(screen.getByText(/Tarik atau ketuk/));
        expect(onOpen).toHaveBeenCalledTimes(1);
    });

    it('calls onOpen when the zip-strip tab is clicked', async () => {
        const onOpen = vi.fn();
        render(<PackWrapper rarity="legendary" onOpen={onOpen} />);
        await userEvent.setup().click(screen.getByRole('button', { name: /Tarik buat buka/ }));
        expect(onOpen).toHaveBeenCalled();
    });
});
