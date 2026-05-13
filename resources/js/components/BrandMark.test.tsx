import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import BrandMark from './BrandMark';

describe('BrandMark', () => {
    it('renders the wordmark by default in hero size', () => {
        render(<BrandMark />);
        expect(screen.getByText('TemanLari')).toBeInTheDocument();
    });

    it('shows the tagline only when requested', () => {
        const { rerender } = render(<BrandMark />);
        expect(screen.queryByText('Setiap Langkah Berarti')).not.toBeInTheDocument();
        rerender(<BrandMark tagline />);
        expect(screen.getByText('Setiap Langkah Berarti')).toBeInTheDocument();
    });

    it('renders the compact size (no tagline regardless of prop)', () => {
        render(<BrandMark size="compact" tagline />);
        expect(screen.getByText('TemanLari')).toBeInTheDocument();
        expect(screen.queryByText('Setiap Langkah Berarti')).not.toBeInTheDocument();
    });

    it('applies the provided className', () => {
        const { container } = render(<BrandMark className="mb-10" />);
        expect(container.firstChild).toHaveClass('mb-10');
    });
});
