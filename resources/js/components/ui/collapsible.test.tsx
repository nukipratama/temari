import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from './collapsible';

describe('Collapsible', () => {
    it('renders the panel content when defaultOpen is set', () => {
        render(
            <Collapsible defaultOpen>
                <CollapsibleTrigger>Toggle</CollapsibleTrigger>
                <CollapsibleContent>Panel content</CollapsibleContent>
            </Collapsible>,
        );

        expect(screen.getByText('Panel content')).toBeInTheDocument();
    });

    it('toggles the panel open state when the trigger is clicked', async () => {
        render(
            <Collapsible defaultOpen>
                <CollapsibleTrigger>Toggle</CollapsibleTrigger>
                <CollapsibleContent>Panel content</CollapsibleContent>
            </Collapsible>,
        );

        const trigger = screen.getByText('Toggle');
        expect(trigger).toHaveAttribute('aria-expanded', 'true');

        await userEvent.setup().click(trigger);
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
    });

    it('starts closed when no defaultOpen/open prop is given', () => {
        render(
            <Collapsible>
                <CollapsibleTrigger>Toggle</CollapsibleTrigger>
                <CollapsibleContent>Panel content</CollapsibleContent>
            </Collapsible>,
        );

        expect(screen.getByText('Toggle')).toHaveAttribute(
            'aria-expanded',
            'false',
        );
    });
});
