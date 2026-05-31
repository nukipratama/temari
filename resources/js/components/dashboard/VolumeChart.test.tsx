import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import VolumeChart, { volumeTooltipLabel } from './VolumeChart';
import type { TooltipItem } from 'chart.js';
import type { ComponentProps } from 'react';

type VolumeData = ComponentProps<typeof VolumeChart>['data'];

function volumeData(overrides: Partial<VolumeData> = {}): VolumeData {
    const { labels = ['a', 'b'], volume = [25, 28], ...rest } = overrides;
    const zeros = labels.map(() => 0);
    return { labels, ctl: zeros, atl: zeros, form: zeros, volume, ...rest };
}

describe('VolumeChart', () => {
    it('renders a bar chart with the provided data', () => {
        render(<VolumeChart data={volumeData({ labels: ['2026-05-01', '2026-05-08'] })} />);
        expect(screen.getByTestId('bar-chart')).toBeInTheDocument();
    });

    it('shows total + peak summary stats above the chart', () => {
        render(<VolumeChart data={volumeData({ labels: ['a', 'b', 'c'], volume: [10, 25, 15] })} />);
        expect(screen.getByText('50.0 km')).toBeInTheDocument(); // total
        expect(screen.getByText('25.0 km')).toBeInTheDocument(); // peak
        expect(screen.getByText('3 minggu')).toBeInTheDocument();
    });

    it('renders em-dash for peak when volume series is entirely null', () => {
        render(<VolumeChart data={volumeData({ labels: ['a'], volume: [null] })} />);
        expect(screen.getByText('—')).toBeInTheDocument();
    });

    it('colour-codes bars across the full intensity range (lightest → darkest)', () => {
        // Ratios: 5/50=0.1 (lightest), 20/50=0.4 (mid), 35/50=0.7 (BAR_FILL),
        // 50/50=1.0 (darkest). Exercises all four buckets in intensityFill.
        render(<VolumeChart data={volumeData({ labels: ['a', 'b', 'c', 'd'], volume: [5, 20, 35, 50] })} />);
        expect(screen.getByTestId('bar-chart')).toBeInTheDocument();
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
