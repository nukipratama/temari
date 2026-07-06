import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import LinkCard from './LinkCard';

describe('LinkCard', () => {
    it('renders an anchor with the given href', () => {
        render(<LinkCard href="/aktivitas/42">hello</LinkCard>);
        const link = screen.getByText('hello').closest('a');
        expect(link?.getAttribute('href')).toBe('/aktivitas/42');
    });

    it('applies the tonal-surface + hover-lift chrome by default', () => {
        const { container } = render(<LinkCard href="/x">x</LinkCard>);
        const root = container.firstChild as HTMLElement;
        expect(root.className).toMatch(/bg-surface-card/);
        expect(root.className).toMatch(/focus-ring/);
    });

    it('passes className through', () => {
        const { container } = render(<LinkCard href="/x" className="custom-extra">x</LinkCard>);
        expect((container.firstChild as HTMLElement).className).toMatch(/custom-extra/);
    });

    it('fires onClick when clicked', () => {
        const onClick = vi.fn();
        render(<LinkCard href="/x" onClick={onClick}>hello</LinkCard>);
        fireEvent.click(screen.getByText('hello'));
        expect(onClick).toHaveBeenCalledOnce();
    });
});
