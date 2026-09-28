import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { WeeklySnapshotWithRecap } from '@/types/inertia';

import {
    chunkIntoWeeks,
    type CalendarCell,
    type WeekRow,
} from '@/pages/Activities/useCalendar';
import { makeUser, setMockPage } from '@/test/setup';

import CalendarGrid from './CalendarGrid';

function cellsFor(
    rows: Array<Partial<CalendarCell> & Pick<CalendarCell, 'date' | 'day'>>,
): CalendarCell[] {
    return rows.map((r) => ({
        is_current_month: true,
        is_today: false,
        distance_km: null,
        pace_sec_per_km: null,
        avg_hr: null,
        trimp: null,
        mood: null,
        rarity: null,
        activity_id: null,
        effort: null,
        runs: [],
        ...r,
    }));
}

function weekFor(
    rows: Array<Partial<CalendarCell> & Pick<CalendarCell, 'date' | 'day'>>,
): WeekRow {
    return chunkIntoWeeks(cellsFor(rows))[0];
}

const PLAIN_WEEK = weekFor([
    {
        date: '2026-05-04',
        day: 4,
        distance_km: 8,
        activity_id: 55,
        effort: 'hard',
        runs: [
            {
                activity_id: 55,
                name: 'long run',
                distance_km: 8,
                pace_sec_per_km: 330,
                effort: 'hard',
                mood: 'blazing',
            },
        ],
    },
    { date: '2026-05-05', day: 5 },
    { date: '2026-05-06', day: 6 },
    { date: '2026-05-07', day: 7, is_today: true },
    { date: '2026-05-08', day: 8 },
    { date: '2026-05-09', day: 9 },
    { date: '2026-05-10', day: 10, is_current_month: false },
]);

