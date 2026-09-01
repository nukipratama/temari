import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { WeeklySnapshotWithRecap } from '@/types/inertia';

import {
    chunkIntoWeeks,
    type CalendarCell,
    type WeekRow,
} from '@/pages/Activities/useCalendar';
import { makeUser, setMockPage } from '@/test/setup';

import CalendarWeekRow from './CalendarWeekRow';

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
        pace_sec_per_km: 330,
        avg_hr: 148,
        mood: 'blazing',
        rarity: 'epic',
        activity_id: 55,
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
        notification_retry_after_seconds: null,
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

describe('CalendarWeekRow', () => {
    it('draws the week summary and seven day boxes', () => {
        render(<CalendarWeekRow week={PLAIN_WEEK} snapshot={null} />);

        expect(screen.getByText('WK 1')).toBeInTheDocument();
        expect(screen.getByText('8.0k')).toBeInTheDocument();
        for (const day of ['4', '5', '6', '7', '8', '9', '10']) {
            expect(screen.getByText(day)).toBeInTheDocument();
        }
    });

    it('links a day that resolved to one activity', () => {
        render(<CalendarWeekRow week={PLAIN_WEEK} snapshot={null} />);

        expect(
            screen.getByRole('link', { name: /2026-05-04/ }),
        ).toHaveAttribute('href', '/activities/55');
    });

    it("keeps the day's numbers in its accessible label", () => {
        render(<CalendarWeekRow week={PLAIN_WEEK} snapshot={null} />);

        expect(
            screen.getByLabelText(
                '2026-05-04: 8 km, 5:30/km, 148 bpm, mood Blazing',
            ),
        ).toBeInTheDocument();
    });

    it('disables the summary button when the week has no recap', () => {
        render(<CalendarWeekRow week={PLAIN_WEEK} snapshot={null} />);

        expect(screen.getByRole('button')).toBeDisabled();
    });

    it("reveals Temari's narration and the weekly chips when expanded", () => {
        render(<CalendarWeekRow week={PLAIN_WEEK} snapshot={snapshot()} />);

        expect(
            screen.queryByText(/Steady week, one strong effort/),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /WK 1/ }));

        expect(
            screen.getByText(/Steady week, one strong effort/),
        ).toBeInTheDocument();
        expect(screen.getByText(/Fitness 42.0/)).toBeInTheDocument();
    });

    // P12: the one Kartu surface History keeps, and it lives here — inside the
    // week's disclosure, never on a day cell.
    it("badges the week's rarest kartu inside the disclosure", () => {
        render(<CalendarWeekRow week={PLAIN_WEEK} snapshot={snapshot()} />);

        expect(screen.queryByText(/Epic kartu/)).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /WK 1/ }));

        expect(screen.getByText(/Epic kartu/)).toBeInTheDocument();
    });

    it('shows no kartu badge for a week that earned none', () => {
        const week = weekFor([
            { date: '2026-05-04', day: 4, distance_km: 8, mood: 'easy' },
            { date: '2026-05-05', day: 5 },
            { date: '2026-05-06', day: 6 },
            { date: '2026-05-07', day: 7 },
            { date: '2026-05-08', day: 8 },
            { date: '2026-05-09', day: 9 },
            { date: '2026-05-10', day: 10 },
        ]);
        render(<CalendarWeekRow week={week} snapshot={snapshot()} />);

        fireEvent.click(screen.getByRole('button', { name: /WK 1/ }));

        expect(screen.queryByText(/kartu/i)).not.toBeInTheDocument();
    });

    it('collapses again on a second press', () => {
        render(<CalendarWeekRow week={PLAIN_WEEK} snapshot={snapshot()} />);
        const toggle = screen.getByRole('button', { name: /WK 1/ });

        fireEvent.click(toggle);
        expect(toggle).toHaveAttribute('aria-expanded', 'true');

        fireEvent.click(toggle);
        expect(toggle).toHaveAttribute('aria-expanded', 'false');
        expect(
            screen.queryByText(/Steady week, one strong effort/),
        ).not.toBeInTheDocument();
    });

    it('keeps the AI pending state inside the disclosure', () => {
        render(
            <CalendarWeekRow
                week={PLAIN_WEEK}
                snapshot={snapshot({
                    is_current_week: true,
                    recap_analysis: {
                        id: null,
                        status: 'pending',
                        content: null,
                        type: 'weekly_recap',
                        subject_type: 'weekly_snapshot',
                        subject_id: 7,
                        discriminator: null,
                    },
                })}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /WK 1/ }));

        expect(
            screen.queryByText(/Steady week, one strong effort/),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Reread/ }),
        ).not.toBeInTheDocument();
    });
});
