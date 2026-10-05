import type { ComponentProps } from 'react';

import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { PlanDay, SeasonSummaryWeek } from '@/lib/plan';

import {
    clearNavigationMemory,
    rememberPlanSelectedDay,
} from '@/lib/navigationMemory';
import { setMockDeferred, setMockPage } from '@/test/setup';

import Plan from './Plan';

const DISCLAIMER_LINE =
    'temari plans from your runs, not a check-up. if something hurts, rest and see a pro.';

function day(overrides: Partial<PlanDay> = {}): PlanDay {
    return {
        id: 1,
        date: '2026-06-18',
        phase: 'base',
        session_type: 'tempo',
        segments: [
            {
                key: 'main',
                minutes: 30,
                zone: 'Z4',
                pace_label: 'threshold',
                km: 5.2,
                pace_sec_per_km: 300,
            },
        ],
        distance_km: 8,
        asked_km: 8,
        pinned: false,
        skipped: false,
        status: 'planned',
        compliance_score: null,
        ran_anyway: false,
        prescribed_km: null,
        prescription_reason: null,
        fall_off_tilt: null,
        goal_pace: null,
        advice_note: null,
        eased_from: null,
        pace_eased_from: null,
        credit_note: null,
        ran_hot: false,
        result_note: null,
        ran_pace_sec_per_km: null,
        actual_km: null,
        credited_km: null,
        activities: [],
        ...overrides,
    };
}

function summaryWeek(
    overrides: Partial<SeasonSummaryWeek> = {},
): SeasonSummaryWeek {
    return {
        week_start: '2026-06-15',
        phase: 'base',
        zone: 'block',
        type: 'current',
        planned_km: 34,
        actual_km: 20,
        sessions: 5,
        ...overrides,
    };
}

const BASE_PROPS: ComponentProps<typeof Plan> = {
    race: null,
    sessionsPerWeek: 5,
    weeks: [
        {
            week_start: '2026-06-15',
            phase: 'base',
            type: 'current',
            days: [
                day({ id: 1, date: '2026-06-18' }),
                day({
                    id: 2,
                    date: '2026-06-19',
                    session_type: 'rest',
                    segments: [],
                    distance_km: 0,
                }),
            ],
        },
    ],
    season: {
        starts_at: '2026-06-15',
        ends_at: '2026-09-04',
        week_index: 1,
        total_weeks: 12,
        under_ready_line: null,
    },
    seasonSummary: [summaryWeek()],
    seasonAdherencePct: 82,
    adaptation: null,
    disclaimerLine: DISCLAIMER_LINE,
};

function renderPlan(overrides: Partial<ComponentProps<typeof Plan>> = {}) {
    return render(<Plan {...BASE_PROPS} {...overrides} />);
}

