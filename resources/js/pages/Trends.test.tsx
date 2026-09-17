import type { ComponentProps } from 'react';

import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { FitnessChartAnnotations } from '@/components/trends/panels/FitnessPanel';
import type { AnalysisPayload, TrainingLoad } from '@/types/inertia';

import { setMockDeferred, setMockPage } from '@/test/setup';

import Trends from './Trends';

function narrationPayload(content: string): AnalysisPayload {
    return {
        id: 1,
        status: 'done',
        content,
        type: 'trend_read',
        is_zone_dependent: true,
        subject_type: 'trend_read_user_range',
        subject_id: 1,
        discriminator: '7d',
    };
}

const NARRATION = narrationPayload(
    'holding steady this week.\n\nno real swing either way.',
);

const LOAD: TrainingLoad = {
    form: -2.5,
    form_status: 'optimal',
    ctl_42d: 42,
    atl_7d: 44.5,
    weekly_trimp: 320,
    monotony: 1.2,
    strain: 384,
};

const NO_ANNOTATIONS: FitnessChartAnnotations = { deload: [], race: [] };

const BASE_PROPS: ComponentProps<typeof Trends> = {
    load: LOAD,
    ctlTrend: [],
    weekComparison: {
        this_week_km: 18.4,
        last_week_km: 22.1,
        this_week_runs: 3,
        last_week_runs: 4,
    },
    narration: NARRATION,
    chartAnnotations: NO_ANNOTATIONS,
};

/** A year of daily points so the fitness chart has data to draw. */
function yearOfTrend() {
    return Array.from({ length: 365 }, (_, i) => ({
        date: `2026-01-${String((i % 28) + 1).padStart(2, '0')}`,
        ctl: 40 + i * 0.05,
        atl: 30,
    }));
}

describe('Trends', () => {
    it('renders the page headline', () => {
        render(<Trends {...BASE_PROPS} />);

        expect(screen.getByText('am I getting fitter,')).toBeInTheDocument();
        expect(screen.getByText('and at what cost?')).toBeInTheDocument();
    });

    it('renders the verdict once narration lands, above the three comparisons', () => {
        const { container } = render(<Trends {...BASE_PROPS} />);

        const verdict = screen.getByText('holding steady this week.');
        const weekSection = screen.getByText('vs last week');

        expect(
            verdict.compareDocumentPosition(weekSection) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
        expect(container).toContainElement(verdict);
    });

    it('renders all three comparisons in order: week, month, race', () => {
        render(<Trends {...BASE_PROPS} ctlTrend={yearOfTrend()} />);

        const headings = screen
            .getAllByRole('heading', { level: 2 })
            .map((h) => h.textContent);

        expect(headings).toEqual([
            'vs last week',
            'vs a month ago',
            'vs your own year',
        ]);
    });

    it('renders "vs race day" instead of "vs your own year" when a race is set', () => {
        setMockPage({
            activeRace: {
                id: 1,
                race_date: '2099-11-08',
                distance_m: 10_000,
                goal_time_sec: 3120,
                name: 'Bandung 10K',
            },
        });

        render(<Trends {...BASE_PROPS} ctlTrend={yearOfTrend()} />);

        expect(screen.getByText('vs race day')).toBeInTheDocument();
        expect(screen.queryByText('vs your own year')).not.toBeInTheDocument();
    });

    it('shows the honest empty verdict card while narration is pending', () => {
        render(
            <Trends
                {...BASE_PROPS}
                narration={{
                    id: null,
                    status: 'pending',
                    content: null,
                    type: 'trend_read',
                    subject_type: 'trend_read_user_range',
                    subject_id: 1,
                    discriminator: '7d',
                }}
            />,
        );

        expect(screen.getByText(/not written yet/)).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /try again/ }),
        ).toBeInTheDocument();
    });

    it('holds every block back behind a skeleton until its props land', () => {
        setMockDeferred([
            'narration',
            'weekComparison',
            'load',
            'ctlTrend',
            'chartAnnotations',
        ]);

        const { container } = render(<Trends />);

        expect(screen.getByText('am I getting fitter,')).toBeInTheDocument();
        expect(screen.queryByText('vs last week')).not.toBeInTheDocument();
        expect(screen.queryByText('vs a month ago')).not.toBeInTheDocument();
        expect(container.querySelectorAll('.skeleton').length).toBeGreaterThan(
            0,
        );
    });

    it("states this week's km and runs against last week", () => {
        render(<Trends {...BASE_PROPS} />);

        expect(screen.getByText('18.4')).toBeInTheDocument();
        expect(screen.getByText('−3.7 km')).toBeInTheDocument();
    });
});
