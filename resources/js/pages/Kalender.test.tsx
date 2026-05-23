import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Kalender, { type CalendarCell } from './Kalender';
import { setMockPage } from '@/test/setup';

beforeEach(() => {
    setMockPage({
        auth: { user: { id: 1, name: 'Andi', first_name: 'Andi', avatar_url: null } },
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
    { date: '2026-05-01', day: 1, is_current_month: true, distance_km: 5.0, trimp: 50, pace_sec_per_km: 360, avg_hr: 145, mood: 'enteng', activity_id: 100 },
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
    it('renders the month label and weekday headers', () => {
        render(<Kalender {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        expect(screen.getByRole('heading', { name: 'Mei 2026' })).toBeInTheDocument();
        expect(screen.getByText('Senin')).toBeInTheDocument();
        expect(screen.getByText('Minggu')).toBeInTheDocument();
        expect(screen.getByText('Pekan')).toBeInTheDocument();
    });

    it('renders monthly stats from current-month cells only', () => {
        render(<Kalender {...BASE_PROPS} cells={TWO_WEEK_CELLS} />);
        // 5.0 + 7.2 + 3.5 = 15.7 km · 3 runs
        expect(screen.getByText(/15\.7 km/)).toBeInTheDocument();
        expect(screen.getByText(/3 lari/)).toBeInTheDocument();
    });

    it('shows "Belum ada lari" stats line when the month is empty', () => {
        const empty = cellsFor([
            { date: '2026-05-01', day: 1, is_current_month: true },
            { date: '2026-05-02', day: 2, is_current_month: true },
        ]);
        render(<Kalender {...BASE_PROPS} cells={empty} />);
        expect(screen.getByText('Belum ada lari')).toBeInTheDocument();
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
        ['Nyala', 'Enteng', 'Lemes', 'Oleng', 'Mumet', 'Adem'].forEach((label) => {
            expect(screen.getByText(label)).toBeInTheDocument();
        });
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
        // Each cell is a div with the day number, not a link.
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
        // No grid rows since groupByWeek returns []. Chrome (month label + stats line) still shows.
        expect(screen.getByRole('heading', { name: 'Mei 2026' })).toBeInTheDocument();
        expect(screen.getByText('Belum ada lari')).toBeInTheDocument();
    });
});
