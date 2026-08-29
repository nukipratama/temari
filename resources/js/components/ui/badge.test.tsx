import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { Badge } from './badge';

describe('Badge', () => {
    it('renders the default variant filled with the primary token', () => {
        render(<Badge>New</Badge>);
        expect(screen.getByText('New').className).toMatch(/bg-primary/);
    });

    it.each([
        ['secondary', 'bg-secondary'],
        ['destructive', 'bg-destructive'],
        ['outline', 'border-border'],
        ['ghost', 'hover:bg-muted'],
        ['link', 'underline-offset-4'],
    ] as const)(
        'renders the %s variant with its class',
        (variant, expected) => {
            render(<Badge variant={variant}>Tag</Badge>);
            expect(screen.getByText('Tag').className).toMatch(expected);
        },
    );

    it('renders as a span by default', () => {
        render(<Badge>Label</Badge>);
        expect(screen.getByText('Label').tagName).toBe('SPAN');
    });
});
