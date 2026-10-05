import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import type { RaceAmbition, RaceSupport } from '@/types/inertia';

import { setMockPage } from '@/test/setup';

import RaceDuel from './RaceDuel';

const RACE = {
    name: 'Jakarta 10K',
    race_date: '2026-12-06',
    goal_time_sec: 3_000,
};

const AMBITION: RaceAmbition = {
    state: 'ambitious',
    target_time_sec: 3_000,
    target_pace_sec_per_km: 300,
    supported_time_sec: 3_150,
    supported_pace_sec_per_km: 315,
    prescribed_time_sec: 3_000,
    gap_pct: 4.8,
    evidence_confidence: 'confirmed',
    stepping_stone_time_sec: null,
    stepping_stone_pace_sec_per_km: null,
    basis: null,
};

const SUPPORT: RaceSupport = {
    mode: 'road',
    dedicated_preparation: true,
    limitation: null,
};

function renderDuel(ambition: Partial<RaceAmbition> = {}, support = SUPPORT) {
    return render(
        <RaceDuel
            race={RACE}
            ambition={{ ...AMBITION, ...ambition }}
            support={support}
        />,
    );
}

describe('RaceDuel', () => {
    beforeEach(() => {
        setMockPage({ today: '2026-11-26' });
    });

    it('faces the target off against the time recent runs support', () => {
        renderDuel();

        expect(screen.getByText('your target')).toBeInTheDocument();
        expect(screen.getByText('50:00')).toBeInTheDocument();
        expect(screen.getByText('supported')).toBeInTheDocument();
        expect(screen.getByText('52:30')).toBeInTheDocument();
        expect(screen.getByText('2:30 behind')).toHaveClass('text-ember-ink');
        expect(
            screen.getByText(
                /ambitious: your target is 4\.8% faster than your recent runs support/,
            ),
        ).toBeInTheDocument();
    });

    it('never says on track when the supported time is behind the target outside the on-track band', () => {
        renderDuel({
            state: 'unsupported',
            supported_time_sec: 3_400,
            gap_pct: 11.8,
        });

        expect(screen.queryByText(/on track/)).not.toBeInTheDocument();
        expect(screen.getByText('supported')).toBeInTheDocument();
        expect(
            screen.getByText(/so the plan trains at the supported effort/),
        ).toBeInTheDocument();
    });

    it('reads on track for only in the on-track band with the supported time not behind', () => {
        renderDuel({
            state: 'on_track',
            supported_time_sec: 2_950,
            gap_pct: -1.7,
        });

        expect(screen.getByText('on track for')).toBeInTheDocument();
        expect(screen.getByText('0:50 ahead')).toHaveClass('text-leaf-ink');
    });

    it('keeps the on-track band neutral when the supported time trails slightly', () => {
        renderDuel({
            state: 'on_track',
            supported_time_sec: 3_060,
            gap_pct: 2,
        });

        expect(screen.queryByText('on track for')).not.toBeInTheDocument();
        expect(screen.getByText('supported')).toBeInTheDocument();
        expect(screen.getByText('1:00 behind')).toHaveClass('text-text-2');
        expect(screen.getByText(/^on track:/)).toBeInTheDocument();
    });

    it('shows low evidence without a band or gap pill', () => {
        renderDuel({ state: 'low_evidence', supported_time_sec: 3_150 });

        expect(screen.getByText('52:30')).toBeInTheDocument();
        expect(
            screen.queryByText(/behind|ahead|on goal/),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(
                /low evidence: your recent results cover less than half this distance/,
            ),
        ).toBeInTheDocument();
    });

    it('explains an unknown comparison and the honest limit for long goals', () => {
        const { container } = renderDuel(
            {
                state: 'unknown',
                supported_time_sec: null,
                supported_pace_sec_per_km: null,
                gap_pct: null,
            },
            {
                mode: 'general_maintenance',
                dedicated_preparation: false,
                limitation:
                    'beyond the marathon, the plan keeps general aerobic training.',
            },
        );

        expect(screen.getByText('50:00')).toBeInTheDocument();
        expect(screen.queryByText('supported')).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'beyond the marathon, the plan keeps general aerobic training.',
            ),
        ).toBeInTheDocument();
        expect(container.querySelector('svg[data-mascot]')).toHaveAttribute(
            'data-mascot',
            'neutral',
        );
    });

    it('says there are not enough recent results when nothing supports a time yet', () => {
        renderDuel({
            state: 'unknown',
            supported_time_sec: null,
            supported_pace_sec_per_km: null,
            gap_pct: null,
        });

        expect(
            screen.getByText('not enough recent results to compare yet.'),
        ).toBeInTheDocument();
    });

    it('poses the watermark from the gap and carries the race line', () => {
        const { container } = renderDuel({
            state: 'unsupported',
            supported_time_sec: 3_300,
        });

        expect(container.querySelector('svg[data-mascot]')).toHaveAttribute(
            'data-mascot',
            'gassed',
        );
        expect(screen.getByText('Jakarta 10K')).toBeInTheDocument();
        expect(screen.getByText(/· 10 days to go/)).toBeInTheDocument();
    });

    it('lets the times and eyebrows wrap instead of clipping in a narrow column', () => {
        render(
            <RaceDuel
                race={{ ...RACE, goal_time_sec: 13_000 }}
                ambition={{
                    ...AMBITION,
                    state: 'on_track',
                    target_time_sec: 13_000,
                    supported_time_sec: 12_912,
                    gap_pct: -0.7,
                }}
                support={SUPPORT}
            />,
        );

        expect(screen.getByText('on track for')).not.toHaveClass(
            'whitespace-nowrap',
        );
        expect(screen.getByText('3:35:12')).toHaveClass('break-all');
    });
});

describe('RaceDuel basis', () => {
    beforeEach(() => {
        setMockPage({ today: '2026-11-26' });
    });

    it('names the effort the supported time rests on under it', () => {
        renderDuel({
            basis: {
                distance_m: 5_000,
                performed_on: '2026-08-26',
                activity_id: 9,
            },
        });

        expect(
            screen.getByText('based on your 5K on aug 26'),
        ).toBeInTheDocument();
    });
});
