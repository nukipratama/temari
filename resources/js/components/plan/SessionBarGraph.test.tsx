import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { PlanSessionSegment } from '@/types/inertia';

import SessionBarGraph from './SessionBarGraph';

function segment(
    overrides: Partial<PlanSessionSegment> = {},
): PlanSessionSegment {
    return {
        key: 'main',
        minutes: 30,
        km: 5.2,
        zone: 'Z2',
        pace_label: 'easy',
        pace_sec_per_km: 348,
        ...overrides,
    };
}

const INTERVAL_SESSION: PlanSessionSegment[] = [
    segment({ key: 'warmup', minutes: 10, km: 2, zone: 'Z1' }),
    segment({
        key: 'interval',
        minutes: 3,
        km: 0.8,
        zone: 'Z5',
        pace_label: 'interval',
    }),
    segment({ key: 'recovery', minutes: 2, km: 0.3, zone: 'Z1' }),
    segment({
        key: 'interval',
        minutes: 3,
        km: 0.8,
        zone: 'Z5',
        pace_label: 'interval',
    }),
    segment({ key: 'recovery', minutes: 2, zone: 'Z1' }),
    segment({
        key: 'interval',
        minutes: 3,
        zone: 'Z5',
        pace_label: 'interval',
    }),
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
                    segment({
                        key: 'warmup',
                        minutes: 10,
                        km: 1.3,
                        zone: 'Z1',
                    }),
                    segment({ key: 'main', minutes: 30, km: 4.6 }),
                ]}
            />,
        );

        expect(screen.getByText('warmup')).toBeInTheDocument();
        expect(screen.getByText('main set')).toBeInTheDocument();
        expect(screen.getByText('1.3 km · 10 min')).toBeInTheDocument();
        expect(screen.getByText('4.6 km · 30 min')).toBeInTheDocument();
    });

    it('collapses interval repeats into one legend block rather than listing every rep', () => {
        render(<SessionBarGraph segments={INTERVAL_SESSION} />);

        expect(screen.getByText('3× interval')).toBeInTheDocument();
        expect(
            screen.getByText('0.8 km · 3 min hard / 2 min easy'),
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

    it('falls back to the duration alone when there is no VDOT estimate to size a bookend', () => {
        render(
            <SessionBarGraph
                segments={[
                    segment({
                        key: 'warmup',
                        minutes: 10,
                        km: null,
                        zone: 'Z1',
                    }),
                    segment({ key: 'main', minutes: 30, km: 4.6 }),
                ]}
            />,
        );

        expect(screen.getByText('10 min')).toBeInTheDocument();
    });

    it('collapses a phase-split tempo day the same way it collapses interval reps', () => {
        render(
            <SessionBarGraph
                segments={[
                    segment({
                        key: 'warmup',
                        minutes: 10,
                        km: 1.7,
                        zone: 'Z1',
                    }),
                    segment({
                        key: 'main',
                        minutes: 18.9,
                        km: 4.2,
                        zone: 'Z4',
                        pace_label: 'threshold',
                    }),
                    segment({
                        key: 'recovery',
                        minutes: 2,
                        km: 0.3,
                        zone: 'Z1',
                    }),
                    segment({
                        key: 'main',
                        minutes: 18.9,
                        km: 4.2,
                        zone: 'Z4',
                        pace_label: 'threshold',
                    }),
                ]}
            />,
        );

        expect(screen.getByText('2× main set')).toBeInTheDocument();
        expect(
            screen.getByText('4.2 km · 18.9 min hard / 2 min easy'),
        ).toBeInTheDocument();
    });

    it('still lists a single main set rather than collapsing it', () => {
        render(
            <SessionBarGraph
                segments={[
                    segment({
                        key: 'warmup',
                        minutes: 10,
                        km: 1.7,
                        zone: 'Z1',
                    }),
                    segment({ key: 'main', minutes: 30, km: 4.6 }),
                ]}
            />,
        );

        expect(screen.getByText('main set')).toBeInTheDocument();
        expect(screen.queryByText(/× main set/)).not.toBeInTheDocument();
    });
});
