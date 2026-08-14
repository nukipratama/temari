import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import PastYouHero, { type PastYouMatch } from './PastYouHero';

function match(overrides: Partial<PastYouMatch> = {}): PastYouMatch {
    return {
        past: {
            start_date_local: '2026-04-01T07:00',
            activity_id: 42,
            name: 'Morning Run',
            distance: 10000,
        },
        pace_diff_sec: 18,
        hr_diff_bpm: -4,
        time_diff_sec: 180,
        days_ago: 30,
        ...overrides,
    };
}

describe('PastYouHero', () => {
    it('renders nothing when there is no match', () => {
        const { container } = render(<PastYouHero match={null} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('leads with the pace delta and how long ago the match was', () => {
        render(<PastYouHero match={match()} />);

        expect(screen.getByText('18')).toBeInTheDocument();
        expect(screen.getByText(/sec\/km faster/)).toBeInTheDocument();
        expect(screen.getByText(/30 days ago/)).toBeInTheDocument();
    });

    it('names the matched distance and run', () => {
        render(<PastYouHero match={match()} />);

        expect(screen.getByText(/the same 10.0 km/)).toBeInTheDocument();
        expect(screen.getByText(/Morning Run/)).toBeInTheDocument();
    });

    it('reads a negative pace delta as slower', () => {
        render(<PastYouHero match={match({ pace_diff_sec: -12 })} />);

        expect(screen.getByText('12')).toBeInTheDocument();
        expect(screen.getByText(/sec\/km slower/)).toBeInTheDocument();
    });

    it('says "dead even" instead of showing a zero', () => {
        render(<PastYouHero match={match({ pace_diff_sec: 0 })} />);

        expect(screen.getByText('Dead even')).toBeInTheDocument();
        expect(screen.queryByText(/sec\/km/)).not.toBeInTheDocument();
    });

    it('reads a lower heart rate as the better direction', () => {
        render(<PastYouHero match={match({ hr_diff_bpm: -4 })} />);

        const hr = screen.getByText('4 bpm lower');
        expect(hr).toBeInTheDocument();
        expect(hr).toHaveClass('text-leaf');
    });

    it('reads a higher heart rate as the worse direction', () => {
        render(<PastYouHero match={match({ hr_diff_bpm: 6 })} />);

        const hr = screen.getByText('6 bpm higher');
        expect(hr).toBeInTheDocument();
        expect(hr).toHaveClass('text-citrus');
    });

    it('omits the heart-rate delta when neither run recorded HR', () => {
        render(<PastYouHero match={match({ hr_diff_bpm: null })} />);

        expect(screen.queryByText('Heart rate')).not.toBeInTheDocument();
    });

    it('shows what the pace delta was worth over the whole distance', () => {
        render(<PastYouHero match={match({ time_diff_sec: 180 })} />);

        expect(screen.getByText('3 min quicker')).toBeInTheDocument();
    });

    it('omits the total-time delta when it rounds to nothing', () => {
        render(<PastYouHero match={match({ time_diff_sec: 0 })} />);

        expect(screen.queryByText('Over the distance')).not.toBeInTheDocument();
    });

    it('links to the matched past run', () => {
        render(<PastYouHero match={match()} />);

        expect(
            screen.getByRole('link', { name: /View that run/ }),
        ).toHaveAttribute('href', '/activities/42');
    });

    it('drops the link when the past run has no id', () => {
        render(
            <PastYouHero
                match={match({
                    past: { start_date_local: '2026-04-01T07:00' },
                })}
            />,
        );

        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(screen.getByText(/the same run/)).toBeInTheDocument();
    });
});
