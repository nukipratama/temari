import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { Budget, CostChart } from '@/pages/Narration/types';

import TodayPanel from './TodayPanel';

const BUDGET: Budget = {
    todayCost: 1.87,
    dailyCeiling: 2,
    perUserCeiling: 1,
    totalCeiling: 5,
    athletes: 2,
    currency: 'USD',
    trippedAt: null,
    degradedFills: 0,
};

const CHART: CostChart = {
    kinds: [],
    days: [
        { day: '2026-09-14', cost: 0.3, byKind: {} },
        { day: '2026-09-15', cost: 1.5, byKind: {} },
        { day: '2026-09-16', cost: 0.6, byKind: {} },
        { day: '2026-09-17', cost: 1.87, byKind: {} },
    ],
};

describe('TodayPanel', () => {
    it('reads today against the app-wide ceiling', () => {
        render(<TodayPanel budget={BUDGET} chart={CHART} />);

        expect(screen.getByText('$1.87')).toBeInTheDocument();
        expect(
            screen.getByText('37% of the app-wide ceiling'),
        ).toBeInTheDocument();
    });

    it('says so plainly when no app-wide ceiling is configured', () => {
        render(
            <TodayPanel
                budget={{ ...BUDGET, totalCeiling: null }}
                chart={CHART}
            />,
        );

        expect(
            screen.getByText('No app-wide ceiling set.'),
        ).toBeInTheDocument();
    });

    it('labels the enforced ceiling, median, yesterday and the busiest prior day', () => {
        render(<TodayPanel budget={BUDGET} chart={CHART} />);

        expect(screen.getByText('app-wide ceiling')).toBeInTheDocument();
        expect(screen.getByText('$5.00')).toBeInTheDocument();

        expect(screen.getByText('median day, 30d')).toBeInTheDocument();
        expect(screen.getByText('$1.05')).toBeInTheDocument();

        expect(screen.getByText('yesterday')).toBeInTheDocument();
        expect(screen.getByText('$0.60')).toBeInTheDocument();

        expect(
            screen.getByText('busiest day before today'),
        ).toBeInTheDocument();
        expect(screen.getByText('$1.50')).toBeInTheDocument();
    });

    it('excludes today itself from the busiest-prior-day reference', () => {
        render(<TodayPanel budget={BUDGET} chart={CHART} />);

        // The busiest of the three days before today is $1.50, not today's $1.87.
        const busiest = screen.getByText(
            'busiest day before today',
        ).nextElementSibling;
        expect(busiest).toHaveTextContent('$1.50');
    });

    it('shows dashes for every reference when there is no chart history yet', () => {
        render(
            <TodayPanel
                budget={{ ...BUDGET, todayCost: 0 }}
                chart={{ kinds: [], days: [] }}
            />,
        );

        expect(screen.getByText('no days yet')).toBeInTheDocument();
        expect(screen.getAllByText('—').length).toBeGreaterThanOrEqual(2);
    });
});
