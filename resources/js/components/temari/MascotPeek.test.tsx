import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import MascotPeek, { MascotPeekClearance } from './MascotPeek';

function svgOf(container: HTMLElement): SVGSVGElement {
    const svg = container.querySelector('svg');
    if (svg === null) {
        throw new Error('MascotPeek rendered no svg');
    }

    return svg as SVGSVGElement;
}

describe('MascotPeek', () => {
    it('draws the posed mascot absolutely, off the top-left corner', () => {
        const svg = svgOf(render(<MascotPeek pose="gassed" />).container);
        const classes = svg.getAttribute('class') ?? '';

        expect(svg.dataset.mascot).toBe('gassed');
        expect(classes).toContain('absolute');
        expect(classes).toContain('pointer-events-none');
        expect(classes).toContain('-left-7.5');
    });

    it('draws at the card peek size', () => {
        const svg = svgOf(render(<MascotPeek pose="easy" />).container);

        expect(svg.getAttribute('width')).toBe('96');
    });

    it('passes the sky ground through', () => {
        const svg = svgOf(render(<MascotPeek pose="easy" onSky />).container);

        expect(svg.getAttribute('data-theme')).toBe('dark');
    });

    it('reserves the corner with a decorative float', () => {
        const { container } = render(<MascotPeekClearance />);
        const span = container.querySelector('span');

        expect(span?.getAttribute('aria-hidden')).toBe('true');
        expect(span?.getAttribute('class')).toContain('float-left');
    });
});
