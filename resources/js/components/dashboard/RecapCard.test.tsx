import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// Stub the share modal — its canvas/share behaviour is covered by its own test.
// Here we only assert the trigger opens it.
vi.mock('@/components/dashboard/RecapShareModal', () => ({
    default: ({ recap }: { recap: unknown }) =>
        recap ? <div data-testid="recap-share-modal" /> : null,
}));

import RecapCard from './RecapCard';
import type { WeeklyRecap } from '@/types/inertia';

function makeRecap(overrides: Partial<WeeklyRecap> = {}): WeeklyRecap {
    return {
        week_start: '2026-05-11',
        week_end: '2026-05-17',
        this_week_km: 32.4,
        this_week_runs: 4,
        last_week_km: 28.9,
        delta_pct: 12,
        streak_weeks: 3,
        best_card: {
            id: 9,
            rarity: 'legendary',
            special_move: 'Pemburu Sabar',
            mood: 'nyala',
            distance_km: 12.3,
            polyline: '_p~iF~ps|U',
            date: '2026-05-14',
        },
        nearest_goal: {
            id: 'accessory.sepatu_basic',
            title: 'Catat 10 lari',
            current: 8,
            target: 10,
            unit: 'lari',
            ratio: 0.8,
            remainder_label: '2 lari lagi',
        },
        ...overrides,
    };
}

describe('RecapCard', () => {
    it('renders the Minggu Kamu eyebrow and the week range', () => {
        render(<RecapCard recap={makeRecap()} />);
        expect(screen.getByText('Minggu Kamu')).toBeInTheDocument();
        expect(screen.getByText('11 Mei - 17 Mei')).toBeInTheDocument();
    });

    it('renders the km hero and the run count', () => {
        render(<RecapCard recap={makeRecap()} />);
        expect(screen.getByText('32.4')).toBeInTheDocument();
        expect(screen.getByText(/4 lari minggu ini/)).toBeInTheDocument();
    });

    it('shows a positive delta with a + sign', () => {
        render(<RecapCard recap={makeRecap({ delta_pct: 12 })} />);
        expect(screen.getByText('+12% dari minggu lalu')).toBeInTheDocument();
    });

    it('shows a negative delta with a - sign and no double minus', () => {
        render(<RecapCard recap={makeRecap({ delta_pct: -25 })} />);
        expect(screen.getByText('-25% dari minggu lalu')).toBeInTheDocument();
    });

    it('reads a zero delta as "sama" rather than "+0%"', () => {
        render(<RecapCard recap={makeRecap({ delta_pct: 0 })} />);
        expect(screen.getByText('sama kayak minggu lalu')).toBeInTheDocument();
        expect(screen.queryByText(/0%/)).toBeNull();
    });

    it('nudges first-week users when the delta is null', () => {
        render(<RecapCard recap={makeRecap({ delta_pct: null })} />);
        expect(screen.getByText('minggu pertama kamu')).toBeInTheDocument();
    });

    it('shows the streak chip when the streak is 2+ weeks', () => {
        render(<RecapCard recap={makeRecap({ streak_weeks: 3 })} />);
        expect(screen.getByText('3 minggu beruntun')).toBeInTheDocument();
    });

    it('hides the streak chip for a single-week streak', () => {
        render(<RecapCard recap={makeRecap({ streak_weeks: 1 })} />);
        expect(screen.queryByText(/beruntun/)).toBeNull();
    });

    it('renders the best card special move and its rarity label', () => {
        render(<RecapCard recap={makeRecap()} />);
        expect(screen.getByText('Kartu terbaik')).toBeInTheDocument();
        // "Legendaris" appears both inside KartuMini's stat block and in the
        // recap's rarity caption, so match all occurrences.
        expect(screen.getAllByText(/Legendaris/).length).toBeGreaterThan(0);
        expect(screen.getByLabelText('Pemburu Sabar')).toBeInTheDocument();
    });

    it('renders the nearest-goal nudge', () => {
        render(<RecapCard recap={makeRecap()} />);
        expect(screen.getByText('2 lari lagi')).toBeInTheDocument();
        expect(screen.getByText(/Catat 10 lari/)).toBeInTheDocument();
    });

    it('opens the share modal when Bagikan minggu ini is clicked', () => {
        render(<RecapCard recap={makeRecap()} />);
        expect(screen.queryByTestId('recap-share-modal')).toBeNull();
        fireEvent.click(screen.getByText(/Bagikan minggu ini/));
        expect(screen.getByTestId('recap-share-modal')).toBeInTheDocument();
    });

    it('shows an encouraging empty state when there were no runs this week', () => {
        render(<RecapCard recap={makeRecap({ this_week_runs: 0, this_week_km: 0, best_card: null })} />);
        expect(screen.getByText(/Minggu ini masih kosong/)).toBeInTheDocument();
        // No km hero, no share button, no best card in the empty state.
        expect(screen.queryByText(/Bagikan minggu ini/)).toBeNull();
        expect(screen.queryByText('Kartu terbaik')).toBeNull();
    });
});
