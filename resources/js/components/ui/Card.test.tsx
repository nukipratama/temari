import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import Card, { type CardTone } from './Card';

describe('Card', () => {
    it('renders children inside the tonal, bordered default surface', () => {
        const { container } = render(<Card>hello</Card>);
        expect(screen.getByText('hello')).toBeInTheDocument();
        const root = container.firstChild as HTMLElement;
        expect(root).toHaveClass('bg-surface-card', 'border-line');
    });

    it.each([
        ['card', 'bg-surface-card'],
        ['onSky', 'bg-cream/[0.06]'],
        ['empty', 'border-dashed'],
    ] satisfies [CardTone, string][])(
        'renders tone %s with its surface class',
        (tone, expected) => {
            const { container } = render(<Card tone={tone}>x</Card>);
            expect(container.firstChild as HTMLElement).toHaveClass(expected);
        },
    );

    it('renders as <section> when as="section"', () => {
        const { container } = render(<Card as="section">x</Card>);
        expect((container.firstChild as HTMLElement).tagName).toBe('SECTION');
    });

    it('honours padding="panel"', () => {
        const { container } = render(<Card padding="panel">x</Card>);
        expect((container.firstChild as HTMLElement).className).toMatch(
            /pad-panel/,
        );
    });

    it('passes className through', () => {
        const { container } = render(<Card className="custom-extra">x</Card>);
        expect((container.firstChild as HTMLElement).className).toMatch(
            /custom-extra/,
        );
    });
});
