import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Card, { type CardTone } from './Card';

describe('Card', () => {
    it('renders children inside a cream-bordered default tone', () => {
        const { container } = render(<Card>hello</Card>);
        expect(screen.getByText('hello')).toBeInTheDocument();
        const root = container.firstChild as HTMLElement;
        expect(root.className).toMatch(/bg-cream/);
        expect(root.className).toMatch(/border-cream-deep/);
    });

    it.each(['cream', 'cream-deep', 'sky-glass', 'empty'] satisfies CardTone[])(
        'renders tone %s',
        (tone) => {
            const { container } = render(<Card tone={tone}>x</Card>);
            expect(container.firstChild).toBeInTheDocument();
        },
    );

    it('renders as <section> when as="section"', () => {
        const { container } = render(<Card as="section">x</Card>);
        expect((container.firstChild as HTMLElement).tagName).toBe('SECTION');
    });

    it('honours padding="sm"', () => {
        const { container } = render(<Card padding="sm">x</Card>);
        expect((container.firstChild as HTMLElement).className).toMatch(/py-3\.5/);
    });

    it('passes className through', () => {
        const { container } = render(<Card className="custom-extra">x</Card>);
        expect((container.firstChild as HTMLElement).className).toMatch(/custom-extra/);
    });
});
