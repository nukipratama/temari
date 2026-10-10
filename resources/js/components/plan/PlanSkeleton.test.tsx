import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import PlanSkeleton, { PlanWeeksSkeleton } from './PlanSkeleton';

describe('PlanSkeleton', () => {
    it('keeps the real title above the placeholders', () => {
        render(<PlanSkeleton />);

        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'the weeks ahead.',
        );
    });

    it('holds the regenerate pill and the race line at their own size', () => {
        const { container } = render(<PlanSkeleton />);
        const heading = screen.getByRole('heading', { level: 1 });

        expect(heading.parentElement?.querySelector('.skeleton')).toHaveClass(
            'h-8',
            'w-9.5',
            'rounded-full',
        );
        expect(container.querySelector('.mt-1.mb-4.h-4')).not.toBeNull();
    });

    it('ends on the same placeholder Plan shows while its weeks load', () => {
        const { container } = render(<PlanSkeleton />);
        const weeks = render(<PlanWeeksSkeleton />).container;

        expect(container.firstElementChild!.lastElementChild!.outerHTML).toBe(
            weeks.innerHTML,
        );
    });

    it('appears at once rather than through the page entrance', () => {
        const { container } = render(<PlanSkeleton />);

        expect(container.firstElementChild).not.toHaveClass('reveal');
    });
});
