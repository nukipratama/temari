import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { FitnessTrendPoint } from './panels/FitnessPanel';

import MonthComparison from './MonthComparison';

vi.mock('./panels/FitnessPanel', () => ({
    default: () => <div data-testid="fitness-panel" />,
}));

function series(ctls: number[]): FitnessTrendPoint[] {
    return ctls.map((ctl, i) => ({
        date: `2026-01-${String(i + 1).padStart(2, '0')}`,
        atl: ctl,
        ctl,
    }));
}

describe('MonthComparison', () => {
    it('labels the section and embeds the fitness chart', () => {
        render(<MonthComparison trend={series([40, 41, 42])} />);

        expect(screen.getByText('vs a month ago')).toBeInTheDocument();
        expect(screen.getByTestId('fitness-panel')).toBeInTheDocument();
    });

    it('reads fitness now and a month ago off the same series, with a delta', () => {
        // 35 points: last is "now", index length-1-30 is "a month ago". An
        // earlier spike keeps "best this year" distinct from "now".
        const values = Array.from({ length: 35 }, (_, i) => 30 + i);
        values[10] = 90;
        render(<MonthComparison trend={series(values)} />);

        expect(screen.getByText('fitness now')).toBeInTheDocument();
        expect(screen.getByText('64.0')).toBeInTheDocument(); // last value
        expect(screen.getByText('+30')).toBeInTheDocument(); // 64 - 34
        expect(
            screen.getByText(/34.0 a month ago · climbing/),
        ).toBeInTheDocument();
    });

    it('states the best-this-year peak and whether today is the high point', () => {
        const values = [50, 45, 60, 55];
        render(<MonthComparison trend={series(values)} />);

        expect(screen.getByText('best this year')).toBeInTheDocument();
        expect(screen.getByText('60.0')).toBeInTheDocument();
        expect(
            screen.getByText(/where today sits against it/),
        ).toBeInTheDocument();
    });

    it('says today is the high point when the last value is the peak', () => {
        render(<MonthComparison trend={series([50, 55, 60])} />);

        expect(screen.getByText(/today is the high point/)).toBeInTheDocument();
    });

    it('renders an em dash with no delta when the series is empty', () => {
        render(<MonthComparison trend={[]} />);

        expect(screen.getAllByText('—')).toHaveLength(2);
    });
});
