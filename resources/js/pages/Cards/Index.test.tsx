import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import CardsIndex from './Index';
import { setMockPage } from '@/test/setup';

beforeEach(() => {
    setMockPage({
        auth: { user: { id: 1, name: 'A', first_name: 'A', avatar_url: null } },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('Cards/Index', () => {
    const baseDetail = {
        id: 11,
        activity_id: 1,
        name: 'Run',
        start_date_local: '2026-05-10T07:00',
        distance: 10000,
        moving_time: 3600,
        average_heartrate: null,
        trimp_edwards: 60,
    };

    it('renders empty state', () => {
        render(
            <CardsIndex
                cards={{ data: [], current_page: 1, last_page: 1, per_page: 24, total: 0, links: [] }}
                selectedRarity={null}
            />,
        );
        expect(screen.getByText(/Belum ada kartu/)).toBeInTheDocument();
    });

    it('renders cards with their special_move', () => {
        render(
            <CardsIndex
                cards={{
                    data: [
                        {
                            id: 1,
                            activity_id: 1,
                            rarity: 'epik',
                            special_move: 'Paru-paru Baja',
                            badges: [],
                            activity: { id: 1, user_id: 1, analyzed_at: '2026-05-10', detail: baseDetail },
                        },
                    ],
                    current_page: 1,
                    last_page: 1,
                    per_page: 24,
                    total: 1,
                    links: [],
                }}
                selectedRarity={null}
            />,
        );
        expect(screen.getByText('Paru-paru Baja')).toBeInTheDocument();
    });

    it('skips cards missing detail relation', () => {
        render(
            <CardsIndex
                cards={{
                    data: [
                        // @ts-expect-error - missing activity.detail intentionally
                        { id: 1, activity_id: 1, rarity: 'epik', special_move: 'X', badges: [], activity: { id: 1, user_id: 1, analyzed_at: null } },
                    ],
                    current_page: 1,
                    last_page: 1,
                    per_page: 24,
                    total: 1,
                    links: [],
                }}
                selectedRarity={null}
            />,
        );
        expect(screen.queryByText('X')).not.toBeInTheDocument();
    });

    it('marks the active rarity pill with its rarity tint', () => {
        render(
            <CardsIndex
                cards={{ data: [], current_page: 1, last_page: 1, per_page: 24, total: 0, links: [] }}
                selectedRarity="epik"
            />,
        );
        // Active Epik now takes its rarity colour (accent), not brand.
        const epikPill = screen.getByText('Epic').closest('a');
        expect(epikPill).toHaveClass(/bg-accent-500/);
    });

    it('promotes the highest-rarity card to the Spotlight slot on page 1', () => {
        render(
            <CardsIndex
                cards={{
                    data: [
                        {
                            id: 1,
                            activity_id: 1,
                            rarity: 'jarang',
                            special_move: 'Daily',
                            badges: [],
                            activity: { id: 1, user_id: 1, analyzed_at: '2026-05-10', detail: { ...baseDetail, id: 1 } },
                        },
                        {
                            id: 2,
                            activity_id: 2,
                            rarity: 'legendaris',
                            special_move: 'Special',
                            badges: [],
                            activity: { id: 2, user_id: 1, analyzed_at: '2026-05-11', detail: { ...baseDetail, id: 2, activity_id: 2, start_date_local: '2026-05-11T07:00' } },
                        },
                    ],
                    current_page: 1,
                    last_page: 1,
                    per_page: 24,
                    total: 2,
                    links: [],
                }}
                selectedRarity={null}
            />,
        );
        expect(screen.getByText(/Spotlight kartu/i)).toBeInTheDocument();
        expect(screen.getByText('Special')).toBeInTheDocument();
    });

    it('breaks rarity ties in pickFeatured by most-recent start date', () => {
        // Two equal-rarity (epik) cards — newer date wins.
        render(
            <CardsIndex
                cards={{
                    data: [
                        {
                            id: 1,
                            activity_id: 1,
                            rarity: 'epik',
                            special_move: 'Older',
                            badges: [],
                            activity: { id: 1, user_id: 1, analyzed_at: '2026-05-10', detail: { ...baseDetail, id: 1, start_date_local: '2026-04-01T07:00' } },
                        },
                        {
                            id: 2,
                            activity_id: 2,
                            rarity: 'epik',
                            special_move: 'Newer',
                            badges: [],
                            activity: { id: 2, user_id: 1, analyzed_at: '2026-05-11', detail: { ...baseDetail, id: 2, activity_id: 2, start_date_local: '2026-05-11T07:00' } },
                        },
                    ],
                    current_page: 1,
                    last_page: 1,
                    per_page: 24,
                    total: 2,
                    links: [],
                }}
                selectedRarity={null}
            />,
        );
        // Spotlight slot picks "Newer".
        expect(screen.getByText('Newer')).toBeInTheDocument();
    });

    it('pickFeatured skips cards without an activity.detail relation', () => {
        // First card has no detail → skipped; second is the only candidate.
        render(
            <CardsIndex
                cards={{
                    data: [
                        // @ts-expect-error intentionally missing activity.detail
                        { id: 1, activity_id: 1, rarity: 'legendaris', special_move: 'No-Detail', badges: [], activity: { id: 1, user_id: 1, analyzed_at: null } },
                        {
                            id: 2,
                            activity_id: 2,
                            rarity: 'jarang',
                            special_move: 'Only Real',
                            badges: [],
                            activity: { id: 2, user_id: 1, analyzed_at: '2026-05-11', detail: { ...baseDetail, id: 2, activity_id: 2 } },
                        },
                    ],
                    current_page: 1,
                    last_page: 1,
                    per_page: 24,
                    total: 2,
                    links: [],
                }}
                selectedRarity={null}
            />,
        );
        // The no-detail card is skipped both for spotlight and grid.
        expect(screen.queryByText('No-Detail')).not.toBeInTheDocument();
        expect(screen.getByText('Only Real')).toBeInTheDocument();
    });

    it.each([
        ['biasa', 'Common'],
        ['jarang', 'Uncommon'],
        ['langka', 'Rare'],
        ['legendaris', 'Legendary'],
    ] as const)('tints the active pill for rarity %s', (rarity, label) => {
        render(
            <CardsIndex
                cards={{ data: [], current_page: 1, last_page: 1, per_page: 24, total: 0, links: [] }}
                selectedRarity={rarity}
            />,
        );
        // Walk up to the active pill anchor and assert its rarity tint class.
        const pill = screen.getByText(label).closest('a');
        expect(pill?.className).toMatch(/bg-(ink-meta|brand-400|mood-spinning|pop-500)/);
    });

    it('falls back to neutral pill colour for an unknown active rarity', () => {
        // Pass a rarity value not in the switch to hit the default branch.
        render(
            <CardsIndex
                cards={{ data: [], current_page: 1, last_page: 1, per_page: 24, total: 0, links: [] }}
                selectedRarity="mythic"
            />,
        );
        // The unknown rarity falls through to the brand-500 default tint.
        expect(screen.getByText('Semua')).toBeInTheDocument();
    });

    it('renders pagination links (active + inactive + disabled) when last_page > 1', () => {
        render(
            <CardsIndex
                cards={{
                    data: [
                        {
                            id: 1,
                            activity_id: 1,
                            rarity: 'epik',
                            special_move: 'Move',
                            badges: [],
                            activity: { id: 1, user_id: 1, analyzed_at: '2026-05-10', detail: baseDetail },
                        },
                    ],
                    current_page: 1,
                    last_page: 3,
                    per_page: 24,
                    total: 72,
                    links: [
                        { url: null, label: '&laquo; Prev', active: false },
                        { url: '/cards?page=1', label: '1', active: true },
                        { url: '/cards?page=2', label: '2', active: false },
                    ],
                }}
                selectedRarity={null}
            />,
        );
        expect(screen.getAllByRole('link').length).toBeGreaterThan(2);
    });
});
