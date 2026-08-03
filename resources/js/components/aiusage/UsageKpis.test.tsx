import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { PreviousTotals, UsageTotals } from '@/pages/AiUsage/types';

import UsageKpis from './UsageKpis';

function totals(overrides: Partial<UsageTotals> = {}): UsageTotals {
    return {
        prompt: 600,
        completion: 280,
        total: 880,
        calls: 3,
        cost: 0.05,
        truncated_calls: 0,
        ...overrides,
    };
}

const previous: PreviousTotals = {
    prompt: 500,
    completion: 200,
    total: 700,
    calls: 2,
    cost: 0.04,
};

function renderKpis(overrides: Partial<Parameters<typeof UsageKpis>[0]> = {}) {
    return render(
        <UsageKpis
            totals={totals()}
            previousTotals={previous}
            currency="USD"
            {...overrides}
        />,
    );
}

describe('UsageKpis', () => {
    it('shows totals, cost and the prompt share of the window', () => {
        renderKpis();

        expect(screen.getByText('880')).toBeInTheDocument();
        expect(screen.getByText('$0,05')).toBeInTheDocument();
        expect(screen.getByText('68% dari total')).toBeInTheDocument();
    });

    it('shows the average tokens per call', () => {
        renderKpis();

        expect(screen.getByText('293 token/call')).toBeInTheDocument();
    });

    it('reports the truncated share against the call count', () => {
        renderKpis({ totals: totals({ calls: 4, truncated_calls: 1 }) });

        expect(screen.getByText('25%')).toBeInTheDocument();
        expect(screen.getByText('1 dari 4 call')).toBeInTheDocument();
    });

    it('avoids dividing by zero on an empty window', () => {
        renderKpis({
            totals: totals({
                prompt: 0,
                completion: 0,
                total: 0,
                calls: 0,
                cost: 0,
            }),
        });

        expect(screen.getByText('0% dari total')).toBeInTheDocument();
        expect(screen.getByText('0 dari 0 call')).toBeInTheDocument();
    });

    it('shows a vs-previous delta on the KPI tiles', () => {
        renderKpis();

        expect(screen.getByText(/26% vs sblm/)).toBeInTheDocument();
    });

    it('marks a drop with a down arrow', () => {
        renderKpis({ previousTotals: { ...previous, total: 1760 } });

        expect(screen.getByText(/▼ 50% vs sblm/)).toBeInTheDocument();
    });

    it('marks an unchanged window without an arrow', () => {
        renderKpis({ previousTotals: { ...previous, total: 880 } });

        expect(screen.getByText(/· 0% vs sblm/)).toBeInTheDocument();
    });

    it('reads a window with no prior data as "baru" rather than an infinite jump', () => {
        renderKpis({ previousTotals: { ...previous, total: 0 } });

        expect(screen.getByText('· baru')).toBeInTheDocument();
    });

    it('shows nothing at all when both the prior and current window are empty', () => {
        renderKpis({
            totals: totals({ total: 0 }),
            previousTotals: { ...previous, total: 0 },
        });

        expect(screen.queryByText('· baru')).not.toBeInTheDocument();
    });

    it('hides the delta when there is no comparable prior window', () => {
        renderKpis({ previousTotals: null });

        expect(screen.queryByText(/vs sblm/)).not.toBeInTheDocument();
    });
});
