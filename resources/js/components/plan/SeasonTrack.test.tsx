import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import SeasonTrack from './SeasonTrack';

describe('SeasonTrack', () => {
    it('names how many tiers are earned and how many are still out there', () => {
        render(
            <SeasonTrack
                earned={3}
                total={5}
                endsAt="2026-11-02"
                tiersKeptFromPastSeasons={0}
            />,
        );

        expect(
            screen.getByRole('img', {
                name: 'Season track: 3 of 5 tiers earned',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText(/2 still out there/i)).toBeInTheDocument();
    });

    it('says the track resets and the collection does not', () => {
        render(
            <SeasonTrack
                earned={0}
                total={5}
                endsAt="2026-11-02"
                tiersKeptFromPastSeasons={0}
            />,
        );

        expect(screen.getByText(/Resets to zero on/i)).toBeInTheDocument();
        expect(
            screen.getByText(/Your cards, accessories and badges do not/i),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /still missing/i }),
        ).toHaveAttribute('href', '/accessories');
    });

    it('drops the "still out there" line once every tier is earned', () => {
        render(
            <SeasonTrack
                earned={5}
                total={5}
                endsAt="2026-11-02"
                tiersKeptFromPastSeasons={0}
            />,
        );

        expect(screen.queryByText(/still out there/i)).not.toBeInTheDocument();
        expect(
            screen.getByText(/the whole track is yours/i),
        ).toBeInTheDocument();
    });

    it('hides the kept-tier count until an earlier season has actually left one', () => {
        const { rerender } = render(
            <SeasonTrack
                earned={1}
                total={5}
                endsAt="2026-11-02"
                tiersKeptFromPastSeasons={0}
            />,
        );
        expect(
            screen.queryByText(/kept from earlier seasons/i),
        ).not.toBeInTheDocument();

        rerender(
            <SeasonTrack
                earned={1}
                total={5}
                endsAt="2026-11-02"
                tiersKeptFromPastSeasons={4}
            />,
        );
        expect(
            screen.getByText('4 tiers kept from earlier seasons'),
        ).toBeInTheDocument();
    });
});
