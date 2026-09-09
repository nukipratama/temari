import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { MonthTotals } from '@/pages/Activities/useCalendar';

import MonthlyStatusChips from './MonthlyStatusChips';

describe('MonthlyStatusChips', () => {
    it("names the month's runs, distance and load", () => {
        const totals: MonthTotals = { runs: 12, km: 84.5, trimp: 960 };
        render(<MonthlyStatusChips totals={totals} />);

        expect(screen.getByText('Runs 12')).toBeInTheDocument();
        expect(screen.getByText('Distance 84.5 km')).toBeInTheDocument();
        expect(screen.getByText('TRIMP 960')).toBeInTheDocument();
    });

    it('omits the TRIMP chip when the month scored nothing, rather than showing a zero', () => {
        const totals: MonthTotals = { runs: 0, km: 0, trimp: null };
        render(<MonthlyStatusChips totals={totals} />);

        expect(screen.getByText('Runs 0')).toBeInTheDocument();
        expect(screen.queryByText(/TRIMP/)).not.toBeInTheDocument();
    });
});
