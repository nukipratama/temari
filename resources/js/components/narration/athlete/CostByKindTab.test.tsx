import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import CostByKindTab from './CostByKindTab';

const rows = [
    {
        kind: 'trend_read',
        today: { cost: 0.02, calls: 1 },
        week: { cost: 0.1, calls: 4 },
        month: { cost: 0.4, calls: 16 },
    },
];

describe('CostByKindTab', () => {
    it('lays each kind out across today, seven and thirty days', () => {
        render(<CostByKindTab rows={rows} currency="USD" />);

        expect(
            screen.getByRole('rowheader', { name: 'trend_read' }),
        ).toBeInTheDocument();
        expect(screen.getByText('$0.02')).toBeInTheDocument();
        expect(screen.getByText('$0.40')).toBeInTheDocument();
        expect(screen.getByText('16 calls')).toBeInTheDocument();
    });

    it('shows an empty state when nothing was billed', () => {
        render(<CostByKindTab rows={[]} currency="USD" />);

        expect(
            screen.getByText('no spend in the last 30 days'),
        ).toBeInTheDocument();
    });
});
