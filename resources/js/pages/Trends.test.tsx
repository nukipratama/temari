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
    form_known_from: '2026-01-01',
    ctl_42d: 42,
    atl_7d: 44.5,
    weekly_trimp: 320,
    weekly_trimp_range: { low: 280, high: 360 },
    monotony: 1.2,
    monotony_range: { low: 1.0, high: 1.6 },
    strain: 384,
    strain_range: { low: 300, high: 460 },
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
        date_ranges: {
            this_week: { start: '2026-05-11', end: '2026-05-14' },
            last_week: { start: '2026-05-04', end: '2026-05-07' },
            load: { start: '2026-05-08', end: '2026-05-14' },
        },
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
        form_status: 'fresh' as const,
    }));
}

describe('Trends', () => {
    it('shows the AI pause banner while generation is paused', () => {
        setMockPage({ aiPaused: true });

        render(<Trends {...BASE_PROPS} />);

        expect(screen.getByText(/catching her breath/)).toBeInTheDocument();
    });

    it('titles the page with exactly one h1', () => {
        render(<Trends {...BASE_PROPS} />);

        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    });

    it('renders the page headline', () => {
        render(<Trends {...BASE_PROPS} />);

        expect(screen.getByText('am i getting fitter,')).toBeInTheDocument();
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
            "Temari's read · last 7 days",
            'vs last week',
            'long-term load',
            'vs race day',
        ]);
    });

    it('places supported over time directly after vs race day when there is history', () => {
        render(
            <Trends
                {...BASE_PROPS}
                ctlTrend={yearOfTrend()}
                supportedHistory={{
                    target_time_sec: 3000,
                    points: [
                        {
                            date: '2026-08-03',
                            supported_time_sec: 3300,
                            source: { distance_m: 5000, date: '2026-07-20' },
                            new_source: false,
                        },
                        {
                            date: '2026-10-04',
                            supported_time_sec: 3105,
                            source: { distance_m: 5000, date: '2026-07-20' },
                            new_source: false,
                        },
                    ],
                }}
            />,
        );

        const headings = screen
            .getAllByRole('heading', { level: 2 })
            .map((h) => h.textContent);

        expect(headings.slice(-2)).toEqual([
            'vs race day',
            'supported over time',
        ]);
    });

    it('counts down to race day when a race is set', () => {
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

        expect(
            screen.getByText(/vs race day · \d+ days out/),
        ).toBeInTheDocument();
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

        expect(screen.getByText('am i getting fitter,')).toBeInTheDocument();
        expect(screen.queryByText('vs last week')).not.toBeInTheDocument();
        expect(screen.queryByText('long-term load')).not.toBeInTheDocument();
        expect(container.querySelectorAll('.skeleton').length).toBeGreaterThan(
            0,
        );
    });

    it('sizes the fitness chart skeleton in rem to match the chart', () => {
        setMockDeferred(['ctlTrend', 'chartAnnotations']);

        const { container } = render(<Trends {...BASE_PROPS} />);

        expect(container.querySelector('.h-\\[10\\.5rem\\]')).not.toBeNull();
        expect(container.querySelector('.h-\\[168px\\]')).toBeNull();
    });

    it("states this week's km and runs against last week", () => {
        render(<Trends {...BASE_PROPS} />);

        expect(screen.getByText('18.4')).toBeInTheDocument();
        expect(screen.getByText('−3.7 km')).toBeInTheDocument();
    });
});
