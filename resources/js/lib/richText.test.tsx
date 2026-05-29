import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { renderBold } from './richText';

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
