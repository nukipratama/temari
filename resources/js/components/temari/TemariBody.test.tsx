import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import TemariBody from './TemariBody';

describe('TemariBody', () => {
    it('renders an SVG with the given size', () => {
        const { container } = render(<TemariBody size={96} />);
        const svg = container.querySelector('svg');
        expect(svg?.getAttribute('width')).toBe('96');
        expect(svg?.getAttribute('height')).toBe('96');
    });

    it('renders meridian + equator thread lines, cross-stitch grid, and tuft', () => {
        const { container } = render(<TemariBody color="#abcdef" />);
        // 2 great-circle thread paths + 1 tuft path = 3 <path> elements
        expect(container.querySelectorAll('path').length).toBe(3);
        // 12 cross-stitch positions × 2 lines each = 24 <line> elements
        expect(container.querySelectorAll('line').length).toBe(24);
    });

    it('uses currentColor by default', () => {
        const { container } = render(<TemariBody />);
        const path = container.querySelector('path');
        expect(path?.getAttribute('stroke')).toBe('currentColor');
    });
});
