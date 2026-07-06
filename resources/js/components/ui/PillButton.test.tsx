import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import PillButton, { type PillTone } from './PillButton';

describe('PillButton', () => {
    it.each([
        ['horizon', 'bg-horizon'],
        ['sky', 'bg-sky'],
        ['ghost', 'border-ink/[0.18]'],
        ['outline', 'border-cream-deep'],
    ] satisfies [PillTone, string][])('renders tone %s with its class', (tone, expected) => {
        render(<PillButton tone={tone}>Klik</PillButton>);
        expect(screen.getByRole('button', { name: 'Klik' }).className).toContain(expected);
    });

    it('renders the cream-bordered outline tone', () => {
        render(<PillButton tone="outline">Keluar</PillButton>);
        const button = screen.getByRole('button', { name: 'Keluar' });
        expect(button.className).toMatch(/bg-cream/);
        expect(button.className).toMatch(/border-cream-deep/);
        expect(button.className).toMatch(/text-ink-2/);
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
