import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import TemariMark from './TemariMark';

function arcsOf(container: HTMLElement): SVGPathElement[] {
    return Array.from(container.querySelectorAll('path'));
}

describe('TemariMark', () => {
    it('draws two nested open arcs, the outer one on horizon', () => {
        const { container } = render(<TemariMark />);
        const [outer, inner] = arcsOf(container);

        expect(arcsOf(container)).toHaveLength(2);
        expect(outer.getAttribute('stroke')).toBe('var(--color-horizon)');
        expect(inner.getAttribute('stroke')).toBe('var(--color-foreground)');
        expect(container.querySelector('g')?.getAttribute('fill')).toBe('none');
    });

    it('sizes both axes from one prop', () => {
        const { container } = render(<TemariMark size={56} />);
        const svg = container.querySelector('svg');

        expect(svg?.getAttribute('width')).toBe('56');
        expect(svg?.getAttribute('height')).toBe('56');
    });

    it('recolours only the inner arc, so the brand hue never flips', () => {
        const { container } = render(<TemariMark color="var(--color-cream)" />);
        const [outer, inner] = arcsOf(container);

        expect(outer.getAttribute('stroke')).toBe('var(--color-horizon)');
        expect(inner.getAttribute('stroke')).toBe('var(--color-cream)');
    });

    it('is decorative, so it carries no accessible name', () => {
        const { container } = render(<TemariMark />);

        expect(container.querySelector('svg')).toHaveAttribute('aria-hidden');
    });
});
