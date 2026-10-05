import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { RaceAmbition } from '@/types/inertia';

import ConfirmEffortNudge from './ConfirmEffortNudge';

const AMBITION: RaceAmbition = {
    state: 'on_track',
    target_time_sec: 3_480,
    target_pace_sec_per_km: 348,
    supported_time_sec: 3_570,
    supported_pace_sec_per_km: 357,
    prescribed_time_sec: 3_480,
    gap_pct: 2.5,
    evidence_confidence: 'provisional',
    basis: { distance_m: 5_000, performed_on: '2026-08-26', activity_id: 42 },
    confirm_nudge: true,
};

describe('ConfirmEffortNudge', () => {
    it('asks about an unconfirmed effort and links to its run', () => {
        render(<ConfirmEffortNudge ambition={AMBITION} />);

        expect(
            screen.getByText(
                /this rests on your 5K on aug 26, which i picked up from Strava/,
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'open the run' }),
        ).toHaveAttribute('href', '/activities/42');
    });

    it('asks for a recent effort when the supported time is stale', () => {
        render(
            <ConfirmEffortNudge
                ambition={{
                    ...AMBITION,
                    evidence_confidence: 'stale',
                    basis: {
                        distance_m: 21_097,
                        performed_on: '2026-03-01',
                        activity_id: null,
                    },
                }}
            />,
        );

        expect(
            screen.getByText(
                /your half marathon on mar 1 is the newest hard effort i have, and it's over 16 weeks old/,
            ),
        ).toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('stays out of the way when the supported time rests on a recent confirmed effort', () => {
        const { container } = render(
            <ConfirmEffortNudge
                ambition={{
                    ...AMBITION,
                    confirm_nudge: false,
                    evidence_confidence: 'confirmed',
                }}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('says nothing without a supported time', () => {
        const { container } = render(
            <ConfirmEffortNudge
                ambition={{
                    ...AMBITION,
                    supported_time_sec: null,
                    basis: null,
                }}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });
});
