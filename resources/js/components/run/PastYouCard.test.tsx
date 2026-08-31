import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import PastYouCard, { type PastYouMatch } from './PastYouCard';

function match(overrides: Partial<PastYouMatch> = {}): PastYouMatch {
    return {
        past: {
            start_date_local: '2026-04-01T07:00',
            activity_id: 42,
            name: 'Morning easy',
            distance: 10400,
        },
        pace_diff_sec: 47,
        hr_diff_bpm: -6,
        time_diff_sec: 112,
        days_ago: 21,
        ...overrides,
    };
}

describe('PastYouCard', () => {
    it('renders nothing when there is no match', () => {
        const { container } = render(<PastYouCard match={null} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('leads with the pace delta and names the run it beat', () => {
        render(<PastYouCard match={match()} />);
        expect(screen.getByText('You vs past you')).toBeInTheDocument();
        expect(screen.getByText('47')).toBeInTheDocument();
        expect(screen.getByText('sec/km faster')).toBeInTheDocument();
        expect(
            screen.getByText(/the same 10.4 km, 21 days ago · Morning easy/),
        ).toBeInTheDocument();
    });

    it('says slower when the past run was quicker', () => {
        render(<PastYouCard match={match({ pace_diff_sec: -12 })} />);
        expect(screen.getByText('12')).toBeInTheDocument();
        expect(screen.getByText('sec/km slower')).toBeInTheDocument();
    });

    it('reads "Dead even" rather than a signed zero', () => {
        render(<PastYouCard match={match({ pace_diff_sec: 0 })} />);
        expect(screen.getByText('Dead even')).toBeInTheDocument();
        expect(screen.queryByText(/sec\/km/)).not.toBeInTheDocument();
    });

    it('falls back to "the same run" when the past distance is unknown', () => {
        render(
            <PastYouCard
                match={match({
                    past: { start_date_local: null, distance: null },
                })}
            />,
        );
        expect(screen.getByText(/the same run, 21 days ago/)).toBeInTheDocument();
    });

    it('links to the matched run only when it has an id', () => {
        const { unmount } = render(<PastYouCard match={match()} />);
        expect(
            screen.getByRole('link', { name: /View that run/ }),
        ).toHaveAttribute('href', '/activities/42');
        unmount();

        render(
            <PastYouCard
                match={match({ past: { start_date_local: null } })}
            />,
        );
        expect(screen.queryByText('View that run')).not.toBeInTheDocument();
    });

    it('tones a lower heart rate as good and a higher one as a warning', () => {
        const { unmount } = render(<PastYouCard match={match()} />);
        expect(screen.getByText('6 bpm lower')).toHaveClass('text-leaf-ink');
        unmount();

        render(<PastYouCard match={match({ hr_diff_bpm: 4 })} />);
        expect(screen.getByText('4 bpm higher')).toHaveClass('text-citrus-ink');
    });

    it('calls an unchanged heart rate "the same", neither good nor bad', () => {
        render(<PastYouCard match={match({ hr_diff_bpm: 0 })} />);
        expect(screen.getByText('0 bpm the same')).toHaveClass('text-text-2');
    });

    it('omits the heart-rate delta when either run had no HR', () => {
        render(<PastYouCard match={match({ hr_diff_bpm: null })} />);
        expect(screen.queryByText('Heart rate')).not.toBeInTheDocument();
    });

    it('shows what the pace gap was worth over the whole distance', () => {
        render(<PastYouCard match={match()} />);
        expect(screen.getByText('Over the distance')).toBeInTheDocument();
        expect(screen.getByText(/quicker/)).toBeInTheDocument();
    });

    it('omits the time delta when the two runs finished level', () => {
        render(<PastYouCard match={match({ time_diff_sec: 0 })} />);
        expect(screen.queryByText('Over the distance')).not.toBeInTheDocument();
    });

    it('marks a slower finish over the same distance', () => {
        render(<PastYouCard match={match({ time_diff_sec: -90 })} />);
        expect(screen.getByText(/slower/)).toHaveClass('text-citrus-ink');
    });
});
