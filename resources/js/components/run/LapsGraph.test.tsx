import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { StreamSummaryLap } from '@/types/inertia';

import LapsGraph from './LapsGraph';

// A watch session with a manual lap press: two full kms, then a short rep and a
// shorter cooldown. Nothing here is a km, which is the point of the laps view.
const laps: StreamSummaryLap[] = [
    {
        lap: 1,
        distance_m: 1000,
        elapsed_sec: 360,
        pace: '6:00',
        avg_hr: 150,
        avg_cadence_spm: 170,
    },
    {
        lap: 2,
        distance_m: 1000,
        elapsed_sec: 345,
        pace: '5:45',
        avg_hr: 155,
        avg_cadence_spm: 173,
    },
    {
        lap: 3,
        distance_m: 647,
        elapsed_sec: 233,
        pace: '6:00',
        avg_hr: 152,
        avg_cadence_spm: 171,
    },
];

const kmGridLaps: StreamSummaryLap[] = [
    { lap: 1, distance_m: 1000, elapsed_sec: 360, pace: '6:00' },
    { lap: 2, distance_m: 1000, elapsed_sec: 345, pace: '5:45' },
];

describe('LapsGraph', () => {
    it('renders the section header and crowns the fastest lap', () => {
        render(<LapsGraph laps={laps} />);
        expect(screen.getByText('Laps')).toBeInTheDocument();
        expect(screen.getByText(/Paling kenceng di lap 2/)).toBeInTheDocument();
        expect(screen.getByText('5:45/km')).toBeInTheDocument();
    });

    it('renders one labelled bar per lap', () => {
        render(<LapsGraph laps={laps} />);
        expect(screen.getAllByRole('img')).toHaveLength(3);
        expect(
            screen.getByLabelText('Lap 1, 1000 m, 6:00 per km'),
        ).toBeInTheDocument();
        expect(
            screen.getByLabelText('Lap 3, 647 m, 6:00 per km'),
        ).toBeInTheDocument();
    });

    it("labels every row with the lap's own distance, never assuming a km", () => {
        render(<LapsGraph laps={laps} />);
        expect(screen.getByText('647m')).toBeInTheDocument();
        expect(screen.getAllByText('1000m')).toHaveLength(2);
    });

    it('renders the HR and cadence cells of a lap that recorded them', () => {
        render(<LapsGraph laps={laps} />);
        expect(screen.getByText('♡ 155')).toBeInTheDocument();
        expect(screen.getByText('↻ 173')).toBeInTheDocument();
    });

    it('dashes the HR and cadence cells of a lap that recorded neither', () => {
        render(<LapsGraph laps={kmGridLaps} />);
        expect(screen.getAllByText('♡ —')).toHaveLength(2);
        expect(screen.getAllByText('↻ —')).toHaveLength(2);
    });

    it('tints the fastest lap and zebra-stripes the rest', () => {
        const { container } = render(<LapsGraph laps={laps} />);
        expect(
            container.querySelector('.bg-horizon\\/\\[0\\.08\\]'),
        ).not.toBeNull();
        expect(
            container.querySelector('.bg-sky\\/\\[0\\.03\\]'),
        ).not.toBeNull();
    });

    it('renders in full when the laps are just the km grid, duplicating the splits view on purpose', () => {
        render(<LapsGraph laps={kmGridLaps} />);
        expect(screen.getByText('Laps')).toBeInTheDocument();
        expect(screen.getAllByRole('img')).toHaveLength(2);
        expect(screen.getAllByText('1000m')).toHaveLength(2);
        expect(screen.queryByText(/sama kayak split/i)).not.toBeInTheDocument();
    });

    it('omits the crown line when no lap has a parseable pace', () => {
        render(
            <LapsGraph
                laps={[
                    { lap: 1, distance_m: 1000, elapsed_sec: 360, pace: 'n/a' },
                ]}
            />,
        );
        expect(screen.getByText('Laps')).toBeInTheDocument();
        expect(screen.queryByText(/Paling kenceng/)).not.toBeInTheDocument();
    });

    it('passes the className through to the card', () => {
        const { container } = render(
            <LapsGraph laps={laps} className="mt-10" />,
        );
        expect(container.querySelector('section')).toHaveClass('mt-10');
    });
});
