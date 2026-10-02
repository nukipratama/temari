import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { makeUser, setMockDeferred, setMockPage } from '@/test/setup';

import Calendar, {
    dominantMoodOf,
    type CalendarCell,
    type MonthlyRecap,
} from './Calendar';

function makeRecap(overrides: Partial<MonthlyRecap> = {}): MonthlyRecap {
    return {
        id: 1,
        status: 'done',
        content: 'May was full and the rhythm held steady.',
        type: 'monthly_recap',
        subject_type: 'monthly_recap_user_month',
        subject_id: 1,
        discriminator: '2026-05',
        is_chain_head: true,
        ...overrides,
    };
}

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser({ name: 'Andi', first_name: 'Andi' }) },
        flash: {},
        demoLoginEnabled: false,
    });
    vi.mocked(router.visit).mockClear();
});

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

// Two complete weeks (14 cells) starting Monday — enough to render at least one
// full week row.
const TWO_WEEK_CELLS: CalendarCell[] = cellsFor([
    { date: '2026-04-27', day: 27, is_current_month: false },
    { date: '2026-04-28', day: 28, is_current_month: false },
    { date: '2026-04-29', day: 29, is_current_month: false },
    { date: '2026-04-30', day: 30, is_current_month: false },
    {
        date: '2026-05-01',
        day: 1,
        distance_km: 5,
        activity_id: 100,
        effort: 'easy',
        runs: [
            {
                activity_id: 100,
                name: 'easy run',
                distance_km: 5,
                pace_sec_per_km: 360,
                effort: 'easy',
                mood: 'easy',
            },
        ],
    },
    { date: '2026-05-02', day: 2 },
    { date: '2026-05-03', day: 3 },
    { date: '2026-05-04', day: 4 },
    {
        date: '2026-05-05',
        day: 5,
        distance_km: 7.2,
        activity_id: 101,
        effort: 'hard',
        runs: [
            {
                activity_id: 101,
                name: 'tempo',
                distance_km: 7.2,
                pace_sec_per_km: 320,
                effort: 'hard',
                mood: 'blazing',
            },
        ],
    },
    { date: '2026-05-06', day: 6 },
    {
        date: '2026-05-07',
        day: 7,
        is_today: true,
        distance_km: 3.5,
        activity_id: 102,
        effort: 'easy',
        runs: [
            {
                activity_id: 102,
                name: 'shakeout',
                distance_km: 3.5,
                pace_sec_per_km: 400,
                effort: 'easy',
                mood: 'overloaded',
            },
        ],
    },
    { date: '2026-05-08', day: 8 },
    { date: '2026-05-09', day: 9 },
    { date: '2026-05-10', day: 10 },
]);

const BASE_PROPS = {
    month: '2026-05',
    monthLabel: 'May 2026',
    prevMonth: '2026-04',
    nextMonth: '2026-06',
    todayMonth: '2026-05',
};

