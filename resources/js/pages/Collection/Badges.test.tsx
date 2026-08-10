import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { setMockPage } from '@/test/setup';

import Badges from './Badges';

function item(
    key: string,
    unlocked: boolean,
    lifetime_count = 0,
    season_count = 0,
) {
    return { key, unlocked, lifetime_count, season_count };
}

beforeEach(() => {
    setMockPage({
        auth: {
            user: { id: 1, name: 'Ada', first_name: 'Ada', avatar_url: null },
        },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('Collection/Badges', () => {
    it('renders the header count and both counts on an earned badge', () => {
        render(
            <Badges
                items={[item('heat_tamer', true, 3, 1)]}
                seasonStartsAt="2026-08-10"
                seasonEndsAt="2026-11-02"
            />,
        );
        expect(screen.getByText(/Every badge,/)).toBeInTheDocument();
        expect(screen.getAllByText(/1 \/ 1/).length).toBeGreaterThan(0);
        expect(screen.getByText('Heat Tamer')).toBeInTheDocument();
        expect(screen.getByText('Lifetime 3')).toBeInTheDocument();
        expect(screen.getByText('This season 1')).toBeInTheDocument();
    });

    it('shows a criterion, not counts, for a locked badge', () => {
        render(
            <Badges
                items={[item('speedster', false)]}
                seasonStartsAt="2026-08-10"
                seasonEndsAt="2026-11-02"
            />,
        );
        expect(screen.getByText('Speedster')).toBeInTheDocument();
        expect(
            screen.getByText('Pace under 5:00/km, fast.'),
        ).toBeInTheDocument();
        expect(screen.queryByText(/Lifetime/)).not.toBeInTheDocument();
    });

    it('renders the rest-honored entry with its own display text', () => {
        render(
            <Badges
                items={[item('season.rest_honored', true, 5, 2)]}
                seasonStartsAt="2026-08-10"
                seasonEndsAt="2026-11-02"
            />,
        );
        expect(screen.getByText('Rest, Honored')).toBeInTheDocument();
        expect(screen.getByText('Lifetime 5')).toBeInTheDocument();
        expect(screen.getByText('This season 2')).toBeInTheDocument();
    });
});
