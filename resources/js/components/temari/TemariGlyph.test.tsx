import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import TemariGlyph from './TemariGlyph';

describe('TemariGlyph', () => {
    it('renders a serif T inside a ringed circle', () => {
        const { container } = render(<TemariGlyph />);
        expect(container.textContent).toContain('T');
    });

    it('respects size prop on the outer wrapper', () => {
        const { container } = render(<TemariGlyph size={48} />);
        const outer = container.firstChild as HTMLElement;
        expect(outer.style.width).toBe('48px');
    });

    it.each(['horizon', 'leaf', 'citrus'] as const)('uses %s ring colour', (color) => {
        const { container } = render(<TemariGlyph ringColor={color} />);
        expect((container.firstChild as HTMLElement).className).toMatch(new RegExp(`border-${color}`));
    });
});
