import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { ToggleGroup, ToggleGroupItem } from './toggle-group';

function Controlled({
    initial = 'a',
    size,
}: Readonly<{ initial?: string | null; size?: 'sm' | 'md' }>) {
    const [value, setValue] = useState<string | null>(initial);

    return (
        <ToggleGroup
            value={value}
            onValueChange={setValue}
            size={size}
            aria-label="pick"
        >
            <ToggleGroupItem value="a">A</ToggleGroupItem>
            <ToggleGroupItem value="b">B</ToggleGroupItem>
            <ToggleGroupItem value="c">C</ToggleGroupItem>
        </ToggleGroup>
    );
}

describe('ToggleGroup', () => {
    it('marks exactly the chosen item pressed and moves the choice on click', async () => {
        render(<Controlled />);

        expect(screen.getByRole('button', { name: 'A' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        await userEvent
            .setup()
            .click(screen.getByRole('button', { name: 'B' }));

        expect(screen.getByRole('button', { name: 'B' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        expect(screen.getByRole('button', { name: 'A' })).toHaveAttribute(
            'aria-pressed',
            'false',
        );
    });

    it('keeps the choice when the pressed item is pressed again', async () => {
        const onValueChange = vi.fn();
        render(
            <ToggleGroup value="a" onValueChange={onValueChange}>
                <ToggleGroupItem value="a">A</ToggleGroupItem>
            </ToggleGroup>,
        );

        await userEvent
            .setup()
            .click(screen.getByRole('button', { name: 'A' }));

        expect(onValueChange).not.toHaveBeenCalled();
    });

    it('presses nothing when the value matches no item', () => {
        render(<Controlled initial={null} />);

        for (const name of ['A', 'B', 'C']) {
            expect(screen.getByRole('button', { name })).toHaveAttribute(
                'aria-pressed',
                'false',
            );
        }
    });

    it('moves focus between items with the arrow keys', async () => {
        const user = userEvent.setup();
        render(<Controlled />);

        await user.tab();
        expect(screen.getByRole('button', { name: 'A' })).toHaveFocus();
        await user.keyboard('{ArrowRight}');
        expect(screen.getByRole('button', { name: 'B' })).toHaveFocus();
    });

    it('draws a rounded hairline pill whose pressed state is the horizon tint', () => {
        render(<Controlled />);
        const item = screen.getByRole('button', { name: 'A' });

        expect(item).toHaveClass(
            'rounded-full',
            'border-border',
            'text-label-micro',
            'pressable',
            'data-[pressed]:bg-horizon/[0.18]',
            'data-[pressed]:text-horizon-ink',
        );
        expect(item).toHaveAttribute('data-pressed');
    });

    it.each([
        ['sm', 'min-h-8'],
        ['md', 'min-h-11'],
    ] as const)('sizes every item %s from the group', (size, expected) => {
        render(<Controlled size={size} />);

        for (const name of ['A', 'B', 'C']) {
            expect(screen.getByRole('button', { name })).toHaveClass(expected);
        }
    });

    it('labels the group for assistive tech', () => {
        render(<Controlled />);
        expect(screen.getByRole('group', { name: 'pick' })).toBeInTheDocument();
    });
});