describe('Plan', () => {
    beforeEach(() => {
        setMockPage({ today: '2026-06-17' }, '/plan', 'Plan');
    });

    afterEach(() => {
        vi.useRealTimers();
        window.history.replaceState({}, '', '/plan');
        clearNavigationMemory();
    });

    it('shows the AI pause banner while generation is paused', () => {
        setMockPage({ today: '2026-06-17', aiPaused: true }, '/plan', 'Plan');

        renderPlan();

        expect(screen.getByText(/catching her breath/)).toBeInTheDocument();
    });

    it("keeps the server's tomorrow editable while the device clock already reads that day", () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 5, 18, 0, 30));

        renderPlan();

        expect(
            screen.getByRole('button', { name: /^skip$/i }),
        ).toBeInTheDocument();
    });

    it("locks the server's today while the device clock still reads the day before", () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 5, 17, 23, 0));
        setMockPage({ today: '2026-06-18' }, '/plan', 'Plan');

        renderPlan();

        expect(
            screen.queryByRole('button', { name: /^skip$/i }),
        ).not.toBeInTheDocument();
    });

    it('leads with the eyebrow, headline and a one-line race summary', () => {
        renderPlan();

        expect(screen.getByText('Plan')).toBeInTheDocument();
        expect(screen.getByRole('heading')).toHaveTextContent(
            /the weeks\s*ahead\./i,
        );
        expect(
            screen.getByText(/no race set · steady build and deload/i),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /set a race/i }),
        ).toHaveAttribute('href', '/race');
    });

    it('paints the header and disclaimer while the plan body is still deferred', () => {
        setMockDeferred([
            'weeks',
            'seasonSummary',
            'seasonAdherencePct',
            'adaptation',
        ]);

        const { container } = renderPlan({
            weeks: undefined,
            seasonSummary: undefined,
            seasonAdherencePct: undefined,
            adaptation: undefined,
        });

        expect(screen.getByText('Plan')).toBeInTheDocument();
        expect(screen.getByRole('heading')).toHaveTextContent(
            /the weeks\s*ahead\./i,
        );
        expect(screen.getByText(/not a check-up/i)).toBeInTheDocument();
        expect(container.querySelectorAll('.skeleton').length).toBeGreaterThan(
            0,
        );
    });

    it('quietly keeps the current plan visible while zone recalibration is pending', () => {
        renderPlan({
            planRecalibration: {
                pending: true,
                started_at: '2026-06-17T08:00:00+07:00',
                completed_at: null,
            },
        });

        expect(screen.getByRole('status')).toHaveTextContent(
            'rechecking your plan',
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            'the current plan stays in place',
        );
        expect(screen.getByRole('tablist')).toBeInTheDocument();
    });

    it('names the race it is built around once one is set', () => {
        renderPlan({
            race: { race_date: '2026-10-12', name: 'Jakarta Half' },
        });

        expect(screen.getByText(/jakarta half · oct 12/i)).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /race goal/i }),
        ).toHaveAttribute('href', '/race');
        expect(screen.getByRole('link', { name: /race goal/i })).toHaveClass(
            'hit-area',
        );
    });

    it('carries no schedule / race-goal tab switch; the race line links out instead', () => {
        renderPlan();

        expect(
            screen.queryByRole('link', { name: /schedule/i }),
        ).not.toBeInTheDocument();
    });

    it('renders the season header card above the week and the weeks list', () => {
        renderPlan();

        expect(screen.getByText(/^week 1 of 12/)).toBeInTheDocument();
        expect(screen.getByText('82%')).toBeInTheDocument();
        expect(
            screen.getByRole('list', { name: 'season weeks' }),
        ).toBeInTheDocument();
    });

    it('uses the same page width as every other page on desktop', () => {
        const { container } = renderPlan();

        expect(container.querySelector('.reveal')).toHaveClass(
            'min-[1280px]:max-w-column-wide',
        );
    });

    it('lays the current week out as a day strip over the first session still to run', () => {
        renderPlan();

        expect(screen.queryByText('Volume this week')).not.toBeInTheDocument();
        expect(
            screen.getByRole('tab', { name: 'Thu, 8.0 km, tempo' }),
        ).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByRole('tab', { name: 'Fri, rest' })).toHaveAttribute(
            'aria-selected',
            'false',
        );
        expect(screen.getByRole('tabpanel')).toHaveTextContent(/tempo/);
    });

    it('opens the day the home week card asked for when it is in this week', () => {
        window.history.replaceState({}, '', '/plan?day=2026-06-19');

        renderPlan();

        expect(screen.getByRole('tab', { name: 'Fri, rest' })).toHaveAttribute(
            'aria-selected',
            'true',
        );
    });

    it('restores the selected day saved in this tab session', () => {
        const scrollIntoView = vi.fn();
        Element.prototype.scrollIntoView = scrollIntoView;
        rememberPlanSelectedDay('2026-06-19');

        renderPlan();

        expect(screen.getByRole('tab', { name: 'Fri, rest' })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        expect(scrollIntoView).not.toHaveBeenCalled();
    });

    it('scrolls to the day the home week card asked for', () => {
        const scrollIntoView = vi.fn();
        Element.prototype.scrollIntoView = scrollIntoView;
        window.history.replaceState({}, '', '/plan?day=2026-06-19');

        renderPlan();

        expect(scrollIntoView).toHaveBeenCalledWith({ block: 'center' });
    });

    it('reads as usual when the day asked for is not a date', () => {
        const scrollIntoView = vi.fn();
        Element.prototype.scrollIntoView = scrollIntoView;
        window.history.replaceState({}, '', '/plan?day=yesterday');

        renderPlan();

        expect(screen.getByRole('tablist')).toBeInTheDocument();
        expect(scrollIntoView).not.toHaveBeenCalled();
    });

    it('regenerates the plan', () => {
        renderPlan();
        fireEvent.click(screen.getByRole('button', { name: /regenerate/i }));

        expect(router.post).toHaveBeenCalledWith(
            '/plan/regenerate',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('counts down instead of offering regenerate during the cooldown', () => {
        renderPlan({ regenerateCooldownSeconds: 3600 });

        const button = screen.getByRole('button', { name: /next in/i });
        expect(button).toBeDisabled();
    });

    it('skips a session through the sessions endpoint', () => {
        renderPlan();
        fireEvent.click(screen.getByRole('button', { name: /^skip$/i }));

        expect(router.patch).toHaveBeenCalledWith(
            '/plan/sessions/1',
            { skipped: true },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('restores a skipped session and returns it to the generator', () => {
        renderPlan({
            weeks: [
                {
                    ...BASE_PROPS.weeks![0],
                    days: [day({ skipped: true, pinned: true })],
                },
            ],
        });

        fireEvent.click(screen.getByRole('button', { name: 'restore' }));

        expect(router.patch).toHaveBeenCalledWith(
            '/plan/sessions/1',
            { skipped: false, pinned: false },
            { preserveScroll: true },
        );
    });

    it('moves a session onto the day picked from the weekday grid', () => {
        renderPlan();
        fireEvent.click(screen.getByRole('button', { name: /^move$/i }));
        fireEvent.click(screen.getByRole('button', { name: 'Fri' }));

        expect(router.patch).toHaveBeenCalledWith(
            '/plan/sessions/1',
            { date: '2026-06-19' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('offers no Pin, Block or Delete action anywhere', () => {
        renderPlan();

        expect(screen.queryByRole('button', { name: /^pin$/i })).toBeNull();
        expect(screen.queryByRole('button', { name: /^block$/i })).toBeNull();
        expect(screen.queryByRole('button', { name: /delete/i })).toBeNull();
    });

    it('draws no season-goal tier module', () => {
        renderPlan();

        expect(screen.queryByText(/badge board/i)).toBeNull();
        expect(screen.queryByText(/season track/i)).toBeNull();
    });

    it('falls back to the empty state before a plan exists', () => {
        renderPlan({ weeks: [] });

        expect(screen.getByText('no plan yet.')).toBeInTheDocument();
        expect(screen.queryByText(/^week 1 of 12/)).toBeNull();
    });

    it('keeps the training disclaimer as a one-line footer with its legal link', () => {
        renderPlan();

        expect(screen.getByRole('contentinfo')).toHaveTextContent(
            DISCLAIMER_LINE,
        );
        expect(
            screen.getByRole('link', { name: /the full disclaimer/i }),
        ).toHaveAttribute('href', '/training-disclaimer');
        expect(
            screen.getByRole('link', { name: /the full disclaimer/i }),
        ).toHaveClass('hit-area');
    });
});
