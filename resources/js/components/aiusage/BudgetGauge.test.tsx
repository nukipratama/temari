import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { Budget } from '@/pages/AiUsage/types';

import BudgetGauge from './BudgetGauge';

function budget(overrides: Partial<Budget> = {}): Budget {
    return {
        todayCost: 0.02,
        dailyCeiling: 0.1,
        currency: 'USD',
        ...overrides,
    };
}

describe('BudgetGauge', () => {
    it('renders spend against the ceiling with a list-price caveat', () => {
        render(<BudgetGauge budget={budget()} />);

        expect(screen.getByText("Today's Budget")).toBeInTheDocument();
        expect(screen.getByText('$0.02')).toBeInTheDocument();
        expect(screen.getByText(/list price/i)).toBeInTheDocument();
    });

    it('fills the gauge to the spend-over-ceiling ratio', () => {
        render(<BudgetGauge budget={budget()} />);

        const gauge = screen.getByRole('progressbar', {
            name: /today's budget/i,
        });
        expect(gauge.getAttribute('aria-valuenow')).toBe('20');
    });

    it('shows a no-ceiling state instead of a gauge when dailyCeiling is null', () => {
        render(<BudgetGauge budget={budget({ dailyCeiling: null })} />);

        expect(screen.getByText(/no limit/i)).toBeInTheDocument();
        expect(screen.getByText('No daily limit set.')).toBeInTheDocument();
        expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
    });

    it('treats a zero ceiling as no ceiling rather than dividing by it', () => {
        render(<BudgetGauge budget={budget({ dailyCeiling: 0 })} />);

        expect(screen.getByText(/no limit/i)).toBeInTheDocument();
    });

    it('names the overshoot amount once spend passes the ceiling', () => {
        render(<BudgetGauge budget={budget({ todayCost: 0.15 })} />);

        expect(
            screen.getByText('Over the daily limit by $0.05.'),
        ).toBeInTheDocument();
    });

    it('scales both figures to the budget currency', () => {
        render(
            <BudgetGauge
                budget={budget({
                    todayCost: 1000,
                    dailyCeiling: 5000,
                    currency: 'IDR',
                })}
            />,
        );

        expect(screen.getByText('Rp 1,000.00')).toBeInTheDocument();
    });
});
