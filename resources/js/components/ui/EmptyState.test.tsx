import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import EmptyState from './EmptyState';

describe('EmptyState', () => {
    it('renders the message inside the dashed placeholder panel', () => {
        const { container } = render(<EmptyState>Belum ada data</EmptyState>);
        expect(screen.getByText('Belum ada data')).toBeInTheDocument();
        expect(container.firstElementChild).toHaveClass('border-dashed');
        expect(container.firstElementChild).toHaveClass('border-cream-deep');
    });

    it('lets a caller override the padding via className', () => {
        const { container } = render(<EmptyState className="py-10">x</EmptyState>);
        expect(container.firstElementChild).toHaveClass('py-10');
        expect(container.firstElementChild).not.toHaveClass('py-8');
    });
});
