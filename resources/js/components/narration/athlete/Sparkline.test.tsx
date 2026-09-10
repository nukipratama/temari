import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import Sparkline from './Sparkline';

const points = [
    { day: '2026-09-08', cost: 0 },
    { day: '2026-09-09', cost: 0.5 },
    { day: '2026-09-10', cost: 0.25 },
];

describe('Sparkline', () => {
    it('draws one path across every day and names the peak', () => {
        render(<Sparkline points={points} currency="USD" />);

        const chart = screen.getByRole('img');
        expect(chart.getAttribute('aria-label')).toContain('$0.50');
        expect(chart.querySelector('path')?.getAttribute('d')).toBe(
            'M0.0,40.0 L120.0,0.0 L240.0,20.0',
        );
        expect(screen.getByText('peak $0.50')).toBeInTheDocument();
    });

    it('draws a flat line when nothing was spent', () => {
        render(
            <Sparkline
                points={points.map((p) => ({ ...p, cost: 0 }))}
                currency="USD"
            />,
        );

        expect(
            screen.getByRole('img').querySelector('path')?.getAttribute('d'),
        ).toBe('M0.0,40.0 L120.0,40.0 L240.0,40.0');
    });

    it('says so when there are no days at all', () => {
        render(<Sparkline points={[]} currency="USD" />);

        expect(screen.getByRole('img').getAttribute('aria-label')).toBe(
            'no spend recorded',
        );
    });
});
