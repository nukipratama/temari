import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import ThreadBandGlyph from './ThreadBandGlyph';

describe('ThreadBandGlyph', () => {
    it('draws 1 stitch for common', () => {
        const { container } = render(
            <ThreadBandGlyph rarity="common" color="#7d8694" />,
        );
        expect(container.querySelectorAll('line').length).toBe(1);
    });

    it('draws 3 stitches for rare, all leaning the same way', () => {
        const { container } = render(
            <ThreadBandGlyph rarity="rare" color="#2f81f7" />,
        );
        const lines = container.querySelectorAll('line');
        expect(lines.length).toBe(3);
        for (const line of lines) {
            expect(Number(line.getAttribute('y1'))).toBe(10);
            expect(Number(line.getAttribute('y2'))).toBe(0);
        }
    });

    it('draws 5 crossing stitches for legendary, the extra 2 leaning the other way', () => {
        const { container } = render(
            <ThreadBandGlyph rarity="legendary" color="#f5a623" />,
        );
        const lines = [...container.querySelectorAll('line')];
        expect(lines.length).toBe(5);
        const crossing = lines.filter(
            (line) => Number(line.getAttribute('y1')) === 0,
        );
        expect(crossing.length).toBe(2);
    });

    it('strokes every stitch with the given rarity color', () => {
        const { container } = render(
            <ThreadBandGlyph rarity="epic" color="#a855f7" />,
        );
        for (const line of container.querySelectorAll('line')) {
            expect(line.getAttribute('stroke')).toBe('#a855f7');
        }
    });
});
