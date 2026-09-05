import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { ActivityDetail, StreamSummary } from '@/types/inertia';

import VitalsCard from './VitalsCard';

function detail(overrides: Partial<ActivityDetail> = {}): ActivityDetail {
    return {
        id: 11,
        activity_id: 99,
        name: 'Morning Run',
        start_date_local: '2026-05-10T07:00:00',
        distance: 10000,
        elapsed_time: 3600,
        average_heartrate: 152.4,
        max_heartrate: 171,
        average_cadence: 88,
        trimp_edwards: 70,
        ...overrides,
    };
}

function renderCard(
    summary: StreamSummary = {},
    overrides: Partial<ActivityDetail> = {},
) {
    return render(<VitalsCard detail={detail(overrides)} summary={summary} />);
}

describe('VitalsCard', () => {
    it('leads with the rounded average heart rate and marks the max on the bar', () => {
        const { container } = renderCard();
        expect(screen.getByText('152')).toBeInTheDocument();
        expect(screen.getByText('avg bpm')).toBeInTheDocument();
        expect(screen.getByText('171')).toBeInTheDocument();
        // avg 152 and max 171 both sit inside the 100-190 scale.
        expect(container.querySelector('[style*="width: 57"]')).not.toBeNull();
        expect(container.querySelector('[style*="left: 78"]')).not.toBeNull();
    });

    it('clamps a reading outside the scale rather than overflowing the bar', () => {
        const { container } = renderCard(
            {},
            { average_heartrate: 40, max_heartrate: 240 },
        );
        expect(container.querySelector('[style*="width: 0%"]')).not.toBeNull();
        expect(container.querySelector('[style*="left: 100%"]')).not.toBeNull();
    });

    it('omits the max marker when the run recorded no max', () => {
        const { container } = renderCard({}, { max_heartrate: null });
        expect(screen.getByText('152')).toBeInTheDocument();
        expect(screen.queryByText('max')).not.toBeInTheDocument();
        expect(container.querySelector('[style*="left:"]')).toBeNull();
    });

    it('doubles Strava single-leg cadence into steps per minute', () => {
        renderCard();
        expect(screen.getByText('176')).toBeInTheDocument();
        expect(screen.getByText('spm avg')).toBeInTheDocument();
    });

    it('shows grade and flat pace only on a run that actually climbed', () => {
        renderCard({ max_grade_pct: 6, gap_pace: '4:31' });
        expect(screen.getByText('6%')).toBeInTheDocument();
        expect(screen.getByText('steepest grade')).toBeInTheDocument();
        expect(screen.getByText('4:31')).toBeInTheDocument();
        expect(screen.getByText('flat pace /km')).toBeInTheDocument();
    });

    it('hides a flat run’s noisy sub-3% grade', () => {
        renderCard({ max_grade_pct: 1, gap_pace: '4:31' });
        expect(screen.queryByText('steepest grade')).not.toBeInTheDocument();
        expect(screen.queryByText('flat pace /km')).not.toBeInTheDocument();
    });

    it('never renders a corrupt grade reading as NaN', () => {
        renderCard({ max_grade_pct: Number.NaN });
        expect(screen.queryByText(/NaN/)).not.toBeInTheDocument();
    });

    it('reads steady breathing under the decoupling threshold', () => {
        renderCard({ decoupling_pct: 3.2 });
        expect(screen.getByText('+3.2%')).toHaveClass('text-icon-accent');
        expect(
            screen.getByText('breathing held steady to the end'),
        ).toBeInTheDocument();
    });

    it('warns when drift clears the threshold on a temperate run', () => {
        renderCard({ decoupling_pct: 11.4 }, { weather_temp_c: 22 });
        expect(screen.getByText('+11.4%')).toHaveClass('text-ember-ink');
        expect(
            screen.getByText('breathing drifted in the second half'),
        ).toBeInTheDocument();
    });

    it('blames the heat, not the athlete, when a hot run drifts', () => {
        renderCard({ decoupling_pct: 11.4 }, { weather_temp_c: 33 });
        expect(screen.getByText('+11.4%')).toHaveClass('text-icon-accent');
        expect(screen.getByText('normal, it was 33°C out')).toBeInTheDocument();
    });

    it('does not excuse a large negative decoupling as heat', () => {
        renderCard({ decoupling_pct: -12 }, { weather_temp_c: 33 });
        expect(screen.getByText('-12.0%')).toHaveClass('text-ember-ink');
        expect(
            screen.getByText('breathing drifted in the second half'),
        ).toBeInTheDocument();
    });

    it('pins the decoupling marker inside the gradient at either extreme', () => {
        const { container, unmount } = renderCard({ decoupling_pct: -4 });
        expect(container.querySelector('[style*="left: 0%"]')).not.toBeNull();
        unmount();

        const hot = renderCard({ decoupling_pct: 40 });
        expect(
            hot.container.querySelector('[style*="left: 100%"]'),
        ).not.toBeNull();
    });

    it('falls back to the empty panel when the run recorded nothing', () => {
        renderCard(
            {},
            {
                average_heartrate: null,
                max_heartrate: null,
                average_cadence: null,
            },
        );
        expect(
            screen.getByText(/Technical detail hasn't been read yet/),
        ).toBeInTheDocument();
    });
});
