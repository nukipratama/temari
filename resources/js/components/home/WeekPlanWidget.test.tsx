import { render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { WeekPlan, WeekPlanDay } from '@/types/inertia';

import WeekPlanWidget from './WeekPlanWidget';

vi.mock('@/lib/pace', async () => {
    const actual =
        await vi.importActual<typeof import('@/lib/pace')>('@/lib/pace');
    return { ...actual, todayLocalIso: () => '2026-01-07' };
});

function day(overrides: Partial<WeekPlanDay>): WeekPlanDay {
    return {
        id: 1,
        date: '2026-01-05',
        phase: 'build',
        session_type: 'easy',
        segments: [
            {
                key: 'main',
                minutes: 48,
                zone: 'Z2',
                pace_label: 'easy',
                pace_sec_per_km: 360,
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

function weekOf(
    days: WeekPlanDay[],
    overrides: Partial<WeekPlan> = {},
): WeekPlan {
    return {
        sessions_per_week: 5,
        phase: 'build',
        planned_km_this_week: 32,
        credited_this_week: 2,
        days,
        ...overrides,
    };
}

const MON_TO_SUN = [
    '2026-01-05',
    '2026-01-06',
    '2026-01-07',
    '2026-01-08',
    '2026-01-09',
    '2026-01-10',
    '2026-01-11',
];

describe('WeekPlanWidget', () => {
    it("renders the week's sessions, distance, and phase", async () => {
        const days = MON_TO_SUN.map((date) => day({ date, id: date.length }));
        render(<WeekPlanWidget weekPlan={weekOf(days)} />);

        await waitFor(() => {
            expect(screen.getByText('2/5')).toBeInTheDocument();
            expect(screen.getByText('32.0')).toBeInTheDocument();
        });
        expect(screen.getByText('Build')).toBeInTheDocument();
    });

    it('renders a day glyph icon by session type, not by status', () => {
        const days = MON_TO_SUN.map((date, i) =>
            day({
                date,
                session_type: (['tempo', 'easy', 'long', 'rest'][i % 4] ??
                    'easy') as string,
            }),
        );
        const { container } = render(
            <WeekPlanWidget weekPlan={weekOf(days)} />,
        );

        expect(
            container.querySelector('[data-icon="mdi:fire"]'),
        ).toBeInTheDocument();
        expect(
            container.querySelector('[data-icon="mdi:feather"]'),
        ).toBeInTheDocument();
        expect(
            container.querySelector('[data-icon="mdi:bed"]'),
        ).toBeInTheDocument();
    });

    it('colors overreached distinctly from a plain done day', () => {
        const days = MON_TO_SUN.map((date, i) =>
            day({
                date,
                status: i === 0 ? 'overreached' : 'done',
                compliance_score: i === 0 ? 154 : 100,
            }),
        );
        const { container } = render(
            <WeekPlanWidget weekPlan={weekOf(days)} />,
        );

        const overreachedIcon = container.querySelector(
            '[title^="Overreached"] [data-icon]',
        );
        const doneIcon = container.querySelector('[title^="Done"] [data-icon]');
        expect(overreachedIcon).toHaveClass('text-horizon-ink');
        expect(doneIcon).toHaveClass('text-leaf-ink');
    });

    it("surfaces a rest day's ran-anyway credit instead of the plain rest label", () => {
        const days = MON_TO_SUN.map((date) =>
            date === '2026-01-06'
                ? day({
                      date,
                      session_type: 'rest',
                      status: 'done',
                      ran_anyway: true,
                  })
                : day({ date }),
        );
        render(<WeekPlanWidget weekPlan={weekOf(days)} />);

        expect(screen.getByText('Ran Anyway')).toBeInTheDocument();
    });

    it("exposes each day's status and compliance score as an accessible title", () => {
        const days = MON_TO_SUN.map((date) =>
            date === '2026-01-05'
                ? day({ date, status: 'partial', compliance_score: 62 })
                : day({ date }),
        );
        const { container } = render(
            <WeekPlanWidget weekPlan={weekOf(days)} />,
        );

        expect(
            container.querySelector('li[title="Partial · 62%"]'),
        ).toBeInTheDocument();
    });

    it("surfaces today's session in the today panel, including pace and clamp note", () => {
        const days = MON_TO_SUN.map((date) =>
            date === '2026-01-07'
                ? day({
                      date,
                      session_type: 'long',
                      distance_km: 15,
                      segments: [
                          {
                              key: 'main',
                              minutes: 66,
                              zone: 'Z2',
                              pace_label: 'easy',
                              pace_sec_per_km: 330,
                          },
                      ],
                      clamp_note: 'Clamped for low readiness.',
                  })
                : day({ date }),
        );
        render(<WeekPlanWidget weekPlan={weekOf(days)} />);

        expect(
            screen.getByText(/Today · Long run · 15 km · 5:30\/km/),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Clamped for low readiness.'),
        ).toBeInTheDocument();
    });

    it('labels a rest day without a distance or pace suffix', () => {
        const days = MON_TO_SUN.map((date) =>
            date === '2026-01-07'
                ? day({
                      date,
                      session_type: 'rest',
                      distance_km: 0,
                      segments: [],
                  })
                : day({ date }),
        );
        render(<WeekPlanWidget weekPlan={weekOf(days)} />);

        expect(screen.getByText('Today · Rest')).toBeInTheDocument();
    });

    it('renders one tile per day and marks pinned days', () => {
        const days = MON_TO_SUN.map((date) =>
            date === '2026-01-09' ? day({ date, pinned: true }) : day({ date }),
        );
        render(<WeekPlanWidget weekPlan={weekOf(days)} />);

        expect(
            screen.getAllByText(/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)$/),
        ).toHaveLength(7);
        expect(screen.getByLabelText('Pinned')).toBeInTheDocument();
    });
});
