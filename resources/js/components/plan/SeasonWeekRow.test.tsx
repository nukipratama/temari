import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { PlanDay, PlanWeek, SeasonSummaryWeek } from '@/lib/plan';
import type { AnalysisPayload } from '@/types/inertia';

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
        clamp: null,
        actual_km: 8,
        activities: [],
        ...overrides,
    };
}

function week(overrides: Partial<SeasonSummaryWeek> = {}): SeasonSummaryWeek {
    return {
        week_start: '2026-06-15',
        phase: 'base',
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
            narration={null}
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

        expect(screen.getByText('Wk 3')).toBeInTheDocument();
        expect(screen.getByText('jun 15–21')).toBeInTheDocument();
        expect(
            screen.getByText(/30 km target · 5 sessions/),
        ).toBeInTheDocument();
    });

    it('shows a past week’s adherence in its header', () => {
        renderRow();

        expect(screen.getByText(/· 90%/)).toBeInTheDocument();
    });

    it('says "this week" instead of a percentage on the current week', () => {
        renderRow({ week: week({ type: 'current' }) });

        expect(screen.getByText(/· this week/)).toBeInTheDocument();
        expect(screen.queryByText(/· 90%/)).not.toBeInTheDocument();
    });

    it('opens the current week by default', () => {
        renderRow({ week: week({ type: 'current' }) });

        expect(screen.getByText('Volume this week')).toBeInTheDocument();
    });

    it('leaves every other week closed', () => {
        renderRow({ week: week({ type: 'history' }) });

        expect(screen.queryByText('Volume that week')).not.toBeInTheDocument();
    });

    it('reveals the chart and a row per day once expanded', () => {
        renderRow();
        fireEvent.click(screen.getByRole('button', { name: /Wk 3/ }));

        expect(screen.getByText('Volume that week')).toBeInTheDocument();
        expect(screen.getAllByText('easy')).toHaveLength(2);
    });

    it('carries the week narration and the adaptation focus into the open week', () => {
        renderRow({
            week: week({ type: 'current' }),
            focus: {
                headline: 'Holding the line.',
                detail: 'Volume stays put this week.',
            },
            narration: {
                id: 1,
                status: 'done',
                content: 'Steady, no red flags.',
                type: 'plan_week_voice',
                is_zone_dependent: false,
                subject_type: 'plan_adaptation',
                subject_id: 1,
                discriminator: null,
            } as AnalysisPayload,
        });

        expect(screen.getByText('Steady, no red flags.')).toBeInTheDocument();
        expect(screen.getByText('Holding the line.')).toBeInTheDocument();
        expect(
            screen.getByText('Volume stays put this week.'),
        ).toBeInTheDocument();
    });

    it('renders a flat summary card for a week with no day-level plan', () => {
        renderRow({ detail: null });

        expect(screen.getByText('Wk 3')).toBeInTheDocument();
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
