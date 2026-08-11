import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import TemariProto, { type TemariPose } from './TemariProto';

const ALL_POSES: TemariPose[] = [
    'proud',
    'pumped',
    'excited',
    'holding',
    'reading',
    'wobble',
    'observational',
    'glow',
];

describe('TemariProto', () => {
    it.each(ALL_POSES)('renders without crashing for pose %s', (pose) => {
        const { container } = render(<TemariProto pose={pose} />);
        expect(container.querySelector('svg')).toBeInTheDocument();
        expect(container.firstChild).toHaveAttribute('data-pose', pose);
    });

    it('disables animation when animate=false', () => {
        const { container } = render(
            <TemariProto pose="proud" animate={false} />,
        );
        const root = container.firstChild as HTMLElement;
        expect(root.style.animation).toBe('none');
    });

    it('accepts a custom animation string', () => {
        const { container } = render(
            <TemariProto pose="proud" animate="custom 1s linear" />,
        );
        const root = container.firstChild as HTMLElement;
        expect(root.style.animation).toContain('custom 1s linear');
    });

    it('renders the legendary headband star detail when equipped', () => {
        const { container } = render(
            <TemariProto equipped={{ headband: 'legendaris' }} />,
        );
        const paths = Array.from(container.querySelectorAll('path'));
        const hasStar = paths.some((p) =>
            p.getAttribute('d')?.includes('l 1 -3 l 1 3 l 3 1'),
        );
        expect(hasStar).toBe(true);
    });

    it('renders no headband bow when nothing is equipped', () => {
        const { container } = render(<TemariProto pose="proud" />);
        const bow = container.querySelector('g[transform="translate(60, 22)"]');
        expect(bow).toBeFalsy();
    });

    it('renders an aura layer when equipped.aura is set', () => {
        const { container } = render(
            <TemariProto equipped={{ aura: 'pemanasan' }} />,
        );
        // Gradient id is uniquified per instance (useId suffix), so match by prefix.
        expect(
            container.querySelector('radialGradient[id^="temari-aura-grad"]'),
        ).toBeInTheDocument();
    });

    it('skips the medal when equipped.medal === "none"', () => {
        const { container } = render(
            <TemariProto pose="proud" equipped={{ medal: 'none' }} />,
        );
        const transformed = Array.from(container.querySelectorAll('g')).find(
            (g) => g.getAttribute('transform') === 'translate(60, 78)',
        );
        expect(transformed).toBeFalsy();
    });

    it('renders no medal when nothing is equipped', () => {
        const { container } = render(<TemariProto pose="proud" />);
        const medalGroup = Array.from(container.querySelectorAll('g')).find(
            (g) => g.getAttribute('transform') === 'translate(60, 78)',
        );
        expect(medalGroup).toBeFalsy();
    });

    it('respects the size prop on the outer wrapper', () => {
        const { container } = render(<TemariProto size={200} />);
        const outer = container.firstChild as HTMLElement;
        expect(outer.style.width).toBe('200px');
    });

    it('renders the ball-form viewBox', () => {
        const { container } = render(<TemariProto />);
        const svg = container.querySelector('svg');
        expect(svg?.getAttribute('viewBox')).toBe('0 -4 120 140');
    });

    it('renders the shirt band when equipped.kaus is set', () => {
        const { container } = render(
            <TemariProto equipped={{ kaus: 'hujan' }} />,
        );
        const rects = Array.from(container.querySelectorAll('rect'));
        const hasBand = rects.some((r) => r.getAttribute('fill') === '#5E89B5');
        expect(hasBand).toBe(true);
    });

    it('renders the shorts band when equipped.celana is set', () => {
        const { container } = render(
            <TemariProto equipped={{ celana: 'split' }} />,
        );
        const rects = Array.from(container.querySelectorAll('rect'));
        const hasBand = rects.some((r) => r.getAttribute('fill') === '#2c355c');
        expect(hasBand).toBe(true);
    });

    it('renders the trailing ribbon when equipped.sepatu is set', () => {
        const { container } = render(
            <TemariProto equipped={{ sepatu: 'legendaris' }} />,
        );
        const paths = Array.from(container.querySelectorAll('path'));
        const hasRibbon = paths.some(
            (p) => p.getAttribute('stroke') === '#D9B23A',
        );
        expect(hasRibbon).toBe(true);
    });

    it('renders two resting tendrils when not holding', () => {
        const { container } = render(<TemariProto pose="proud" />);
        const tendrils = Array.from(container.querySelectorAll('path')).filter(
            (p) => p.getAttribute('stroke-width') === '2.2',
        );
        expect(tendrils).toHaveLength(2);
    });

    it.each(['holding', 'reading'] as const)(
        'grips a book in the %s pose',
        (pose) => {
            const { container } = render(<TemariProto pose={pose} />);
            expect(
                container.querySelector('#temari-book-glow'),
            ).toBeInTheDocument();
            // Resting tendrils are replaced by the book grip in held poses.
            const restingTendrils = Array.from(
                container.querySelectorAll('path'),
            ).filter((p) => p.getAttribute('stroke-width') === '2.2');
            expect(restingTendrils).toHaveLength(0);
        },
    );

    it.each([
        'proud',
        'pumped',
        'excited',
        'wobble',
        'observational',
        'glow',
    ] as const)('shows no held book in the %s pose', (pose) => {
        const { container } = render(<TemariProto pose={pose} />);
        expect(
            container.querySelector('#temari-book-glow'),
        ).not.toBeInTheDocument();
    });

    it('applies the drop-shadow filter by default and omits it when dropShadow=false', () => {
        const withShadow = render(<TemariProto pose="proud" />);
        expect(
            withShadow.container.querySelector(
                'g[filter="url(#temari-shadow)"]',
            ),
        ).toBeInTheDocument();

        const noShadow = render(
            <TemariProto pose="proud" dropShadow={false} />,
        );
        expect(
            noShadow.container.querySelector('g[filter="url(#temari-shadow)"]'),
        ).not.toBeInTheDocument();
    });

    it('renders platina medal with a glow ring', () => {
        const { container } = render(
            <TemariProto equipped={{ medal: 'platina' }} />,
        );
        const medalGroup = Array.from(container.querySelectorAll('g')).find(
            (g) => g.getAttribute('transform') === 'translate(60, 78)',
        );
        expect(medalGroup).toBeTruthy();
        const rings = Array.from(medalGroup!.querySelectorAll('circle'));
        const glowRing = rings.find((c) => c.getAttribute('r') === '8');
        expect(glowRing).toBeTruthy();
    });

    it('falls back to the default thread texture when no season phase is given', () => {
        const { container } = render(<TemariProto />);
        const stroked = Array.from(
            container.querySelectorAll('ellipse'),
        ).filter((e) => e.getAttribute('fill') === 'none');
        expect(stroked.length).toBeGreaterThan(0);
    });

    it('renders denser thread coverage for peak than for base', () => {
        const base = render(<TemariProto seasonPhase="base" />);
        const peak = render(<TemariProto seasonPhase="peak" />);
        const countBands = (container: HTMLElement) =>
            Array.from(container.querySelectorAll('ellipse')).filter(
                (e) =>
                    e.getAttribute('fill') === 'none' &&
                    e.getAttribute('stroke'),
            ).length;
        expect(countBands(peak.container)).toBeGreaterThan(
            countBands(base.container),
        );
    });

    it('adds a rested shine for the taper phase without dropping coverage', () => {
        const peak = render(<TemariProto seasonPhase="peak" />);
        const taper = render(<TemariProto seasonPhase="taper" />);
        const countBands = (container: HTMLElement) =>
            Array.from(container.querySelectorAll('ellipse')).filter(
                (e) =>
                    e.getAttribute('fill') === 'none' &&
                    e.getAttribute('stroke'),
            ).length;
        // Same band count as peak (no regression)...
        expect(countBands(taper.container)).toBe(countBands(peak.container));
        // ...plus an extra highlight-gradient ellipse for the shine treatment.
        const shineEllipses = (container: HTMLElement) =>
            Array.from(container.querySelectorAll('ellipse')).filter(
                (e) => e.getAttribute('fill') === 'url(#ball-highlight)',
            ).length;
        expect(shineEllipses(taper.container)).toBeGreaterThan(
            shineEllipses(peak.container),
        );
    });
});
