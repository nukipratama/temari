import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { AthleteHeaderData } from '@/pages/Narration/types';

import AthleteHeader from './AthleteHeader';

function header(overrides: Partial<AthleteHeaderData> = {}): AthleteHeaderData {
    return {
        athlete: { id: 7, name: 'Dina', is_demo: false },
        currency: 'USD',
        today_spend: 0.25,
        ceiling: { value: 1, source: 'config' },
        sparkline: [{ day: '2026-09-10', cost: 0.25 }],
        forecast: {
            month_to_date: 2,
            projected: 6,
            days_remaining: 20,
            daily_rate: 0.2,
        },
        ...overrides,
    };
}

describe('AthleteHeader', () => {
    it('shows today against the configured ceiling', () => {
        render(<AthleteHeader header={header()} />);

        expect(screen.getByText('$0.25')).toBeInTheDocument();
        expect(screen.getByText(/configured slice/)).toBeInTheDocument();
        expect(
            screen
                .getByRole('progressbar', { name: /ceiling/i })
                .getAttribute('aria-valuenow'),
        ).toBe('25');
    });

    it('names an override as the ceiling in force', () => {
        render(
            <AthleteHeader
                header={header({ ceiling: { value: 4, source: 'override' } })}
            />,
        );

        expect(screen.getByText(/today-only override/)).toBeInTheDocument();
    });

    it('drops the gauge when no ceiling is configured', () => {
        render(
            <AthleteHeader
                header={header({ ceiling: { value: null, source: 'config' } })}
            />,
        );

        expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
        expect(
            screen.getByText('no per-athlete ceiling configured.'),
        ).toBeInTheDocument();
    });

    it('labels the month-end number as a projection', () => {
        render(<AthleteHeader header={header()} />);

        expect(screen.getByText(/projection/)).toBeInTheDocument();
        expect(screen.getByText('$6.00')).toBeInTheDocument();
        expect(screen.getByText(/20 day\(s\) left/)).toBeInTheDocument();
    });
});
