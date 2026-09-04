import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { WeeklySnapshotWithRecap } from '@/types/inertia';

import WeeklyStatusChips from './WeeklyStatusChips';

function snapshot(
    overrides: Partial<WeeklySnapshotWithRecap> = {},
): WeeklySnapshotWithRecap {
    return {
        id: 1,
        user_id: 1,
        week_ending: '2026-05-10',
        distance_km: 30,
        runs: 4,
        weekly_trimp: 300,
        atl_7d: 44.5,
        ctl_42d: 42,
        form: -2.5,
        form_status: 'optimal',
        avg_decoupling: 3.2,
        monotony: 1.2,
        strain: 384,
        is_current_week: false,
        is_chain_head: false,
        recap_analysis: {
            id: 1,
            status: 'done',
            content: 'ok',
            type: 'weekly_recap',
            subject_type: 'weekly_snapshot',
            subject_id: 1,
            discriminator: null,
        },
        notification_retry_after_seconds: null,
        ...overrides,
    };
}

describe('WeeklyStatusChips', () => {
    it('names every metric the week scored', () => {
        render(<WeeklyStatusChips snapshot={snapshot()} />);

        expect(screen.getByText(/Fatigue 44.5/)).toBeInTheDocument();
        expect(screen.getByText(/Monotony 1.20/)).toBeInTheDocument();
        expect(screen.getByText(/Drift 3.2%/)).toBeInTheDocument();
        expect(screen.getByText(/Fitness 42.0/)).toBeInTheDocument();
        expect(screen.getByText(/Readiness -2.5/)).toBeInTheDocument();
        expect(screen.getByText(/right on track/)).toBeInTheDocument();
    });

    it('omits a chip whose metric is unknown rather than showing a zero', () => {
        render(
            <WeeklyStatusChips
                snapshot={snapshot({
                    atl_7d: null,
                    monotony: null,
                    avg_decoupling: null,
                    ctl_42d: null,
                    form: null,
                    form_status: null,
                })}
            />,
        );

        expect(screen.queryByText(/Fatigue/)).not.toBeInTheDocument();
        expect(screen.queryByText(/Fitness/)).not.toBeInTheDocument();
    });

    it('flags monotony and drift past their alarm thresholds', () => {
        render(
            <WeeklyStatusChips
                snapshot={snapshot({ monotony: 1.8, avg_decoupling: 9.4 })}
            />,
        );

        expect(screen.getByText(/Monotony 1.80/)).toHaveClass('bg-ember/15');
        expect(screen.getByText(/Drift 9.4%/)).toHaveClass('bg-ember/15');
    });

    it('takes a card ground for the chips that sit inside a muted panel', () => {
        render(<WeeklyStatusChips snapshot={snapshot()} tone="card" />);

        expect(screen.getByText(/Fatigue 44.5/)).toHaveClass('bg-card');
    });

    it('signs a positive readiness', () => {
        render(<WeeklyStatusChips snapshot={snapshot({ form: 3.1 })} />);

        expect(screen.getByText(/Readiness \+3.1/)).toBeInTheDocument();
    });
});
