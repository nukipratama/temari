import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import TemariFace from './TemariFace';
import type { Mood } from '@/types/inertia';

const MOODS: Mood[] = ['glow', 'bouncy', 'wobble', 'squished', 'spinning', 'dim'];

describe('TemariFace', () => {
    it.each(MOODS)('renders distinct face for mood %s', (mood) => {
        const { container } = render(<TemariFace mood={mood} size={100} />);
        const svg = container.querySelector('svg');
        expect(svg).toBeTruthy();
        expect(svg?.getAttribute('width')).toBe('100');
        // every mood draws 2 cheek circles regardless of expression
        expect(container.querySelectorAll('circle').length).toBeGreaterThanOrEqual(2);
    });

    it('uses given stroke color + cheek color', () => {
        const { container } = render(<TemariFace mood="dim" color="#112233" cheekColor="#445566" />);
        // cheek fill is on the wrapping <g>, not each <circle>
        const cheekGroup = container.querySelector('circle')?.parentElement;
        expect(cheekGroup?.getAttribute('fill')).toBe('#445566');
        const strokeGroup = container.querySelector('line')?.parentElement;
        expect(strokeGroup?.getAttribute('stroke')).toBe('#112233');
    });

    it('falls back to default mood rendering for unknown mood string', () => {
        // typed-as-mood escape hatch — ensures the default branch in switch is exercised
        const { container } = render(<TemariFace mood={'unknown' as unknown as Mood} />);
        expect(container.querySelector('svg')).toBeTruthy();
    });
});
