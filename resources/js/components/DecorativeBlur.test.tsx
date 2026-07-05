import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import DecorativeBlur from './DecorativeBlur';

describe('DecorativeBlur', () => {
    it('renders an aria-hidden decorative span with the passed positioning class', () => {
        const { container } = render(<DecorativeBlur className="left-4 top-4 h-40 w-40 bg-horizon/20" />);
        const span = container.querySelector('span')!;
        expect(span).toHaveAttribute('aria-hidden');
        expect(span.className).toContain('left-4');
        expect(span.className).toContain('pointer-events-none');
    });

    it('defaults to the lg blur and drops to blur-2xl at md intensity', () => {
        const { container: lg } = render(<DecorativeBlur className="a" />);
        expect(lg.querySelector('span')!.className).toContain('blur-3xl');

        const { container: md } = render(<DecorativeBlur className="a" intensity="md" />);
        expect(md.querySelector('span')!.className).toContain('blur-2xl');
    });
});
