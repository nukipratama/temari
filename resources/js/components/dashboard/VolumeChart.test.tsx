import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import VolumeChart, { volumeTooltipLabel } from './VolumeChart';
import type { TooltipItem } from 'chart.js';

describe('VolumeChart', () => {
    it('renders a bar chart with the provided data', () => {
        render(
            <VolumeChart
                data={{
                    labels: ['2026-05-01', '2026-05-08'],
                    ctl: [40, 42],
                    atl: [30, 35],
                    form: [10, 7],
                    volume: [25, 28],
                }}
            />,
        );
        expect(screen.getByTestId('bar-chart')).toBeInTheDocument();
    });

    it('shows total + peak summary stats above the chart', () => {
        render(
            <VolumeChart
                data={{
                    labels: ['a', 'b', 'c'],
                    ctl: [0, 0, 0],
                    atl: [0, 0, 0],
                    form: [0, 0, 0],
                    volume: [10, 25, 15],
                }}
            />,
        );
        expect(screen.getByText('50.0 km')).toBeInTheDocument(); // total
        expect(screen.getByText('25.0 km')).toBeInTheDocument(); // peak
        expect(screen.getByText('3 minggu')).toBeInTheDocument();
    });

    it('renders em-dash for peak when volume series is entirely null', () => {
        render(
            <VolumeChart
                data={{
                    labels: ['a'],
                    ctl: [0],
                    atl: [0],
                    form: [0],
                    volume: [null],
                }}
            />,
        );
        expect(screen.getByText('—')).toBeInTheDocument();
    });
});

describe('volumeTooltipLabel', () => {
    it('renders "Volume: <one-decimal value> km"', () => {
        const ctx = { parsed: { y: 12.5 } } as unknown as TooltipItem<'bar'>;
        expect(volumeTooltipLabel(ctx)).toBe('Volume: 12.5 km');
    });

    it('renders an em-dash for null y', () => {
        const ctx = { parsed: { y: null } } as unknown as TooltipItem<'bar'>;
        expect(volumeTooltipLabel(ctx)).toBe('Volume: —');
    });
});
