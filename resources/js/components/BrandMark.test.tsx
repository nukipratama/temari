import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import BrandMark from './BrandMark';

describe('BrandMark', () => {
    it('renders the wordmark', () => {
        render(<BrandMark />);
        expect(screen.getByText('TemanLari')).toBeInTheDocument();
    });

    it('uses cream tone on dark surfaces', () => {
        render(<BrandMark tone="cream" />);
        expect(screen.getByText('TemanLari')).toHaveClass('text-cream');
    });

    it('applies the provided className', () => {
        const { container } = render(<BrandMark className="mb-10" />);
        expect(container.firstChild).toHaveClass('mb-10');
    });
});
