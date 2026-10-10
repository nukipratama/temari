import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import Trends from '@/pages/Trends';
import { setMockDeferred } from '@/test/setup';

import TrendsSkeleton, { TrendsSectionSkeleton } from './TrendsSkeleton';

function withoutEmptyLanes(root: Element): string {
    const copy = root.cloneNode(true) as Element;
    copy.querySelectorAll('[data-slot="lane"]:empty').forEach((lane) =>
        lane.remove(),
    );
    return copy.innerHTML;
}

describe('TrendsSkeleton', () => {
    it('keeps the real title above the placeholders', () => {
        render(<TrendsSkeleton />);

        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'am i getting fitter,and at what cost?',
        );
        expect(screen.getAllByRole('status').length).toBe(4);
    });

    it('lays out exactly what Trends shows while every block is deferred', () => {
        setMockDeferred([
            'narration',
            'weekComparison',
            'load',
            'ctlTrend',
            'chartAnnotations',
            'raceOutlook',
            'supportedHistory',
        ]);
        const page = render(<Trends />).container.querySelector('.reveal')!;
        const skeleton = render(<TrendsSkeleton />).container
            .firstElementChild!;

        expect(withoutEmptyLanes(skeleton)).toBe(withoutEmptyLanes(page));
    });

    it('appears at once rather than through the page entrance', () => {
        const { container } = render(<TrendsSkeleton />);

        expect(container.firstElementChild).not.toHaveClass('reveal');
    });
});

describe('TrendsSectionSkeleton', () => {
    it('stands in a stat rail under a heading bar by default', () => {
        const { container } = render(<TrendsSectionSkeleton />);

        expect(container.querySelectorAll('.skeleton')).toHaveLength(3);
    });

    it('stands in the fitness chart at its rem height', () => {
        const { container } = render(<TrendsSectionSkeleton chart />);

        expect(container.querySelector('.h-\\[10\\.5rem\\]')).not.toBeNull();
    });
});
