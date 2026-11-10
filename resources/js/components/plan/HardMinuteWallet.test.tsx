import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import HardMinuteWallet from './HardMinuteWallet';

describe('HardMinuteWallet', () => {
    it('leads with the minutes left, then what is spent and booked under the cap', () => {
        render(
            <HardMinuteWallet
                budget={{
                    cap_minutes: 60,
                    spent_minutes: 29,
                    booked_minutes: 15,
                    left_minutes: 16,
                }}
            />,
        );

        expect(screen.getByText('16 left')).toHaveClass('text-stat-sm');
        expect(screen.getByText('29 spent')).toBeInTheDocument();
        expect(screen.getByText('15 booked')).toBeInTheDocument();
        expect(screen.getByText('cap 60')).toBeInTheDocument();
        expect(screen.queryByText(/ of 60|% hard/)).not.toBeInTheDocument();
        expect(
            screen.getByRole('img', {
                name: '29 hard minutes spent, 15 booked, 16 left of a 60-minute cap',
            }),
        ).toBeInTheDocument();
    });

    it('says how far over the cap a week runs, with nothing booked', () => {
        render(
            <HardMinuteWallet
                budget={{
                    cap_minutes: 60,
                    spent_minutes: 66,
                    booked_minutes: 0,
                    left_minutes: -6,
                }}
            />,
        );

        expect(screen.getByText('6 over')).toHaveClass('text-stat-sm');
        expect(screen.getByText('66 spent')).toBeInTheDocument();
        expect(screen.queryByText(/booked/)).not.toBeInTheDocument();
    });
});