describe('calendar', () => {
    it('shows the AI pause banner while paused, before the deferred recap lands', () => {
        setMockPage({ auth: { user: makeUser() }, aiPaused: true });
        setMockDeferred(['cells', 'weeklySnapshots', 'monthlyRecap']);

        render(<Calendar {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);

        expect(screen.getByText(/catching her breath/)).toBeInTheDocument();
    });

    it('renders the month label and the lowercase two-letter weekday header', () => {
        render(<Calendar {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        expect(
            screen.getByRole('heading', { name: 'May 2026' }),
        ).toBeInTheDocument();
        for (const day of ['mo', 'tu', 'we', 'th', 'fr', 'sa', 'su']) {
            expect(screen.getByText(day)).toBeInTheDocument();
        }
    });

    it('paints the month nav while the grid skeletons', () => {
        setMockDeferred(['cells', 'weeklySnapshots', 'monthlyRecap']);

        const { container } = render(
            <Calendar
                {...BASE_PROPS}
                cells={TWO_WEEK_CELLS}
                monthlyRecap={makeRecap()}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'May 2026' }),
        ).toBeInTheDocument();
        expect(container.querySelectorAll('.skeleton').length).toBeGreaterThan(
            0,
        );
    });

    it('counts lifetime activities in the shared eyebrow', () => {
        render(
            <Calendar
                {...BASE_PROPS}
                cells={TWO_WEEK_CELLS}
                lifetime={{
                    total_runs: 63,
                    total_km: 544,
                    first_run_at: '2026-02-19T06:00:00+07:00',
                }}
            />,
        );
        expect(screen.getByText('History · 63 activities')).toBeInTheDocument();
    });

    it('renders the consistency line derived from the cells', () => {
        const { container } = render(
            <Calendar {...BASE_PROPS} cells={TWO_WEEK_CELLS} />,
        );
        // 3 current-month runs: 5 + 7.2 + 3.5 = 15.7 -> rounds to 16 km, ran 3/10 days.
        const line = Array.from(container.querySelectorAll('p')).find((p) =>
            p.textContent?.includes('runs ·'),
        );
        expect(line?.textContent).toBe(
            '3 runs · 16 km · ran 3/10 days · longest streak 1',
        );
    });

    it('renders no TRIMP in the header, which left with the old design', () => {
        render(<Calendar {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        expect(screen.queryByText(/TRIMP/)).not.toBeInTheDocument();
    });

    it('renders per-week km totals in the week column', () => {
        render(<Calendar {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        expect(screen.getByText('week 1')).toBeInTheDocument();
        expect(screen.getByText('week 2')).toBeInTheDocument();
    });

    it('links the day cell with a single activity to its detail page', () => {
        render(<Calendar {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        const cellLinks = screen.getAllByRole('link');
        const activityLinks = cellLinks
            .map((el) => el.getAttribute('href') ?? '')
            .filter((href) => href.startsWith('/activities/'));
        expect(activityLinks).toContain('/activities/100');
        expect(activityLinks).toContain('/activities/101');
        expect(activityLinks).toContain('/activities/102');
    });

    it('rings today in the grid, named in its accessible label', () => {
        render(<Calendar {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        expect(screen.getByLabelText(/may 7 \(today\)/)).toBeInTheDocument();
    });

    it('renders prev / next nav links with correct hrefs and a partial reload', () => {
        render(<Calendar {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        expect(
            screen.getByRole('link', { name: 'Previous month' }),
        ).toHaveAttribute('href', '/history?view=calendar&month=2026-04');
        expect(
            screen.getByRole('link', { name: 'Next month' }),
        ).toHaveAttribute('href', '/history?view=calendar&month=2026-06');
    });

    it('swipes left to the next month via a partial reload', () => {
        render(
            <Calendar
                {...BASE_PROPS}
                cells={TWO_WEEK_CELLS}
                monthlyRecap={makeRecap()}
            />,
        );
        const target = screen.getByText(
            /May was full and the rhythm held steady\./,
        );

        fireEvent.touchStart(target, {
            touches: [{ identifier: 1, clientX: 200, clientY: 100 }],
        });
        fireEvent.touchEnd(target, {
            changedTouches: [{ identifier: 1, clientX: 100, clientY: 100 }],
        });

        expect(router.visit).toHaveBeenCalledWith(
            '/history?view=calendar&month=2026-06',
            expect.objectContaining({
                only: expect.arrayContaining(['cells']),
            }),
        );
    });

    it('swipes right to the previous month via a partial reload', () => {
        render(<Calendar {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        const target = screen.getByTestId('calendar-swipe-area');

        fireEvent.touchStart(target, {
            touches: [{ identifier: 1, clientX: 100, clientY: 100 }],
        });
        fireEvent.touchEnd(target, {
            changedTouches: [{ identifier: 1, clientX: 200, clientY: 100 }],
        });

        expect(router.visit).toHaveBeenCalledWith(
            '/history?view=calendar&month=2026-04',
            expect.objectContaining({
                only: expect.arrayContaining(['cells']),
            }),
        );
    });

    it('renders the effort legend with words, replacing the old mood legend', () => {
        render(<Calendar {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        for (const word of [
            'easy',
            'steady',
            'hard',
            'unscored',
            'planned rest',
        ]) {
            expect(screen.getByText(word)).toBeInTheDocument();
        }
        expect(screen.queryByText('blazing')).not.toBeInTheDocument();
    });

    it('draws a run-less day as a plain numbered box', () => {
        const cells = cellsFor([
            { date: '2026-05-01', day: 1 },
            { date: '2026-05-02', day: 2 },
            { date: '2026-05-03', day: 3 },
            { date: '2026-05-04', day: 4 },
            { date: '2026-05-05', day: 5 },
            { date: '2026-05-06', day: 6 },
            { date: '2026-05-07', day: 7 },
        ]);
        render(<Calendar {...BASE_PROPS} cells={cells} />);
        expect(screen.getByLabelText('may 1: no run')).toHaveTextContent('1');
    });

    it('opens the multi-run sheet for a 2+ run day instead of linking', async () => {
        const cells = cellsFor([
            {
                date: '2026-05-01',
                day: 1,
                distance_km: 10,
                activity_id: null,
                effort: 'hard',
                runs: [
                    {
                        activity_id: 1,
                        name: 'am',
                        distance_km: 6,
                        pace_sec_per_km: 300,
                        effort: 'hard',
                        mood: null,
                    },
                    {
                        activity_id: 2,
                        name: 'pm',
                        distance_km: 4,
                        pace_sec_per_km: 320,
                        effort: 'easy',
                        mood: null,
                    },
                ],
            },
            { date: '2026-05-02', day: 2 },
            { date: '2026-05-03', day: 3 },
            { date: '2026-05-04', day: 4 },
            { date: '2026-05-05', day: 5 },
            { date: '2026-05-06', day: 6 },
            { date: '2026-05-07', day: 7 },
        ]);
        render(<Calendar {...BASE_PROPS} cells={cells} />);

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /may 1/ }));
        expect(await screen.findByRole('dialog')).toBeInTheDocument();
        expect(screen.getByText('am')).toBeInTheDocument();
        expect(screen.getByText('pm')).toBeInTheDocument();
    });

    it('renders the page chrome even with an empty cells array', () => {
        render(<Calendar {...BASE_PROPS} cells={[]} />);
        expect(
            screen.getByRole('heading', { name: 'May 2026' }),
        ).toBeInTheDocument();
        expect(screen.getByText('easy')).toBeInTheDocument();
    });

    describe('monthly recap card', () => {
        it('places stats before the recap and the effort legend before the grid', () => {
            const { rerender } = render(
                <Calendar
                    {...BASE_PROPS}
                    todayMonth="2026-06"
                    cells={TWO_WEEK_CELLS}
                    monthlyRecap={makeRecap()}
                />,
            );
            const pastRecap = screen.getByText(
                /May was full and the rhythm held steady\./,
            );
            const pastMonthArea = screen.getByTestId('calendar-swipe-area');
            const consistency = screen.getByText(/3 runs · 16 km/);
            const grid = screen.getByText('week 1');
            expect(consistency.compareDocumentPosition(pastRecap)).toBe(
                Node.DOCUMENT_POSITION_FOLLOWING,
            );
            expect(
                screen.getByText('effort').compareDocumentPosition(grid),
            ).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
            expect(pastRecap.compareDocumentPosition(grid)).toBe(
                Node.DOCUMENT_POSITION_FOLLOWING,
            );

            rerender(
                <Calendar
                    {...BASE_PROPS}
                    month="2026-06"
                    monthLabel="June 2026"
                    todayMonth="2026-06"
                    cells={TWO_WEEK_CELLS}
                    monthlyRecap={makeRecap({
                        status: 'pending',
                        content: null,
                        discriminator: '2026-06',
                    })}
                />,
            );
            expect(screen.queryByText(/May was full/)).not.toBeInTheDocument();
            expect(screen.getByTestId('calendar-swipe-area')).not.toBe(
                pastMonthArea,
            );
            const awaitingRecap = screen.getByText(
                "this month's recap isn't ready yet.",
            );
            expect(
                screen
                    .getByText(/3 runs · 16 km/)
                    .compareDocumentPosition(awaitingRecap),
            ).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
            expect(
                awaitingRecap.compareDocumentPosition(
                    screen.getByText('week 1'),
                ),
            ).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
        });

        it('shows the deferred recap skeleton above the loaded grid', () => {
            setMockDeferred(['monthlyRecap']);
            const { container } = render(
                <Calendar
                    {...BASE_PROPS}
                    cells={TWO_WEEK_CELLS}
                    monthlyRecap={makeRecap()}
                />,
            );
            const recapSkeleton = container.querySelector('.skeleton');
            expect(recapSkeleton).not.toBeNull();
            expect(
                recapSkeleton?.compareDocumentPosition(
                    screen.getByText('week 1'),
                ),
            ).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
        });

        it("renders Temari's narrative when the recap is done", () => {
            render(
                <Calendar
                    {...BASE_PROPS}
                    cells={TWO_WEEK_CELLS}
                    monthlyRecap={makeRecap()}
                />,
            );
            expect(
                screen.getByText(/May was full and the rhythm held steady\./),
            ).toBeInTheDocument();
        });

        it('is omitted entirely when no recap prop is passed', () => {
            render(<Calendar {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
            expect(screen.queryByText(/May was full/)).not.toBeInTheDocument();
        });
    });
});

describe('dominantMoodOf', () => {
    it("picks the most frequent run mood among the month's own days", () => {
        const cells = cellsFor([
            { date: '2026-05-01', day: 1, mood: 'blazing' },
            { date: '2026-05-02', day: 2, mood: 'chill' },
            { date: '2026-05-03', day: 3, mood: 'chill' },
            { date: '2026-05-04', day: 4, mood: null },
        ]);
        expect(dominantMoodOf(cells)).toBe('chill');
    });

    it('returns null when the month has no runs', () => {
        const cells = cellsFor([
            { date: '2026-05-01', day: 1 },
            { date: '2026-05-02', day: 2 },
        ]);
        expect(dominantMoodOf(cells)).toBeNull();
    });
});
