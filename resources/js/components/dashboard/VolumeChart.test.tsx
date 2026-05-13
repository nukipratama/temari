import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import VolumeChart from './VolumeChart';

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
});
