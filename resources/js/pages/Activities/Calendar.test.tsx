import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { makeUser, setMockPage, stubSyncAnimationFrame } from '@/test/setup';

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
        notification_retry_after_seconds: null,
        ...overrides,
    };
}

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser({ name: 'Andi', first_name: 'Andi' }) },
        flash: {},
        demoLoginEnabled: false,
    });
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
        ...r,
    }));
}

// Two complete weeks (14 cells) starting Monday — enough to render at least one
// full week row.
const TWO_WEEK_CELLS: CalendarCell[] = cellsFor([
    { date: '2026-04-27', day: 27 }, // Mon prev month
    { date: '2026-04-28', day: 28 },
    { date: '2026-04-29', day: 29 },
    { date: '2026-04-30', day: 30 },
    {
        date: '2026-05-01',
        day: 1,
        is_current_month: true,
        distance_km: 5,
        trimp: 50,
        pace_sec_per_km: 360,
        avg_hr: 145,
        mood: 'easy',
        activity_id: 100,
    },
    { date: '2026-05-02', day: 2, is_current_month: true },
    { date: '2026-05-03', day: 3, is_current_month: true },
    { date: '2026-05-04', day: 4, is_current_month: true },
    {
        date: '2026-05-05',
        day: 5,
        is_current_month: true,
        distance_km: 7.2,
        trimp: 80,
        pace_sec_per_km: 380,
        avg_hr: 150,
        mood: 'blazing',
        activity_id: 101,
    },
    { date: '2026-05-06', day: 6, is_current_month: true },
    {
        date: '2026-05-07',
        day: 7,
        is_current_month: true,
        is_today: true,
        distance_km: 3.5,
        trimp: 25,
        mood: 'overloaded',
        activity_id: 102,
    },
    { date: '2026-05-08', day: 8, is_current_month: true },
    { date: '2026-05-09', day: 9, is_current_month: true },
    { date: '2026-05-10', day: 10, is_current_month: true },
]);

const BASE_PROPS = {
    month: '2026-05',
    monthLabel: 'May 2026',
    prevMonth: '2026-04',
    nextMonth: '2026-06',
    todayMonth: '2026-05',
};

