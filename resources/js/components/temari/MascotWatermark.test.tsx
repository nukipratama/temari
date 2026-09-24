import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import MascotWatermark from './MascotWatermark';

describe('MascotWatermark', () => {
    it('draws the posed mascot faint and behind the content, where the card places it', () => {
        const svg = render(
            <MascotWatermark pose="gassed" className="-right-14 -bottom-15" />,
        ).container.querySelector('svg');
        const classes = svg?.getAttribute('class') ?? '';

        expect(svg?.dataset.mascot).toBe('gassed');
        expect(svg?.getAttribute('width')).toBe('200');
        expect(classes).toContain('absolute');
        expect(classes).toContain('-z-10');
        expect(classes).toContain('opacity-16');
        expect(classes).toContain('size-50');
        expect(classes).toContain('-bottom-15');
    });
});
