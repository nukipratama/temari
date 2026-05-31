import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import PillButton, { type PillTone } from './PillButton';

describe('PillButton', () => {
    it.each(['horizon', 'sky', 'ghost'] satisfies PillTone[])('renders tone %s', (tone) => {
        render(<PillButton tone={tone}>Klik</PillButton>);
        expect(screen.getByRole('button', { name: 'Klik' })).toBeInTheDocument();
    });

    it('switches ghost to onSky variant when onSky=true', () => {
        render(
            <PillButton tone="ghost" onSky>
                Ikuti
            </PillButton>,
        );
        const button = screen.getByRole('button', { name: 'Ikuti' });
        expect(button.className).toMatch(/text-cream/);
    });

    it('fires onClick when clicked', async () => {
        const onClick = vi.fn();
        render(<PillButton onClick={onClick}>Go</PillButton>);
        await userEvent.setup().click(screen.getByRole('button'));
        expect(onClick).toHaveBeenCalledOnce();
    });
});
