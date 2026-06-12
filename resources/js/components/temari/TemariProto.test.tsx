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
        const { container } = render(<TemariProto pose="proud" animate={false} />);
        const root = container.firstChild as HTMLElement;
        expect(root.style.animation).toBe('none');
    });

    it('accepts a custom animation string', () => {
        const { container } = render(<TemariProto pose="proud" animate="custom 1s linear" />);
        const root = container.firstChild as HTMLElement;
        expect(root.style.animation).toContain('custom 1s linear');
    });

    it('renders the legendary headband star detail when equipped', () => {
        const { container } = render(<TemariProto equipped={{ headband: 'legendaris' }} />);
        const paths = Array.from(container.querySelectorAll('path'));
        const hasStar = paths.some((p) => p.getAttribute('d')?.includes('l 1 -3 l 1 3 l 3 1'));
        expect(hasStar).toBe(true);
    });

    it('renders an aura layer when equipped.aura is set', () => {
        const { container } = render(<TemariProto equipped={{ aura: 'pemanasan' }} />);
        expect(container.querySelector('#temari-aura-grad')).toBeInTheDocument();
    });

    it('skips the medal when equipped.medal === "none"', () => {
        const { container } = render(<TemariProto pose="proud" equipped={{ medal: 'none' }} />);
        const transformed = Array.from(container.querySelectorAll('g')).find(
            (g) => g.getAttribute('transform') === 'translate(60, 70)',
        );
        expect(transformed).toBeFalsy();
    });

    it('renders no medal when nothing is equipped', () => {
        const { container } = render(<TemariProto pose="proud" />);
        const medalGroup = Array.from(container.querySelectorAll('g')).find(
            (g) => g.getAttribute('transform') === 'translate(60, 70)',
        );
        expect(medalGroup).toBeFalsy();
    });

    it('respects the size prop on the outer wrapper', () => {
        const { container } = render(<TemariProto size={200} />);
        const outer = container.firstChild as HTMLElement;
        expect(outer.style.width).toBe('200px');
    });

    it('renders the full-body viewBox with torso and legs', () => {
        const { container } = render(<TemariProto />);
        const svg = container.querySelector('svg');
        expect(svg?.getAttribute('viewBox')).toBe('0 -24 120 158');
    });

    it('renders kaus layer when equipped.kaus is set', () => {
        const { container } = render(<TemariProto equipped={{ kaus: 'hujan' }} />);
        // The kaus layer should render with the "hujan" fill color (#5E89B5)
        const paths = Array.from(container.querySelectorAll('path'));
        const hasKaus = paths.some((p) => p.getAttribute('fill') === '#5E89B5');
        expect(hasKaus).toBe(true);
    });

    it('renders celana layer when equipped.celana is set', () => {
        const { container } = render(<TemariProto equipped={{ celana: 'split' }} />);
        // The celana layer should render with the "split" fill color (#2c355c)
        const paths = Array.from(container.querySelectorAll('path'));
        const hasCelana = paths.some((p) => p.getAttribute('fill') === '#2c355c');
        expect(hasCelana).toBe(true);
    });

    it('renders sepatu layer when equipped.sepatu is set', () => {
        const { container } = render(<TemariProto equipped={{ sepatu: 'legendaris' }} />);
        // The sepatu layer should render with the "legendaris" upper color (#D9B23A)
        const paths = Array.from(container.querySelectorAll('path'));
        const hasSepatu = paths.some((p) => p.getAttribute('fill') === '#D9B23A');
        expect(hasSepatu).toBe(true);
    });

    it('renders platina medal with a glow ring', () => {
        const { container } = render(<TemariProto equipped={{ medal: 'platina' }} />);
        const medalGroup = Array.from(container.querySelectorAll('g')).find(
            (g) => g.getAttribute('transform') === 'translate(60, 70)',
        );
        expect(medalGroup).toBeTruthy();
        const rings = Array.from(medalGroup!.querySelectorAll('circle'));
        const glowRing = rings.find((c) => c.getAttribute('r') === '13');
        expect(glowRing).toBeTruthy();
    });
});