function snapshot(
    overrides: Partial<WeeklySnapshotWithRecap> = {},
): WeeklySnapshotWithRecap {
    return {
        id: 7,
        user_id: 1,
        week_ending: '2026-05-10',
        distance_km: 8,
        runs: 1,
        weekly_trimp: 90,
        atl_7d: 44.5,
        ctl_42d: 42,
        form: -2.5,
        form_status: 'optimal',
        avg_decoupling: 3.2,
        avg_decoupling_v2: 3.2,
        monotony: 1.2,
        strain: 384,
        is_current_week: false,
        is_chain_head: true,
        recap_analysis: {
            id: 1,
            status: 'done',
            content: 'Steady week, one strong effort.',
            type: 'weekly_recap',
            subject_type: 'weekly_snapshot',
            subject_id: 7,
            discriminator: null,
        },
        ...overrides,
    };
}

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser({ name: 'Ada', first_name: 'Ada' }) },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('CalendarGrid', () => {
    it('draws the week column and seven day bars', () => {
        render(
            <CalendarGrid
                weeks={[PLAIN_WEEK]}
                snapshotsByWeek={new Map()}
                onOpenDay={vi.fn()}
            />,
        );

        expect(screen.getByText('week 1')).toBeInTheDocument();
        expect(screen.getAllByText('8.0').length).toBeGreaterThan(0);
        for (const day of ['4', '5', '6', '7', '8', '9', '10']) {
            expect(screen.getByText(day)).toBeInTheDocument();
        }
    });

    it('separates weeks with a dashed lane line', () => {
        const { container } = render(
            <CalendarGrid
                weeks={[PLAIN_WEEK]}
                snapshotsByWeek={new Map()}
                onOpenDay={vi.fn()}
            />,
        );
        expect(
            container.querySelector('.border-dashed.border-border'),
        ).toBeInTheDocument();
    });

    it("colors the day's bar with its hardest effort", () => {
        render(
            <CalendarGrid
                weeks={[PLAIN_WEEK]}
                snapshotsByWeek={new Map()}
                onOpenDay={vi.fn()}
            />,
        );
        expect(
            screen
                .getByRole('link', { name: /2026-05-04/ })
                .querySelector('.bg-ember'),
        ).toBeInTheDocument();
    });

    it('rings today in lime', () => {
        render(
            <CalendarGrid
                weeks={[PLAIN_WEEK]}
                snapshotsByWeek={new Map()}
                onOpenDay={vi.fn()}
            />,
        );
        const todayCell = screen.getByLabelText('2026-05-07 (today): no run');
        expect(
            todayCell.querySelector('.ring-icon-accent'),
        ).toBeInTheDocument();
    });

    it('gives an unexcused planned rest day the dashed rest marker', () => {
        const week = weekFor([
            { date: '2026-05-04', day: 4, effort: 'rest' },
            { date: '2026-05-05', day: 5 },
            { date: '2026-05-06', day: 6 },
            { date: '2026-05-07', day: 7 },
            { date: '2026-05-08', day: 8 },
            { date: '2026-05-09', day: 9 },
            { date: '2026-05-10', day: 10 },
        ]);
        render(
            <CalendarGrid
                weeks={[week]}
                snapshotsByWeek={new Map()}
                onOpenDay={vi.fn()}
            />,
        );
        const cell = screen.getByLabelText('2026-05-04: planned rest');
        expect(
            cell.querySelector('.border-dashed.border-border-strong'),
        ).toBeInTheDocument();
    });

    it('gives a day with no run and no plan just a muted date, no marker', () => {
        render(
            <CalendarGrid
                weeks={[PLAIN_WEEK]}
                snapshotsByWeek={new Map()}
                onOpenDay={vi.fn()}
            />,
        );
        const cell = screen.getByLabelText('2026-05-05: no run');
        expect(
            cell.querySelector(
                '.bg-leaf, .bg-citrus, .bg-ember, .border-dashed',
            ),
        ).not.toBeInTheDocument();
    });

    describe('tap behavior', () => {
        it('links a single-run day straight to its activity detail', () => {
            render(
                <CalendarGrid
                    weeks={[PLAIN_WEEK]}
                    snapshotsByWeek={new Map()}
                    onOpenDay={vi.fn()}
                />,
            );
            expect(
                screen.getByRole('link', { name: /2026-05-04/ }),
            ).toHaveAttribute('href', '/activities/55');
        });

        it('opens the multi-run sheet callback for a 2+ run day', () => {
            const onOpenDay = vi.fn();
            const week = weekFor([
                {
                    date: '2026-05-04',
                    day: 4,
                    distance_km: 12,
                    activity_id: null,
                    effort: 'hard',
                    runs: [
                        {
                            activity_id: 1,
                            name: 'a',
                            distance_km: 8,
                            pace_sec_per_km: 300,
                            effort: 'hard',
                            mood: null,
                        },
                        {
                            activity_id: 2,
                            name: 'b',
                            distance_km: 4,
                            pace_sec_per_km: 320,
                            effort: 'easy',
                            mood: null,
                        },
                    ],
                },
                { date: '2026-05-05', day: 5 },
                { date: '2026-05-06', day: 6 },
                { date: '2026-05-07', day: 7 },
                { date: '2026-05-08', day: 8 },
                { date: '2026-05-09', day: 9 },
                { date: '2026-05-10', day: 10 },
            ]);
            render(
                <CalendarGrid
                    weeks={[week]}
                    snapshotsByWeek={new Map()}
                    onOpenDay={onOpenDay}
                />,
            );

            fireEvent.click(screen.getByRole('button', { name: /2026-05-04/ }));
            expect(onOpenDay).toHaveBeenCalledWith(week.days[0]);
        });

        it('is inert (no link, no button) for an empty day', () => {
            render(
                <CalendarGrid
                    weeks={[PLAIN_WEEK]}
                    snapshotsByWeek={new Map()}
                    onOpenDay={vi.fn()}
                />,
            );
            const cell = screen.getByLabelText('2026-05-05: no run');
            expect(cell.tagName).toBe('DIV');
        });
    });

    it('disables the week button when the week has no recap', () => {
        render(
            <CalendarGrid
                weeks={[PLAIN_WEEK]}
                snapshotsByWeek={new Map()}
                onOpenDay={vi.fn()}
            />,
        );
        expect(screen.getByRole('button', { name: /week 1/ })).toBeDisabled();
    });

    it("reveals Temari's weekly narration when the week column is opened", () => {
        render(
            <CalendarGrid
                weeks={[PLAIN_WEEK]}
                snapshotsByWeek={new Map([[PLAIN_WEEK.weekEnding, snapshot()]])}
                onOpenDay={vi.fn()}
            />,
        );

        expect(
            screen.queryByText(/Steady week, one strong effort/),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /week 1/ }));

        expect(
            screen.getByText(/Steady week, one strong effort/),
        ).toBeInTheDocument();
    });
});
