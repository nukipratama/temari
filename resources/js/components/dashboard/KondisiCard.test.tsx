import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { TrainingLoad, WeeklySnapshot } from '@/types/inertia';

import KondisiCard from './KondisiCard';

const load: TrainingLoad = {
    form: -2.5,
    form_status: 'optimal',
    ctl_42d: 42,
    atl_7d: 44.5,
    weekly_trimp: 320,
    monotony: 1.2,
    strain: 384,
};

const snapshot: WeeklySnapshot = {
    id: 1,
    user_id: 1,
    week_ending: '2026-05-11',
    runs: 4,
    distance_km: 35.5,
    weekly_trimp: 280,
    ctl_42d: 42,
    atl_7d: 44.5,
    form: -2.5,
    form_status: 'optimal',
    avg_decoupling: 3.2,
    monotony: 1.4,
    strain: 392,
};

describe('KondisiCard', () => {
    it('renders all four metric rows with formatted values', () => {
        render(<KondisiCard load={load} snapshot={snapshot} />);
        ['Fitness', 'Fatigue', 'Strain', 'Monotony'].forEach((label) => {
            expect(screen.getByText(label)).toBeInTheDocument();
        });
        expect(screen.getByText('42.0')).toBeInTheDocument(); // ctl toFixed(1)
        expect(screen.getByText('44.5')).toBeInTheDocument(); // atl toFixed(1)
        expect(screen.getByText('384')).toBeInTheDocument(); // strain rounded
        expect(screen.getByText('1.20')).toBeInTheDocument(); // monotony toFixed(2)
    });

    it('shows the "7 days" subtitle and a technical-detail link', () => {
        render(<KondisiCard load={load} snapshot={snapshot} />);
        expect(screen.getByText(/7 days/)).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Technical detail/ }),
        ).toHaveAttribute('href', '/activities');
    });

    it('falls back to em-dash values and "not enough data yet" when load and snapshot are null', () => {
        render(<KondisiCard load={null} snapshot={null} />);
        expect(screen.getByText(/not enough data yet/)).toBeInTheDocument();
        expect(screen.getAllByText('—').length).toBe(4);
    });

    it('says the runs carried no HR instead of showing a zero strain', () => {
        render(
            <KondisiCard
                load={{
                    ...load,
                    weekly_trimp: null,
                    monotony: null,
                    strain: null,
                }}
                snapshot={snapshot}
            />,
        );
        // Fitness/Fatigue survive an unscored week; strain/monotony cannot.
        expect(screen.getByText('42.0')).toBeInTheDocument();
        expect(screen.getAllByText('—').length).toBe(2);
        expect(screen.getAllByText('no HR on these runs').length).toBe(2);
        expect(screen.queryByText('0')).not.toBeInTheDocument();
        expect(screen.queryByText('0.00')).not.toBeInTheDocument();
    });

    it('names the missing HR when a week of runs scored nothing at all', () => {
        render(<KondisiCard load={null} snapshot={snapshot} />);
        expect(screen.getByText(/no HR data yet/)).toBeInTheDocument();
        expect(
            screen.queryByText(/not enough data yet/),
        ).not.toBeInTheDocument();
        expect(screen.getAllByText('—').length).toBe(4);
    });

    // Regression: monotony 3.15 (the demo account's actual reading) used to
    // render Monotony in the same calm leaf/green as a safe 1.2 reading — the
    // riskiest state on the card looked the calmest. Strain tracks the same axis.
    it('colors Monotony and Strain as alert when monotony and strain are both high', () => {
        const riskyLoad: TrainingLoad = {
            ...load,
            monotony: 3.15,
            strain: 6380.3,
        };
        render(<KondisiCard load={riskyLoad} snapshot={snapshot} />);

        expect(screen.getByText('3.15')).toHaveClass('text-ember-ink');
        expect(screen.getByText('6380')).toHaveClass('text-ember-ink');
    });
});
