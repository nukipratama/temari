import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import PastYouCard, { type PastYouMatch } from './PastYouCard';

function match(overrides: Partial<PastYouMatch> = {}): PastYouMatch {
    return {
        days_ago: 21,
        pace: { seconds_per_km: 47, relation: 'faster' },
        time: { seconds: 112, relation: 'faster' },
        hr: { bpm: 6, relation: 'lower' },
        direction: 'better',
        past_km: 10.4,
        past_activity_id: 42,
        past_name: 'Morning easy',
        effort: 'easy',
        ...overrides,
    };
}

describe('PastYouCard', () => {
    it('renders nothing when there is no match', () => {
        const { container } = render(<PastYouCard match={null} />);
        expect(container).toBeEmptyDOMElement();
    });

    it("colors the card's leading-edge stripe by the viewed run's effort", () => {
        render(<PastYouCard match={match({ effort: 'hard' })} />);
        expect(document.querySelector('span[aria-hidden]')).toHaveClass(
            'border-ember',
        );
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

    it('says slower purely from the relation word, never a recomputed sign', () => {
        render(
            <PastYouCard
                match={match({
                    pace: { seconds_per_km: 12, relation: 'slower' },
                    time: { seconds: 28, relation: 'slower' },
                })}
            />,
        );
        expect(screen.getByText('12')).toBeInTheDocument();
        expect(screen.getByText('sec/km slower')).toBeInTheDocument();
    });

    it('reads "Dead even" for a same-pace relation', () => {
        render(
            <PastYouCard
                match={match({
                    pace: { seconds_per_km: 2, relation: 'same' },
                })}
            />,
        );
        expect(screen.getByText('Dead even')).toBeInTheDocument();
        expect(screen.queryByText(/sec\/km/)).not.toBeInTheDocument();
    });

    it('links to the matched run', () => {
        render(<PastYouCard match={match()} />);
        expect(
            screen.getByRole('link', { name: /View that run/ }),
        ).toHaveAttribute('href', '/activities/42');
    });

    it('tones a lower heart rate as good and a higher one as a warning', () => {
        const { unmount } = render(<PastYouCard match={match()} />);
        expect(screen.getByText('6 bpm lower')).toHaveClass('text-leaf-ink');
        unmount();

        render(
            <PastYouCard
                match={match({ hr: { bpm: 4, relation: 'higher' } })}
            />,
        );
        expect(screen.getByText('4 bpm higher')).toHaveClass('text-citrus-ink');
    });

    it('calls an unchanged heart rate "the same", neither good nor bad', () => {
        render(
            <PastYouCard match={match({ hr: { bpm: 0, relation: 'same' } })} />,
        );
        expect(screen.getByText('0 bpm the same')).toHaveClass('text-text-2');
    });

    it('omits the heart-rate delta when either run had no HR', () => {
        render(<PastYouCard match={match({ hr: null })} />);
        expect(screen.queryByText('Heart rate')).not.toBeInTheDocument();
    });

    it('shows what the pace gap was worth over the whole distance', () => {
        render(<PastYouCard match={match()} />);
        expect(screen.getByText('Over the distance')).toBeInTheDocument();
        expect(screen.getByText(/quicker/)).toBeInTheDocument();
    });

    it('omits the time delta when the two runs finished level', () => {
        render(
            <PastYouCard
                match={match({ time: { seconds: 0, relation: 'same' } })}
            />,
        );
        expect(screen.queryByText('Over the distance')).not.toBeInTheDocument();
    });

    it('marks a slower finish over the same distance', () => {
        render(
            <PastYouCard
                match={match({ time: { seconds: 90, relation: 'slower' } })}
            />,
        );
        expect(screen.getByText(/slower/)).toHaveClass('text-citrus-ink');
    });

    it('renders the mixed case: slower pace against a lower heart rate (run 652)', () => {
        render(
            <PastYouCard
                match={match({
                    pace: { seconds_per_km: 13, relation: 'slower' },
                    time: { seconds: 33, relation: 'slower' },
                    hr: { bpm: 16, relation: 'lower' },
                    direction: 'worse',
                })}
            />,
        );
        expect(screen.getByText('sec/km slower')).toBeInTheDocument();
        expect(screen.getByText('16 bpm lower')).toHaveClass('text-leaf-ink');
    });

    it('renders the mixed case: slower pace against a lower heart rate (run 648)', () => {
        render(
            <PastYouCard
                match={match({
                    pace: { seconds_per_km: 43.1, relation: 'slower' },
                    time: { seconds: 120, relation: 'slower' },
                    hr: { bpm: 16, relation: 'lower' },
                    direction: 'worse',
                })}
            />,
        );
        expect(screen.getByText('43')).toBeInTheDocument();
        expect(screen.getByText('sec/km slower')).toBeInTheDocument();
        expect(screen.getByText('16 bpm lower')).toHaveClass('text-leaf-ink');
    });
});