describe('calendar', () => {
    it('coach-marks the month grid on a first visit', () => {
        window.localStorage.clear();
        stubSyncAnimationFrame();
        render(<Calendar {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        expect(
            screen.getByRole('dialog', { name: 'tap any day' }),
        ).toBeInTheDocument();
    });

    it("renders the month label and the prototype's two-letter weekday header", () => {
        render(<Calendar {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        expect(
            screen.getByRole('heading', { name: 'May 2026' }),
        ).toBeInTheDocument();
        for (const day of ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su']) {
            expect(screen.getByText(day)).toBeInTheDocument();
        }
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

    it('renders per-week km totals in the week summary column', () => {
        render(<Calendar {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        // Week 2: 7.2 + 3.5 = 10.7k.
        expect(screen.getByText('10.7k')).toBeInTheDocument();
        expect(screen.getByText('WK 1')).toBeInTheDocument();
        expect(screen.getByText('WK 2')).toBeInTheDocument();
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

    it("names today in the cell's accessible label, not by fill alone", () => {
        render(<Calendar {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        expect(
            screen.getByLabelText(/2026-05-07 \(today\)/),
        ).toBeInTheDocument();
    });

    it('hides the "Today" jump-back when already on the current month', () => {
        render(<Calendar {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        expect(
            screen.queryByRole('link', { name: 'Jump to current month' }),
        ).not.toBeInTheDocument();
    });

    it('shows the "Today" jump-back when viewing a different month', () => {
        render(
            <Calendar
                {...BASE_PROPS}
                cells={TWO_WEEK_CELLS}
                month="2026-04"
                todayMonth="2026-05"
            />,
        );
        expect(
            screen.getByRole('link', { name: 'Jump to current month' }),
        ).toHaveAttribute('href', '/history?view=calendar');
    });

    it('renders prev / next nav buttons with correct hrefs', () => {
        render(<Calendar {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        expect(
            screen.getByRole('link', { name: 'Previous month' }),
        ).toHaveAttribute('href', '/history?view=calendar&month=2026-04');
        expect(
            screen.getByRole('link', { name: 'Next month' }),
        ).toHaveAttribute('href', '/history?view=calendar&month=2026-06');
    });

    it('renders all six mood swatches in the legend', () => {
        render(<Calendar {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        ['blazing', 'easy', 'wobbly', 'gassed', 'overloaded', 'chill'].forEach(
            (label) => {
                expect(screen.getByText(label)).toBeInTheDocument();
            },
        );
    });

    // The row sits beside that week's own ISO-week recap, so it counts the
    // whole Mon-Sun week rather than only the part inside the viewed month.
    it('counts prev-month padding days in the week total, muted but included', () => {
        const cells = cellsFor([
            {
                date: '2026-04-27',
                day: 27,
                is_current_month: false,
                distance_km: 10,
                trimp: 100,
            },
            { date: '2026-04-28', day: 28, is_current_month: false },
            { date: '2026-04-29', day: 29, is_current_month: false },
            { date: '2026-04-30', day: 30, is_current_month: false },
            {
                date: '2026-05-01',
                day: 1,
                is_current_month: true,
                distance_km: 5,
                trimp: 50,
                activity_id: 100,
            },
            { date: '2026-05-02', day: 2, is_current_month: true },
            { date: '2026-05-03', day: 3, is_current_month: true },
        ]);
        render(<Calendar {...BASE_PROPS} cells={cells} />);
        expect(screen.getByText('15.0k')).toBeInTheDocument();
        expect(screen.getByText('WK 1')).toBeInTheDocument();
    });

    it('renders the Feed ⇄ Calendar nav with calendar active', () => {
        render(<Calendar {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        expect(screen.getByText('calendar').closest('a')).toHaveClass(
            'bg-card',
        );
    });

    it('renders the viewed month totals (not lifetime) as a meta line', () => {
        render(<Calendar {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        // Two current-month runs: 5 + 7.2 + 3.5 km = 15.7, 3 runs, 50+80+25 TRIMP = 155.
        expect(
            screen.getByText(/3 runs · 15\.7 km · 155 TRIMP/),
        ).toBeInTheDocument();
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
        expect(screen.getByLabelText('2026-05-01: no run')).toHaveTextContent(
            '1',
        );
    });

    it('rolls multi-activity days into a non-linked cell', () => {
        const cells = cellsFor([
            {
                date: '2026-05-01',
                day: 1,
                is_current_month: true,
                distance_km: 10,
                trimp: 100,
                mood: 'gassed',
                activity_id: null,
            },
            { date: '2026-05-02', day: 2, is_current_month: true },
            { date: '2026-05-03', day: 3, is_current_month: true },
            { date: '2026-05-04', day: 4, is_current_month: true },
            { date: '2026-05-05', day: 5, is_current_month: true },
            { date: '2026-05-06', day: 6, is_current_month: true },
            { date: '2026-05-07', day: 7, is_current_month: true },
        ]);
        render(<Calendar {...BASE_PROPS} cells={cells} />);
        const activityLinks = screen
            .getAllByRole('link')
            .filter((el) =>
                (el.getAttribute('href') ?? '').startsWith('/activities/'),
            );
        expect(activityLinks).toHaveLength(0);
    });

    it('renders the page chrome even with an empty cells array', () => {
        render(<Calendar {...BASE_PROPS} cells={[]} />);
        // No grid rows since chunkIntoWeeks returns []. Chrome (month label + legend) still shows.
        expect(
            screen.getByRole('heading', { name: 'May 2026' }),
        ).toBeInTheDocument();
        expect(screen.getByText('blazing')).toBeInTheDocument();
    });

    describe('monthly recap card', () => {
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

        it('renders no narration/trigger when a past month is not yet narrated', () => {
            render(
                <Calendar
                    {...BASE_PROPS}
                    month="2026-04"
                    cells={TWO_WEEK_CELLS}
                    monthlyRecap={makeRecap({
                        status: 'pending',
                        content: null,
                        id: null,
                    })}
                />,
            );
            expect(
                screen.queryByText(/thinking it over/),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: /try again/ }),
            ).not.toBeInTheDocument();
        });

        it('suppresses every trigger on the still-open current month and reads "belum tersedia"', () => {
            render(
                <Calendar
                    {...BASE_PROPS}
                    cells={TWO_WEEK_CELLS}
                    monthlyRecap={makeRecap({
                        status: 'pending',
                        content: null,
                        id: null,
                        is_chain_head: false,
                    })}
                />,
            );
            expect(
                screen.getByText("this month's recap isn't ready yet."),
            ).toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: /try again/ }),
            ).not.toBeInTheDocument();
        });

        it('shows a "try again" resume action when a past month recap failed', () => {
            render(
                <Calendar
                    {...BASE_PROPS}
                    month="2026-04"
                    cells={TWO_WEEK_CELLS}
                    monthlyRecap={makeRecap({
                        status: 'failed',
                        content: null,
                    })}
                />,
            );
            expect(
                screen.getByRole('button', { name: /try again/ }),
            ).toBeInTheDocument();
        });

        it('shows the "reread" regenerate action only on the chain-head month', () => {
            render(
                <Calendar
                    {...BASE_PROPS}
                    month="2026-04"
                    cells={TWO_WEEK_CELLS}
                    monthlyRecap={makeRecap({ is_chain_head: true })}
                />,
            );
            expect(
                screen.getByRole('button', { name: /reread/ }),
            ).toBeInTheDocument();
        });

        it('hides the regenerate action on a historical (non-head) month', () => {
            render(
                <Calendar
                    {...BASE_PROPS}
                    month="2026-04"
                    cells={TWO_WEEK_CELLS}
                    monthlyRecap={makeRecap({ is_chain_head: false })}
                />,
            );
            expect(
                screen.queryByRole('button', { name: /reread/ }),
            ).not.toBeInTheDocument();
        });

        it('shows a muted send button that nudges (no send) when no channel is wired', () => {
            // telegramConnected defaults to falsy in beforeEach.
            vi.mocked(router.post).mockReset();
            render(
                <Calendar
                    {...BASE_PROPS}
                    month="2026-04"
                    cells={TWO_WEEK_CELLS}
                    monthlyRecap={makeRecap()}
                />,
            );
            fireEvent.click(screen.getByText('Send notification'));
            expect(router.post).not.toHaveBeenCalled();
        });

        it('force-sends the monthly recap when a channel is wired and the button is clicked', () => {
            vi.mocked(router.post).mockReset();
            setMockPage({
                auth: { user: makeUser({ name: 'Andi', first_name: 'Andi' }) },
                flash: {},
                demoLoginEnabled: false,
                telegramConnected: true,
            });
            render(
                <Calendar
                    {...BASE_PROPS}
                    month="2026-04"
                    cells={TWO_WEEK_CELLS}
                    monthlyRecap={makeRecap()}
                />,
            );
            fireEvent.click(screen.getByText('Send notification'));
            expect(router.post).toHaveBeenCalledWith(
                '/recaps/monthly/2026-04/send',
                {},
                expect.objectContaining({ preserveScroll: true }),
            );
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

    it('breaks ties by MOOD_ORDER so the pick is deterministic', () => {
        // blazing and chill each appear once; blazing is earlier in MOOD_ORDER.
        const cells = cellsFor([
            { date: '2026-05-01', day: 1, mood: 'chill' },
            { date: '2026-05-02', day: 2, mood: 'blazing' },
        ]);
        expect(dominantMoodOf(cells)).toBe('blazing');
    });

    it('excludes padding days from adjacent months', () => {
        const cells = cellsFor([
            {
                date: '2026-04-30',
                day: 30,
                is_current_month: false,
                mood: 'chill',
            },
            {
                date: '2026-04-29',
                day: 29,
                is_current_month: false,
                mood: 'chill',
            },
            {
                date: '2026-05-01',
                day: 1,
                is_current_month: true,
                mood: 'blazing',
            },
        ]);
        expect(dominantMoodOf(cells)).toBe('blazing');
    });

    it('returns null when the month has no runs', () => {
        const cells = cellsFor([
            { date: '2026-05-01', day: 1 },
            { date: '2026-05-02', day: 2 },
        ]);
        expect(dominantMoodOf(cells)).toBeNull();
    });
});
