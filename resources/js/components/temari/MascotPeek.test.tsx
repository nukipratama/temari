import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import MascotPeek from './MascotPeek';

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

    it('sizes to the surface it peeks from', () => {
        const card = svgOf(render(<MascotPeek pose="easy" />).container);
        const panel = svgOf(
            render(<MascotPeek pose="easy" fit="panel" />).container,
        );

        expect(card.getAttribute('width')).toBe('96');
        expect(panel.getAttribute('width')).toBe('112');
        expect(panel.getAttribute('class')).toContain('-left-8.5');
    });

    it('passes the sky ground through', () => {
        const svg = svgOf(render(<MascotPeek pose="easy" onSky />).container);

        expect(svg.getAttribute('data-theme')).toBe('dark');
    });
});
