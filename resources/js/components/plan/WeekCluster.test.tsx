import type { SeasonSummaryWeek } from '@/lib/plan';

import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import WeekCluster from './WeekCluster';

function week(overrides: Partial<SeasonSummaryWeek> = {}): SeasonSummaryWeek {
    return {
        week_start: '2026-06-15',
        phase: 'base',
        type: 'history',
        planned_km: 30,
        actual_km: null,
        sessions: 5,
        ...overrides,
    };
}

describe('WeekCluster', () => {
    it('totals the weeks it stands in for', () => {
        render(
            <WeekCluster
                weeks={[
                    week({ planned_km: 30, sessions: 5 }),
                    week({
                        week_start: '2026-06-22',
                        planned_km: 32,
                        sessions: 4,
                    }),
                ]}
                label="2 weeks behind"
                isLast={false}
                onExpand={vi.fn()}
            />,
        );

        expect(screen.getByText('2 weeks behind')).toBeInTheDocument();
        expect(screen.getByText('62 km · 9 sessions')).toBeInTheDocument();
    });

    it('expands on click', () => {
        const onExpand = vi.fn();
        render(
            <WeekCluster
                weeks={[week()]}
                label="1 week behind"
                isLast={false}
                onExpand={onExpand}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /1 week behind/ }));
        expect(onExpand).toHaveBeenCalledOnce();
    });

    it('runs a completed rail through, and a future one dimmed', () => {
        const { container, rerender } = render(
            <WeekCluster
                weeks={[week({ type: 'history' })]}
                label="1 week behind"
                isLast={false}
                onExpand={vi.fn()}
            />,
        );
        expect(container.querySelector('.bg-horizon')).toBeInTheDocument();

        rerender(
            <WeekCluster
                weeks={[week({ type: 'lookahead' })]}
                label="1 week ahead"
                isLast={false}
                onExpand={vi.fn()}
            />,
        );
        expect(container.querySelector('.bg-horizon')).not.toBeInTheDocument();
    });

    it('drops the connecting rail on the last node', () => {
        const { container } = render(
            <WeekCluster
                weeks={[week()]}
                label="1 week ahead"
                isLast
                onExpand={vi.fn()}
            />,
        );

        expect(container.querySelector('.flex-1.rounded-full')).toBeNull();
    });
});
