import type { PlanSessionSegment } from '@/types/inertia';

import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import SessionBarGraph from './SessionBarGraph';

function segment(
    overrides: Partial<PlanSessionSegment> = {},
): PlanSessionSegment {
    return {
        key: 'main',
        minutes: 30,
        zone: 'Z2',
        pace_label: 'easy',
        pace_sec_per_km: 348,
        ...overrides,
    };
}

const INTERVAL_SESSION: PlanSessionSegment[] = [
    segment({ key: 'warmup', minutes: 10, zone: 'Z1' }),
    segment({ key: 'interval', minutes: 3, zone: 'Z5', pace_label: 'interval' }),
    segment({ key: 'recovery', minutes: 2, zone: 'Z1' }),
    segment({ key: 'interval', minutes: 3, zone: 'Z5', pace_label: 'interval' }),
    segment({ key: 'recovery', minutes: 2, zone: 'Z1' }),
    segment({ key: 'interval', minutes: 3, zone: 'Z5', pace_label: 'interval' }),
    segment({ key: 'cooldown', minutes: 10, zone: 'Z1' }),
];

describe('SessionBarGraph', () => {
    it('renders nothing on a rest day', () => {
        const { container } = render(<SessionBarGraph segments={[]} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('lists every segment of a straight session', () => {
        render(
            <SessionBarGraph
                segments={[
                    segment({ key: 'warmup', minutes: 10, zone: 'Z1' }),
                    segment({ key: 'main', minutes: 30 }),
                    segment({ key: 'cooldown', minutes: 5, zone: 'Z1' }),
                ]}
            />,
        );

        expect(screen.getByText('Warmup')).toBeInTheDocument();
        expect(screen.getByText('Main set')).toBeInTheDocument();
        expect(screen.getByText('Cooldown')).toBeInTheDocument();
        expect(screen.getByText('30 min')).toBeInTheDocument();
    });

    it('collapses interval repeats into one legend block rather than listing every rep', () => {
        render(<SessionBarGraph segments={INTERVAL_SESSION} />);

        expect(screen.getByText('3× Interval')).toBeInTheDocument();
        expect(
            screen.getByText('3 min hard / 2 min easy'),
        ).toBeInTheDocument();
        expect(screen.queryByText('Recovery')).not.toBeInTheDocument();
    });

    it('still draws one bar per rep even when the legend collapses them', () => {
        const { container } = render(
            <SessionBarGraph segments={INTERVAL_SESSION} />,
        );

        expect(container.querySelectorAll('.rounded-t-xs')).toHaveLength(
            INTERVAL_SESSION.length,
        );
    });

    it('sizes bars by minutes and heights by zone', () => {
        const { container } = render(
            <SessionBarGraph
                segments={[
                    segment({ key: 'warmup', minutes: 10, zone: 'Z1' }),
                    segment({ key: 'main', minutes: 30, zone: 'Z5' }),
                ]}
            />,
        );

        const bars = container.querySelectorAll('.rounded-t-xs');
        expect(bars[0]).toHaveStyle({ width: '25%', height: '32%' });
        expect(bars[1]).toHaveStyle({ width: '75%', height: '100%' });
    });

    it('shows the pace target beside a segment that has one', () => {
        render(<SessionBarGraph segments={[segment({ minutes: 30 })]} />);

        expect(screen.getByText('5:48/km · easy')).toBeInTheDocument();
    });

    it('falls back to the pace band alone when there is no VDOT estimate yet', () => {
        render(
            <SessionBarGraph
                segments={[segment({ minutes: 30, pace_sec_per_km: null })]}
            />,
        );

        expect(screen.getByText('easy')).toBeInTheDocument();
    });
});
