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
        streak_days: 0,
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

    it('shows a streak chip only when streak_days is positive', () => {
        const days = MON_TO_SUN.map((date) => day({ date }));

        const { rerender } = render(
            <WeekPlanWidget weekPlan={weekOf(days, { streak_days: 3 })} />,
        );
        expect(screen.getByText('3-day streak')).toBeInTheDocument();

        rerender(
            <WeekPlanWidget weekPlan={weekOf(days, { streak_days: 0 })} />,
        );
        expect(screen.queryByText(/day streak/)).not.toBeInTheDocument();
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
