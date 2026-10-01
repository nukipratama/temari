import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { TrainingLoad, WeekComparison as Payload } from '@/types/inertia';

import WeekComparison from './WeekComparison';

function payload(overrides: Partial<Payload> = {}): Payload {
    return {
        this_week_km: 18.4,
        last_week_km: 22.1,
        this_week_runs: 3,
        last_week_runs: 4,
        date_ranges: {
            this_week: { start: '2026-05-11', end: '2026-05-14' },
            last_week: { start: '2026-05-04', end: '2026-05-07' },
            load: { start: '2026-05-08', end: '2026-05-14' },
        },
        ...overrides,
    };
}

function load(overrides: Partial<TrainingLoad> = {}): TrainingLoad {
    return {
        form: -18.5,
        form_status: 'fatigued',
        form_known_from: '2026-01-01',
        ctl_42d: 42.8,
        atl_7d: 61.3,
        weekly_trimp: 246,
        weekly_trimp_range: { low: 200, high: 300 },
        monotony: 1.9,
        monotony_range: { low: 1.4, high: 2.2 },
        strain: 467,
        strain_range: { low: 350, high: 550 },
        ...overrides,
    };
}

describe('WeekComparison', () => {
    it('labels both calendar slices and the rolling load window with date ranges', () => {
        render(<WeekComparison weekComparison={payload()} load={load()} />);

        expect(screen.getByText('vs last week')).toBeInTheDocument();
        expect(
            screen.getByText(
                'this week · 11–14 may 2026, last week · 4–7 may 2026',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText('last 7 days · 8–14 may 2026'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('load balance as of 14 may 2026'),
        ).toBeInTheDocument();
    });

    it('shows a one-day window once', () => {
        render(
            <WeekComparison
                weekComparison={payload({
                    date_ranges: {
                        this_week: { start: '2026-09-28', end: '2026-09-28' },
                        last_week: { start: '2026-09-21', end: '2026-09-21' },
                        load: { start: '2026-09-22', end: '2026-09-28' },
                    },
                })}
                load={load()}
            />,
        );

        expect(
            screen.getByText(
                'this week · 28 sep 2026, last week · 21 sep 2026',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText('last 7 days · 22–28 sep 2026'),
        ).toBeInTheDocument();
    });

    it('keeps both years explicit when the load range crosses new year', () => {
        render(
            <WeekComparison
                weekComparison={payload({
                    date_ranges: {
                        this_week: { start: '2026-12-28', end: '2027-01-03' },
                        last_week: { start: '2026-12-21', end: '2026-12-27' },
                        load: { start: '2026-12-28', end: '2027-01-03' },
                    },
                })}
                load={load()}
            />,
        );

        expect(
            screen.getByText(
                'this week · 28 dec 2026–3 jan 2027, last week · 21–27 dec 2026',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText('last 7 days · 28 dec 2026–3 jan 2027'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('load balance as of 3 jan 2027'),
        ).toBeInTheDocument();
    });

    it("states this week's km and runs with a delta against last week", () => {
        render(<WeekComparison weekComparison={payload()} load={load()} />);

        expect(screen.getByText('km this week')).toBeInTheDocument();
        expect(screen.getByText('18.4')).toBeInTheDocument();
        expect(screen.getByText('−3.7 km')).toBeInTheDocument();
        expect(screen.getByText('runs this week')).toBeInTheDocument();
        expect(screen.getByText('3')).toBeInTheDocument();
        expect(screen.getByText('−1')).toBeInTheDocument();
    });

    it('renders an em dash and no delta when there is no prior-week baseline', () => {
        render(
            <WeekComparison
                weekComparison={payload({
                    this_week_km: null,
                    last_week_km: null,
                    this_week_runs: null,
                    last_week_runs: null,
                })}
                load={load()}
            />,
        );

        expect(screen.getAllByText('—')).toHaveLength(2);
    });

    it('states the form chip, its signed number and a plain-language meaning', () => {
        render(<WeekComparison weekComparison={payload()} load={load()} />);

        expect(screen.getByText('heavy')).toBeInTheDocument();
        expect(screen.getByText('-18.5')).toBeInTheDocument();
        expect(
            screen.getByText(/above your longer-term load/),
        ).toBeInTheDocument();
    });

    it('states load, sameness and total cost, each with a plain label and its own normal-range meaning line', () => {
        render(<WeekComparison weekComparison={payload()} load={load()} />);

        expect(screen.getByText('load')).toBeInTheDocument();
        expect(screen.getByText('246')).toBeInTheDocument();
        expect(screen.getByText('sameness')).toBeInTheDocument();
        expect(screen.getByText('1.9')).toBeInTheDocument();
        expect(screen.getByText('total cost')).toBeInTheDocument();
        expect(screen.getByText('467')).toBeInTheDocument();
        expect(
            screen.getByText(
                /246 over your last 7 days\. a steady week for you sits around 200 to 300\./,
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /1\.9 over your last 7 days\. a steady week for you sits around 1\.4 to 2\.2\./,
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /467 over your last 7 days\. a steady week for you sits around 350 to 550\./,
            ),
        ).toBeInTheDocument();
    });

    it("falls back to a plain gloss for each of the three when there's no baseline range yet", () => {
        render(
            <WeekComparison
                weekComparison={payload()}
                load={load({
                    weekly_trimp_range: null,
                    monotony_range: null,
                    strain_range: null,
                })}
            />,
        );

        expect(
            screen.getByText(
                /246 over your last 7 days: heart rate and time, added up\./,
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /1\.9 over your last 7 days: how varied your training's been\./,
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /467 over your last 7 days: the week's effort multiplied by how varied it was\./,
            ),
        ).toBeInTheDocument();
    });

    it('renders an honest empty note instead of the cost side when load is null', () => {
        render(<WeekComparison weekComparison={payload()} load={null} />);

        expect(
            screen.getByText(/not enough training history yet/),
        ).toBeInTheDocument();
        expect(screen.queryByText('load')).not.toBeInTheDocument();
    });
});

describe('WeekComparison during the form warm-up', () => {
    it('shows a neutral learning state with the days left instead of a verdict', () => {
        render(
            <WeekComparison
                weekComparison={payload()}
                load={load({
                    form_status: null,
                    form_known_from: '2026-05-26',
                })}
            />,
        );

        expect(screen.getByText('learning')).toBeInTheDocument();
        expect(
            screen.getByText('still learning your load · 12 days to go'),
        ).toBeInTheDocument();
        expect(screen.queryByText('-18.5')).not.toBeInTheDocument();
    });
});
