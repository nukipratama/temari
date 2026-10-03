import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import PillButton, { type PillTone } from './PillButton';

describe('PillButton', () => {
    it.each([
        ['horizon', 'bg-horizon'],
        ['sky', 'bg-foreground'],
        ['ghost', 'border-foreground/20'],
        ['outline', 'border-border'],
        ['danger', 'bg-ember-deep'],
        ['muted', 'bg-muted'],
    ] satisfies [PillTone, string][])(
        'renders tone %s with its class',
        (tone, expected) => {
            render(<PillButton tone={tone}>Klik</PillButton>);
            expect(
                screen.getByRole('button', { name: 'Klik' }).className,
            ).toContain(expected);
        },
    );

    it('renders the card-bordered outline tone', () => {
        render(<PillButton tone="outline">Log out</PillButton>);
        const button = screen.getByRole('button', { name: 'Log out' });
        expect(button.className).toMatch(/bg-card/);
        expect(button.className).toMatch(/border-border/);
        expect(button.className).toMatch(/text-text-2/);
    });

    it('draws the default tone from ground-reactive tokens, not the fixed sky fill', () => {
        render(<PillButton>send</PillButton>);
        const button = screen.getByRole('button', { name: 'send' });
        expect(button).toHaveClass('bg-foreground', 'text-background');
        expect(button).not.toHaveClass('bg-sky');
        expect(button).not.toHaveClass('text-cream');
    });

    it('keeps the cream fill for the default tone on a sky panel', () => {
        render(<PillButton onSky>send</PillButton>);
        const button = screen.getByRole('button', { name: 'send' });
        expect(button).toHaveClass(
            'bg-cream',
            'text-sky',
            'hover:bg-cream-deep',
        );
        expect(button).not.toHaveClass('bg-foreground');
        expect(button).not.toHaveClass('text-background');
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

    it('sets the compact action pill on the mono label tier, not the sans base', () => {
        render(
            <PillButton tone="muted" size="xs">
                edit race
            </PillButton>,
        );
        const button = screen.getByRole('button', { name: 'edit race' });

        expect(button).toHaveClass('text-label-micro');
        expect(button).not.toHaveClass('font-sans');
        expect(button).not.toHaveClass('font-medium');
    });
});
