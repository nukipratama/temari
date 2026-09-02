import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import { ToggleGroup, ToggleGroupItem } from './toggle-group';

describe('ToggleGroup', () => {
    it('renders every item and fires onValueChange on selection', async () => {
        let value: string[] = [];
        render(
            <ToggleGroup onValueChange={(next: string[]) => (value = next)}>
                <ToggleGroupItem value="light">Light</ToggleGroupItem>
                <ToggleGroupItem value="dark">Dark</ToggleGroupItem>
            </ToggleGroup>,
        );

        expect(screen.getByText('Light')).toBeInTheDocument();
        expect(screen.getByText('Dark')).toBeInTheDocument();

        await userEvent.setup().click(screen.getByText('Dark'));
        expect(value).toContain('dark');
    });

    it('passes variant/size down to every item via context', () => {
        render(
            <ToggleGroup variant="outline" size="sm">
                <ToggleGroupItem value="a">A</ToggleGroupItem>
            </ToggleGroup>,
        );
        expect(screen.getByText('A').className).toMatch(/border-input/);
    });
});
