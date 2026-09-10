import { render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { WeekPlan, WeekPlanDay, WeeklySnapshot } from '@/types/inertia';

import WeekPlanWidget from './WeekPlanWidget';

const snapshot: WeeklySnapshot = {
    id: 1,
    user_id: 1,
    week_ending: '2026-01-11',
    runs: 3,
    distance_km: 18.2,
    weekly_trimp: 214,
    ctl_42d: 42,
    atl_7d: 44.5,
    form: -2.5,
    form_status: 'optimal',
    avg_decoupling: 3.2,
    monotony: 1.4,
    strain: 392,
};

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
                km: 5.2,
                pace_sec_per_km: 360,
            },
        ],
        distance_km: 8,
        pinned: false,
        skipped: false,
        status: 'planned',
        compliance_score: null,
        ran_anyway: false,
        prescribed_km: null,
        clamp: null,
        actual_km: null,
        activities: [],
        ...overrides,
    };
}

function weekOf(
    days: WeekPlanDay[],
    overrides: Partial<WeekPlan> = {},
): WeekPlan {
    return {
        sessions_this_week: 5,
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
    it("reads the week's sessions, actual against planned km, trimp and phase", async () => {
        const days = MON_TO_SUN.map((date) => day({ date, id: date.length }));
        render(<WeekPlanWidget weekPlan={weekOf(days)} snapshot={snapshot} />);

        await waitFor(() => {
            expect(screen.getByText('2/5')).toBeInTheDocument();
            expect(screen.getByText('18.2 of 32.0')).toBeInTheDocument();
            expect(screen.getByText('214')).toBeInTheDocument();
        });
        expect(screen.getByText('sessions')).toBeInTheDocument();
        expect(screen.getByText('km')).toBeInTheDocument();
        expect(screen.getByText('trimp')).toBeInTheDocument();
        expect(screen.getByText('build')).toBeInTheDocument();
    });

    it('states a week with nothing run yet as a plain zero, not 0.0', async () => {
        const days = MON_TO_SUN.map((date) => day({ date, id: date.length }));
        render(
            <WeekPlanWidget
                weekPlan={weekOf(days)}
                snapshot={{ ...snapshot, distance_km: 0, weekly_trimp: null }}
            />,
        );

        await waitFor(() => {
            expect(screen.getByText('0 of 32.0')).toBeInTheDocument();
        });
        expect(screen.getByText('—')).toBeInTheDocument();
    });

    it('falls back to dashes when no snapshot has been written for the week', async () => {
        const days = MON_TO_SUN.map((date) => day({ date, id: date.length }));
        render(<WeekPlanWidget weekPlan={weekOf(days)} snapshot={null} />);

        await waitFor(() => {
            expect(screen.getByText('0 of 32.0')).toBeInTheDocument();
        });
        expect(screen.getByText('—')).toBeInTheDocument();
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
            <WeekPlanWidget weekPlan={weekOf(days)} snapshot={snapshot} />,
        );

        expect(
            container.querySelector('[data-icon="Flame"]'),
        ).toBeInTheDocument();
        expect(
            container.querySelector('[data-icon="Feather"]'),
        ).toBeInTheDocument();
        expect(
            container.querySelector('[data-icon="Bed"]'),
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
            <WeekPlanWidget weekPlan={weekOf(days)} snapshot={snapshot} />,
        );

        const overreachedIcon = container.querySelector(
            '[title^="Overreached"] [data-icon]',
        );
        const doneIcon = container.querySelector('[title^="Done"] [data-icon]');
        expect(overreachedIcon).toHaveClass('text-horizon-ink');
        expect(doneIcon).toHaveClass('text-leaf-ink');
    });

    it("shows a run-anyway rest day's actual distance, the way the prototype's wednesday cell does", () => {
        const days = MON_TO_SUN.map((date) =>
            date === '2026-01-06'
                ? day({
                      date,
                      session_type: 'rest',
                      status: 'done',
                      ran_anyway: true,
                      prescribed_km: null,
                      actual_km: 4.2,
                  })
                : day({ date }),
        );
        const { container } = render(
            <WeekPlanWidget weekPlan={weekOf(days)} snapshot={snapshot} />,
        );

        expect(screen.getByText('4.2k')).toBeInTheDocument();
        expect(
            container.querySelector('[title^="Done"] [data-icon]'),
        ).toHaveClass('text-leaf-ink');
    });

    it("exposes each day's status and compliance score as an accessible title", () => {
        const days = MON_TO_SUN.map((date) =>
            date === '2026-01-05'
                ? day({ date, status: 'partial', compliance_score: 62 })
                : day({ date }),
        );
        const { container } = render(
            <WeekPlanWidget weekPlan={weekOf(days)} snapshot={snapshot} />,
        );

        expect(
            container.querySelector('li[title="Partial · planned 8k · 62%"]'),
        ).toBeInTheDocument();
    });

    it('reads an elapsed day as what was run and a day still ahead as what is planned', () => {
        const days = MON_TO_SUN.map((date) =>
            date === '2026-01-05'
                ? day({ date, status: 'done', actual_km: 9.4 })
                : day({ date }),
        );
        render(<WeekPlanWidget weekPlan={weekOf(days)} snapshot={snapshot} />);

        expect(screen.getByText('9.4k')).toHaveClass('text-leaf-ink');
        expect(screen.getByText('of 8k')).toBeInTheDocument();
        expect(screen.getAllByText('8k')).toHaveLength(6);
    });

    it('leaves a run rest day showing the actual alone, with nothing to be "of"', () => {
        const days = MON_TO_SUN.map((date) =>
            date === '2026-01-05'
                ? day({
                      date,
                      session_type: 'rest',
                      status: 'done',
                      ran_anyway: true,
                      actual_km: 4.2,
                  })
                : day({ date }),
        );
        render(<WeekPlanWidget weekPlan={weekOf(days)} snapshot={snapshot} />);

        expect(screen.getByText('4.2k')).toBeInTheDocument();
        expect(screen.queryByText(/^of /)).not.toBeInTheDocument();
    });

    it('links out to the full plan, which states today once, on the today card', () => {
        const days = MON_TO_SUN.map((date) => day({ date }));
        render(<WeekPlanWidget weekPlan={weekOf(days)} snapshot={snapshot} />);

        expect(
            screen.getByRole('link', { name: 'see the plan' }),
        ).toHaveAttribute('href', '/plan');
        expect(screen.queryByText(/today ·/)).not.toBeInTheDocument();
    });

    it('lays out the ring, km and trimp figures as three sibling columns', async () => {
        const days = MON_TO_SUN.map((date) => day({ date }));
        render(<WeekPlanWidget weekPlan={weekOf(days)} snapshot={snapshot} />);

        await waitFor(() => {
            expect(screen.getByText('18.2 of 32.0')).toBeInTheDocument();
        });
        const statsRow = screen.getByText('sessions').closest('.grid');
        expect(statsRow).toHaveClass('grid-cols-3');
        expect(statsRow?.children).toHaveLength(3);
        expect(statsRow?.contains(screen.getByText('km'))).toBe(true);
        expect(statsRow?.contains(screen.getByText('trimp'))).toBe(true);
    });

    it('renders one cell per day and rings today', () => {
        const days = MON_TO_SUN.map((date) => day({ date }));
        const { container } = render(
            <WeekPlanWidget weekPlan={weekOf(days)} snapshot={snapshot} />,
        );

        expect(
            screen.getAllByText(/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)$/),
        ).toHaveLength(7);
        expect(container.querySelectorAll('li.ring-inset')).toHaveLength(1);
    });
});
