import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { EditablePlanDay, PlanWeek, SeasonSummaryWeek } from '@/lib/plan';

import { defined } from '@/test/defined';

import WeeksList from './WeeksList';

function week(overrides: Partial<SeasonSummaryWeek> = {}): SeasonSummaryWeek {
    return {
        week_start: '2026-06-15',
        phase: 'base',
        zone: 'block',
        type: 'history',
        planned_km: 30.4,
        actual_km: 27.6,
        sessions: 5,
        adherence_pct: null,
        ...overrides,
    };
}

function detail(weekStart: string, scores: (number | null)[]): PlanWeek {
    return {
        week_start: weekStart,
        phase: 'base',
        type: 'history',
        days: scores.map(
            (score, index) =>
                ({
                    date: `${weekStart.slice(0, 8)}${String(
                        Number(weekStart.slice(8)) + index,
                    ).padStart(2, '0')}`,
                    compliance_score: score,
                }) as EditablePlanDay,
        ),
    };
}

/** An old unloaded week, a loaded week behind, this week, a loaded and an unloaded week ahead. */
const SEASON: SeasonSummaryWeek[] = [
    week({ week_start: '2026-06-01', adherence_pct: 94 }),
    week({ week_start: '2026-06-08', actual_km: null, adherence_pct: 80 }),
    week({
        week_start: '2026-06-15',
        type: 'current',
        phase: 'build',
        zone: 'general',
    }),
    week({ week_start: '2026-06-22', type: 'lookahead', phase: 'peak' }),
    week({ week_start: '2026-06-29', type: 'lookahead', phase: 'taper' }),
];

const DETAIL: Record<string, PlanWeek> = {
    '2026-06-08': detail('2026-06-08', [100, 100, null]),
    '2026-06-15': detail('2026-06-15', [100]),
    '2026-06-22': detail('2026-06-22', [null]),
};

function renderList(overrides: Partial<Parameters<typeof WeeksList>[0]> = {}) {
    const onShow = vi.fn();
    render(
        <WeeksList
            weeks={SEASON}
            detailByWeekStart={DETAIL}
            shownWeekStart="2026-06-15"
            onShow={onShow}
            {...overrides}
        />,
    );
    return { onShow, rows: screen.getAllByRole('listitem') };
}

describe('WeeksList', () => {
    it('lists every week of the season with its number, dates and phase', () => {
        const { rows } = renderList();

        expect(rows).toHaveLength(5);
        expect(rows[0]).toHaveTextContent('Week 1');
        expect(rows[0]).toHaveTextContent('jun 1–7');
        expect(rows[0]).toHaveTextContent('base');
        expect(rows[3]).toHaveTextContent('peak');
        expect(rows[4]).toHaveTextContent('taper');
    });

    it('folds a general-zone week under maintain, whatever its own phase', () => {
        const { rows } = renderList();

        expect(rows[2]).toHaveTextContent('maintain');
        expect(rows[2]).not.toHaveTextContent('build');
    });

    it('shows what was run for a week behind and what is planned otherwise', () => {
        const { rows } = renderList();

        expect(rows[0]).toHaveTextContent('27.6 km');
        expect(rows[1]).toHaveTextContent('—');
        expect(rows[2]).toHaveTextContent('30.4 km');
        expect(rows[3]).toHaveTextContent('30.4 km');
    });

    it('gives a scored week behind its season adherence, loaded or not, and no other week one', () => {
        const { rows } = renderList();

        expect(rows[0]).toHaveTextContent('94%');
        expect(rows[1]).toHaveTextContent('80%');
        expect(rows[2]).not.toHaveTextContent('%');
        expect(rows[3]).not.toHaveTextContent('%');
    });

    it('marks the current week', () => {
        const { rows } = renderList();

        expect(
            within(defined(rows[2])).getByText('this week'),
        ).toBeInTheDocument();
        expect(rows[1]).not.toHaveTextContent('this week');
    });

    it('shows a loaded week when its row is picked, and says which one is shown', () => {
        const { onShow, rows } = renderList();
        const behind = within(defined(rows[1])).getByRole('button');

        expect(within(defined(rows[2])).getByRole('button')).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        expect(behind).toHaveAttribute('aria-pressed', 'false');

        fireEvent.click(behind);

        expect(onShow).toHaveBeenCalledWith('2026-06-08');
    });

    it('lets an unloaded week behind be picked, and leaves an unloaded week ahead a plain row', () => {
        const { onShow, rows } = renderList();

        fireEvent.click(within(defined(rows[0])).getByRole('button'));

        expect(onShow).toHaveBeenCalledWith('2026-06-01');
        expect(
            within(defined(rows[4])).queryByRole('button'),
        ).not.toBeInTheDocument();
        expect(screen.getAllByRole('button')).toHaveLength(4);
    });

    it('marks the week whose day rows are loading as busy', () => {
        const { rows } = renderList({ loadingWeekStart: '2026-06-01' });

        expect(within(defined(rows[0])).getByRole('button')).toHaveAttribute(
            'aria-busy',
            'true',
        );
        expect(within(defined(rows[1])).getByRole('button')).toHaveAttribute(
            'aria-busy',
            'false',
        );
    });
});
