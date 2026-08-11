import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import ThreadBandGlyph from './ThreadBandGlyph';

describe('ThreadBandGlyph', () => {
    it('draws 1 stitch for common', () => {
        const { container } = render(<ThreadBandGlyph rarity="common" />);
        expect(container.querySelectorAll('line').length).toBe(1);
    });

    it('draws 3 stitches for rare, all leaning the same way', () => {
        const { container } = render(<ThreadBandGlyph rarity="rare" />);
        const lines = container.querySelectorAll('line');
        expect(lines.length).toBe(3);
        for (const line of lines) {
            expect(Number(line.getAttribute('y1'))).toBe(10);
            expect(Number(line.getAttribute('y2'))).toBe(0);
        }
    });

    it('draws 5 crossing stitches for legendary, the extra 2 leaning the other way', () => {
        const { container } = render(<ThreadBandGlyph rarity="legendary" />);
        const lines = [...container.querySelectorAll('line')];
        expect(lines.length).toBe(5);
        const crossing = lines.filter(
            (line) => Number(line.getAttribute('y1')) === 0,
        );
        expect(crossing.length).toBe(2);
    });

    it('strokes every stitch with the rarity tint', () => {
        const { container } = render(<ThreadBandGlyph rarity="epic" />);
        for (const line of container.querySelectorAll('line')) {
            expect(line.getAttribute('stroke')).toBe('#a855f7');
        }
    });
});
