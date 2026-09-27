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
        ...overrides,
    };
}

function load(overrides: Partial<TrainingLoad> = {}): TrainingLoad {
    return {
        form: -18.5,
        form_status: 'fatigued',
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
    it('labels the section and states the weekday scope', () => {
        render(<WeekComparison weekComparison={payload()} load={load()} />);

        expect(screen.getByText('vs last week')).toBeInTheDocument();
        expect(
            screen.getByText(/so it.?s the same slice of both weeks/),
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

        expect(screen.getByText('tired')).toBeInTheDocument();
        expect(screen.getByText('-18.5')).toBeInTheDocument();
        expect(screen.getByText(/legs are carrying it/)).toBeInTheDocument();
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
