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
        const hasStar = paths.some((p) => p.getAttribute('d')?.includes('M 60 49'));
        expect(hasStar).toBe(true);
    });

    it('renders an aura layer when equipped.aura is true', () => {
        const { container } = render(<TemariProto equipped={{ aura: true }} />);
        expect(container.querySelector('#temari-aura-grad')).toBeInTheDocument();
    });

    it('renders the pita sash when equipped.pita is true', () => {
        const { container } = render(<TemariProto equipped={{ pita: true }} />);
        const paths = Array.from(container.querySelectorAll('path'));
        const hasPita = paths.some((p) => p.getAttribute('d')?.startsWith('M 32 94'));
        expect(hasPita).toBe(true);
    });

    it('renders a default bronze medal for proud / pumped / etc.', () => {
        const { container } = render(<TemariProto pose="proud" />);
        // Default medal is the pertama (bronze) coin at translate(60, 100).
        const transformed = Array.from(container.querySelectorAll('g')).find(
            (g) => g.getAttribute('transform') === 'translate(60, 100)',
        );
        expect(transformed).toBeTruthy();
    });

    it('skips the medal when equipped.medal === "none"', () => {
        const { container } = render(<TemariProto pose="proud" equipped={{ medal: 'none' }} />);
        const transformed = Array.from(container.querySelectorAll('g')).find(
            (g) => g.getAttribute('transform') === 'translate(60, 100)',
        );
        expect(transformed).toBeFalsy();
    });

    it('respects the size prop on the outer wrapper', () => {
        const { container } = render(<TemariProto size={200} />);
        const outer = container.firstChild as HTMLElement;
        expect(outer.style.width).toBe('200px');
    });
});
