import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { RaceAmbition, RaceAmbitionState } from '@/types/inertia';

import SteppingStoneCard from './SteppingStoneCard';

const UNSUPPORTED: RaceAmbition = {
    state: 'unsupported',
    target_time_sec: 3_000,
    target_pace_sec_per_km: 300,
    supported_time_sec: 3_300,
    supported_pace_sec_per_km: 330,
    prescribed_time_sec: 3_300,
    gap_pct: 9.1,
    evidence_confidence: 'confirmed',
    basis: null,
    stepping_stone_time_sec: 3_201,
    stepping_stone_pace_sec_per_km: 320,
};

describe('SteppingStoneCard', () => {
    it('shows the stepping-stone time and pace with what it is for', () => {
        render(<SteppingStoneCard ambition={UNSUPPORTED} />);

        expect(
            screen.getByRole('region', { name: 'stepping stone' }),
        ).toBeInTheDocument();
        expect(screen.getByText('53:21')).toBeInTheDocument();
        expect(screen.getByText('5:20/km')).toBeInTheDocument();
        expect(
            screen.getByText(
                'the edge of on track, 3% faster than your supported time. your goal-pace work runs here, and it moves as you get fitter.',
            ),
        ).toBeInTheDocument();
    });

    it.each<RaceAmbitionState>([
        'on_track',
        'ambitious',
        'low_evidence',
        'unknown',
    ])('renders nothing for a %s goal', (state) => {
        const { container } = render(
            <SteppingStoneCard
                ambition={{
                    ...UNSUPPORTED,
                    state,
                    stepping_stone_time_sec: null,
                    stepping_stone_pace_sec_per_km: null,
                }}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });
});
