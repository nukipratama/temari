import type { ComponentProps } from 'react';

import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { PlanDay, SeasonSummaryWeek } from '@/lib/plan';

import Plan from './Plan';

vi.mock('@/lib/pace', async () => {
    const actual =
        await vi.importActual<typeof import('@/lib/pace')>('@/lib/pace');
    return { ...actual, todayLocalIso: () => '2026-06-17' };
});

const DISCLAIMER_HEADLINE = 'Training guidance, not medical advice';
const DISCLAIMER =
    'Temari prescribes from your own data, not from a medical assessment.';

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
                pace_sec_per_km: 300,
            },
        ],
        distance_km: 8,
        pinned: false,
        skipped: false,
        status: 'planned',
        compliance_score: null,
        ran_anyway: false,
        clamp_note: null,
        actual_km: null,
        activity: null,
        ...overrides,
    };
}

function summaryWeek(
    overrides: Partial<SeasonSummaryWeek> = {},
): SeasonSummaryWeek {
    return {
        week_start: '2026-06-15',
        phase: 'base',
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
        is_race_oriented: false,
    },
    seasonSummary: [summaryWeek()],
    seasonAdherencePct: 82,
    adaptation: null,
    disclaimerHeadline: DISCLAIMER_HEADLINE,
    disclaimer: DISCLAIMER,
};

function renderPlan(overrides: Partial<ComponentProps<typeof Plan>> = {}) {
    render(<Plan {...BASE_PROPS} {...overrides} />);
}

describe('Plan', () => {
    it('leads with the eyebrow, headline and intro, in the prototype’s order', () => {
        renderPlan();

        expect(screen.getByText('Plan')).toBeInTheDocument();
        expect(screen.getByRole('heading')).toHaveTextContent(
            /the weeks\s*ahead\./i,
        );
        expect(
            screen.getByText(/no race set yet, so this cycles/i),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /set a race/i }),
        ).toHaveAttribute('href', '/race');
    });

    it('names the race it is built around once one is set', () => {
        renderPlan({
            race: { race_date: '2026-10-12', name: 'Jakarta Half' },
        });

        expect(
            screen.getByText(/built around jakarta half/i),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /change your race/i }),
        ).toBeInTheDocument();
    });

    it('offers the schedule / race-goal tab switch', () => {
        renderPlan();

        expect(
            screen.getByRole('link', { name: /race goal/i }),
        ).toHaveAttribute('href', '/race');
    });

    it('renders the season header card above the timeline', () => {
        renderPlan();

        expect(screen.getByText('Season · Week 1 of 12')).toBeInTheDocument();
        expect(screen.getByText('82%')).toBeInTheDocument();
        expect(screen.getByText('Base phase')).toBeInTheDocument();
    });

    it('opens the current week onto its chart and day rows', () => {
        renderPlan();

        expect(screen.getByText('Volume this week')).toBeInTheDocument();
        expect(screen.getByText('Tempo')).toBeInTheDocument();
        expect(screen.getByText('Rest')).toBeInTheDocument();
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
        fireEvent.click(screen.getByRole('button', { name: /tempo/i }));
        fireEvent.click(
            screen.getByRole('button', { name: /skip this session/i }),
        );

        expect(router.patch).toHaveBeenCalledWith(
            '/plan/sessions/1',
            { skipped: true },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('moves a session onto the day picked from the weekday grid', () => {
        renderPlan();
        fireEvent.click(screen.getByRole('button', { name: /tempo/i }));
        fireEvent.click(
            screen.getByRole('button', { name: /move this session/i }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Fri' }));

        expect(router.patch).toHaveBeenCalledWith(
            '/plan/sessions/1',
            { date: '2026-06-19' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('offers no Pin, Block or Delete action anywhere', () => {
        renderPlan();
        fireEvent.click(screen.getByRole('button', { name: /tempo/i }));

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

        expect(screen.getByText('No plan yet.')).toBeInTheDocument();
        expect(screen.queryByText('Season · Week 1 of 12')).toBeNull();
    });

    it('keeps the training disclaimer and its legal link', () => {
        renderPlan();

        expect(screen.getByText(DISCLAIMER_HEADLINE)).toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: /what the plan can and cannot see/i,
            }),
        ).toHaveAttribute('href', '/training-disclaimer');
    });
});
