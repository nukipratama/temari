import { render, screen } from '@testing-library/react';
import { Chart as ChartJS } from 'chart.js';
import { describe, expect, it } from 'vitest';

import LineChart from './LineChart';

describe('LineChart', () => {
    it('registers the scales and elements the progression chart draws with', () => {
        expect(ChartJS.registry.scales.get('category')).toBeDefined();
        expect(ChartJS.registry.scales.get('linear')).toBeDefined();
        expect(ChartJS.registry.elements.get('point')).toBeDefined();
        expect(ChartJS.registry.elements.get('line')).toBeDefined();
        expect(ChartJS.registry.plugins.get('filler')).toBeDefined();
        expect(ChartJS.registry.plugins.get('tooltip')).toBeDefined();
        expect(ChartJS.registry.plugins.get('legend')).toBeDefined();
    });

    it('default-exports the react-chartjs-2 Line component so React.lazy can mount it', () => {
        render(<LineChart data={{ labels: [], datasets: [] }} />);
        expect(screen.getByTestId('line-chart')).toBeInTheDocument();
    });
});
