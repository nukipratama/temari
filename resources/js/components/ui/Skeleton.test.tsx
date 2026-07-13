import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Skeleton from './Skeleton';

describe('Skeleton', () => {
    it('renders a decorative pulsing block', () => {
        const { container } = render(<Skeleton />);
        const el = container.firstElementChild;
        expect(el).toHaveClass('animate-pulse');
        expect(el).toHaveAttribute('aria-hidden');
    });

    it('applies caller-supplied sizing classes', () => {
        const { container } = render(<Skeleton className="h-[180px] rounded-xl" />);
        expect(container.firstElementChild).toHaveClass('h-[180px]');
        expect(container.firstElementChild).toHaveClass('rounded-xl');
    });
});
