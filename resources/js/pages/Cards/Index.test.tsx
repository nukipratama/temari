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

    it('marks the active rarity pill', () => {
        render(
            <CardsIndex
                cards={{ data: [], current_page: 1, last_page: 1, per_page: 24, total: 0, links: [] }}
                selectedRarity="epik"
            />,
        );
        const epikPill = screen.getByText('Epik').closest('a');
        expect(epikPill).toHaveClass(/bg-brand-500/);
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
