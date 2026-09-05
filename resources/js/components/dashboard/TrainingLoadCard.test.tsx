import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { TrainingLoad, WeeklySnapshot } from '@/types/inertia';

import TrainingLoadCard from './TrainingLoadCard';

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

describe('TrainingLoadCard', () => {
    it("renders the prototype's three condition rows with formatted values", () => {
        render(<TrainingLoadCard load={load} snapshot={snapshot} />);

        ['fitness', 'fatigue', 'strain'].forEach((label) => {
            expect(screen.getByText(label)).toBeInTheDocument();
        });
        expect(screen.getByText('42.0')).toBeInTheDocument(); // ctl toFixed(1)
        expect(screen.getByText('44.5')).toBeInTheDocument(); // atl toFixed(1)
        expect(screen.getByText('384')).toBeInTheDocument(); // strain rounded
    });

    // The prototype's condition card draws fitness/fatigue/strain only.
    // Monotony survives as History's per-week alert.
    it('does not draw a monotony row', () => {
        render(<TrainingLoadCard load={load} snapshot={snapshot} />);

        expect(screen.queryByText('monotony')).not.toBeInTheDocument();
        expect(screen.queryByText('1.20')).not.toBeInTheDocument();
    });

    it('shows the "7 days" scope and a technical-detail link', () => {
        render(<TrainingLoadCard load={load} snapshot={snapshot} />);

        expect(screen.getByText(/7 days/)).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Technical detail/ }),
        ).toHaveAttribute('href', '/history');
    });

    it('falls back to em-dash values and "not enough data yet" when load and snapshot are null', () => {
        render(<TrainingLoadCard load={null} snapshot={null} />);

        expect(screen.getByText(/not enough data yet/)).toBeInTheDocument();
        expect(screen.getAllByText('—')).toHaveLength(3);
    });

    it('shows an unscored strain as unknown, never a zero', () => {
        render(
            <TrainingLoadCard
                load={{ ...load, weekly_trimp: null, strain: null }}
                snapshot={snapshot}
            />,
        );

        // Fitness/Fatigue survive an unscored week; strain cannot.
        expect(screen.getByText('42.0')).toBeInTheDocument();
        expect(screen.getAllByText('—')).toHaveLength(1);
        expect(screen.queryByText('0')).not.toBeInTheDocument();
    });

    it('names the missing HR when a week of runs scored nothing at all', () => {
        render(<TrainingLoadCard load={null} snapshot={snapshot} />);

        expect(screen.getByText(/no HR data yet/)).toBeInTheDocument();
        expect(
            screen.queryByText(/not enough data yet/),
        ).not.toBeInTheDocument();
    });
});
