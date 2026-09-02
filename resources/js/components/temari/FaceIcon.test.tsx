import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import FaceIcon from './FaceIcon';

function svgOf(container: HTMLElement): SVGSVGElement {
    const svg = container.querySelector('svg');
    if (svg === null) {
        throw new Error('FaceIcon rendered no svg');
    }

    return svg as SVGSVGElement;
}

describe('FaceIcon', () => {
    it('draws the ring, the face disc, two brows, two eyes and a mouth', () => {
        const { container } = render(<FaceIcon />);
        const svg = svgOf(container);

        expect(svg.getAttribute('viewBox')).toBe('0 0 100 100');
        expect(svg.querySelectorAll('circle')).toHaveLength(4);
        expect(svg.querySelectorAll('path')).toHaveLength(3);
    });

    it('sizes both axes from one prop, keeping the icon square', () => {
        const { container } = render(<FaceIcon size={64} />);
        const svg = svgOf(container);

        expect(svg.getAttribute('width')).toBe('64');
        expect(svg.getAttribute('height')).toBe('64');
    });

    it('defaults to 40px on the brand ring over the card ground', () => {
        const { container } = render(<FaceIcon />);
        const svg = svgOf(container);
        const [ring, face] = Array.from(svg.querySelectorAll('circle'));

        expect(svg.getAttribute('width')).toBe('40');
        expect(ring.getAttribute('stroke')).toBe('var(--color-horizon)');
        expect(face.getAttribute('fill')).toBe('var(--color-card)');
        expect(face.getAttribute('stroke')).toBe('var(--color-foreground)');
    });

    it('takes a mood ring and an inverted face for surfaces that carry one', () => {
        const { container } = render(
            <FaceIcon
                ring="var(--color-mood-easy)"
                fill="var(--color-sky-2)"
                feature="var(--color-cream)"
            />,
        );
        const svg = svgOf(container);
        const [ring, face] = Array.from(svg.querySelectorAll('circle'));

        expect(ring.getAttribute('stroke')).toBe('var(--color-mood-easy)');
        expect(face.getAttribute('fill')).toBe('var(--color-sky-2)');
        expect(face.getAttribute('stroke')).toBe('var(--color-cream)');
    });

    it('paints every feature stroke in the one feature colour', () => {
        const { container } = render(<FaceIcon feature="#123456" />);
        const svg = svgOf(container);

        for (const path of Array.from(svg.querySelectorAll('path'))) {
            expect(path.getAttribute('stroke')).toBe('#123456');
        }
        for (const eye of Array.from(svg.querySelectorAll('circle')).slice(2)) {
            expect(eye.getAttribute('fill')).toBe('#123456');
        }
    });

    it('is decorative, so it carries no accessible name', () => {
        const { container } = render(<FaceIcon />);

        expect(svgOf(container).getAttribute('aria-hidden')).toBe('true');
    });
});
