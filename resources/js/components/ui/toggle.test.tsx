import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import { Toggle } from './toggle';

describe('Toggle', () => {
    it('renders a pressable button', () => {
        render(<Toggle>Bold</Toggle>);
        expect(
            screen.getByRole('button', { name: 'Bold' }),
        ).toBeInTheDocument();
    });

    it('fires onPressedChange when clicked', async () => {
        let pressed: boolean | null = null;
        render(<Toggle onPressedChange={(next) => (pressed = next)}>B</Toggle>);
        await userEvent.setup().click(screen.getByRole('button'));
        expect(pressed).toBe(true);
    });

    it('applies the outline variant and sm size classes', () => {
        render(
            <Toggle variant="outline" size="sm">
                X
            </Toggle>,
        );
        const button = screen.getByRole('button');
        expect(button.className).toMatch(/border-input/);
        expect(button.className).toMatch(/h-8/);
    });
});
