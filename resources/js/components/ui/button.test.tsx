import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import { Button } from './button';

describe('Button', () => {
    it('renders the default variant filled with the primary token', () => {
        render(<Button>Save</Button>);
        expect(screen.getByRole('button', { name: 'Save' }).className).toMatch(
            /bg-primary/,
        );
    });

    it.each([
        ['outline', 'border-border'],
        ['secondary', 'bg-secondary'],
        ['ghost', 'hover:bg-muted'],
        ['destructive', 'bg-destructive'],
        ['link', 'underline-offset-4'],
    ] as const)(
        'renders the %s variant with its class',
        (variant, expected) => {
            render(<Button variant={variant}>Go</Button>);
            expect(
                screen.getByRole('button', { name: 'Go' }).className,
            ).toMatch(expected);
        },
    );

    it('fires onClick and respects disabled', async () => {
        let clicks = 0;
        render(<Button onClick={() => (clicks += 1)}>Tap</Button>);
        await userEvent.setup().click(screen.getByRole('button'));
        expect(clicks).toBe(1);

        render(
            <Button disabled onClick={() => (clicks += 1)}>
                Blocked
            </Button>,
        );
        expect(screen.getByRole('button', { name: 'Blocked' })).toBeDisabled();
    });
});
