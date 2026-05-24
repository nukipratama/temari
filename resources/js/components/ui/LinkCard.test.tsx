import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import LinkCard from './LinkCard';

describe('LinkCard', () => {
    it('renders an anchor with the given href', () => {
        render(<LinkCard href="/aktivitas/42">hello</LinkCard>);
        const link = screen.getByText('hello').closest('a');
        expect(link?.getAttribute('href')).toBe('/aktivitas/42');
    });

    it('applies the cream-bordered + hover-lift chrome by default', () => {
        const { container } = render(<LinkCard href="/x">x</LinkCard>);
        const root = container.firstChild as HTMLElement;
        expect(root.className).toMatch(/bg-cream/);
        expect(root.className).toMatch(/border-cream-deep/);
        expect(root.className).toMatch(/hover:-translate-y-0\.5/);
        expect(root.className).toMatch(/focus-visible:ring-leaf/);
    });

    it('passes className through', () => {
        const { container } = render(<LinkCard href="/x" className="custom-extra">x</LinkCard>);
        expect((container.firstChild as HTMLElement).className).toMatch(/custom-extra/);
    });
});
