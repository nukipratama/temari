import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import HardMinuteWallet from './HardMinuteWallet';

describe('HardMinuteWallet', () => {
    it('reads spent and booked minutes against the locked cap, as minutes and share', () => {
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

        expect(
            screen.getByText('44 / 60 hard min · 22% hard, cap 30%'),
        ).toBeInTheDocument();
        expect(screen.getByText('29 spent')).toBeInTheDocument();
        expect(screen.getByText('15 booked')).toBeInTheDocument();
        expect(screen.getByText('16 left')).toBeInTheDocument();
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

        expect(
            screen.getByText('66 / 60 hard min · 33% hard, cap 30%'),
        ).toBeInTheDocument();
        expect(screen.getByText('6 over')).toBeInTheDocument();
        expect(screen.queryByText(/booked/)).not.toBeInTheDocument();
    });
});
