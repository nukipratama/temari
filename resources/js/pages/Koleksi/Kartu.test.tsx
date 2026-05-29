import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import KoleksiKartu from './Kartu';
import { setMockPage } from '@/test/setup';
import type { Activity, ActivityDetail, RunCard } from '@/types/inertia';

const rarityCounts = { common: 5, uncommon: 4, rare: 3, epic: 2, legendary: 0 };

function emptyCards() {
    return { data: [], current_page: 1, last_page: 1, per_page: 12, total: 0, links: [] };
}

function cardWithRel(id: number, rarity: RunCard['rarity'], move = 'Langkah Mantap') {
    const activity: Activity = { id, user_id: 1, analyzed_at: '2026-05-10' };
    const detail: ActivityDetail = {
        id, activity_id: id, name: 'Lari pagi', start_date_local: '2026-05-10T06:30',
        distance: 5000, moving_time: 1800, trimp_edwards: 50, average_heartrate: 150,
        activity: { ...activity, run_card: { id, activity_id: id, rarity, special_move: move, badges: ['negative_split'] } },
    };
    return {
        id, activity_id: id, rarity, special_move: move, badges: ['negative_split'],
        share_image_path: null,
        activity: { ...activity, detail },
    };
}

beforeEach(() => {
    setMockPage({
        auth: { user: { id: 1, name: 'Ada', first_name: 'Ada', avatar_url: null } },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('Koleksi/Kartu', () => {
    it('renders the EmptyState when no cards and no featured card', () => {
        render(<KoleksiKartu cards={emptyCards()} selectedRarity={null} featuredCard={null} rarityCounts={rarityCounts} />);
        expect(screen.getByText(/Belum ada kartu di sini/)).toBeInTheDocument();
    });

    it('renders the LegendaryTease when legendary count is 0', () => {
        render(<KoleksiKartu cards={emptyCards()} selectedRarity={null} featuredCard={null} rarityCounts={rarityCounts} />);
        expect(screen.getByText(/Ada kartu Legendaris nungguin/)).toBeInTheDocument();
    });

    it('omits the LegendaryTease when at least one legendary card exists', () => {
        const counts = { ...rarityCounts, legendary: 1 };
        render(<KoleksiKartu cards={emptyCards()} selectedRarity={null} featuredCard={null} rarityCounts={counts} />);
        expect(screen.queryByText(/Ada kartu Legendaris nungguin/)).not.toBeInTheDocument();
    });

    it('renders the rarity filter pills (counts per rarity)', () => {
        render(<KoleksiKartu cards={emptyCards()} selectedRarity="epic" featuredCard={null} rarityCounts={rarityCounts} />);
        expect(screen.getByText(/Biasa · 5/)).toBeInTheDocument();
        expect(screen.getByText(/Luar Biasa · 2/)).toBeInTheDocument();
    });

    it('renders the featured panel with flavor analysis + badge tags', () => {
        const featured = {
            id: 7, activity_id: 99, rarity: 'epic' as const, special_move: 'Pembalik Keadaan', badges: ['negative_split', 'hari_panas'],
            detail: {
                id: 1, activity_id: 99, name: 'Sub-30', start_date_local: '2026-05-10T06:00',
                distance: 5000, moving_time: 1751, trimp_edwards: 85, average_heartrate: 150,
            } as ActivityDetail,
            flavor_analysis: {
                id: 1, status: 'done' as const, content: 'Lari yang menyegarkan.',
                type: 'card_flavor' as const, subject_type: 'run_card', subject_id: 7, discriminator: null,
            },
        };
        render(<KoleksiKartu cards={emptyCards()} selectedRarity={null} featuredCard={featured} rarityCounts={rarityCounts} />);
        expect(screen.getAllByText('Pembalik Keadaan').length).toBeGreaterThan(0);
        expect(screen.getByText(/Highlight minggu ini/)).toBeInTheDocument();
        expect(screen.getByText(/Lari yang menyegarkan/)).toBeInTheDocument();
        expect(screen.getAllByText('Negative Split').length).toBeGreaterThan(0);
    });

    it('triggers a confetti burst when an epic featured card is tapped', () => {
        const featured = {
            id: 7, activity_id: 99, rarity: 'legendary' as const, special_move: 'Legendaris', badges: [],
            detail: {
                id: 1, activity_id: 99, name: 'Half marathon', start_date_local: '2026-05-10T06:00',
                distance: 21097, moving_time: 7200, trimp_edwards: 250, average_heartrate: 160,
            } as ActivityDetail,
        };
        render(<KoleksiKartu cards={emptyCards()} selectedRarity={null} featuredCard={featured} rarityCounts={{ ...rarityCounts, legendary: 1 }} />);
        const cardLink = screen.getAllByRole('link').find((el) => el.getAttribute('href') === '/kartu/7');
        fireEvent.click(cardLink!);
    });

    it('renders the grid when cards.data has entries + handles cell taps', () => {
        const cards = { ...emptyCards(), data: [cardWithRel(1, 'epic', 'Tendangan Epic'), cardWithRel(2, 'common')] };
        render(<KoleksiKartu cards={cards} selectedRarity={null} featuredCard={null} rarityCounts={rarityCounts} />);
        expect(screen.getByText('Tendangan Epic')).toBeInTheDocument();
        const links = screen.getAllByRole('link').filter((el) => el.getAttribute('href') === '/kartu/1');
        fireEvent.click(links[0]);
    });

    it('falls back gracefully when featured card has null detail / no badges', () => {
        const featured = {
            id: 7, activity_id: 99, rarity: 'rare' as const, special_move: 'Pemburu Sabar', badges: null,
            detail: null,
        };
        render(<KoleksiKartu cards={emptyCards()} selectedRarity={null} featuredCard={featured} rarityCounts={rarityCounts} />);
        expect(screen.getAllByText('Pemburu Sabar').length).toBeGreaterThan(0);
    });

    it('skips grid cells whose card has no detail', () => {
        const cardWithoutDetail = {
            id: 9, activity_id: 99, rarity: 'common' as const, special_move: 'Tanpa Detail', badges: null,
            share_image_path: null,
            activity: { id: 99, user_id: 1, analyzed_at: '2026-05-10', strava_external_id: null, detail: undefined as never },
        };
        const cards = { ...emptyCards(), data: [cardWithoutDetail] };
        render(<KoleksiKartu cards={cards} selectedRarity={null} featuredCard={null} rarityCounts={rarityCounts} />);
        expect(screen.queryByText('Tanpa Detail')).not.toBeInTheDocument();
    });
});
