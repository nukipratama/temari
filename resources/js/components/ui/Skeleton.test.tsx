import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import Skeleton, {
    SkeletonChart,
    SkeletonProse,
    SkeletonRows,
    SkeletonStats,
} from './Skeleton';

describe('Skeleton', () => {
    it('renders a decorative shimmering block', () => {
        const { container } = render(<Skeleton />);
        const el = container.firstElementChild;
        expect(el).toHaveClass('skeleton');
        expect(el).toHaveAttribute('aria-hidden');
    });

    it('applies caller-supplied sizing classes', () => {
        const { container } = render(
            <Skeleton className="h-[180px] rounded-xl" />,
        );
        expect(container.firstElementChild).toHaveClass('h-[180px]');
        expect(container.firstElementChild).toHaveClass('rounded-xl');
    });
});

describe('SkeletonProse', () => {
    it('draws three ragged bars announced as a loading status', () => {
        const { container } = render(<SkeletonProse />);
        expect(screen.getByRole('status')).toHaveAccessibleName('Loading');
        expect(container.querySelectorAll('.skeleton')).toHaveLength(3);
        expect(container.querySelector('.w-\\[70\\%\\]')).not.toBeNull();
    });
});

describe('SkeletonStats', () => {
    it('draws three tiles by default', () => {
        const { container } = render(<SkeletonStats />);
        expect(container.querySelectorAll('.skeleton')).toHaveLength(3);
    });

    it('draws the requested number of tiles', () => {
        const { container } = render(<SkeletonStats count={5} />);
        expect(container.querySelectorAll('.skeleton')).toHaveLength(5);
    });
});

describe('SkeletonChart', () => {
    it('defaults to a plot-sized block', () => {
        const { container } = render(<SkeletonChart />);
        expect(container.querySelector('.skeleton')).toHaveClass('h-[180px]');
    });

    it('lets the caller override the height', () => {
        const { container } = render(<SkeletonChart className="h-[320px]" />);
        expect(container.querySelector('.skeleton')).toHaveClass('h-[320px]');
    });
});

describe('SkeletonRows', () => {
    it('repeats a row per requested item', () => {
        const { container } = render(<SkeletonRows count={4} />);
        expect(container.querySelectorAll('.skeleton')).toHaveLength(4);
    });
});
