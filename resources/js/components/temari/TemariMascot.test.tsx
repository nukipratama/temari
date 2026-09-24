import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import TemariMascot, { type MascotPose, POSES, arcPath } from './TemariMascot';

function svgOf(container: HTMLElement): SVGSVGElement {
    const svg = container.querySelector('svg');
    if (svg === null) {
        throw new Error('TemariMascot rendered no svg');
    }

    return svg as SVGSVGElement;
}

const arcs = (svg: SVGSVGElement, which: 'outer' | 'inner') =>
    Array.from(svg.querySelectorAll(`[data-arc="${which}"] path`));

describe('TemariMascot', () => {
    it('defaults to the neutral pose at 48px with a full face', () => {
        const svg = svgOf(render(<TemariMascot />).container);

        expect(svg.dataset.mascot).toBe('neutral');
        expect(svg.getAttribute('width')).toBe('48');
        expect(svg.getAttribute('height')).toBe('48');
        expect(
            svg.querySelector('[data-face]')?.getAttribute('data-face'),
        ).toBe('full');
    });

    it('keeps the outer arc on the brand horizon in every pose', () => {
        for (const pose of Object.keys(POSES) as MascotPose[]) {
            const svg = svgOf(render(<TemariMascot pose={pose} />).container);

            expect(
                svg.querySelector('[data-arc="outer"]')?.getAttribute('stroke'),
            ).toBe('var(--color-horizon)');
        }
    });

    it('tints the inner arc with the mood ink tier', () => {
        const svg = svgOf(render(<TemariMascot pose="gassed" />).container);

        expect(
            svg.querySelector('[data-arc="inner"]')?.getAttribute('stroke'),
        ).toBe('var(--color-mood-gassed-ink)');
    });

    it('keeps blazing on the vivid gold, which already clears the dark ground', () => {
        const svg = svgOf(render(<TemariMascot pose="blazing" />).container);

        expect(
            svg.querySelector('[data-arc="inner"]')?.getAttribute('stroke'),
        ).toBe('var(--color-mood-blazing)');
    });

    it('breaks the outer arc in two when overloaded', () => {
        const svg = svgOf(render(<TemariMascot pose="overloaded" />).container);

        expect(arcs(svg, 'outer')).toHaveLength(2);
        expect(arcs(svg, 'inner')).toHaveLength(1);
    });

    it('drops brows and mouth below 32px, keeping the eyes', () => {
        const full = svgOf(
            render(<TemariMascot pose="gassed" size={32} />).container,
        );
        const small = svgOf(
            render(<TemariMascot pose="gassed" size={28} />).container,
        );
        const featureCount = (svg: SVGSVGElement) =>
            svg.querySelector('[data-face]')?.querySelectorAll('circle, path')
                .length;

        expect(
            full.querySelector('[data-face]')?.getAttribute('data-face'),
        ).toBe('full');
        expect(
            small.querySelector('[data-face]')?.getAttribute('data-face'),
        ).toBe('eyes');
        expect(featureCount(small)).toBe(2);
        expect(featureCount(full)).toBeGreaterThan(2);
    });

    it('resolves tokens against the dark ground on sky surfaces', () => {
        const onSky = svgOf(render(<TemariMascot onSky />).container);
        const onCard = svgOf(render(<TemariMascot />).container);

        expect(onSky.getAttribute('data-theme')).toBe('dark');
        expect(onCard.hasAttribute('data-theme')).toBe(false);
    });

    it('traces every solid arc in when drawIn is set', () => {
        const svg = svgOf(
            render(<TemariMascot pose="easy" drawIn />).container,
        );

        for (const path of [...arcs(svg, 'outer'), ...arcs(svg, 'inner')]) {
            expect(path.getAttribute('class')).toContain('draw-in');
            expect(path.getAttribute('pathLength')).toBe('1');
        }
    });

    it('never traces the dotted sleepy arc, whose dashes the trace would replace', () => {
        const svg = svgOf(
            render(<TemariMascot pose="sleepy" drawIn />).container,
        );
        const [outer] = arcs(svg, 'outer');

        expect(outer.getAttribute('class') ?? '').not.toContain('draw-in');
        expect(
            svg
                .querySelector('[data-arc="outer"]')
                ?.getAttribute('stroke-dasharray'),
        ).not.toBeNull();
    });

    it('counter-rotates the arcs only while thinking', () => {
        const thinking = svgOf(
            render(<TemariMascot pose="thinking" />).container,
        );
        const easy = svgOf(render(<TemariMascot pose="easy" />).container);

        expect(
            thinking.querySelector('[data-arc="outer"]')?.getAttribute('class'),
        ).toContain('mascot-spin');
        expect(
            thinking.querySelector('[data-arc="inner"]')?.getAttribute('class'),
        ).toContain('mascot-spin-reverse');
        expect(
            easy.querySelector('.mascot-spin, .mascot-spin-reverse'),
        ).toBeNull();
    });

    it('is decorative, so it carries no accessible name', () => {
        const svg = svgOf(render(<TemariMascot />).container);

        expect(svg.getAttribute('aria-hidden')).toBe('true');
    });

    it('draws only the face, cropped to fill the box, when faceOnly', () => {
        const svg = svgOf(
            render(<TemariMascot size={26} faceOnly />).container,
        );

        expect(svg.querySelector('[data-arc]')).toBeNull();
        expect(svg.getAttribute('viewBox')).toBe('30 30 40 40');
    });

    it('keeps the full face when the crop renders it large enough to read', () => {
        const cropped = svgOf(
            render(<TemariMascot size={26} faceOnly />).container,
        );
        const tiny = svgOf(
            render(<TemariMascot size={12} faceOnly />).container,
        );

        expect(
            cropped.querySelector('[data-face]')?.getAttribute('data-face'),
        ).toBe('full');
        expect(
            tiny.querySelector('[data-face]')?.getAttribute('data-face'),
        ).toBe('eyes');
    });
});

describe('arcPath', () => {
    it('starts at twelve o clock and sweeps clockwise', () => {
        expect(arcPath([0, 90], 10)).toBe(
            'M50.00 40.00 A10 10 0 0 1 60.00 50.00',
        );
    });

    it('takes the large arc past half a turn', () => {
        expect(arcPath([0, 270], 10)).toContain(' 0 1 1 ');
    });
});
