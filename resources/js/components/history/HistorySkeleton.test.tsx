import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import Feed from '@/pages/Activities/Feed';
import { setMockDeferred, setMockPage } from '@/test/setup';

import HistorySkeleton, {
    CalendarGridSkeleton,
    ConsistencyLineSkeleton,
    MonthRecapSkeleton,
} from './HistorySkeleton';

describe('HistorySkeleton', () => {
    it('keeps the real header with the feed half selected', () => {
        render(<HistorySkeleton href="/history" />);

        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'every runhas a story.',
        );
        expect(screen.getByText('feed').closest('a')).toHaveClass('bg-card');
    });

    it('lays out exactly what the feed shows while its runs are deferred', () => {
        setMockDeferred(['runs', 'notes', 'moods', 'weeklySnapshots']);
        const page = render(<Feed rangeFilter="8w" />).container.querySelector(
            '.reveal',
        )!;
        const skeleton = render(<HistorySkeleton href="/history" />).container
            .firstElementChild!;

        expect(skeleton.innerHTML).toBe(page.innerHTML);
    });

    it('draws the calendar half when the tab remembers the calendar', () => {
        const { container } = render(
            <HistorySkeleton href="/history?view=calendar&month=2026-10" />,
        );

        expect(screen.getByText('calendar').closest('a')).toHaveClass(
            'bg-card',
        );
        expect(container.innerHTML).toContain(
            render(<ConsistencyLineSkeleton />).container.innerHTML,
        );
        expect(container.innerHTML).toContain(
            render(<MonthRecapSkeleton />).container.innerHTML,
        );
        expect(container.innerHTML).toContain(
            render(<CalendarGridSkeleton />).container.innerHTML,
        );
        expect(
            container.querySelector(
                '.mt-3.mb-3.h-17.min-\\[900px\\]\\:h-11\\.5',
            ),
        ).not.toBeNull();
    });

    it('appears at once rather than through the page entrance', () => {
        const { container } = render(<HistorySkeleton href="/history" />);

        expect(container.firstElementChild).not.toHaveClass('reveal');
    });
});

describe('HistorySkeleton outage banner', () => {
    it('shows the AI outage banner above the page while AI is paused', () => {
        setMockPage({
            auth: { user: null },
            flash: {},
            demoLoginEnabled: false,
            aiPaused: true,
        });
        render(<HistorySkeleton href="/history" />);

        expect(screen.getByText(/catching her breath/)).toBeInTheDocument();
    });
});
