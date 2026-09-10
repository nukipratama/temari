import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type {
    AthleteRow,
    CostChart as CostChartData,
} from '@/pages/Narration/types';

import CostChart from './CostChart';

const CHART: CostChartData = {
    kinds: [
        { kind: 'briefing', label: 'Briefing', cost: 0.4 },
        { kind: 'weekly_recap', label: 'WeeklyRecap', cost: 0.1 },
    ],
    days: [
        {
            day: '2026-09-09',
            cost: 0.3,
            byKind: { briefing: 0.2, weekly_recap: 0.1 },
        },
        { day: '2026-09-10', cost: 0.2, byKind: { briefing: 0.2 } },
    ],
};

const ATHLETE: AthleteRow = {
    user_id: 7,
    user_name: 'Nuki',
    is_demo: false,
    deleted: false,
    today: 0.2,
    last7: 0.5,
    last30: 0.5,
    calls: 4,
    ceiling: 1,
    ceiling_overridden: false,
    capped: false,
    sparkline: [{ day: '2026-09-10', cost: 0.2 }],
    served: { llm: 3, rule_based: 1, unknown: 0 },
    flags: 0,
    dead_lettered: 0,
};

function renderChart(overrides: Partial<Parameters<typeof CostChart>[0]> = {}) {
    return render(
        <CostChart
            chart={CHART}
            currency="USD"
            athletes={[ATHLETE]}
            selected={null}
            onSelect={vi.fn()}
            {...overrides}
        />,
    );
}

describe('CostChart', () => {
    it('totals the range in the header', () => {
        renderChart();

        expect(screen.getByText('$0.50')).toBeInTheDocument();
    });

    it('names each stacked band in the legend by its kind label', () => {
        renderChart();

        expect(screen.getByText('Briefing')).toBeInTheDocument();
        expect(screen.getByText('WeeklyRecap')).toBeInTheDocument();
    });

    it('labels each day bar with its own cost', () => {
        renderChart();

        expect(screen.getByLabelText('sep 9: $0.30')).toBeInTheDocument();
    });

    it('offers every athlete plus an all-athletes option', () => {
        renderChart();

        expect(
            screen.getByRole('option', { name: 'All athletes' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('option', { name: 'Nuki' }),
        ).toBeInTheDocument();
    });

    it('reports the chosen athlete as a number', () => {
        const onSelect = vi.fn();
        renderChart({ onSelect });

        fireEvent.change(screen.getByLabelText('athlete'), {
            target: { value: '7' },
        });

        expect(onSelect).toHaveBeenCalledWith(7);
    });

    it('reports clearing the filter as null rather than an empty string', () => {
        const onSelect = vi.fn();
        renderChart({ onSelect, selected: 7 });

        fireEvent.change(screen.getByLabelText('athlete'), {
            target: { value: '' },
        });

        expect(onSelect).toHaveBeenCalledWith(null);
    });

    it('falls back to an empty state when nothing billed in the range', () => {
        renderChart({ chart: { kinds: [], days: [] } });

        expect(screen.queryByText('Briefing')).not.toBeInTheDocument();
        expect(
            screen.getByText('No token usage recorded in this range yet.'),
        ).toBeInTheDocument();
    });
});
