import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { renderBold, stripEdgeQuotes } from './richText';

describe('stripEdgeQuotes', () => {
    it('leaves text without a leading quote untouched', () => {
        expect(stripEdgeQuotes('Zone Two Zen jarang ketemu')).toBe('Zone Two Zen jarang ketemu');
    });

    it('unwraps a leading quoted name so it does not double the decorative frame', () => {
        expect(stripEdgeQuotes('"Zone Two Zen" jarang ketemu')).toBe('Zone Two Zen jarang ketemu');
    });

    it('unwraps a whole-line straight-quoted string', () => {
        expect(stripEdgeQuotes('"lari kamu stabil"')).toBe('lari kamu stabil');
    });

    it('handles a leading curly quote', () => {
        expect(stripEdgeQuotes('“Zone Two Zen” jarang')).toBe('Zone Two Zen jarang');
    });

    it('drops a lone leading quote with no matching close', () => {
        expect(stripEdgeQuotes('"lari terus tanpa henti')).toBe('lari terus tanpa henti');
    });

    it('leaves a mid-string pace quote (5\'30") untouched', () => {
        expect(stripEdgeQuotes('pace 5\'30" itu bagus')).toBe('pace 5\'30" itu bagus');
    });
});

describe('renderBold', () => {
    it('returns plain text unchanged when there is no bold', () => {
        render(<div>{renderBold('halo dunia')}</div>);
        expect(screen.getByText('halo dunia')).toBeInTheDocument();
    });

    it('wraps **bold** spans in a font-bold <strong>', () => {
        const { container } = render(<div>{renderBold('lari kamu **stabil** hari ini')}</div>);
        const strong = container.querySelector('strong');
        expect(strong).not.toBeNull();
        expect(strong).toHaveTextContent('stabil');
        expect(strong).toHaveClass('font-bold');
    });

    it('handles multiple bold spans', () => {
        const { container } = render(<div>{renderBold('**a** dan **b**')}</div>);
        expect(container.querySelectorAll('strong')).toHaveLength(2);
    });

    it('leaves dangling asterisks untouched', () => {
        render(<div>{renderBold('harga **murah')}</div>);
        expect(screen.getByText('harga **murah')).toBeInTheDocument();
    });
});
