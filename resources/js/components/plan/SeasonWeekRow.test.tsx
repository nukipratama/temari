import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { PlanDay, PlanWeek, SeasonSummaryWeek } from '@/lib/plan';

import SeasonWeekRow, { SeasonRailNode } from './SeasonWeekRow';

function day(overrides: Partial<PlanDay> = {}): PlanDay {
    return {
        id: 1,
        date: '2026-06-15',
        phase: 'base',
        session_type: 'easy',
        segments: [],
        distance_km: 8,
        asked_km: 8,
        pinned: false,
        skipped: false,
        status: 'done',
        compliance_score: 90,
        ran_anyway: false,
        prescribed_km: null,
        prescription_reason: null,
        clamp: null,
        eased_from: null,
        pace_eased_from: null,
        credit_note: null,
        hot_note: null,
        ran_pace_sec_per_km: null,
        actual_km: 8,
        credited_km: 8,
        activities: [],
        ...overrides,
    };
}

function week(overrides: Partial<SeasonSummaryWeek> = {}): SeasonSummaryWeek {
    return {
        week_start: '2026-06-15',
        phase: 'base',
        zone: 'block',
        type: 'history',
        planned_km: 30.4,
        actual_km: 28,
        sessions: 5,
        ...overrides,
    };
}

const DETAIL: PlanWeek = {
    week_start: '2026-06-15',
    phase: 'base',
    type: 'history',
    days: [
        day({ id: 1, date: '2026-06-15', compliance_score: 100 }),
        day({ id: 2, date: '2026-06-16', compliance_score: 80 }),
    ],
};

function renderRow(
    overrides: Partial<Parameters<typeof SeasonWeekRow>[0]> = {},
) {
    return render(
        <SeasonWeekRow
            week={week()}
            weekNumber={3}
            detail={DETAIL}
            isLast={false}
            today="2026-06-20"
            focus={null}
            dayNarration={{}}
            onMove={vi.fn()}
            onSkip={vi.fn()}
            {...overrides}
        />,
    );
}

describe('SeasonRailNode', () => {
    it('haloes the current week, fills a done one and hollows one still ahead', () => {
        const { container, rerender } = render(
            <SeasonRailNode type="current" />,
        );
        expect(container.firstChild).toHaveClass('ring-4');

        rerender(<SeasonRailNode type="history" />);
        expect(container.firstChild).toHaveClass('bg-horizon');

        rerender(<SeasonRailNode type="lookahead" />);
        expect(container.firstChild).toHaveClass('bg-card');
    });
});

describe('SeasonWeekRow', () => {
    it('heads the week with its number, dates and volume', () => {
        renderRow();

        expect(screen.getByText('Week 3')).toBeInTheDocument();
        expect(screen.getByText('jun 15–21')).toBeInTheDocument();
        expect(
            screen.getByText(/30 km target · 5 sessions/),
        ).toBeInTheDocument();
    });

    it('heads an eased current week with the eased target and the original beside it', () => {
        renderRow({
            week: week({
                type: 'current',
                planned_km: 24.6,
                eased_from_km: 26.9,
            }),
            detail: { ...DETAIL, type: 'current' },
        });

        expect(screen.getByText('27')).toBeInTheDocument();
        expect(screen.getByText('25 km target')).toBeInTheDocument();
        expect(screen.getByText(/5 sessions/)).toBeInTheDocument();
    });

    it('shows a past week’s adherence in its header', () => {
        renderRow();

        expect(screen.getByText(/· 90%/)).toBeInTheDocument();
    });

    it('heads the current week with its target alone, no percentage', () => {
        renderRow({ week: week({ type: 'current' }) });

        expect(
            screen.getByText('30 km target · 5 sessions'),
        ).toBeInTheDocument();
        expect(screen.queryByText(/· 90%/)).not.toBeInTheDocument();
    });

    it('lays the current week out open as a day strip, with no volume chart', () => {
        renderRow({ week: week({ type: 'current' }) });

        expect(screen.getAllByRole('tab')).toHaveLength(2);
        expect(screen.getByRole('tabpanel')).toBeInTheDocument();
        expect(screen.queryByText(/^Volume/)).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Week 3/ }),
        ).not.toBeInTheDocument();
    });

    it('keeps the volume chart and day rows for a week other than the current one', () => {
        renderRow({ focusDay: '2026-06-16' });

        expect(screen.queryByRole('tablist')).not.toBeInTheDocument();
    });

    it('opens a past week holding the day that was asked for', () => {
        renderRow({ focusDay: '2026-06-16' });

        expect(screen.getByText('Volume that week')).toBeInTheDocument();
    });

    it('leaves a week closed when the day asked for is not in it', () => {
        renderRow({ focusDay: '2026-07-01' });

        expect(screen.queryByText('Volume that week')).not.toBeInTheDocument();
    });

    it('leaves every other week closed', () => {
        renderRow({ week: week({ type: 'history' }) });

        expect(screen.queryByText('Volume that week')).not.toBeInTheDocument();
    });

    it('reveals the chart and a row per day once expanded', () => {
        renderRow();
        fireEvent.click(screen.getByRole('button', { name: /Week 3/ }));

        expect(screen.getByText('Volume that week')).toBeInTheDocument();
        expect(screen.getAllByText('easy')).toHaveLength(2);
    });

    it('carries the adaptation focus into the current week in full', () => {
        renderRow({
            week: week({ type: 'current' }),
            focus: {
                headline: 'Holding the line.',
                detail: 'Volume stays put this week.',
            },
        });

        expect(screen.getByText('Holding the line.')).toBeInTheDocument();
        expect(
            screen.getByText('Volume stays put this week.'),
        ).toBeInTheDocument();
    });

    it('renders a flat summary card for a week with no day-level plan', () => {
        renderRow({ detail: null });

        expect(screen.getByText('Week 3')).toBeInTheDocument();
        expect(
            screen.getByText('30 km target · 5 sessions'),
        ).toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });
});

describe('week marks', () => {
    it('marks a deload week in its header, in words', () => {
        renderRow({ week: week({ phase: 'deload' }) });

        expect(screen.getByText('deload week')).toBeInTheDocument();
        expect(screen.queryByText('race week')).not.toBeInTheDocument();
    });

    it('marks the week the goal race falls in', () => {
        renderRow({ week: week({ phase: 'taper' }), raceDate: '2026-06-21' });

        expect(screen.getByText('race week')).toBeInTheDocument();
        expect(screen.queryByText('deload week')).not.toBeInTheDocument();
    });

    it('marks a week the race sits outside of, and a plain phase, not at all', () => {
        renderRow({ week: week({ phase: 'build' }), raceDate: '2026-07-05' });

        expect(screen.queryByText('race week')).not.toBeInTheDocument();
        expect(screen.queryByText('deload week')).not.toBeInTheDocument();
    });

    it('marks a week with no day rows of its own too', () => {
        renderRow({ week: week({ phase: 'deload' }), detail: null });

        expect(screen.getByText('deload week')).toBeInTheDocument();
    });
});
