import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderBold, stripEdgeQuotes } from './richText';

describe('stripEdgeQuotes', () => {
    it('leaves text without a leading quote untouched', () => {
        expect(stripEdgeQuotes('Zone Two Zen rarely happens')).toBe(
            'Zone Two Zen rarely happens',
        );
    });

    it('unwraps a leading quoted name so it does not double the decorative frame', () => {
        expect(stripEdgeQuotes('"Zone Two Zen" rarely happens')).toBe(
            'Zone Two Zen rarely happens',
        );
    });

    it('unwraps a whole-line straight-quoted string', () => {
        expect(stripEdgeQuotes('"your run was steady"')).toBe(
            'your run was steady',
        );
    });

    it('handles a leading curly quote', () => {
        expect(stripEdgeQuotes('“Zone Two Zen” rarely')).toBe(
            'Zone Two Zen rarely',
        );
    });

    it('drops a lone leading quote with no matching close', () => {
        expect(stripEdgeQuotes('"kept running without stopping')).toBe(
            'kept running without stopping',
        );
    });

    it('leaves a mid-string pace quote (5\'30") untouched', () => {
        expect(stripEdgeQuotes('pace 5\'30" is great')).toBe(
            'pace 5\'30" is great',
        );
    });
});

describe('renderBold', () => {
    it('returns plain text unchanged when there is no bold', () => {
        render(<div>{renderBold('hello world')}</div>);
        expect(screen.getByText('hello world')).toBeInTheDocument();
    });

    it('wraps **bold** spans in a font-bold <strong>', () => {
        const { container } = render(
            <div>{renderBold('your run was **steady** today')}</div>,
        );
        const strong = container.querySelector('strong');
        expect(strong).not.toBeNull();
        expect(strong).toHaveTextContent('steady');
        expect(strong).toHaveClass('font-bold');
    });

    it('handles multiple bold spans', () => {
        const { container } = render(
            <div>{renderBold('**a** and **b**')}</div>,
        );
        expect(container.querySelectorAll('strong')).toHaveLength(2);
    });

    it('leaves dangling asterisks untouched', () => {
        render(<div>{renderBold('price **cheap')}</div>);
        expect(screen.getByText('price **cheap')).toBeInTheDocument();
    });
});
