import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import FitnessChart, { fitnessTooltipLabel } from './FitnessChart';
import type { TooltipItem } from 'chart.js';

describe('FitnessChart', () => {
    it('renders a line chart with the provided data', () => {
        render(
            <FitnessChart
                data={{
                    labels: ['2026-05-01', '2026-05-08'],
                    ctl: [40, 42],
                    atl: [30, 35],
                    form: [10, 7],
                    volume: [25, 28],
                }}
            />,
        );
        expect(screen.getByTestId('line-chart')).toBeInTheDocument();
    });

    it('surfaces the latest CTL / ATL / Form values above the chart', () => {
        render(
            <FitnessChart
                data={{
                    labels: ['a', 'b'],
                    ctl: [10, 50.7],
                    atl: [20, 40.3],
                    form: [-3, 10.4],
                    volume: [0, 0],
                }}
            />,
        );
        expect(screen.getByText('50.7')).toBeInTheDocument();
        expect(screen.getByText('40.3')).toBeInTheDocument();
        expect(screen.getByText('10.4')).toBeInTheDocument();
    });

    it('renders em-dash for series that have no numeric data points', () => {
        render(
            <FitnessChart
                data={{
                    labels: ['a', 'b'],
                    ctl: [null, null],
                    atl: [null, null],
                    form: [null, null],
                    volume: [null, null],
                }}
            />,
        );
        expect(screen.getAllByText('—').length).toBe(3);
    });

    it('falls back to the most-recent non-null point when later values are null', () => {
        render(
            <FitnessChart
                data={{
                    labels: ['a', 'b', 'c'],
                    ctl: [10, 20, null],
                    atl: [null, null, null],
                    form: [null, null, null],
                    volume: [0, 0, 0],
                }}
            />,
        );
        // CTL → most recent non-null = 20.0
        expect(screen.getByText('20.0')).toBeInTheDocument();
    });
});

describe('fitnessTooltipLabel', () => {
    it('renders "<label>: <one-decimal value>"', () => {
        const ctx = { dataset: { label: 'CTL' }, parsed: { y: 42.345 } } as unknown as TooltipItem<'line'>;
        expect(fitnessTooltipLabel(ctx)).toBe('CTL: 42.3');
    });

    it('renders an em-dash for null y', () => {
        const ctx = { dataset: { label: 'Form' }, parsed: { y: null } } as unknown as TooltipItem<'line'>;
        expect(fitnessTooltipLabel(ctx)).toBe('Form: —');
    });

    it('uses an empty label when the dataset label is missing', () => {
        const ctx = { dataset: {}, parsed: { y: 7 } } as unknown as TooltipItem<'line'>;
        expect(fitnessTooltipLabel(ctx)).toBe(': 7.0');
    });
});
