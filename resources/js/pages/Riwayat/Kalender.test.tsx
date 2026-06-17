import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Kalender, { type CalendarCell } from './Kalender';
import { makeUser, setMockPage } from '@/test/setup';

beforeEach(() => {
    setMockPage({
        auth: { user: makeUser({ name: 'Andi', first_name: 'Andi' }) },
        flash: {},
        demoLoginEnabled: false,
    });
});

function cellsFor(rows: Array<Partial<CalendarCell> & Pick<CalendarCell, 'date' | 'day'>>): CalendarCell[] {
    return rows.map((r) => ({
        is_current_month: true,
        is_today: false,
        distance_km: null,
        pace_sec_per_km: null,
        avg_hr: null,
        trimp: null,
        mood: null,
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
    { date: '2026-05-01', day: 1, is_current_month: true, distance_km: 5, trimp: 50, pace_sec_per_km: 360, avg_hr: 145, mood: 'enteng', activity_id: 100 },
    { date: '2026-05-02', day: 2, is_current_month: true },
    { date: '2026-05-03', day: 3, is_current_month: true },
    { date: '2026-05-04', day: 4, is_current_month: true },
    { date: '2026-05-05', day: 5, is_current_month: true, distance_km: 7.2, trimp: 80, pace_sec_per_km: 380, avg_hr: 150, mood: 'nyala', activity_id: 101 },
    { date: '2026-05-06', day: 6, is_current_month: true },
    { date: '2026-05-07', day: 7, is_current_month: true, is_today: true, distance_km: 3.5, trimp: 25, mood: 'mumet', activity_id: 102 },
    { date: '2026-05-08', day: 8, is_current_month: true },
    { date: '2026-05-09', day: 9, is_current_month: true },
    { date: '2026-05-10', day: 10, is_current_month: true },
]);

const BASE_PROPS = {
    month: '2026-05',
    monthLabel: 'Mei 2026',
    prevMonth: '2026-04',
    nextMonth: '2026-06',
    todayMonth: '2026-05',
};

describe('Kalender', () => {
    it('renders the month label and short weekday headers', () => {
        render(<Kalender {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        expect(screen.getByRole('heading', { name: 'Mei 2026' })).toBeInTheDocument();
        expect(screen.getByText('Sen')).toBeInTheDocument();
        expect(screen.getByText('Min')).toBeInTheDocument();
    });

    it('shows the phone-only horizontal-scroll hint, hidden from md up where all 7 columns fit', () => {
        render(<Kalender {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        const hint = screen.getByText(/Geser buat lihat seminggu penuh/);
        expect(hint).toBeInTheDocument();
        expect(hint.className).toContain('md:hidden');
    });

    it('renders the lifetime stats eyebrow when lifetime data is provided', () => {
        render(
            <Kalender
                {...BASE_PROPS}
                cells={TWO_WEEK_CELLS}
                lifetime={{ total_runs: 63, total_km: 544, first_run_at: '2026-02-19T06:00:00+07:00' }}
            />,
        );
        expect(screen.getByText(/63 lari/i)).toBeInTheDocument();
        expect(screen.getByText(/544 km/i)).toBeInTheDocument();
        expect(screen.getByText(/sejak 19 feb 2026/i)).toBeInTheDocument();
    });

    it('renders per-week km totals in the week summary column', () => {
        render(<Kalender {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        // Week 2: 7.2 + 3.5 = 10.7 — distinct from any day-cell distance so the
        // regex won't collide with the per-day "X.XX km" rendering.
        expect(screen.getByText(/10\.7/)).toBeInTheDocument();
        expect(screen.getByText('WK 1')).toBeInTheDocument();
        expect(screen.getByText('WK 2')).toBeInTheDocument();
    });

    it('links the day cell with a single activity to its detail page', () => {
        render(<Kalender {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        const cellLinks = screen.getAllByRole('link');
        const activityLinks = cellLinks
            .map((el) => el.getAttribute('href') ?? '')
            .filter((href) => href.startsWith('/aktivitas/'));
        expect(activityLinks).toContain('/aktivitas/100');
        expect(activityLinks).toContain('/aktivitas/101');
        expect(activityLinks).toContain('/aktivitas/102');
    });

    it('renders the navy "Hari ini" badge on today\'s cell', () => {
        render(<Kalender {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        expect(screen.getByText('Hari ini')).toBeInTheDocument();
    });

    it('renders today\'s storyline quote in the today cell when provided', () => {
        render(
            <Kalender {...BASE_PROPS} cells={TWO_WEEK_CELLS} todayQuote="Form pas — sesi tempo cocok." />,
        );
        expect(screen.getByText(/Form pas — sesi tempo cocok\./)).toBeInTheDocument();
    });

    it('hides the "Hari ini" jump-back when already on the current month', () => {
        render(<Kalender {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        expect(screen.queryByRole('link', { name: 'Hari ini' })).not.toBeInTheDocument();
    });

    it('shows the "Hari ini" jump-back when viewing a different month', () => {
        render(<Kalender {...BASE_PROPS} cells={TWO_WEEK_CELLS} month="2026-04" todayMonth="2026-05" />);
        expect(screen.getByRole('link', { name: 'Hari ini' })).toHaveAttribute('href', '/kalender');
    });

    it('renders prev / next nav buttons with correct hrefs', () => {
        render(<Kalender {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        expect(screen.getByRole('link', { name: 'Bulan sebelumnya' })).toHaveAttribute('href', '/kalender?month=2026-04');
        expect(screen.getByRole('link', { name: 'Bulan berikutnya' })).toHaveAttribute('href', '/kalender?month=2026-06');
    });

    it('renders all six mood swatches in the legend', () => {
        render(<Kalender {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        ['Nyala', 'Enteng', 'Oleng', 'Lemes', 'Mumet', 'Adem'].forEach((label) => {
            expect(screen.getByText(label)).toBeInTheDocument();
        });
    });

    it('mutes prev-month cells and excludes them from week totals', () => {
        const cells = cellsFor([
            { date: '2026-04-27', day: 27, is_current_month: false, distance_km: 10, trimp: 100 },
            { date: '2026-04-28', day: 28, is_current_month: false },
            { date: '2026-04-29', day: 29, is_current_month: false },
            { date: '2026-04-30', day: 30, is_current_month: false },
            { date: '2026-05-01', day: 1, is_current_month: true, distance_km: 5, trimp: 50, activity_id: 100 },
            { date: '2026-05-02', day: 2, is_current_month: true },
            { date: '2026-05-03', day: 3, is_current_month: true },
        ]);
        render(<Kalender {...BASE_PROPS} cells={cells} />);
        // The 10 prev-month value would yield a 15.0 sum if included — assert
        // the combined total never appears, proving the prev-month cell was skipped.
        expect(screen.queryByText(/15\.0/)).not.toBeInTheDocument();
        expect(screen.getByText('WK 1')).toBeInTheDocument();
    });

    it('renders a Filter button that opens a mood filter menu', () => {
        const { container } = render(<Kalender {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        const filterButton = screen.getByRole('button', { name: /filter/i });
        expect(filterButton).toHaveAttribute('aria-expanded', 'false');
        fireEvent.click(filterButton);
        expect(filterButton).toHaveAttribute('aria-expanded', 'true');
        expect(container.querySelector('[role="menu"]')).not.toBeNull();
        expect(screen.getByRole('menuitemcheckbox', { name: /Nyala/ })).toBeInTheDocument();
    });

    it('dims cells whose mood is not in the active filter set', () => {
        const { container } = render(<Kalender {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        fireEvent.click(screen.getByRole('button', { name: /filter/i }));
        // Toggle only "Nyala" — cells with mood enteng/mumet should now be dimmed.
        fireEvent.click(screen.getByRole('menuitemcheckbox', { name: /Nyala/ }));
        // Find the May 1 cell (mood: enteng, activity_id: 100) — it should pick up the dim opacity class.
        const link = container.querySelector('a[href="/aktivitas/100"]');
        expect(link?.className).toContain('opacity-30');
    });

    it('renders an empty placeholder for cells with no run', () => {
        const cells = cellsFor([
            { date: '2026-05-01', day: 1, is_current_month: true },
            { date: '2026-05-02', day: 2, is_current_month: true },
            { date: '2026-05-03', day: 3, is_current_month: true },
            { date: '2026-05-04', day: 4, is_current_month: true },
            { date: '2026-05-05', day: 5, is_current_month: true },
            { date: '2026-05-06', day: 6, is_current_month: true },
            { date: '2026-05-07', day: 7, is_current_month: true },
        ]);
        const { container } = render(<Kalender {...BASE_PROPS} cells={cells} />);
        const dayNumbers = Array.from(container.querySelectorAll('.tabular-nums'));
        expect(dayNumbers.length).toBeGreaterThan(0);
    });

    it('rolls multi-activity days into a non-linked cell', () => {
        const cells = cellsFor([
            { date: '2026-05-01', day: 1, is_current_month: true, distance_km: 10, trimp: 100, mood: 'lemes', activity_id: null },
            { date: '2026-05-02', day: 2, is_current_month: true },
            { date: '2026-05-03', day: 3, is_current_month: true },
            { date: '2026-05-04', day: 4, is_current_month: true },
            { date: '2026-05-05', day: 5, is_current_month: true },
            { date: '2026-05-06', day: 6, is_current_month: true },
            { date: '2026-05-07', day: 7, is_current_month: true },
        ]);
        render(<Kalender {...BASE_PROPS} cells={cells} />);
        const activityLinks = screen.getAllByRole('link').filter((el) => (el.getAttribute('href') ?? '').startsWith('/aktivitas/'));
        expect(activityLinks).toHaveLength(0);
    });

    it('renders the page chrome even with an empty cells array', () => {
        render(<Kalender {...BASE_PROPS} cells={[]} />);
        // No grid rows since groupByWeek returns []. Chrome (month label + legend) still shows.
        expect(screen.getByRole('heading', { name: 'Mei 2026' })).toBeInTheDocument();
        expect(screen.getByText('Mood')).toBeInTheDocument();
    });
});
