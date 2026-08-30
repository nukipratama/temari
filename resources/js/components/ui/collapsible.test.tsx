import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from './collapsible';

describe('Collapsible', () => {
    it('hides the panel until the trigger is activated', async () => {
        render(
            <Collapsible>
                <CollapsibleTrigger>Toggle</CollapsibleTrigger>
                <CollapsibleContent>Panel content</CollapsibleContent>
            </Collapsible>,
        );

        expect(screen.queryByText('Panel content')).not.toBeInTheDocument();

        await userEvent.setup().click(screen.getByText('Toggle'));

        expect(screen.getByText('Panel content')).toBeInTheDocument();
    });

    it('starts open when defaultOpen is set', () => {
        render(
            <Collapsible defaultOpen>
                <CollapsibleTrigger>Toggle</CollapsibleTrigger>
                <CollapsibleContent>Panel content</CollapsibleContent>
            </Collapsible>,
        );

        expect(screen.getByText('Panel content')).toBeInTheDocument();
    });
});
